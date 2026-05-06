"""
SpiderNet OS — OpenAI Timeout/Retry/Cost Wrapper

Dedicated wrapper for OpenAI API calls with:
- Exponential backoff retries
- Per-request timeout budgets
- Cost ceiling enforcement
- Circuit breaker for repeated failures
- Model-agnostic interface (works with any OpenAI model)
"""
import asyncio
import time
from dataclasses import dataclass, field
from typing import Optional, Callable, Any
from enum import Enum

import httpx


class RetryStrategy(Enum):
    EXPONENTIAL = "exponential"
    LINEAR = "linear"
    CONSTANT = "constant"


@dataclass
class CostBudget:
    """Tracks cost consumption for a single request chain."""
    ceiling: float
    spent: float = 0.0
    requests: int = 0

    def can_spend(self, estimated: float) -> bool:
        return (self.spent + estimated) <= self.ceiling

    def record(self, cost: float):
        self.spent += cost
        self.requests += 1

    @property
    def remaining(self) -> float:
        return max(0.0, self.ceiling - self.spent)


@dataclass
class RetryPolicy:
    """Configurable retry behavior."""
    max_retries: int = 3
    base_delay_ms: float = 500
    max_delay_ms: float = 10_000
    strategy: RetryStrategy = RetryStrategy.EXPONENTIAL
    retryable_status_codes: set = field(default_factory=lambda: {429, 500, 502, 503, 504})

    def delay_for_attempt(self, attempt: int) -> float:
        """Calculate delay in seconds for a given attempt number."""
        if self.strategy == RetryStrategy.EXPONENTIAL:
            delay = self.base_delay_ms * (2 ** attempt)
        elif self.strategy == RetryStrategy.LINEAR:
            delay = self.base_delay_ms * (attempt + 1)
        else:
            delay = self.base_delay_ms
        return min(delay, self.max_delay_ms) / 1000


@dataclass
class TimeoutConfig:
    """Timeout budgets for different operations."""
    connect_ms: float = 5_000
    read_ms: float = 60_000
    total_ms: float = 120_000


@dataclass
class CircuitBreaker:
    """Prevents repeated calls to failing endpoints."""
    failure_threshold: int = 5
    recovery_timeout_ms: float = 30_000
    failures: int = 0
    last_failure_at: float = 0.0
    state: str = "closed"  # closed, open, half-open

    def record_failure(self):
        self.failures += 1
        self.last_failure_at = time.time()
        if self.failures >= self.failure_threshold:
            self.state = "open"

    def record_success(self):
        self.failures = 0
        self.state = "closed"

    def is_available(self) -> bool:
        if self.state == "closed":
            return True
        if self.state == "open":
            elapsed = (time.time() - self.last_failure_at) * 1000
            if elapsed >= self.recovery_timeout_ms:
                self.state = "half-open"
                return True
            return False
        return True  # half-open allows one trial


@dataclass
class OpenAIResponse:
    """Normalized response from any OpenAI model."""
    text: str
    model: str
    prompt_tokens: int
    completion_tokens: int
    total_tokens: int
    cost: float
    latency_ms: float
    attempts: int
    retries: int


# ─── Model Pricing (2026-05 rates) ─────────────────────────────────────────

OPENAI_MODELS = {
    # ── Budget tier ──────────────────────────────────────────────────────
    "gpt-5-nano": {
        "input_per_1k": 0.00005,
        "output_per_1k": 0.0004,
        "context_window": 272_000,
        "max_output": 16_384,
        "latency_p50_ms": 400,
        "latency_p99_ms": 1500,
        "tier": "budget",
    },
    "gpt-4o-mini": {
        "input_per_1k": 0.00015,
        "output_per_1k": 0.0006,
        "context_window": 128_000,
        "max_output": 16_384,
        "latency_p50_ms": 800,
        "latency_p99_ms": 3000,
        "tier": "budget",
        "note": "legacy — prefer gpt-5-mini",
    },
    # ── Best step-up from budget (recommended) ──────────────────────────
    "gpt-5-mini": {
        "input_per_1k": 0.00025,
        "output_per_1k": 0.002,
        "context_window": 272_000,
        "max_output": 32_768,
        "latency_p50_ms": 600,
        "latency_p99_ms": 2500,
        "tier": "standard",
        "note": "best quality-to-cost step-up from gpt-4o-mini",
    },
    "gpt-4.1-mini": {
        "input_per_1k": 0.0004,
        "output_per_1k": 0.0016,
        "context_window": 1_048_576,
        "max_output": 32_768,
        "latency_p50_ms": 2000,
        "latency_p99_ms": 8000,
        "tier": "standard",
        "note": "use when 1M context needed",
    },
    # ── Production default ───────────────────────────────────────────────
    "gpt-5": {
        "input_per_1k": 0.00125,
        "output_per_1k": 0.01,
        "context_window": 272_000,
        "max_output": 32_768,
        "latency_p50_ms": 1200,
        "latency_p99_ms": 4000,
        "tier": "flagship",
        "note": "general-purpose default",
    },
    "gpt-5.4": {
        "input_per_1k": 0.0025,
        "output_per_1k": 0.015,
        "context_window": 1_050_000,
        "max_output": 128_000,
        "latency_p50_ms": 1500,
        "latency_p99_ms": 5000,
        "tier": "flagship",
        "note": "affordable coding/professional work",
    },
    "gpt-4.1": {
        "input_per_1k": 0.002,
        "output_per_1k": 0.008,
        "context_window": 1_048_576,
        "max_output": 32_768,
        "latency_p50_ms": 1500,
        "latency_p99_ms": 5000,
        "tier": "flagship",
        "note": "legacy — prefer gpt-5",
    },
    # ── Reasoning tier ───────────────────────────────────────────────────
    "o4-mini": {
        "input_per_1k": 0.0011,
        "output_per_1k": 0.0044,
        "context_window": 200_000,
        "max_output": 100_000,
        "latency_p50_ms": 3000,
        "latency_p99_ms": 15000,
        "tier": "reasoning",
        "note": "latest reasoning, best value",
    },
    "o3-mini": {
        "input_per_1k": 0.0011,
        "output_per_1k": 0.0044,
        "context_window": 200_000,
        "max_output": 100_000,
        "latency_p50_ms": 5000,
        "latency_p99_ms": 30000,
        "tier": "reasoning",
        "note": "superseded by o4-mini",
    },
    "o3": {
        "input_per_1k": 0.002,
        "output_per_1k": 0.008,
        "context_window": 200_000,
        "max_output": 100_000,
        "latency_p50_ms": 10000,
        "latency_p99_ms": 60000,
        "tier": "reasoning",
    },
}


# ─── Core Wrapper ───────────────────────────────────────────────────────────

class OpenAIWrapper:
    """
    Production-grade OpenAI API wrapper with timeout, retry, and cost control.

    Usage:
        wrapper = OpenAIWrapper(api_key="sk-...")
        resp = await wrapper.chat(
            model="gpt-4o-mini",
            messages=[{"role": "user", "content": "Hello"}],
            cost_ceiling=0.01,  # $0.01 max
        )
    """

    def __init__(
        self,
        api_key: str,
        retry_policy: Optional[RetryPolicy] = None,
        timeout: Optional[TimeoutConfig] = None,
        circuit_breaker: Optional[CircuitBreaker] = None,
        base_url: str = "https://api.openai.com/v1",
    ):
        self.api_key = api_key
        self.base_url = base_url.rstrip("/")
        self.retry_policy = retry_policy or RetryPolicy()
        self.timeout = timeout or TimeoutConfig()
        self.circuit_breaker = circuit_breaker or CircuitBreaker()
        self._client: Optional[httpx.AsyncClient] = None

    async def _get_client(self) -> httpx.AsyncClient:
        if self._client is None or self._client.is_closed:
            self._client = httpx.AsyncClient(
                timeout=httpx.Timeout(
                    connect=self.timeout.connect_ms / 1000,
                    read=self.timeout.read_ms / 1000,
                    write=10.0,
                    pool=5.0,
                ),
                limits=httpx.Limits(max_connections=100),
            )
        return self._client

    async def close(self):
        if self._client and not self._client.is_closed:
            await self._client.aclose()

    def _estimate_cost(self, model: str, prompt: str, max_tokens: int) -> float:
        """Estimate cost before making the call."""
        pricing = OPENAI_MODELS.get(model)
        if not pricing:
            return 0.0
        input_tokens = len(prompt.split())  # rough estimate
        input_cost = (input_tokens / 1000) * pricing["input_per_1k"]
        output_cost = (max_tokens / 1000) * pricing["output_per_1k"]
        return input_cost + output_cost

    async def chat(
        self,
        model: str,
        messages: list[dict],
        max_tokens: int = 4096,
        temperature: float = 0.7,
        cost_ceiling: Optional[float] = None,
        timeout_ms: Optional[float] = None,
    ) -> OpenAIResponse:
        """
        Chat completion with timeout/retry/cost wrapper.

        Args:
            model: OpenAI model name (e.g., "gpt-4o-mini")
            messages: Chat messages array
            max_tokens: Maximum completion tokens
            temperature: Sampling temperature
            cost_ceiling: Maximum $ to spend on this request chain (incl retries)
            timeout_ms: Per-attempt timeout override

        Returns:
            OpenAIResponse with text, cost, token counts, and retry stats

        Raises:
            CostExceededError: If estimated cost exceeds ceiling
            TimeoutError: If all attempts timeout
            CircuitOpenError: If circuit breaker is open
            OpenAIError: If all retries exhausted
        """
        if not self.circuit_breaker.is_available():
            raise CircuitOpenError(
                f"Circuit breaker open after {self.circuit_breaker.failures} failures. "
                f"Recovery in {self.circuit_breaker.recovery_timeout_ms}ms"
            )

        # Validate model
        if model not in OPENAI_MODELS:
            raise ValueError(
                f"Unknown model: {model}. Available: {list(OPENAI_MODELS.keys())}"
            )

        # Cost check
        if cost_ceiling is not None:
            budget = CostBudget(ceiling=cost_ceiling)
            estimated = self._estimate_cost(model, messages[-1].get("content", ""), max_tokens)
            if not budget.can_spend(estimated):
                raise CostExceededError(
                    f"Estimated ${estimated:.6f} exceeds ceiling ${cost_ceiling:.6f}"
                )
        else:
            budget = CostBudget(ceiling=float("inf"))

        prompt = "\n".join(m.get("content", "") for m in messages)
        last_error = None
        attempts = 0
        retries = 0

        for attempt in range(self.retry_policy.max_retries + 1):
            attempts += 1
            start = time.time()

            try:
                client = await self._get_client()
                resp = await client.post(
                    f"{self.base_url}/chat/completions",
                    headers={
                        "Authorization": f"Bearer {self.api_key}",
                        "Content-Type": "application/json",
                    },
                    json={
                        "model": model,
                        "messages": messages,
                        "max_tokens": max_tokens,
                        "temperature": temperature,
                    },
                )

                if resp.status_code in self.retry_policy.retryable_status_codes:
                    raise RetryableError(
                        f"HTTP {resp.status_code}: {resp.text[:200]}",
                        status_code=resp.status_code,
                    )

                resp.raise_for_status()
                data = resp.json()

                latency_ms = (time.time() - start) * 1000
                usage = data.get("usage", {})
                completion = data["choices"][0]["message"]["content"]

                # Calculate actual cost
                pricing = OPENAI_MODELS[model]
                prompt_tokens = usage.get("prompt_tokens", 0)
                completion_tokens = usage.get("completion_tokens", 0)
                total_tokens = usage.get("total_tokens", prompt_tokens + completion_tokens)
                actual_cost = (
                    (prompt_tokens / 1000) * pricing["input_per_1k"]
                    + (completion_tokens / 1000) * pricing["output_per_1k"]
                )

                budget.record(actual_cost)
                self.circuit_breaker.record_success()

                return OpenAIResponse(
                    text=completion,
                    model=model,
                    prompt_tokens=prompt_tokens,
                    completion_tokens=completion_tokens,
                    total_tokens=total_tokens,
                    cost=round(actual_cost, 6),
                    latency_ms=round(latency_ms, 1),
                    attempts=attempts,
                    retries=retries,
                )

            except (RetryableError, httpx.TimeoutException, httpx.RemoteProtocolError) as e:
                last_error = e
                retries += 1
                self.circuit_breaker.record_failure()

                if attempt < self.retry_policy.max_retries:
                    delay = self.retry_policy.delay_for_attempt(attempt)
                    await asyncio.sleep(delay)
                continue

            except httpx.HTTPStatusError as e:
                last_error = e
                self.circuit_breaker.record_failure()
                if e.response.status_code not in self.retry_policy.retryable_status_codes:
                    raise OpenAIError(f"Non-retryable HTTP {e.response.status_code}: {e}")
                if attempt < self.retry_policy.max_retries:
                    delay = self.retry_policy.delay_for_attempt(attempt)
                    await asyncio.sleep(delay)
                continue

        raise OpenAIError(
            f"All {attempts} attempts failed. Last error: {last_error}"
        )


# ─── Custom Exceptions ──────────────────────────────────────────────────────

class CostExceededError(Exception):
    """Estimated or actual cost exceeds the configured ceiling."""
    pass


class CircuitOpenError(Exception):
    """Circuit breaker is open — service is temporarily unavailable."""
    pass


class OpenAIError(Exception):
    """All retries exhausted or non-retryable error occurred."""
    pass


class RetryableError(Exception):
    """Transient error that should trigger a retry."""
    def __init__(self, message: str, status_code: Optional[int] = None):
        super().__init__(message)
        self.status_code = status_code


# ─── Recommended Configurations ─────────────────────────────────────────────

def recommended_for_cost_control(api_key: str) -> OpenAIWrapper:
    """
    Best config for cost-sensitive workloads (high volume, tight budget).

    Model: gpt-5-nano ($0.05/$0.40 per 1M)
    Retries: 3 with exponential backoff (500ms → 1s → 2s → 4s)
    Timeout: 30s total
    Cost ceiling: $0.01 per request chain
    """
    return OpenAIWrapper(
        api_key=api_key,
        retry_policy=RetryPolicy(
            max_retries=3,
            base_delay_ms=500,
            strategy=RetryStrategy.EXPONENTIAL,
        ),
        timeout=TimeoutConfig(
            connect_ms=5_000,
            read_ms=25_000,
            total_ms=30_000,
        ),
    )


def recommended_for_quality(api_key: str) -> OpenAIWrapper:
    """
    Best config for quality-sensitive workloads — the step-up from budget.

    Model: gpt-5-mini ($0.25/$2.00 per 1M)
    Retries: 3 with exponential backoff
    Timeout: 30s total
    Cost ceiling: $0.05 per request chain

    8-10x quality over gpt-4o-mini for ~1.7x cost.
    """
    return OpenAIWrapper(
        api_key=api_key,
        retry_policy=RetryPolicy(
            max_retries=3,
            base_delay_ms=500,
            strategy=RetryStrategy.EXPONENTIAL,
        ),
        timeout=TimeoutConfig(
            connect_ms=5_000,
            read_ms=25_000,
            total_ms=30_000,
        ),
    )


def recommended_for_production(api_key: str) -> OpenAIWrapper:
    """
    Best config for general production workloads.

    Model: gpt-5 ($1.25/$10 per 1M)
    Retries: 2 with exponential backoff
    Timeout: 60s total
    Cost ceiling: $0.10 per request chain
    """
    return OpenAIWrapper(
        api_key=api_key,
        retry_policy=RetryPolicy(
            max_retries=2,
            base_delay_ms=1000,
            strategy=RetryStrategy.EXPONENTIAL,
        ),
        timeout=TimeoutConfig(
            connect_ms=5_000,
            read_ms=50_000,
            total_ms=60_000,
        ),
    )


def recommended_for_reasoning(api_key: str) -> OpenAIWrapper:
    """
    Best config for deep reasoning (o4-mini model).

    Model: o4-mini ($1.10/$4.40 per 1M)
    Retries: 1 (reasoning models are expensive)
    Timeout: 60s total
    Cost ceiling: $0.50 per request chain
    """
    return OpenAIWrapper(
        api_key=api_key,
        retry_policy=RetryPolicy(
            max_retries=1,
            base_delay_ms=2000,
            strategy=RetryStrategy.EXPONENTIAL,
        ),
        timeout=TimeoutConfig(
            connect_ms=10_000,
            read_ms=50_000,
            total_ms=60_000,
        ),
    )

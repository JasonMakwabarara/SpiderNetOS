"""
SpiderNet OS — Inference Plane Configuration
"""
import os

REDIS_URL = os.getenv("REDIS_URL", "redis://localhost:6379/1")
OLLAMA_URL = os.getenv("OLLAMA_URL", "http://localhost:11434")
# Set OLLAMA_ENABLED=0 on hosts without a local Ollama so zero-cost local
# models are excluded from routing instead of burning a retry per request.
OLLAMA_ENABLED = os.getenv("OLLAMA_ENABLED", "1").lower() not in ("0", "false", "off")
OPENAI_API_KEY = os.getenv("OPENAI_API_KEY", "")
DEFAULT_COST_CEILING = float(os.getenv("DEFAULT_COST_CEILING", "50.0"))
DEFAULT_OLLAMA_MODEL = os.getenv("DEFAULT_OLLAMA_MODEL", "gemma2:2b")

# DeepSeek via BytePlus ModelArk (OpenAI-compatible chat completions).
# Conventions ported from Hannah AI: international host only — never the
# cn-beijing host in production. ModelArk addresses models by model slug or
# endpoint ID; DEEPSEEK_ARK_MODEL carries whichever your Ark account uses.
DEEPSEEK_API_KEY = os.getenv("DEEPSEEK_API_KEY", "")
DEEPSEEK_BASE_URL = os.getenv("DEEPSEEK_BASE_URL", "https://ark.ap-southeast.bytepluses.com/api/v3")
DEEPSEEK_ARK_MODEL = os.getenv("DEEPSEEK_ARK_MODEL", "deepseek-v3-250324")

# Embedding config
EMBEDDING_MODEL = os.getenv("EMBEDDING_MODEL", "nomic-embed-text")
EMBEDDING_DIM = int(os.getenv("EMBEDDING_DIM", "384"))

MODEL_COST_TABLE = {
    # GPT-5 family (2026 — current)
    "gpt-5-nano": {"cost_per_1k_tokens": 0.00005, "latency_avg_ms": 400, "provider": "openai"},
    "gpt-5-mini": {"cost_per_1k_tokens": 0.00025, "latency_avg_ms": 600, "provider": "openai"},
    "gpt-5": {"cost_per_1k_tokens": 0.00125, "latency_avg_ms": 1200, "provider": "openai"},
    "gpt-5.4": {"cost_per_1k_tokens": 0.0025, "latency_avg_ms": 1500, "provider": "openai"},
    # Reasoning models
    "o4-mini": {"cost_per_1k_tokens": 0.0011, "latency_avg_ms": 3000, "provider": "openai"},
    "o3-mini": {"cost_per_1k_tokens": 0.0011, "latency_avg_ms": 5000, "provider": "openai"},
    "o3": {"cost_per_1k_tokens": 0.002, "latency_avg_ms": 10000, "provider": "openai"},
    # GPT-4 family (legacy — prefer GPT-5 equivalents)
    "gpt-4o-mini": {"cost_per_1k_tokens": 0.00015, "latency_avg_ms": 800, "provider": "openai"},
    "gpt-4.1-mini": {"cost_per_1k_tokens": 0.0004, "latency_avg_ms": 2000, "provider": "openai"},
    # Gemma family (local Ollama — zero cost)
    "gemma4": {"cost_per_1k_tokens": 0.0, "latency_avg_ms": 3000, "provider": "ollama"},
    "gemma2:2b": {"cost_per_1k_tokens": 0.0, "latency_avg_ms": 2000, "provider": "ollama"},
    "gemma3:9b": {"cost_per_1k_tokens": 0.0, "latency_avg_ms": 800, "provider": "ollama"},
    "gemma3:27b": {"cost_per_1k_tokens": 0.0, "latency_avg_ms": 2500, "provider": "ollama"},
    # Qwen family (local Ollama)
    "qwen3": {"cost_per_1k_tokens": 0.0, "latency_avg_ms": 2000, "provider": "ollama"},
    # MedGemma (medical domain — local Ollama)
    "medgemma": {"cost_per_1k_tokens": 0.0, "latency_avg_ms": 2800, "provider": "ollama"},
    # DeepSeek via BytePlus ModelArk (primary hosted provider)
    "deepseek-v3": {"cost_per_1k_tokens": 0.0007, "latency_avg_ms": 1500, "provider": "modelark"},
    "deepseek-r1": {"cost_per_1k_tokens": 0.0022, "latency_avg_ms": 6000, "provider": "modelark"},
}

# Table key → Ark model/endpoint ID (ModelArk addresses models by its own
# IDs, mirroring Hannah's deepseek_endpoint_map). deepseek-v3 follows
# DEEPSEEK_ARK_MODEL; override the reasoner via DEEPSEEK_ARK_MODEL_R1.
MODELARK_MODEL_MAP = {
    "deepseek-v3": DEEPSEEK_ARK_MODEL,
    "deepseek-r1": os.getenv("DEEPSEEK_ARK_MODEL_R1", "deepseek-r1-250528"),
}

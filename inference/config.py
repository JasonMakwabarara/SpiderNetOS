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
DEEPSEEK_ARK_MODEL_FLASH = os.getenv("DEEPSEEK_ARK_MODEL_FLASH", "deepseek-v4-flash")
DEEPSEEK_ARK_MODEL_PRO = os.getenv("DEEPSEEK_ARK_MODEL_PRO", "deepseek-v4-pro")

# Provider preference for routing (lower = preferred). DeepSeek via BytePlus
# ModelArk is the primary hosted provider; a local Ollama model (zero cost)
# is preferred when one is enabled; OpenAI is the fallback. rank_models()
# applies this BEFORE the cost sort, so a cheaper OpenAI model never displaces
# ModelArk just by being cheaper (which is what happened before 2026-09-16).
PROVIDER_PRIORITY = {"ollama": 0, "modelark": 1, "openai": 2}
PROVIDER_PRIORITY_DEFAULT = 9

# Embedding config
EMBEDDING_MODEL = os.getenv("EMBEDDING_MODEL", "nomic-embed-text")
EMBEDDING_DIM = int(os.getenv("EMBEDDING_DIM", "384"))

MODEL_COST_TABLE = {
    # GPT-5 family (2026 — current). All multimodal => "vision" capability.
    "gpt-5-nano": {"cost_per_1k_tokens": 0.00005, "latency_avg_ms": 400, "provider": "openai", "capabilities": ["vision"]},
    "gpt-5-mini": {"cost_per_1k_tokens": 0.00025, "latency_avg_ms": 600, "provider": "openai", "capabilities": ["vision"]},
    "gpt-5": {"cost_per_1k_tokens": 0.00125, "latency_avg_ms": 1200, "provider": "openai", "capabilities": ["vision"]},
    "gpt-5.4": {"cost_per_1k_tokens": 0.0025, "latency_avg_ms": 1500, "provider": "openai", "capabilities": ["vision"]},
    # Reasoning models
    "o4-mini": {"cost_per_1k_tokens": 0.0011, "latency_avg_ms": 3000, "provider": "openai"},
    "o3-mini": {"cost_per_1k_tokens": 0.0011, "latency_avg_ms": 5000, "provider": "openai"},
    "o3": {"cost_per_1k_tokens": 0.002, "latency_avg_ms": 10000, "provider": "openai"},
    # GPT-4 family (legacy — prefer GPT-5 equivalents). Multimodal.
    "gpt-4o-mini": {"cost_per_1k_tokens": 0.00015, "latency_avg_ms": 800, "provider": "openai", "capabilities": ["vision"]},
    "gpt-4.1-mini": {"cost_per_1k_tokens": 0.0004, "latency_avg_ms": 2000, "provider": "openai", "capabilities": ["vision"]},
    # Gemma family (local Ollama — zero cost)
    "gemma4": {"cost_per_1k_tokens": 0.0, "latency_avg_ms": 3000, "provider": "ollama"},
    "gemma2:2b": {"cost_per_1k_tokens": 0.0, "latency_avg_ms": 2000, "provider": "ollama"},
    "gemma3:9b": {"cost_per_1k_tokens": 0.0, "latency_avg_ms": 800, "provider": "ollama"},
    "gemma3:27b": {"cost_per_1k_tokens": 0.0, "latency_avg_ms": 2500, "provider": "ollama"},
    # Qwen family (local Ollama)
    "qwen3": {"cost_per_1k_tokens": 0.0, "latency_avg_ms": 2000, "provider": "ollama"},
    # MedGemma (medical domain — local Ollama)
    "medgemma": {"cost_per_1k_tokens": 0.0, "latency_avg_ms": 2800, "provider": "ollama"},
    # Llama 3.2 Vision (local Ollama — zero-cost vision for document extraction)
    "llama3.2-vision": {"cost_per_1k_tokens": 0.0, "latency_avg_ms": 4000, "provider": "ollama", "capabilities": ["vision"]},
    # DeepSeek V4 via BytePlus ModelArk (primary hosted provider):
    # flash = fast/cheap default, pro = heavier reasoning fallback.
    "deepseek-v4-flash": {"cost_per_1k_tokens": 0.0004, "latency_avg_ms": 900, "provider": "modelark"},
    "deepseek-v4-pro": {"cost_per_1k_tokens": 0.0016, "latency_avg_ms": 2500, "provider": "modelark"},
}


def _cheapest_vision_model() -> str:
    """Cheapest vision-capable model in the cost table (cost, then latency)."""
    vision = [
        (name, info) for name, info in MODEL_COST_TABLE.items()
        if "vision" in info.get("capabilities", [])
    ]
    if not vision:
        return "llama3.2-vision"
    vision.sort(key=lambda x: (x[1]["cost_per_1k_tokens"], x[1]["latency_avg_ms"]))
    return vision[0][0]


VISION_DEFAULT_MODEL = os.getenv("VISION_DEFAULT_MODEL", _cheapest_vision_model())

# Table key → Ark model/endpoint ID (ModelArk addresses models by its own
# IDs, mirroring Hannah's deepseek_endpoint_map). Per-request overrides are
# possible via InferenceRequest.provider_model_id, which the Laravel admin
# dashboard controls through the inference.* feature flags.
MODELARK_MODEL_MAP = {
    "deepseek-v4-flash": DEEPSEEK_ARK_MODEL_FLASH,
    "deepseek-v4-pro": DEEPSEEK_ARK_MODEL_PRO,
}

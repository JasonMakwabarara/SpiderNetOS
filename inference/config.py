"""
SpiderNet OS — Inference Plane Configuration
"""
import os

REDIS_URL = os.getenv("REDIS_URL", "redis://localhost:6379/1")
OLLAMA_URL = os.getenv("OLLAMA_URL", "http://localhost:11434")
OPENAI_API_KEY = os.getenv("OPENAI_API_KEY", "")
DEFAULT_COST_CEILING = float(os.getenv("DEFAULT_COST_CEILING", "50.0"))

# Embedding config
EMBEDDING_MODEL = os.getenv("EMBEDDING_MODEL", "nomic-embed-text")
EMBEDDING_DIM = int(os.getenv("EMBEDDING_DIM", "384"))

MODEL_COST_TABLE = {
    # Gemma family (local Ollama — zero cost)
    "gemma4": {"cost_per_1k_tokens": 0.0, "latency_avg_ms": 3000, "provider": "ollama"},
    "gemma3:9b": {"cost_per_1k_tokens": 0.0, "latency_avg_ms": 800, "provider": "ollama"},
    "gemma3:27b": {"cost_per_1k_tokens": 0.0, "latency_avg_ms": 2500, "provider": "ollama"},
    # Qwen family (local Ollama)
    "qwen3": {"cost_per_1k_tokens": 0.0, "latency_avg_ms": 2000, "provider": "ollama"},
    # MedGemma (medical domain — local Ollama)
    "medgemma": {"cost_per_1k_tokens": 0.0, "latency_avg_ms": 2800, "provider": "ollama"},
    # OpenAI (paid API)
    "gpt-4o-mini": {"cost_per_1k_tokens": 0.00015, "latency_avg_ms": 1200, "provider": "openai"},
    "gpt-4o": {"cost_per_1k_tokens": 0.005, "latency_avg_ms": 2000, "provider": "openai"},
}

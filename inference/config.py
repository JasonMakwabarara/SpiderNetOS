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
    "gemma3:9b": {"cost_per_1k_tokens": 0.0, "latency_avg_ms": 800, "provider": "ollama"},
    "gemma3:27b": {"cost_per_1k_tokens": 0.0, "latency_avg_ms": 2500, "provider": "ollama"},
    # Qwen family (local Ollama)
    "qwen3": {"cost_per_1k_tokens": 0.0, "latency_avg_ms": 2000, "provider": "ollama"},
    # MedGemma (medical domain — local Ollama)
    "medgemma": {"cost_per_1k_tokens": 0.0, "latency_avg_ms": 2800, "provider": "ollama"},
}

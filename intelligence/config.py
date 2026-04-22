"""
SpiderNet OS — Intelligence Worker Configuration
All values sourced from environment variables.
"""
import os

DATABASE_URL = os.getenv("DATABASE_URL", "postgresql://spidernet:spidernet@db:5432/spidernet")
REDIS_URL = os.getenv("REDIS_URL", "redis://redis:6379/0")
INFERENCE_URL = os.getenv("INFERENCE_URL", "http://inference:9000")
LARAVEL_API_URL = os.getenv("LARAVEL_API_URL", "http://api:8000")
OPENAI_API_KEY = os.getenv("OPENAI_API_KEY", "")

# Embedding configuration
EMBEDDING_MODEL = os.getenv("EMBEDDING_MODEL", "nomic-embed-text")
EMBEDDING_DIM = int(os.getenv("EMBEDDING_DIM", "384"))

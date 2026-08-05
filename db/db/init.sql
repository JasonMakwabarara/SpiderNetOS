-- SpiderNet OS: PostgreSQL initialization
-- Enable pgvector extension for memory graph embeddings
CREATE EXTENSION IF NOT EXISTS vector;

-- Enable pg_trgm for fuzzy text search
CREATE EXTENSION IF NOT EXISTS pg_trgm;

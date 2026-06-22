-- Minimal V2 intelligence tables for semantic-gateway evaluate path
CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE IF NOT EXISTS crm_cached_patterns (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id UUID NOT NULL,
    playbook_id UUID NOT NULL,
    embedding vector(384),
    confidence_score DOUBLE PRECISION DEFAULT 0
);

CREATE TABLE IF NOT EXISTS atlas_recommendations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id UUID NOT NULL,
    title TEXT,
    impact_score INTEGER,
    expected_revenue_gain INTEGER,
    status TEXT DEFAULT 'pending',
    justification TEXT,
    proposed_dag JSONB,
    risk_score DOUBLE PRECISION,
    estimated_time_saved DOUBLE PRECISION,
    simulation_result JSONB,
    created_at TIMESTAMP DEFAULT NOW()
);

ALTER TABLE atlas_recommendations ADD COLUMN IF NOT EXISTS simulation_result JSONB;

CREATE TABLE IF NOT EXISTS workspace_autonomy_settings (
    workspace_id UUID PRIMARY KEY,
    autonomy_level INTEGER DEFAULT 1,
    auto_execute_threshold DOUBLE PRECISION DEFAULT 0.85,
    rollback_threshold DOUBLE PRECISION DEFAULT 0.05,
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS atlas_memory_events (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id UUID NOT NULL,
    event_type TEXT,
    outcome TEXT,
    confidence DOUBLE PRECISION,
    metadata JSONB,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS atlas_policy_updates (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    recommendation_id UUID,
    reward_score DOUBLE PRECISION,
    metric_applied TEXT,
    processed_at TIMESTAMP DEFAULT NOW()
);

--
-- SpiderNet OS - CPL (Control Plane Learning) Schema
-- Production-grade PostgreSQL schema for RL system
--

-- System State Snapshots (for CPL state encoding)
CREATE TABLE system_state_snapshot (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id VARCHAR(255) NOT NULL,
    timestamp TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    -- Economic metrics
    total_cost FLOAT DEFAULT 0.0,
    total_revenue FLOAT DEFAULT 0.0,
    system_load FLOAT DEFAULT 0.0,
    instability_score FLOAT DEFAULT 0.0,
    opportunity_pressure FLOAT DEFAULT 0.0,
    
    -- GPU metrics
    gpu_0_util FLOAT DEFAULT 0.0,
    gpu_1_util FLOAT DEFAULT 0.0,
    gpu_0_memory FLOAT DEFAULT 0.0,
    gpu_1_memory FLOAT DEFAULT 0.0,
    
    -- Queue metrics
    queue_depth_gpu_0 INT DEFAULT 0,
    queue_depth_gpu_1 INT DEFAULT 0,
    queue_depth_cpu INT DEFAULT 0,
    queue_depth_network INT DEFAULT 0,
    
    -- Vector embedding for similarity search
    state_embedding VECTOR(384),
    
    -- Raw JSON for extensibility
    raw_metrics JSONB
);

CREATE INDEX idx_system_state_tenant_timestamp ON system_state_snapshot(tenant_id, timestamp DESC);
CREATE INDEX idx_system_state_embedding ON system_state_snapshot USING ivfflat (state_embedding vector_cosine_ops);

-- Agent Registry
CREATE TABLE cpl_agents (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    agent_id VARCHAR(255) UNIQUE NOT NULL,
    agent_type VARCHAR(100) NOT NULL,
    tenant_id VARCHAR(255) NOT NULL,
    
    status VARCHAR(50) DEFAULT 'idle' CHECK (status IN ('active', 'idle', 'failed', 'terminated')),
    
    -- Performance metrics
    compute_cost FLOAT DEFAULT 0.0,
    success_rate FLOAT DEFAULT 0.0,
    avg_latency FLOAT DEFAULT 0.0,
    tasks_completed INT DEFAULT 0,
    failure_count INT DEFAULT 0,
    
    -- Resource allocation
    gpu_allocated VARCHAR(10), -- 'gpu_0', 'gpu_1', 'cpu'
    memory_allocated_mb INT DEFAULT 0,
    
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    last_active_at TIMESTAMP WITH TIME ZONE,
    terminated_at TIMESTAMP WITH TIME ZONE
);

CREATE INDEX idx_cpl_agents_tenant_status ON cpl_agents(tenant_id, status);
CREATE INDEX idx_cpl_agents_type ON cpl_agents(agent_type);

-- Agent Telemetry Stream
CREATE TABLE cpl_agent_metrics (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    agent_id UUID REFERENCES cpl_agents(id),
    tenant_id VARCHAR(255) NOT NULL,
    
    timestamp TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    -- Usage metrics
    tokens_used FLOAT DEFAULT 0.0,
    tokens_generated FLOAT DEFAULT 0.0,
    tasks_completed INT DEFAULT 0,
    failure_count INT DEFAULT 0,
    
    -- Performance metrics
    latency_ms FLOAT DEFAULT 0.0,
    throughput FLOAT DEFAULT 0.0,
    
    -- RL reward
    reward FLOAT DEFAULT 0.0,
    reward_decomposition JSONB, -- breakdown by objective
    
    -- Cost tracking
    cost_incurred FLOAT DEFAULT 0.0,
    
    -- State snapshot reference
    state_snapshot_id UUID REFERENCES system_state_snapshot(id)
);

CREATE INDEX idx_agent_metrics_agent_time ON cpl_agent_metrics(agent_id, timestamp DESC);
CREATE INDEX idx_agent_metrics_tenant_time ON cpl_agent_metrics(tenant_id, timestamp DESC);

-- CPL Action Log (for training and audit)
CREATE TABLE cpl_actions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id VARCHAR(255) NOT NULL,
    
    timestamp TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    action_type VARCHAR(100) NOT NULL CHECK (action_type IN (
        'spawn_agent', 'kill_agent', 'route_task', 'allocate_budget', 
        'adjust_temperature', 'prioritize_workflow', 'no_op'
    )),
    
    target_id VARCHAR(255), -- agent_id, workflow_id, etc.
    
    -- Action parameters
    payload JSONB,
    
    -- Outcome
    outcome_reward FLOAT,
    outcome_cost FLOAT,
    outcome_success BOOLEAN,
    
    -- Policy info at time of action
    policy_version VARCHAR(50),
    entropy FLOAT,
    log_prob FLOAT,
    
    -- State snapshot for replay
    state_snapshot_id UUID REFERENCES system_state_snapshot(id),
    
    -- Correlation with execution
    execution_id UUID
);

CREATE INDEX idx_cpl_actions_tenant_time ON cpl_actions(tenant_id, timestamp DESC);
CREATE INDEX idx_cpl_actions_type ON cpl_actions(action_type);

-- Replay Buffer (for PPO training)
CREATE TABLE cpl_replay_buffer (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id VARCHAR(255) NOT NULL,
    
    -- State encoding
    state VECTOR(280), -- 280-dim state vector
    state_metadata JSONB,
    
    -- Action taken
    action INT NOT NULL,
    action_log_prob FLOAT,
    
    -- Outcome
    reward FLOAT NOT NULL,
    reward_vector JSONB, -- 6-dim multi-objective reward
    
    -- Next state
    next_state VECTOR(280),
    next_state_metadata JSONB,
    
    -- Terminal flag
    done BOOLEAN DEFAULT FALSE,
    
    -- Cost for Lagrangian constraint
    cost FLOAT DEFAULT 0.0,
    
    -- Priority for PER (Prioritized Experience Replay)
    priority FLOAT DEFAULT 1.0,
    
    -- TD error (updated after training)
    td_error FLOAT,
    
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    -- Soft delete for buffer management
    is_active BOOLEAN DEFAULT TRUE
);

CREATE INDEX idx_replay_buffer_tenant_priority ON cpl_replay_buffer(tenant_id, priority DESC);
CREATE INDEX idx_replay_buffer_active ON cpl_replay_buffer(tenant_id, is_active) WHERE is_active = TRUE;
CREATE INDEX idx_replay_buffer_state ON cpl_replay_buffer USING ivfflat (state vector_cosine_ops);

-- Policy Checkpoints
CREATE TABLE cpl_policy_checkpoints (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id VARCHAR(255) NOT NULL,
    
    version VARCHAR(50) NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    -- Model storage reference (file path or blob)
    model_path TEXT,
    model_size_bytes BIGINT,
    
    -- Training metrics at checkpoint
    avg_reward FLOAT,
    avg_cost FLOAT,
    policy_loss FLOAT,
    value_loss FLOAT,
    entropy FLOAT,
    lagrangian_lambda FLOAT,
    
    -- Pareto frontier snapshot
    pareto_frontier JSONB,
    
    -- Validation results
    validation_metrics JSONB,
    
    -- Is this the active policy?
    is_active BOOLEAN DEFAULT FALSE,
    
    -- Rollback info
    parent_version VARCHAR(50),
    rollback_reason TEXT
);

CREATE INDEX idx_policy_checkpoints_tenant_version ON cpl_policy_checkpoints(tenant_id, version);
CREATE INDEX idx_policy_checkpoints_active ON cpl_policy_checkpoints(tenant_id, is_active) WHERE is_active = TRUE;

-- Lagrangian Constraint History
CREATE TABLE cpl_lagrangian_history (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id VARCHAR(255) NOT NULL,
    
    timestamp TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    lambda_cost FLOAT NOT NULL,
    lambda_risk FLOAT DEFAULT 0.0,
    lambda_latency FLOAT DEFAULT 0.0,
    
    -- Violations that triggered updates
    cost_violation FLOAT DEFAULT 0.0,
    risk_violation FLOAT DEFAULT 0.0,
    latency_violation FLOAT DEFAULT 0.0,
    
    -- Budget status
    budget_limit FLOAT,
    budget_used FLOAT,
    budget_remaining FLOAT
);

CREATE INDEX idx_lagrangian_tenant_time ON cpl_lagrangian_history(tenant_id, timestamp DESC);

-- Cost Events (financial audit trail)
CREATE TABLE cost_events (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id VARCHAR(255) NOT NULL,
    
    recorded_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    cost_type VARCHAR(100) NOT NULL CHECK (cost_type IN (
        'gpu_time', 'tokens', 'storage', 'network', 'api_call', 'execution'
    )),
    
    amount FLOAT NOT NULL,
    currency VARCHAR(3) DEFAULT 'USD',
    
    -- Detailed breakdown
    metadata JSONB,
    
    -- Source
    service VARCHAR(100), -- 'inference', 'cpl', 'metaplanner', etc.
    action_id UUID REFERENCES cpl_actions(id),
    
    -- For verification
    verified BOOLEAN DEFAULT FALSE,
    verification_hash VARCHAR(64)
);

CREATE INDEX idx_cost_events_tenant_time ON cost_events(tenant_id, recorded_at DESC);
CREATE INDEX idx_cost_events_type ON cost_events(cost_type);

-- Daily Cost Aggregation (for fast dashboard queries)
CREATE TABLE cost_daily_aggregation (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id VARCHAR(255) NOT NULL,
    date DATE NOT NULL,
    
    total_cost FLOAT DEFAULT 0.0,
    total_gpu_cost FLOAT DEFAULT 0.0,
    total_token_cost FLOAT DEFAULT 0.0,
    total_executions INT DEFAULT 0,
    
    -- By service
    cost_by_service JSONB,
    
    -- Budget status
    budget_limit FLOAT,
    budget_utilization FLOAT,
    
    UNIQUE(tenant_id, date)
);

CREATE INDEX idx_cost_daily_tenant_date ON cost_daily_aggregation(tenant_id, date DESC);

-- Training Metrics (for monitoring convergence)
CREATE TABLE cpl_training_metrics (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id VARCHAR(255) NOT NULL,
    
    timestamp TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    -- Episode stats
    episode_number BIGINT,
    steps_in_episode INT,
    
    -- Losses
    policy_loss FLOAT,
    value_loss FLOAT,
    cost_loss FLOAT,
    total_loss FLOAT,
    
    -- Rewards
    episode_reward FLOAT,
    avg_reward_last_100 FLOAT,
    
    -- PPO specific
    clip_fraction FLOAT,
    approx_kl FLOAT,
    entropy FLOAT,
    
    -- Constraints
    cost_violation FLOAT,
    lagrangian_penalty FLOAT,
    
    -- Performance
    training_time_ms INT,
    gpu_utilization FLOAT
);

CREATE INDEX idx_training_metrics_tenant_time ON cpl_training_metrics(tenant_id, timestamp DESC);
CREATE INDEX idx_training_metrics_episode ON cpl_training_metrics(tenant_id, episode_number);

-- Buffer cleanup job (run periodically)
CREATE OR REPLACE FUNCTION cleanup_old_replay_buffer()
RETURNS void AS $$
BEGIN
    -- Mark old low-priority transitions as inactive
    UPDATE cpl_replay_buffer
    SET is_active = FALSE
    WHERE created_at < NOW() - INTERVAL '7 days'
      AND priority < 0.1
      AND is_active = TRUE;
    
    -- Hard delete very old inactive records
    DELETE FROM cpl_replay_buffer
    WHERE created_at < NOW() - INTERVAL '30 days'
      AND is_active = FALSE;
END;
$$ LANGUAGE plpgsql;

-- Aggregation job (run daily)
CREATE OR REPLACE FUNCTION aggregate_daily_costs(target_date DATE)
RETURNS void AS $$
BEGIN
    INSERT INTO cost_daily_aggregation (
        tenant_id, date, total_cost, total_gpu_cost, total_token_cost, 
        total_executions, cost_by_service
    )
    SELECT 
        tenant_id,
        target_date,
        SUM(amount) as total_cost,
        SUM(CASE WHEN cost_type = 'gpu_time' THEN amount ELSE 0 END) as total_gpu_cost,
        SUM(CASE WHEN cost_type = 'tokens' THEN amount ELSE 0 END) as total_token_cost,
        COUNT(*) as total_executions,
        jsonb_object_agg(cost_type, SUM(amount)) as cost_by_service
    FROM cost_events
    WHERE DATE(recorded_at) = target_date
    GROUP BY tenant_id
    ON CONFLICT (tenant_id, date) DO UPDATE SET
        total_cost = EXCLUDED.total_cost,
        total_gpu_cost = EXCLUDED.total_gpu_cost,
        total_token_cost = EXCLUDED.total_token_cost,
        total_executions = EXCLUDED.total_executions,
        cost_by_service = EXCLUDED.cost_by_service;
END;
$$ LANGUAGE plpgsql;

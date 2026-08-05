--
-- SpiderNet OS - MetaPlanner Schema
-- Multi-objective RL + Pareto optimization
--

-- Plan Storage
CREATE TABLE plans (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id VARCHAR(255) NOT NULL,
    plan_id VARCHAR(255) UNIQUE NOT NULL,
    
    name VARCHAR(255),
    description TEXT,
    
    -- Context at creation
    context JSONB,
    context_embedding VECTOR(384),
    
    -- Objective scores (multi-objective vector)
    profit_score FLOAT DEFAULT 0.0,
    feasibility_score FLOAT DEFAULT 0.0,
    risk_score FLOAT DEFAULT 0.0,
    novelty_score FLOAT DEFAULT 0.0,
    strategic_score FLOAT DEFAULT 0.0,
    cost_score FLOAT DEFAULT 0.0,
    
    -- Pareto ranking
    pareto_rank INT,
    is_pareto_optimal BOOLEAN DEFAULT FALSE,
    
    -- Scoring metadata
    scoring_version VARCHAR(50),
    adaptive_weights JSONB, -- w = softmax(W*context) at scoring time
    
    -- Lagrangian penalties applied
    lagrangian_cost_penalty FLOAT DEFAULT 0.0,
    lagrangian_risk_penalty FLOAT DEFAULT 0.0,
    
    -- Final score components
    pareto_utility FLOAT DEFAULT 0.0,
    expected_value FLOAT DEFAULT 0.0,
    exploration_bonus FLOAT DEFAULT 0.0,
    final_score FLOAT DEFAULT 0.0,
    
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    expires_at TIMESTAMP WITH TIME ZONE -- Plans can expire
);

CREATE INDEX idx_plans_tenant_pareto ON plans(tenant_id, pareto_rank) WHERE is_pareto_optimal = TRUE;
CREATE INDEX idx_plans_tenant_score ON plans(tenant_id, final_score DESC);
CREATE INDEX idx_plans_context_embedding ON plans USING ivfflat (context_embedding vector_cosine_ops);

-- Plan Objectives (normalized)
CREATE TABLE plan_objectives (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    plan_id UUID REFERENCES plans(id) ON DELETE CASCADE,
    
    objective_type VARCHAR(50) NOT NULL CHECK (objective_type IN (
        'profit', 'feasibility', 'risk', 'novelty', 'strategic', 'cost'
    )),
    
    raw_value FLOAT NOT NULL,
    normalized_value FLOAT NOT NULL,
    weight_at_scoring FLOAT NOT NULL,
    
    -- Context that influenced weight
    context_factor VARCHAR(100) -- e.g., 'market_state', 'system_load'
);

CREATE INDEX idx_plan_objectives_plan ON plan_objectives(plan_id);
CREATE INDEX idx_plan_objectives_type ON plan_objectives(objective_type);

-- Plan Executions
CREATE TABLE plan_executions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id VARCHAR(255) NOT NULL,
    plan_id UUID REFERENCES plans(id),
    
    status VARCHAR(50) NOT NULL CHECK (status IN (
        'pending', 'queued', 'running', 'completed', 'failed', 'cancelled'
    )),
    
    -- Timing
    queued_at TIMESTAMP WITH TIME ZONE,
    started_at TIMESTAMP WITH TIME ZONE,
    completed_at TIMESTAMP WITH TIME ZONE,
    
    -- Outcome
    outcome VARCHAR(50) CHECK (outcome IN ('success', 'partial', 'failure')),
    
    -- Economic results
    actual_profit FLOAT,
    actual_cost FLOAT,
    actual_revenue FLOAT,
    
    -- Deviation from plan estimates
    profit_deviation FLOAT, -- (actual - estimated) / estimated
    cost_deviation FLOAT,
    
    -- Reward received by CPL
    reward FLOAT,
    reward_decomposition JSONB,
    
    -- Execution details
    agents_involved JSONB, -- List of agent IDs
    execution_path JSONB,  -- Step-by-step trace
    
    -- Error info (if failed)
    error_message TEXT,
    error_stack TEXT,
    
    -- Rollback info
    rollback_plan_id UUID REFERENCES plans(id),
    rollback_reason TEXT
);

CREATE INDEX idx_plan_executions_tenant_status ON plan_executions(tenant_id, status);
CREATE INDEX idx_plan_executions_plan ON plan_executions(plan_id);
CREATE INDEX idx_plan_executions_time ON plan_executions(queued_at DESC);

-- Pareto Frontier Snapshots
CREATE TABLE pareto_frontier_snapshots (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id VARCHAR(255) NOT NULL,
    
    snapshot_time TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    -- Frontier composition
    frontier_size INT,
    dominant_plan_ids JSONB, -- Array of plan IDs on frontier
    
    -- Coverage metrics
    objective_coverage JSONB, -- How well each objective is represented
    hypervolume FLOAT, -- Quality metric for frontier
    
    -- Context at snapshot
    system_state_snapshot_id UUID,
    
    -- Historical tracking
    plans_added_since_last INT,
    plans_removed_since_last INT
);

CREATE INDEX idx_pareto_snapshots_tenant_time ON pareto_frontier_snapshots(tenant_id, snapshot_time DESC);

-- Plan Dependencies (for complex workflows)
CREATE TABLE plan_dependencies (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    parent_plan_id UUID REFERENCES plans(id) ON DELETE CASCADE,
    child_plan_id UUID REFERENCES plans(id) ON DELETE CASCADE,
    
    dependency_type VARCHAR(50) DEFAULT 'must_complete' CHECK (dependency_type IN (
        'must_complete', 'must_succeed', 'can_parallel', 'fallback_to'
    )),
    
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    UNIQUE(parent_plan_id, child_plan_id)
);

CREATE INDEX idx_plan_dependencies_parent ON plan_dependencies(parent_plan_id);
CREATE INDEX idx_plan_dependencies_child ON plan_dependencies(child_plan_id);

-- Plan Selection Log (for audit and learning)
CREATE TABLE plan_selection_log (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id VARCHAR(255) NOT NULL,
    
    selection_time TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    selected_plan_id UUID REFERENCES plans(id),
    
    -- Alternative plans considered
    candidates_count INT,
    candidate_plan_ids JSONB,
    
    -- Scoring context
    scoring_context JSONB,
    adaptive_weights JSONB,
    
    -- Why this plan won
    selection_reason TEXT,
    score_advantage FLOAT, -- How much better than runner-up
    
    -- CPL recommendation (if any)
    cpl_recommended BOOLEAN,
    cpl_confidence FLOAT,
    cpl_followed BOOLEAN,
    
    -- Cost Governor check
    budget_approved BOOLEAN,
    budget_status JSONB
);

CREATE INDEX idx_selection_log_tenant_time ON plan_selection_log(tenant_id, selection_time DESC);
CREATE INDEX idx_selection_log_plan ON plan_selection_log(selected_plan_id);

-- Multi-objective weight history (for analysis)
CREATE TABLE mo_weight_history (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id VARCHAR(255) NOT NULL,
    
    timestamp TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    -- Context that produced weights
    context_embedding VECTOR(384),
    context_factors JSONB,
    
    -- Resulting weights
    w_profit FLOAT,
    w_feasibility FLOAT,
    w_risk FLOAT,
    w_novelty FLOAT,
    w_strategic FLOAT,
    w_cost FLOAT,
    
    -- Performance with these weights
    avg_reward_after FLOAT,
    plans_selected INT
);

CREATE INDEX idx_mo_weights_tenant_time ON mo_weight_history(tenant_id, timestamp DESC);

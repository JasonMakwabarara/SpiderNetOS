--
-- SpiderNet OS - Opportunity Friction Miner Schema
-- Entrepreneurial opportunity detection and gating
--

-- Opportunities (main table)
CREATE TABLE opportunities (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    opportunity_id VARCHAR(255) UNIQUE NOT NULL,
    tenant_id VARCHAR(255) NOT NULL,
    
    -- Classification
    opportunity_type VARCHAR(100) NOT NULL CHECK (opportunity_type IN (
        'performance_optimization',
        'revenue_leakage_fix',
        'workflow_automation',
        'new_product_opportunity',
        'agent_efficiency_improvement',
        'cost_reduction',
        'risk_mitigation'
    )),
    
    -- Detection source
    detected_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    detected_by VARCHAR(100), -- Sensor that detected it
    
    -- Friction analysis
    friction_sources JSONB, -- ['latency_spike', 'retry_loop', ...]
    friction_score FLOAT NOT NULL,
    friction_details JSONB, -- Per-source breakdown
    
    -- Demand analysis
    demand_proxy FLOAT NOT NULL,
    demand_signals JSONB, -- {query_frequency, recurrence_rate, ...}
    
    -- Opportunity scoring
    opportunity_score FLOAT GENERATED ALWAYS AS (
        friction_score * demand_proxy / NULLIF(cost_to_fix + 1e-8, 0)
    ) STORED,
    
    -- Economic estimates
    estimated_value FLOAT NOT NULL,
    cost_to_fix FLOAT NOT NULL,
    roi_estimate FLOAT GENERATED ALWAYS AS (
        estimated_value / NULLIF(cost_to_fix, 0)
    ) STORED,
    confidence FLOAT NOT NULL CHECK (confidence >= 0 AND confidence <= 1),
    
    -- Solution
    suggested_solution TEXT,
    required_agents JSONB, -- List of agent types needed
    implementation_complexity INT CHECK (implementation_complexity BETWEEN 1 AND 10),
    
    -- Gating status
    gate_status VARCHAR(50) DEFAULT 'pending' CHECK (gate_status IN (
        'pending', 'approved', 'rejected', 'auto_fix', 'executing', 'completed', 'failed'
    )),
    
    -- Gating decision
    gated_at TIMESTAMP WITH TIME ZONE,
    gate_decision_reason VARCHAR(255),
    gate_filtered_reason VARCHAR(255), -- If rejected
    projected_profit FLOAT,
    
    -- Execution
    execution_plan_id UUID,
    executed_at TIMESTAMP WITH TIME ZONE,
    execution_outcome VARCHAR(50),
    actual_roi FLOAT,
    
    -- Semantic embedding for duplicate detection
    content_embedding VECTOR(384),
    
    -- Raw detection data
    raw_detection_data JSONB
);

CREATE INDEX idx_opportunities_tenant_status ON opportunities(tenant_id, gate_status);
CREATE INDEX idx_opportunities_tenant_score ON opportunities(tenant_id, opportunity_score DESC);
CREATE INDEX idx_opportunities_tenant_type ON opportunities(tenant_id, opportunity_type);
CREATE INDEX idx_opportunities_detected_time ON opportunities(tenant_id, detected_at DESC);
CREATE INDEX idx_opportunities_content_embedding ON opportunities USING ivfflat (content_embedding vector_cosine_ops);

-- Friction Events (raw sensor detections)
CREATE TABLE friction_events (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    event_id VARCHAR(255) UNIQUE NOT NULL,
    tenant_id VARCHAR(255) NOT NULL,
    
    -- Event details
    detected_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    event_type VARCHAR(100) NOT NULL CHECK (event_type IN (
        'latency_spike',
        'cost_anomaly',
        'repetition_detected',
        'failure_loop',
        'user_dropoff',
        'queue_backlog',
        'resource_waste'
    )),
    
    -- Severity
    severity FLOAT NOT NULL CHECK (severity >= 0 AND severity <= 1),
    severity_level VARCHAR(20) GENERATED ALWAYS AS (
        CASE 
            WHEN severity >= 0.8 THEN 'critical'
            WHEN severity >= 0.6 THEN 'high'
            WHEN severity >= 0.4 THEN 'medium'
            ELSE 'low'
        END
    ) STORED,
    
    -- Source
    source_entity_type VARCHAR(50), -- 'agent', 'workflow', 'system'
    source_entity_id VARCHAR(255),
    
    -- Metrics at detection
    raw_metrics JSONB,
    baseline_value FLOAT,
    current_value FLOAT,
    deviation_percent FLOAT,
    
    -- Associated opportunity (if clustered)
    opportunity_id UUID REFERENCES opportunities(id),
    
    -- Raw data for analysis
    raw_data JSONB,
    
    -- Resolution
    resolved_at TIMESTAMP WITH TIME ZONE,
    resolution_type VARCHAR(50), -- 'auto_fixed', 'manual_fixed', 'false_positive', 'still_active'
    
    -- Semantic embedding
    event_embedding VECTOR(384)
);

CREATE INDEX idx_friction_events_tenant_time ON friction_events(tenant_id, detected_at DESC);
CREATE INDEX idx_friction_events_tenant_type ON friction_events(tenant_id, event_type);
CREATE INDEX idx_friction_events_opportunity ON friction_events(opportunity_id);
CREATE INDEX idx_friction_events_severity ON friction_events(tenant_id, severity DESC);

-- Demand Signals (continuous tracking)
CREATE TABLE demand_signals (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id VARCHAR(255) NOT NULL,
    
    signal_type VARCHAR(100) NOT NULL, -- 'query_frequency', 'recurrence', 'partial_completion'
    
    -- Time window
    window_start TIMESTAMP WITH TIME ZONE,
    window_end TIMESTAMP WITH TIME ZONE,
    window_duration_hours FLOAT,
    
    -- Signal value
    raw_count INT,
    normalized_score FLOAT,
    
    -- Context
    associated_workflow VARCHAR(255),
    associated_intent VARCHAR(255),
    
    -- Demand proxy calculation
    demand_proxy_component FLOAT,
    
    -- Trend
    trend_direction VARCHAR(20), -- 'increasing', 'decreasing', 'stable'
    trend_slope FLOAT,
    
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX idx_demand_signals_tenant_time ON demand_signals(tenant_id, window_start DESC);
CREATE INDEX idx_demand_signals_tenant_type ON demand_signals(tenant_id, signal_type);

-- Gating Decision Log (for audit and threshold tuning)
CREATE TABLE gating_decisions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id VARCHAR(255) NOT NULL,
    
    opportunity_id UUID REFERENCES opportunities(id),
    
    decision_time TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    decision VARCHAR(20) NOT NULL CHECK (decision IN ('approved', 'rejected')),
    
    -- Gate filters applied
    score_threshold_check BOOLEAN,
    score_threshold_value FLOAT,
    score_threshold_passed BOOLEAN,
    
    roi_ratio_check BOOLEAN,
    roi_ratio_value FLOAT,
    roi_ratio_passed BOOLEAN,
    
    system_load_check BOOLEAN,
    gpu_utilization FLOAT,
    queue_depth INT,
    system_load_passed BOOLEAN,
    
    rate_limit_check BOOLEAN,
    opportunities_in_last_minute INT,
    rate_limit_passed BOOLEAN,
    
    duplicate_check BOOLEAN,
    similarity_score FLOAT,
    similar_opportunity_id UUID,
    duplicate_check_passed BOOLEAN,
    
    -- Final decision
    overall_passed BOOLEAN,
    rejection_reason VARCHAR(255),
    
    -- System context at decision
    system_context_snapshot JSONB,
    
    -- Value impact
    projected_profit_if_approved FLOAT,
    value_saved_if_rejected FLOAT
);

CREATE INDEX idx_gating_decisions_tenant_time ON gating_decisions(tenant_id, decision_time DESC);
CREATE INDEX idx_gating_decisions_opportunity ON gating_decisions(opportunity_id);
CREATE INDEX idx_gating_decisions_outcome ON gating_decisions(tenant_id, decision);

-- Opportunity Clustering (HDBSCAN/GMM results)
CREATE TABLE opportunity_clusters (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id VARCHAR(255) NOT NULL,
    
    cluster_id VARCHAR(255) NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    -- Cluster characteristics
    cluster_size INT,
    avg_friction_score FLOAT,
    avg_demand_proxy FLOAT,
    avg_opportunity_score FLOAT,
    
    -- Centroid
    centroid_embedding VECTOR(384),
    
    -- Member opportunities
    member_opportunity_ids JSONB,
    
    -- Common patterns
    common_friction_sources JSONB,
    common_required_agents JSONB,
    
    -- Status
    status VARCHAR(50) DEFAULT 'active' CHECK (status IN ('active', 'merged', 'split', 'archived')),
    
    -- If merged/split
    parent_cluster_id UUID REFERENCES opportunity_clusters(id),
    merged_into_cluster_id UUID REFERENCES opportunity_clusters(id)
);

CREATE INDEX idx_opportunity_clusters_tenant ON opportunity_clusters(tenant_id, status);

-- ROI Validation (actual vs estimated)
CREATE TABLE opportunity_roi_validation (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    opportunity_id UUID REFERENCES opportunities(id),
    
    validation_time TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    -- Estimates
    estimated_value FLOAT,
    estimated_cost FLOAT,
    estimated_roi FLOAT,
    
    -- Actuals
    actual_value FLOAT,
    actual_cost FLOAT,
    actual_roi FLOAT,
    
    -- Deviation analysis
    value_deviation_percent FLOAT,
    cost_deviation_percent FLOAT,
    roi_deviation_percent FLOAT,
    
    -- Confidence calibration
    was_confidence_accurate BOOLEAN,
    confidence_calibration_score FLOAT
);

CREATE INDEX idx_roi_validation_opportunity ON opportunity_roi_validation(opportunity_id);

-- Gate Threshold History (for auto-tuning)
CREATE TABLE gate_threshold_history (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id VARCHAR(255) NOT NULL,
    
    changed_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    -- Old values
    old_score_threshold FLOAT,
    old_min_roi_ratio FLOAT,
    old_max_gpu_utilization FLOAT,
    old_max_queue_depth INT,
    old_max_opportunities_per_minute INT,
    old_similarity_threshold FLOAT,
    
    -- New values
    new_score_threshold FLOAT,
    new_min_roi_ratio FLOAT,
    new_max_gpu_utilization FLOAT,
    new_max_queue_depth INT,
    new_max_opportunities_per_minute INT,
    new_similarity_threshold FLOAT,
    
    -- Why changed
    change_reason VARCHAR(255),
    target_pass_rate FLOAT,
    actual_pass_rate_before FLOAT,
    
    -- Performance after change
    pass_rate_after_1h FLOAT,
    pass_rate_after_24h FLOAT
);

CREATE INDEX idx_gate_thresholds_tenant_time ON gate_threshold_history(tenant_id, changed_at DESC);

-- Opportunity Action History
CREATE TABLE opportunity_action_history (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    opportunity_id UUID REFERENCES opportunities(id),
    
    action_time TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    action_type VARCHAR(100) NOT NULL CHECK (action_type IN (
        'detected', 'gated', 'approved', 'rejected', 'auto_fixed',
        'assigned_to_plan', 'executed', 'completed', 'failed', 'rollback'
    )),
    
    -- Actor
    action_by VARCHAR(100), -- 'friction_miner', 'economic_gate', 'metaplanner', 'cpl', 'admin'
    
    -- Details
    action_payload JSONB,
    
    -- Result
    result_status VARCHAR(50),
    result_payload JSONB
);

CREATE INDEX idx_opportunity_actions_opportunity ON opportunity_action_history(opportunity_id);
CREATE INDEX idx_opportunity_actions_time ON opportunity_action_history(action_time DESC);

-- View: High-Value Active Opportunities
CREATE VIEW high_value_opportunities AS
SELECT 
    o.*,
    g.projected_profit_if_approved
FROM opportunities o
LEFT JOIN gating_decisions g ON o.id = g.opportunity_id AND g.decision = 'approved'
WHERE o.gate_status IN ('approved', 'executing')
  AND o.opportunity_score > 0.8
  AND o.confidence > 0.7
ORDER BY o.opportunity_score DESC;

-- View: Gating Performance Metrics
CREATE VIEW gating_performance AS
SELECT 
    tenant_id,
    DATE_TRUNC('hour', decision_time) as hour,
    COUNT(*) as total_decisions,
    SUM(CASE WHEN decision = 'approved' THEN 1 ELSE 0 END) as approved_count,
    SUM(CASE WHEN decision = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
    AVG(CASE WHEN decision = 'approved' THEN projected_profit_if_approved END) as avg_projected_profit,
    SUM(CASE WHEN decision = 'rejected' THEN value_saved_if_rejected END) as total_value_saved
FROM gating_decisions
GROUP BY tenant_id, DATE_TRUNC('hour', decision_time);

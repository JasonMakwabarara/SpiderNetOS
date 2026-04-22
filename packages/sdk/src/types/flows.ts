/**
 * SpiderNet OS — Flow & DAG Types
 * Matches flows, flow_executions, dag_nodes, dag_edges tables from migration 000005
 */

// ─── Flow Types ─────────────────────────────────────────────

export interface Flow {
  id: string; // UUID
  tenant_id: string; // UUID
  name: string;
  slug: string;
  description: string | null;
  dag: DagDefinition;
  triggers: FlowTrigger[];
  status: FlowStatus;
  published_at: string | null;
  created_at: string;
  updated_at: string;
}

export type FlowStatus = 'draft' | 'published' | 'archived';

// ─── DAG Definition ─────────────────────────────────────────

export interface DagDefinition {
  nodes: DagNodeDef[];
  edges: DagEdgeDef[];
  metadata?: Record<string, unknown>;
}

export interface DagNodeDef {
  id: string;
  type: DagNodeType;
  label: string;
  agent_id?: string;
  config: DagNodeConfig;
  position: { x: number; y: number };
}

export type DagNodeType =
  | 'trigger'
  | 'agent'
  | 'condition'
  | 'action'
  | 'output'
  | 'approval'
  | 'delay'
  | 'parallel';

export interface DagNodeConfig {
  agent_slug?: string;
  intent?: string;
  parameters?: Record<string, unknown>;
  condition?: string;
  timeout_ms?: number;
  retry_count?: number;
  retry_delay_ms?: number;
  approval_required?: boolean;
  [key: string]: unknown;
}

export interface DagEdgeDef {
  id: string;
  source: string; // Source node ID
  target: string; // Target node ID
  condition?: string;
  label?: string;
}

// ─── Flow Triggers ──────────────────────────────────────────

export interface FlowTrigger {
  type: TriggerType;
  config: Record<string, unknown>;
}

export type TriggerType =
  | 'manual'
  | 'schedule'
  | 'event'
  | 'webhook'
  | 'agent_output';

// ─── DAG Nodes (DB) ────────────────────────────────────────

export interface DagNode {
  id: string; // UUID
  flow_id: string; // UUID
  node_type: DagNodeType;
  agent_id: string | null;
  config: DagNodeConfig;
  position_x: number;
  position_y: number;
  created_at: string;
  updated_at: string;
}

// ─── DAG Edges (DB) ────────────────────────────────────────

export interface DagEdge {
  id: string; // UUID
  flow_id: string; // UUID
  source_node_id: string; // UUID
  target_node_id: string; // UUID
  condition: string | null;
  created_at: string;
  updated_at: string;
}

// ─── Flow Execution Types ───────────────────────────────────

export interface FlowExecution {
  id: string; // UUID
  flow_id: string; // UUID
  tenant_id: string; // UUID
  status: ExecutionStatus;
  context: ExecutionContext;
  results: ExecutionResult | null;
  errors: ExecutionError[] | null;
  started_at: string | null;
  completed_at: string | null;
  created_at: string;
  updated_at: string;
}

export type ExecutionStatus =
  | 'pending'
  | 'running'
  | 'completed'
  | 'failed'
  | 'cancelled'
  | 'waiting_approval';

export interface ExecutionContext {
  trigger: FlowTrigger;
  input: Record<string, unknown>;
  variables: Record<string, unknown>;
  [key: string]: unknown;
}

export interface ExecutionResult {
  output: Record<string, unknown>;
  node_results: Record<string, NodeExecutionResult>;
  total_cost_usd: number;
  total_duration_ms: number;
}

export interface NodeExecutionResult {
  node_id: string;
  status: ExecutionStatus;
  output: unknown;
  agent_id?: string;
  cost_usd: number;
  duration_ms: number;
  started_at: string;
  completed_at: string;
}

export interface ExecutionError {
  node_id: string;
  error_type: string;
  message: string;
  stack?: string;
  timestamp: string;
}

// ─── Flow API Types ─────────────────────────────────────────

export interface CreateFlowRequest {
  name: string;
  slug: string;
  description?: string;
  dag: DagDefinition;
  triggers: FlowTrigger[];
}

export interface UpdateFlowRequest {
  name?: string;
  description?: string;
  dag?: DagDefinition;
  triggers?: FlowTrigger[];
}

export interface ExecuteFlowRequest {
  flow_id: string;
  context?: Record<string, unknown>;
}

export interface ExecuteFlowResponse {
  execution_id: string;
  status: ExecutionStatus;
  message: string;
}

// ─── Observability (from migration 000009) ──────────────────

export interface Trace {
  id: string; // UUID
  dag_id: string; // UUID
  tenant_id: string; // UUID
  flow_execution_id: string; // UUID
  node_states: Record<string, NodeState>;
  execution_log: ExecutionLogEntry[];
  started_at: string;
  completed_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface NodeState {
  node_id: string;
  status: ExecutionStatus;
  input: unknown;
  output: unknown;
  started_at: string | null;
  completed_at: string | null;
}

export interface ExecutionLogEntry {
  timestamp: string;
  node_id: string;
  event: string;
  data: Record<string, unknown>;
}

export interface Anomaly {
  id: string; // UUID
  tenant_id: string; // UUID
  anomaly_type: string;
  severity: AnomalySeverity;
  description: string;
  context: Record<string, unknown>;
  signals: Record<string, unknown>;
  status: AnomalyStatus;
  detected_at: string;
  resolved_at: string | null;
  created_at: string;
  updated_at: string;
}

export type AnomalySeverity = 'critical' | 'high' | 'medium' | 'low';
export type AnomalyStatus = 'open' | 'acknowledged' | 'resolved' | 'dismissed';

/**
 * SpiderNet OS — Event Sourcing Types
 * Matches event_log table schema from migration 000001
 */

// ─── Core Event Types ───────────────────────────────────────

export interface EventLogEntry {
  id: string; // UUID
  tenant_id: string; // UUID
  aggregate_type: AggregateType;
  aggregate_id: string; // UUID
  event_type: EventType;
  payload: Record<string, unknown>;
  metadata: EventMetadata | null;
  version: number;
  occurred_at: string; // ISO 8601
  sequence_num: number;
}

export interface EventMetadata {
  user_id?: string;
  ip_address?: string;
  user_agent?: string;
  correlation_id?: string;
  causation_id?: string;
  [key: string]: unknown;
}

// ─── Aggregate Types ────────────────────────────────────────

export type AggregateType =
  | 'tenant'
  | 'user'
  | 'agent'
  | 'flow'
  | 'execution'
  | 'memory'
  | 'usage'
  | 'approval'
  | 'atlas'
  | 'brief';

// ─── Event Types ────────────────────────────────────────────

export type EventType =
  // Tenant events
  | 'tenant.created'
  | 'tenant.updated'
  | 'tenant.plan_changed'
  // User events
  | 'user.created'
  | 'user.logged_in'
  | 'user.logged_out'
  | 'user.updated'
  // Agent events
  | 'agent.registered'
  | 'agent.activated'
  | 'agent.deactivated'
  | 'agent.updated'
  | 'agent.deleted'
  | 'agent.dispatch_started'
  | 'agent.dispatch_completed'
  | 'agent.dispatch_failed'
  // Flow events
  | 'flow.created'
  | 'flow.updated'
  | 'flow.published'
  | 'flow.archived'
  | 'flow.deleted'
  | 'flow.execution_started'
  | 'flow.execution_completed'
  | 'flow.execution_failed'
  | 'flow.node_started'
  | 'flow.node_completed'
  // Memory events
  | 'memory.node_created'
  | 'memory.node_updated'
  | 'memory.edge_created'
  // Usage events
  | 'usage.recorded'
  | 'usage.budget_exceeded'
  | 'usage.budget_warning'
  // Approval events
  | 'approval.requested'
  | 'approval.granted'
  | 'approval.rejected'
  | 'approval.expired'
  // Atlas events
  | 'atlas.message.received'
  | 'atlas.message.sent'
  | 'atlas.intent.parsed'
  | 'atlas.plan.created'
  | 'atlas.plan.executed'
  // Brief events
  | 'brief.generated'
  | 'brief.viewed'
  // Command events
  | 'command.received'
  | 'command.parsed'
  | 'command.dispatched'
  | 'command.completed'
  | 'command.failed';

// ─── Event Payloads ─────────────────────────────────────────

export interface AgentRegisteredPayload {
  name: string;
  slug: string;
  type: string;
  capabilities: string[];
  config: Record<string, unknown>;
}

export interface AgentDispatchPayload {
  agent_id: string;
  intent: string;
  message: string;
  context: Record<string, unknown>;
}

export interface FlowCreatedPayload {
  name: string;
  slug: string;
  description: string;
  dag: Record<string, unknown>;
  triggers: Record<string, unknown>;
}

export interface FlowExecutionPayload {
  flow_id: string;
  execution_id: string;
  context: Record<string, unknown>;
}

export interface UsageRecordedPayload {
  agent_id: string;
  model: string;
  tokens_input: number;
  tokens_output: number;
  cost_usd: number;
  duration_ms: number;
}

export interface ApprovalRequestedPayload {
  approval_type: string;
  resource_type: string;
  resource_id: string;
  reason: string;
  context: Record<string, unknown>;
}

export interface AtlasMessagePayload {
  session_id: string;
  role: 'user' | 'atlas';
  content: string;
  intent?: string;
  ast?: CommandAST;
}

export interface CommandAST {
  intent: string;
  entities: Record<string, unknown>;
  parameters: Record<string, unknown>;
  confidence: number;
  raw_input: string;
}

// ─── Event Store API ────────────────────────────────────────

export interface AppendEventRequest {
  aggregate_type: AggregateType;
  aggregate_id: string;
  event_type: EventType;
  payload: Record<string, unknown>;
  metadata?: EventMetadata;
  expected_version?: number;
}

export interface EventQueryParams {
  tenant_id?: string;
  aggregate_type?: AggregateType;
  aggregate_id?: string;
  event_type?: EventType;
  since?: string;
  limit?: number;
  offset?: number;
}

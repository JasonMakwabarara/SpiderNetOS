/**
 * SpiderNet OS — Atlas Executive Assistant Types
 * Types for the dual-layer Atlas NL compiler and command interface
 */

import type { CommandAST } from './events';
import type { ExecutionStatus } from './flows';

// ─── Atlas Session Types ────────────────────────────────────

export interface AtlasSession {
  id: string; // UUID
  tenant_id: string; // UUID
  user_id: string; // UUID
  messages: AtlasMessage[];
  plan: AtlasPlan | null;
  metadata: AtlasSessionMetadata;
  started_at: string;
  updated_at: string;
}

export interface AtlasSessionMetadata {
  total_messages: number;
  total_cost_usd: number;
  agents_used: string[];
  intents_parsed: string[];
}

// ─── Atlas Messages ─────────────────────────────────────────

export interface AtlasMessage {
  id: string;
  role: 'user' | 'atlas' | 'system';
  content: string;
  timestamp: string;
  metadata?: AtlasMessageMetadata;
}

export interface AtlasMessageMetadata {
  intent?: string;
  ast?: CommandAST;
  agent_used?: string;
  model_used?: string;
  cost_usd?: number;
  duration_ms?: number;
  is_streaming?: boolean;
  tool_calls?: AtlasToolCall[];
  suggestions?: AtlasSuggestion[];
}

// ─── Atlas Intent Types ─────────────────────────────────────

export type AtlasIntent =
  | 'create_flow'
  | 'execute_flow'
  | 'query_status'
  | 'analyze_data'
  | 'teach'
  | 'help'
  | 'configure'
  | 'monitor'
  | 'search_memory'
  | 'manage_agent'
  | 'approve'
  | 'chat';

export interface IntentSlot {
  name: string;
  value: unknown;
  type: 'string' | 'number' | 'boolean' | 'entity' | 'datetime';
  required: boolean;
  filled: boolean;
}

export interface ParsedIntent {
  intent: AtlasIntent;
  confidence: number;
  slots: IntentSlot[];
  agent_target: string | null; // Which agent should handle this
  requires_planning: boolean;
  raw_input: string;
}

// ─── Atlas Task Planning ────────────────────────────────────

export interface AtlasPlan {
  id: string;
  session_id: string;
  intent: AtlasIntent;
  status: PlanStatus;
  tasks: AtlasTask[];
  created_at: string;
  started_at: string | null;
  completed_at: string | null;
}

export type PlanStatus = 'draft' | 'approved' | 'executing' | 'completed' | 'failed' | 'cancelled';

export interface AtlasTask {
  id: string;
  label: string;
  description: string;
  agent_id: string;
  status: ExecutionStatus;
  order: number;
  depends_on: string[]; // Task IDs
  result: unknown;
  cost_usd: number;
  duration_ms: number;
  started_at: string | null;
  completed_at: string | null;
}

// ─── Atlas Tool Calls ───────────────────────────────────────

export interface AtlasToolCall {
  tool_id: string;
  agent_id: string;
  parameters: Record<string, unknown>;
  result: unknown;
  status: 'pending' | 'running' | 'completed' | 'failed';
}

// ─── Atlas Suggestions ──────────────────────────────────────

export interface AtlasSuggestion {
  id: string;
  type: SuggestionType;
  title: string;
  description: string;
  action: SuggestionAction;
  priority: 'high' | 'medium' | 'low';
  dismissed: boolean;
}

export type SuggestionType =
  | 'budget_warning'
  | 'anomaly_detected'
  | 'flow_failure'
  | 'optimization'
  | 'onboarding'
  | 'quick_action';

export interface SuggestionAction {
  type: 'command' | 'navigate' | 'dispatch';
  payload: Record<string, unknown>;
  label: string;
}

// ─── Atlas API Types ────────────────────────────────────────

export interface AtlasChatRequest {
  message: string;
  session_id?: string;
}

export interface AtlasChatResponse {
  session_id: string;
  message: AtlasMessage;
  plan?: AtlasPlan;
  ast?: CommandAST;
  suggestions?: AtlasSuggestion[];
}

export interface AtlasPlanRequest {
  message: string;
  session_id: string;
}

export interface AtlasPlanResponse {
  plan: AtlasPlan;
  preview: string; // Human-readable plan summary
}

export interface AtlasExecuteRequest {
  plan_id: string;
  session_id: string;
}

export interface AtlasCancelRequest {
  plan_id: string;
  session_id: string;
}

// ─── Atlas Streaming Events (WebSocket) ─────────────────────

export type AtlasStreamEvent =
  | { type: 'message.start'; data: { message_id: string } }
  | { type: 'message.delta'; data: { content: string } }
  | { type: 'message.end'; data: { message: AtlasMessage } }
  | { type: 'plan.created'; data: { plan: AtlasPlan } }
  | { type: 'task.started'; data: { task_id: string; agent_id: string } }
  | { type: 'task.progress'; data: { task_id: string; progress: number; message: string } }
  | { type: 'task.completed'; data: { task_id: string; result: unknown } }
  | { type: 'task.failed'; data: { task_id: string; error: string } }
  | { type: 'plan.completed'; data: { plan_id: string; results: unknown } }
  | { type: 'suggestion'; data: { suggestion: AtlasSuggestion } }
  | { type: 'error'; data: { message: string; code: string } };

// ─── Usage Types (from migration 000007) ────────────────────

export interface UsageRecord {
  id: string; // UUID
  tenant_id: string;
  user_id: string | null;
  agent_id: string | null;
  resource_type: string;
  model: string | null;
  tokens_input: number;
  tokens_output: number;
  cost_usd: string; // decimal returned as string
  duration_ms: number | null;
  status: string;
  recorded_at: string;
  metadata: Record<string, unknown> | null;
}

export interface UsageDailyAggregate {
  id: number;
  tenant_id: string;
  date: string;
  resource_type: string;
  total_calls: number;
  total_tokens: number;
  total_cost: string;
  cost_ceiling: string;
  calculated_at: string;
}

export interface CostBudget {
  id: string; // UUID
  tenant_id: string;
  daily_limit: string;
  monthly_limit: string;
  alert_threshold: string;
  action_at_limit: 'block' | 'degrade' | 'notify';
  notifications: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
}

export interface BudgetStatus {
  daily_spent: number;
  daily_limit: number;
  daily_percent: number;
  monthly_spent: number;
  monthly_limit: number;
  monthly_percent: number;
  is_blocked: boolean;
  is_degraded: boolean;
}

// ─── Approval Types (from migration 000008) ─────────────────

export interface Approval {
  id: string; // UUID
  tenant_id: string;
  requester_id: string;
  approval_type: string;
  resource_type: string;
  resource_id: string;
  reason: string;
  context: Record<string, unknown>;
  status: ApprovalStatus;
  approver_id: string | null;
  response: string | null;
  requested_at: string;
  responded_at: string | null;
  expires_at: string | null;
  created_at: string;
  updated_at: string;
}

export type ApprovalStatus = 'pending' | 'approved' | 'rejected' | 'expired';

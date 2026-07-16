/**
 * SpiderNet OS — Agent Types
 * Matches agents + agent_delegations tables from migration 000004
 */

// ─── Core Agent Types ───────────────────────────────────────

export interface Agent {
  id: string; // UUID
  tenant_id: string; // UUID
  name: string;
  slug: string;
  description: string | null;
  type: AgentType;
  status: AgentStatus;
  capabilities: AgentCapability[];
  config: AgentConfig | null;
  activated_at: string | null;
  created_at: string;
  updated_at: string;
}

export type AgentType = 'core' | 'dynamic' | 'pack';

export type AgentStatus = 'active' | 'inactive' | 'degraded' | 'error';

export type AgentCapability =
  | 'nl_compilation'
  | 'flow_creation'
  | 'flow_modification'
  | 'flow_validation'
  | 'dag_execution'
  | 'retry_management'
  | 'data_analysis'
  | 'research'
  | 'summarization'
  | 'report_generation'
  | 'health_monitoring'
  | 'anomaly_detection'
  | 'alerting'
  | 'trace_management'
  | 'onboarding'
  | 'teaching'
  | 'help'
  | 'memory_management'
  | 'delegation'
  | string; // Allow custom capabilities for dynamic agents

// ─── Agent Config (for dynamic agents) ─────────────────────

export interface AgentConfig {
  system_prompt?: string;
  model?: string;
  tools?: string[];
  delegation?: string[]; // Agent slugs this agent can delegate to
  temperature?: number;
  max_tokens?: number;
  [key: string]: unknown;
}

// ─── Agent Delegation ───────────────────────────────────────

export interface AgentDelegation {
  id: number;
  agent_id: string; // UUID
  delegate_id: string; // UUID
  permission: string;
  conditions: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
}

// ─── Core Agent Slugs ───────────────────────────────────────

export type CoreAgentSlug =
  | 'atlas'
  | 'hannah'
  | 'forge'
  | 'sentinel'
  | 'prism'
  | 'nexus';

export const CORE_AGENTS: Record<CoreAgentSlug, { name: string; description: string }> = {
  atlas: { name: 'Atlas', description: 'NL Compiler & Executive Assistant' },
  hannah: { name: 'Hannah', description: 'Tutor & Onboarding Guide' },
  forge: { name: 'Forge', description: 'Flow Builder & DAG Designer' },
  sentinel: { name: 'Sentinel', description: 'Monitor & Anomaly Detector' },
  prism: { name: 'Prism', description: 'Data Analyst & Researcher' },
  nexus: { name: 'Nexus', description: 'Execution Engine & DAG Runner' },
};

// ─── Agent Templates (for dynamic agent creation) ──────────

export interface AgentTemplate {
  id: string;
  name: string;
  description: string;
  icon: string;
  system_prompt: string;
  model: string;
  capabilities: AgentCapability[];
  tools: string[];
  delegation: string[];
  temperature: number;
  max_tokens: number;
}

// ─── Agent API Types ────────────────────────────────────────

export interface CreateAgentRequest {
  name: string;
  slug: string;
  description?: string;
  type: AgentType;
  capabilities: AgentCapability[];
  config: AgentConfig;
}

export interface UpdateAgentRequest {
  name?: string;
  description?: string;
  capabilities?: AgentCapability[];
  config?: AgentConfig;
}

export interface AgentDispatchRequest {
  agent_id: string;
  intent: string;
  message: string;
  context?: Record<string, unknown>;
  session_id?: string;
}

export interface AgentDispatchResponse {
  execution_id: string;
  agent_id: string;
  status: 'success' | 'failed' | 'degraded' | 'blocked';
  output: string;
  metadata: {
    model_used: string;
    tokens_input: number;
    tokens_output: number;
    cost_usd: number;
    duration_ms: number;
    delegated_to?: string[];
  };
}

export interface DelegationGraph {
  nodes: Array<{
    id: string;
    slug: string;
    name: string;
    type: AgentType;
    status: AgentStatus;
  }>;
  edges: Array<{
    source: string;
    target: string;
    permission: string;
  }>;
}

export interface AgentSession {
  id: string;
  agent_id: string;
  tenant_id: string;
  messages: Array<{
    role: 'user' | 'agent';
    content: string;
    timestamp: string;
  }>;
  started_at: string;
  completed_at: string | null;
}

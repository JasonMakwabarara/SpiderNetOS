/**
 * @spidernet/sdk — Shared TypeScript SDK
 * Type-safe contracts between cockpit UI and backend API
 */

// Event sourcing types
export type {
  EventLogEntry,
  EventMetadata,
  AggregateType,
  EventType,
  AgentRegisteredPayload,
  AgentDispatchPayload,
  FlowCreatedPayload,
  FlowExecutionPayload,
  UsageRecordedPayload,
  ApprovalRequestedPayload,
  AtlasMessagePayload,
  CommandAST,
  AppendEventRequest,
  EventQueryParams,
} from './types/events';

// Agent types
export type {
  Agent,
  AgentType,
  AgentStatus,
  AgentCapability,
  AgentConfig,
  AgentDelegation,
  CoreAgentSlug,
  AgentTemplate,
  CreateAgentRequest,
  UpdateAgentRequest,
  AgentDispatchRequest,
  AgentDispatchResponse,
  DelegationGraph,
  AgentSession,
} from './types/agents';
export { CORE_AGENTS } from './types/agents';

// Flow & DAG types
export type {
  Flow,
  FlowStatus,
  DagDefinition,
  DagNodeDef,
  DagNodeType,
  DagNodeConfig,
  DagEdgeDef,
  FlowTrigger,
  TriggerType,
  DagNode,
  DagEdge,
  FlowExecution,
  ExecutionStatus,
  ExecutionContext,
  ExecutionResult,
  NodeExecutionResult,
  ExecutionError,
  CreateFlowRequest,
  UpdateFlowRequest,
  ExecuteFlowRequest,
  ExecuteFlowResponse,
  Trace,
  NodeState,
  ExecutionLogEntry,
  Anomaly,
  AnomalySeverity,
  AnomalyStatus,
} from './types/flows';

// Atlas types
export type {
  AtlasSession,
  AtlasSessionMetadata,
  AtlasMessage,
  AtlasMessageMetadata,
  AtlasIntent,
  IntentSlot,
  ParsedIntent,
  AtlasPlan,
  PlanStatus,
  AtlasTask,
  AtlasToolCall,
  AtlasSuggestion,
  SuggestionType,
  SuggestionAction,
  AtlasChatRequest,
  AtlasChatResponse,
  AtlasPlanRequest,
  AtlasPlanResponse,
  AtlasExecuteRequest,
  AtlasCancelRequest,
  AtlasStreamEvent,
  UsageRecord,
  UsageDailyAggregate,
  CostBudget,
  BudgetStatus,
  Approval,
  ApprovalStatus,
} from './types/atlas';

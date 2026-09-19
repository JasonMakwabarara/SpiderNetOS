"""
SpiderNet OS v3.2 - Agent Base Class
Hard Rule #2: Meta-Planner is sole decision authority
Agents NEVER call other agents directly - all dispatch through MetaPlanner
"""

import asyncio
import json
from abc import ABC, abstractmethod
from dataclasses import dataclass
from datetime import datetime
from typing import Any, Dict, List, Optional

# DeepSeek integration for strategic reasoning
from .deepseek_client import DeepSeekClient, get_deepseek_client


@dataclass
class AgentContext:
    """Immutable context passed to agents - no shared memory with UI"""
    tenant_id: str
    session_id: str
    user_id: Optional[str]
    message: str
    ast: Dict[str, Any]
    metadata: Dict[str, Any]
    degraded_mode: bool = False
    model_override: Optional[str] = None


@dataclass
class AgentResult:
    """Standard agent execution result"""
    status: str  # 'success', 'error', 'deferred', 'blocked'
    output: Any
    tokens_used: int = 0
    cost_usd: float = 0.0
    execution_time_ms: float = 0.0
    metadata: Dict[str, Any] = None


class AgentBase(ABC):
    """Base class for all SpiderNet agents"""

    def __init__(
        self,
        agent_id: str,
        name: str,
        capabilities: List[str],
        meta_planner: 'MetaPlanner',
        cost_governor: 'CostGovernor',
        memory_graph: 'MemoryGraph',
    ):
        self.agent_id = agent_id
        self.name = name
        self.capabilities = capabilities
        self.meta_planner = meta_planner
        self.cost_governor = cost_governor
        self.memory_graph = memory_graph
        self.execution_count = 0
        self.total_cost = 0.0

    @abstractmethod
    async def execute(self, context: AgentContext) -> AgentResult:
        """Execute agent logic - MUST be implemented by subclasses"""
        pass

    def can_handle(self, intent: str) -> bool:
        """Check if agent has capability for intent"""
        required = self._map_intent_to_capability(intent)
        return required in self.capabilities

    def _map_intent_to_capability(self, intent: str) -> str:
        """Map intent to required capability"""
        mapping = {
            'chat': 'chat',
            'process_command': 'chat',
            'execute_flow': 'flow_execution',
            'generate_content': 'content_generation',
            'analyze_data': 'data_analysis',
            'search_memory': 'memory_access',
            'delegate_task': 'task_delegation',
        }
        return mapping.get(intent, 'basic')

    async def _record_execution(self, result: AgentResult) -> None:
        """Record execution metrics"""
        self.execution_count += 1
        self.total_cost += result.cost_usd


class MetaPlanner:
    """
    Hard Rule #2: Meta-Planner is the ONLY decision authority.
    All agent dispatch goes through here. Agents cannot call other agents directly.
    """

    def __init__(self, redis_client, event_store, cost_governor, use_deepseek: bool = True):
        self.redis = redis_client
        self.event_store = event_store
        self.cost_governor = cost_governor
        self.agents: Dict[str, AgentBase] = {}
        self._running = False

        # DeepSeek strategic reasoning layer
        self.use_deepseek = use_deepseek
        self.deepseek: Optional[DeepSeekClient] = None
        if use_deepseek:
            try:
                self.deepseek = get_deepseek_client()
            except Exception as e:
                print(f"[MetaPlanner] DeepSeek not available: {e}")
                self.use_deepseek = False

    def register_agent(self, agent: AgentBase) -> None:
        """Register an agent with the planner"""
        self.agents[agent.agent_id] = agent

    async def plan_strategy(
        self,
        tenant_id: str,
        command: str,
        context: Dict[str, Any]
    ) -> Dict[str, Any]:
        """
        Use DeepSeek for strategic planning before dispatch.

        Returns execution plan with:
        - Which agents to use
        - Execution order
        - Risk assessment
        - Cost estimate
        """
        if not self.use_deepseek or not self.deepseek:
            # Fallback to heuristic planning
            return self._heuristic_plan(command, context)

        # Get cost context
        budget = await self.cost_governor.get_budget_status(tenant_id)

        # Build planning context
        plan_context = {
            'budget': budget.get('remaining', 0),
            'cost_ceiling': budget.get('ceiling', 50),
            'active_agents': list(self.agents.keys()),
            'load': context.get('system_load', 0),
            'tier': context.get('tenant_tier', 'basic'),
            'time_constraint': context.get('time_constraint', 'none')
        }

        # Call DeepSeek for strategic reasoning
        try:
            plan = await asyncio.get_event_loop().run_in_executor(
                None,  # Default executor
                self.deepseek.plan_execution,
                command,
                plan_context
            )

            # Record planning event
            await self.event_store.append(
                tenant_id=tenant_id,
                aggregate_type='metaplanner',
                aggregate_id='strategy',
                event_type='metaplanner.strategy_planned',
                payload={
                    'command': command[:100],  # Truncate
                    'plan': plan,
                    'model': self.deepseek.model,
                }
            )

            return plan

        except Exception as e:
            print(f"[MetaPlanner] DeepSeek planning failed: {e}")
            return self._heuristic_plan(command, context)

    def _heuristic_plan(self, command: str, context: Dict) -> Dict:
        """Fallback heuristic planning when DeepSeek unavailable"""
        # Simple keyword-based routing
        command_lower = command.lower()

        if any(k in command_lower for k in ['create', 'build', 'make', 'generate']):
            agents = ['atlas', 'forge']
        elif any(k in command_lower for k in ['deploy', 'infrastructure', 'scale']):
            agents = ['atlas', 'nexus']
        elif any(k in command_lower for k in ['analyze', 'report', 'metrics']):
            agents = ['atlas', 'prism']
        elif any(k in command_lower for k in ['security', 'monitor', 'alert']):
            agents = ['atlas', 'sentinel']
        else:
            agents = ['atlas']

        return {
            'agents': agents,
            'execution_order': list(range(len(agents))),
            'risks': ['heuristic_fallback'],
            'estimated_cost': 5.0 * len(agents),
            'execution_decision': 'execute_now',
            'rationale': 'Heuristic fallback - DeepSeek unavailable'
        }

    async def dispatch(
        self,
        tenant_id: str,
        agent_id: str,
        intent: str,
        context: Dict[str, Any],
        flow_id: Optional[str] = None
    ) -> Dict[str, Any]:
        """Dispatch to agent with cost and permission checks"""

        # Hard Rule #4: CostGovernor overrides ALL execution
        cost_status = await self.cost_governor.can_execute(tenant_id)

        if not cost_status['allowed']:
            return {
                'status': 'blocked',
                'reason': 'Budget exceeded',
                'cost_status': cost_status,
            }

        # Check agent exists and has permission
        agent = self.agents.get(agent_id)
        if not agent:
            return {
                'status': 'error',
                'reason': f'Agent {agent_id} not found',
            }

        if not agent.can_handle(intent):
            return {
                'status': 'blocked',
                'reason': f'Agent lacks permission for intent: {intent}',
            }

        # Build immutable context
        agent_context = AgentContext(
            tenant_id=tenant_id,
            session_id=context.get('session_id', ''),
            user_id=context.get('user_id'),
            message=context.get('original_message', ''),
            ast=context.get('ast', {}),
            metadata=context.get('metadata', {}),
            degraded_mode=cost_status.get('degraded', False),
            model_override=context.get('model_override') if cost_status.get('degraded') else None,
        )

        # Execute
        start_time = asyncio.get_event_loop().time()
        try:
            result = await agent.execute(agent_context)
            execution_time = (asyncio.get_event_loop().time() - start_time) * 1000

            # Record cost
            await self.cost_governor.record_usage(
                tenant_id=tenant_id,
                resource_type='agent_execution',
                cost=result.cost_usd,
                metadata={
                    'agent_id': agent_id,
                    'intent': intent,
                    'tokens': result.tokens_used,
                }
            )

            return {
                'status': 'success',
                'result': result,
                'execution_time_ms': execution_time,
                'cost_status': cost_status,
            }

        except Exception as e:
            return {
                'status': 'error',
                'reason': str(e),
                'execution_time_ms': (asyncio.get_event_loop().time() - start_time) * 1000,
            }

    async def process_atlas_request(
        self,
        tenant_id: str,
        user_id: str,
        message: str,
        session_id: Optional[str] = None
    ) -> Dict[str, Any]:
        """
        Hard Rule #3: Atlas UI and Atlas Agent NEVER share runtime memory.
        Atlas UI requests are processed through Meta-Planner with ephemeral session only.
        """
        session_id = session_id or self._generate_uuid()

        # Record command received event
        await self.event_store.append(
            tenant_id=tenant_id,
            aggregate_type='atlas_session',
            aggregate_id=session_id,
            event_type='atlas.command_received',
            payload={
                'user_id': user_id,
                'message': message,
                'timestamp': datetime.utcnow().isoformat(),
            },
            metadata={'source': 'ui'}
        )

        # Parse to AST
        ast = self._parse_command_to_ast(message)

        # Resolve Atlas agent
        atlas_agent_id = await self._resolve_atlas_agent(tenant_id)
        if not atlas_agent_id:
            return {
                'status': 'error',
                'reason': 'Atlas agent not found',
            }

        # Get ephemeral session context (NOT shared memory)
        session_context = await self._get_session_context(session_id)

        # Dispatch with isolated context
        return await self.dispatch(
            tenant_id=tenant_id,
            agent_id=atlas_agent_id,
            intent='process_command',
            context={
                'session_id': session_id,
                'user_id': user_id,
                'original_message': message,
                'ast': ast,
                'session_context': session_context,  # Isolated, passed as context only
            }
        )

    def _parse_command_to_ast(self, message: str) -> Dict[str, Any]:
        """Parse natural language to AST"""
        message_lower = message.lower().strip()

        if 'create flow' in message_lower or 'new flow' in message_lower:
            return {
                'type': 'create_flow',
                'params': {'name': self._extract_quoted(message)},
            }

        if any(word in message_lower for word in ['run', 'execute']):
            return {
                'type': 'execute',
                'params': {'target': self._extract_quoted(message)},
            }

        if any(word in message_lower for word in ['status', 'health']):
            return {
                'type': 'query_status',
                'params': {},
            }

        return {
            'type': 'chat',
            'params': {'message': message},
        }

    def _extract_quoted(self, message: str) -> Optional[str]:
        """Extract quoted string from message"""
        import re
        match = re.search(r'"([^"]+)"', message)
        return match.group(1) if match else None

    def _generate_uuid(self) -> str:
        """Generate UUID v4"""
        import uuid
        return str(uuid.uuid4())

    async def _resolve_atlas_agent(self, tenant_id: str) -> Optional[str]:
        """Resolve Atlas agent ID for tenant"""
        # In production: query from projection/cache
        # For now: return 'atlas' as slug
        return 'atlas'

    async def _get_session_context(self, session_id: str) -> List[str]:
        """Get recent session history from event_log only"""
        events = await self.event_store.get_events(
            aggregate_type='atlas_session',
            aggregate_id=session_id,
            limit=5
        )
        return [
            e['payload'].get('message', '')
            for e in events
            if e['event_type'] == 'atlas.command_received'
        ]

    async def start(self) -> None:
        """Start the planner event loop"""
        self._running = True
        while self._running:
            try:
                message = await self.redis.blpop('agent:dispatch', timeout=1)
                if message:
                    data = json.loads(message[1])
                    await self.dispatch(
                        tenant_id=data['tenant_id'],
                        agent_id=data['agent_id'],
                        intent=data['intent'],
                        context=data['context'],
                        flow_id=data.get('flow_id'),
                    )
            except Exception as e:
                print(f"Planner error: {e}")

    def stop(self) -> None:
        """Stop the planner"""
        self._running = False

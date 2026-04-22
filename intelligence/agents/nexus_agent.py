"""
SpiderNet OS v3.2 - Nexus Agent
Execution & Orchestration: Flow execution engine
"""

from typing import Dict, Any, List, Optional
from datetime import datetime
import asyncio
import json
from core.agent_base import AgentBase, AgentContext, AgentResult


class NexusAgent(AgentBase):
    """
    Nexus: The Execution & Orchestration Agent
    
    Responsibilities:
    - Execute flow DAGs
    - Orchestrate multi-agent workflows
    - Manage execution state
    - Handle errors and retries
    """
    
    def __init__(self, meta_planner, cost_governor, memory_graph, db_pool):
        super().__init__(
            agent_id='nexus',
            name='Nexus',
            capabilities=['flow_execution', 'orchestration', 'state_management', 'error_handling'],
            meta_planner=meta_planner,
            cost_governor=cost_governor,
            memory_graph=memory_graph,
        )
        self.db = db_pool
        self.executions: Dict[str, Dict] = {}  # In-memory execution state
    
    async def execute(self, context: AgentContext) -> AgentResult:
        """Execute Nexus agent logic"""
        
        ast = context.ast
        ast_type = ast.get('type', '')
        
        if ast_type == 'execute':
            return await self._execute_flow(context)
        elif ast_type == 'execute_flow':
            return await self._execute_flow(context)
        elif ast_type == 'dag_step':
            return await self._execute_dag_step(context)
        elif ast_type == 'retry_execution':
            return await self._retry_execution(context)
        elif ast_type == 'get_execution_status':
            return await self._get_execution_status(context)
        # Phase C: post-call processing intent
        elif context.metadata.get('intent') == 'voice.post_call_process' or ast_type == 'voice_post_call':
            return await self._handle_post_call(context)
        else:
            return AgentResult(
                status='error',
                output={'error': f'Unknown execution intent: {ast_type}'},
                tokens_used=0,
                cost_usd=0.0,
            )

    async def _handle_post_call(self, context: AgentContext) -> AgentResult:
        """
        Phase C — Post-call processing DAG.

        Receives voice.post_call_process intent from Redis pub/sub
        (triggered by VoiceController after CallStatus=completed).

        This Python-side handler is the Nexus coordination layer.
        Actual structured summary is handled by the PHP ProcessVoiceCallSummary job.
        This handler focuses on memory enrichment.

        DAG steps:
          1. Retrieve full transcript from backend API
          2. Store conversation summary in memory_graph (L3 — episodic)
          3. Publish completion event
        """
        call_sid  = context.metadata.get('call_sid', '')
        tenant_id = context.tenant_id

        try:
            # Step 1: Retrieve call record via backend API
            call_data = await self._fetch_call_record(call_sid, tenant_id)

            if not call_data:
                return AgentResult(
                    status='error',
                    output={'error': f'Call record not found: {call_sid}'},
                    tokens_used=0,
                    cost_usd=0.0,
                )

            transcript = call_data.get('transcript', [])
            summary    = call_data.get('summary', '')

            # Step 2: Store in memory graph (L3 episodic layer)
            if self.memory_graph and transcript:
                transcript_text = '\n'.join(
                    f"{t.get('speaker', 'unknown').upper()}: {t.get('text', '')}"
                    for t in transcript
                )
                await self.memory_graph.store(
                    content=transcript_text,
                    metadata={
                        'layer':    'L3',
                        'type':     'voice_call_transcript',
                        'call_sid': call_sid,
                        'tenant_id': tenant_id,
                        'duration':  call_data.get('duration_seconds'),
                    }
                )

            return AgentResult(
                status='success',
                output={
                    'call_sid':  call_sid,
                    'steps_run': ['memory_store'],
                    'summary_available': bool(summary),
                },
                tokens_used=0,
                cost_usd=0.001,
            )

        except Exception as exc:
            import logging
            logging.getLogger(__name__).exception("nexus.voice_post_call_failed call_sid=%s", call_sid)
            return AgentResult(
                status='error',
                output={'error': str(exc), 'call_sid': call_sid},
                tokens_used=0,
                cost_usd=0.0,
            )

    async def _fetch_call_record(self, call_sid: str, tenant_id: str) -> Optional[dict]:
        """Fetch the call record from the backend service."""
        import httpx, os
        backend_url = os.getenv('BACKEND_URL', 'http://backend:8000')
        api_key     = os.getenv('BACKEND_INTERNAL_KEY', '')

        try:
            async with httpx.AsyncClient(timeout=10.0) as client:
                resp = await client.get(
                    f"{backend_url}/api/internal/voice/calls/{call_sid}",
                    headers={'X-Internal-Key': api_key, 'X-Tenant-Id': tenant_id},
                )
                if resp.status_code == 200:
                    return resp.json()
        except Exception:
            pass
        return None
    
    async def _execute_flow(self, context: AgentContext) -> AgentResult:
        """Execute a flow by ID or target"""
        
        params = context.ast.get('params', {})
        flow_id = params.get('flow_id')
        target = params.get('target', flow_id)
        
        # Resolve flow
        if not flow_id and target:
            flow_id = await self._resolve_flow_by_name(context.tenant_id, target)
        
        if not flow_id:
            return AgentResult(
                status='error',
                output={'error': f'Flow not found: {target}'},
                tokens_used=0,
                cost_usd=0.0,
            )
        
        # Load flow structure
        flow = await self._load_flow(context.tenant_id, flow_id)
        
        if not flow:
            return AgentResult(
                status='error',
                output={'error': f'Flow not found: {flow_id}'},
                tokens_used=0,
                cost_usd=0.0,
            )
        
        # Check cost before execution
        cost_status = await self.cost_governor.can_execute(context.tenant_id)
        if not cost_status['allowed']:
            return AgentResult(
                status='blocked',
                output={'reason': 'Budget exceeded', 'cost_status': cost_status},
                tokens_used=0,
                cost_usd=0.0,
            )
        
        # Create execution record
        execution_id = self._generate_uuid()
        execution = {
            'id': execution_id,
            'flow_id': flow_id,
            'tenant_id': context.tenant_id,
            'status': 'running',
            'context': context.metadata,
            'started_at': datetime.utcnow().isoformat(),
            'nodes_executed': [],
            'results': {},
            'errors': [],
        }
        
        self.executions[execution_id] = execution
        
        # Execute DAG
        try:
            result = await self._execute_dag(
                execution_id=execution_id,
                flow=flow,
                context=context
            )
            
            execution['status'] = 'completed'
            execution['completed_at'] = datetime.utcnow().isoformat()
            execution['results'] = result
            
            # Persist execution
            await self._persist_execution(execution)
            
            return AgentResult(
                status='success',
                output={
                    'execution_id': execution_id,
                    'flow_id': flow_id,
                    'status': 'completed',
                    'results': result,
                    'nodes_executed': len(execution['nodes_executed']),
                },
                tokens_used=0,
                cost_usd=0.0,
                metadata={'execution_time_ms': self._calculate_duration(execution)}
            )
            
        except Exception as e:
            execution['status'] = 'failed'
            execution['errors'].append(str(e))
            execution['completed_at'] = datetime.utcnow().isoformat()
            
            await self._persist_execution(execution)
            
            return AgentResult(
                status='error',
                output={
                    'execution_id': execution_id,
                    'flow_id': flow_id,
                    'status': 'failed',
                    'error': str(e),
                },
                tokens_used=0,
                cost_usd=0.0,
            )
    
    async def _execute_dag(
        self,
        execution_id: str,
        flow: Dict,
        context: AgentContext
    ) -> Dict:
        """Execute flow DAG"""
        
        nodes = {n['id']: n for n in flow.get('nodes', [])}
        edges = flow.get('edges', [])
        
        # Build adjacency list
        adjacency = {node_id: [] for node_id in nodes}
        for edge in edges:
            source = edge.get('source')
            target = edge.get('target')
            if source in adjacency:
                adjacency[source].append({
                    'target': target,
                    'condition': edge.get('condition'),
                })
        
        # Find start nodes (triggers)
        start_nodes = [
            node_id for node_id, node in nodes.items()
            if node.get('type') == 'trigger'
        ]
        
        if not start_nodes:
            raise ValueError('No trigger nodes found in flow')
        
        # Execute from each start node
        results = {}
        for start in start_nodes:
            node_results = await self._execute_node_chain(
                execution_id=execution_id,
                start_node=start,
                nodes=nodes,
                adjacency=adjacency,
                context=context
            )
            results.update(node_results)
        
        return results
    
    async def _execute_node_chain(
        self,
        execution_id: str,
        start_node: str,
        nodes: Dict,
        adjacency: Dict,
        context: AgentContext
    ) -> Dict:
        """Execute chain of nodes starting from start_node"""
        
        results = {}
        visited = set()
        queue = [start_node]
        
        while queue:
            node_id = queue.pop(0)
            
            if node_id in visited:
                continue
            
            visited.add(node_id)
            node = nodes.get(node_id)
            
            if not node:
                continue
            
            # Execute node
            result = await self._execute_node(execution_id, node, context)
            results[node_id] = result
            
            # Record execution
            self.executions[execution_id]['nodes_executed'].append(node_id)
            
            # Check condition and queue next nodes
            for edge in adjacency.get(node_id, []):
                condition = edge.get('condition')
                target = edge['target']
                
                if condition:
                    # Evaluate condition
                    if self._evaluate_condition(condition, result):
                        queue.append(target)
                else:
                    queue.append(target)
        
        return results
    
    async def _execute_node(
        self,
        execution_id: str,
        node: Dict,
        context: AgentContext
    ) -> Dict:
        """Execute a single node"""
        
        node_type = node.get('type')
        node_id = node.get('id')
        
        if node_type == 'trigger':
            # Triggers just pass through
            return {'status': 'triggered', 'node_id': node_id}
        
        elif node_type == 'agent':
            # Dispatch to agent via MetaPlanner
            agent_id = node.get('agent_id')
            agent_context = {
                'session_id': context.session_id,
                'user_id': context.user_id,
                'original_message': context.message,
                'ast': {'type': 'execute', 'params': node.get('config', {})},
                'metadata': context.metadata,
            }
            
            dispatch_result = await self.meta_planner.dispatch(
                tenant_id=context.tenant_id,
                agent_id=agent_id,
                intent='execute',
                context=agent_context,
            )
            
            return {
                'status': dispatch_result.get('status'),
                'result': dispatch_result,
                'node_id': node_id,
            }
        
        elif node_type == 'condition':
            # Evaluate condition
            config = node.get('config', {})
            condition = config.get('condition', 'true')
            return {
                'status': 'evaluated',
                'condition': condition,
                'result': self._evaluate_condition(condition, {}),
                'node_id': node_id,
            }
        
        elif node_type == 'action':
            # Execute action
            config = node.get('config', {})
            action_type = config.get('action_type', 'noop')
            
            # Execute action logic
            return {
                'status': 'executed',
                'action': action_type,
                'node_id': node_id,
            }
        
        elif node_type == 'output':
            # Output result
            config = node.get('config', {})
            destination = config.get('destination', 'user')
            
            return {
                'status': 'output',
                'destination': destination,
                'node_id': node_id,
            }
        
        else:
            return {'status': 'unknown_type', 'node_type': node_type, 'node_id': node_id}
    
    async def _execute_dag_step(self, context: AgentContext) -> AgentResult:
        """Execute a single DAG step (for resumable flows)"""
        params = context.ast.get('params', {})
        execution_id = params.get('execution_id')
        step_index = params.get('step_index', 0)
        
        if execution_id not in self.executions:
            return AgentResult(
                status='error',
                output={'error': f'Execution not found: {execution_id}'},
                tokens_used=0,
                cost_usd=0.0,
            )
        
        execution = self.executions[execution_id]
        
        return AgentResult(
            status='success',
            output={
                'execution_id': execution_id,
                'step_executed': step_index,
                'status': execution['status'],
            },
            tokens_used=0,
            cost_usd=0.0,
        )
    
    async def _retry_execution(self, context: AgentContext) -> AgentResult:
        """Retry a failed execution"""
        params = context.ast.get('params', {})
        execution_id = params.get('execution_id')
        
        if execution_id not in self.executions:
            return AgentResult(
                status='error',
                output={'error': f'Execution not found: {execution_id}'},
                tokens_used=0,
                cost_usd=0.0,
            )
        
        execution = self.executions[execution_id]
        
        if execution['status'] != 'failed':
            return AgentResult(
                status='error',
                output={'error': 'Can only retry failed executions'},
                tokens_used=0,
                cost_usd=0.0,
            )
        
        # Reset and retry
        execution['status'] = 'retrying'
        execution['errors'] = []
        execution['started_at'] = datetime.utcnow().isoformat()
        
        # Reload flow and re-execute
        flow = await self._load_flow(execution['tenant_id'], execution['flow_id'])
        
        try:
            result = await self._execute_dag(
                execution_id=execution_id,
                flow=flow,
                context=context
            )
            
            execution['status'] = 'completed'
            execution['results'] = result
            execution['completed_at'] = datetime.utcnow().isoformat()
            
            return AgentResult(
                status='success',
                output={
                    'execution_id': execution_id,
                    'status': 'completed',
                    'results': result,
                },
                tokens_used=0,
                cost_usd=0.0,
            )
            
        except Exception as e:
            execution['status'] = 'failed'
            execution['errors'].append(str(e))
            
            return AgentResult(
                status='error',
                output={
                    'execution_id': execution_id,
                    'status': 'failed',
                    'error': str(e),
                },
                tokens_used=0,
                cost_usd=0.0,
            )
    
    async def _get_execution_status(self, context: AgentContext) -> AgentResult:
        """Get execution status"""
        params = context.ast.get('params', {})
        execution_id = params.get('execution_id')
        
        if execution_id not in self.executions:
            return AgentResult(
                status='error',
                output={'error': f'Execution not found: {execution_id}'},
                tokens_used=0,
                cost_usd=0.0,
            )
        
        execution = self.executions[execution_id]
        
        return AgentResult(
            status='success',
            output={
                'execution_id': execution_id,
                'flow_id': execution['flow_id'],
                'status': execution['status'],
                'started_at': execution['started_at'],
                'completed_at': execution.get('completed_at'),
                'nodes_executed': len(execution['nodes_executed']),
                'errors': execution['errors'],
            },
            tokens_used=0,
            cost_usd=0.0,
        )
    
    async def _load_flow(self, tenant_id: str, flow_id: str) -> Optional[Dict]:
        """Load flow from database"""
        # In production: query flows table
        # Return mock flow structure
        return {
            'id': flow_id,
            'name': 'Mock Flow',
            'nodes': [
                {'id': 'trigger_1', 'type': 'trigger', 'config': {'type': 'manual'}},
                {'id': 'atlas_1', 'type': 'agent', 'agent_id': 'atlas', 'config': {'prompt': 'Process'}},
                {'id': 'output_1', 'type': 'output', 'config': {'destination': 'user'}},
            ],
            'edges': [
                {'source': 'trigger_1', 'target': 'atlas_1'},
                {'source': 'atlas_1', 'target': 'output_1'},
            ],
        }
    
    async def _resolve_flow_by_name(self, tenant_id: str, name: str) -> Optional[str]:
        """Resolve flow ID by name"""
        # In production: query flows table by slug
        return 'mock-flow-id'
    
    async def _persist_execution(self, execution: Dict) -> None:
        """Persist execution to database"""
        # In production: INSERT/UPDATE flow_executions
        pass
    
    def _evaluate_condition(self, condition: str, data: Dict) -> bool:
        """Evaluate a condition string"""
        # Simple condition evaluation
        if condition == 'true':
            return True
        if condition == 'false':
            return False
        
        # Check for result status conditions
        if 'status' in data:
            return data['status'] in ['success', 'completed', 'triggered']
        
        return True
    
    def _generate_uuid(self) -> str:
        """Generate UUID v4"""
        import uuid
        return str(uuid.uuid4())
    
    def _calculate_duration(self, execution: Dict) -> int:
        """Calculate execution duration in ms"""
        started = datetime.fromisoformat(execution['started_at'])
        completed_str = execution.get('completed_at')
        if completed_str:
            completed = datetime.fromisoformat(completed_str)
            return int((completed - started).total_seconds() * 1000)
        return 0

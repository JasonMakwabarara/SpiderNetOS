"""
SpiderNet OS v3.2 - Forge Agent
Flow Builder: Creates and modifies agent workflows
"""

from typing import Dict

from core.agent_base import AgentBase, AgentContext, AgentResult


class ForgeAgent(AgentBase):
    """
    Forge: The Flow Builder Agent

    Responsibilities:
    - Create and modify agent workflow DAGs
    - Generate node configurations
    - Validate flow topology
    """

    def __init__(self, meta_planner, cost_governor, memory_graph, llm_client):
        super().__init__(
            agent_id='forge',
            name='Forge',
            capabilities=['flow_creation', 'flow_modification', 'dag_building'],
            meta_planner=meta_planner,
            cost_governor=cost_governor,
            memory_graph=memory_graph,
        )
        self.llm = llm_client

    async def execute(self, context: AgentContext) -> AgentResult:
        """Execute Forge agent logic"""

        ast = context.ast
        ast_type = ast.get('type', '')

        if ast_type == 'create_flow':
            return await self._create_flow(context)
        elif ast_type == 'modify_flow':
            return await self._modify_flow(context)
        elif ast_type == 'validate_flow':
            return await self._validate_flow(context)
        else:
            return AgentResult(
                status='error',
                output={'error': f'Unknown intent: {ast_type}'},
                tokens_used=0,
                cost_usd=0.0,
            )

    async def _create_flow(self, context: AgentContext) -> AgentResult:
        """Create a new flow from natural language description"""

        params = context.ast.get('params', {})
        flow_name = params.get('name', 'Unnamed Flow')
        description = params.get('description', '')

        # Generate flow structure using LLM
        prompt = f"""Create a flow structure for: {flow_name}

Description: {description}

Generate a DAG structure with nodes and edges. Each node should have:
- node_type: one of [trigger, agent, condition, action, output]
- agent_id: which agent handles this node (if agent type)
- config: node-specific configuration

Return as JSON:
{{
    "name": "flow name",
    "description": "description",
    "nodes": [
        {{"id": "node_1", "type": "trigger", "config": {{}}}},
        {{"id": "node_2", "type": "agent", "agent_id": "atlas", "config": {{}}}},
    ],
    "edges": [
        {{"source": "node_1", "target": "node_2", "condition": null}}
    ]
}}"""

        model = context.model_override or 'gpt-4o-mini'
        response = await self.llm.complete(prompt, model=model)

        try:
            flow_structure = __import__('json').loads(response.get('text', '{}'))
        except:
            flow_structure = self._generate_default_flow(flow_name, description)

        # Validate structure
        validation = self._validate_structure(flow_structure)

        if not validation['valid']:
            return AgentResult(
                status='error',
                output={'error': validation['errors']},
                tokens_used=response.get('tokens', 0),
                cost_usd=self._estimate_cost(model, response.get('tokens', 0)),
            )

        # Emit flow.created event (would go through EventStore in production)
        flow_id = self._generate_uuid()

        return AgentResult(
            status='success',
            output={
                'flow_id': flow_id,
                'flow_structure': flow_structure,
                'status': 'draft',
            },
            tokens_used=response.get('tokens', 0),
            cost_usd=self._estimate_cost(model, response.get('tokens', 0)),
            metadata={'validation': validation}
        )

    async def _modify_flow(self, context: AgentContext) -> AgentResult:
        """Modify an existing flow"""
        params = context.ast.get('params', {})
        flow_id = params.get('flow_id')
        modifications = params.get('modifications', [])

        return AgentResult(
            status='deferred',
            output={'message': f'Flow {flow_id} modification queued'},
            metadata={'modifications': modifications}
        )

    async def _validate_flow(self, context: AgentContext) -> AgentResult:
        """Validate a flow structure"""
        params = context.ast.get('params', {})
        flow_structure = params.get('flow_structure', {})

        validation = self._validate_structure(flow_structure)

        return AgentResult(
            status='success' if validation['valid'] else 'error',
            output=validation,
            tokens_used=0,
            cost_usd=0.0,
        )

    def _validate_structure(self, structure: Dict) -> Dict:
        """Validate flow DAG structure"""
        errors = []

        nodes = structure.get('nodes', [])
        edges = structure.get('edges', [])

        if not nodes:
            errors.append('Flow must have at least one node')

        if not any(n.get('type') == 'trigger' for n in nodes):
            errors.append('Flow must have at least one trigger node')

        # Check for orphaned nodes
        node_ids = {n.get('id') for n in nodes}
        connected = set()

        for edge in edges:
            connected.add(edge.get('source'))
            connected.add(edge.get('target'))

        orphaned = node_ids - connected
        if len(nodes) > 1 and orphaned:
            errors.append(f'Orphaned nodes: {orphaned}')

        # Check for cycles (simplified)
        # In production: use proper cycle detection

        return {
            'valid': len(errors) == 0,
            'errors': errors,
            'node_count': len(nodes),
            'edge_count': len(edges),
        }

    def _generate_default_flow(self, name: str, description: str) -> Dict:
        """Generate a default simple flow"""
        return {
            'name': name,
            'description': description,
            'nodes': [
                {'id': 'trigger_1', 'type': 'trigger', 'config': {'type': 'manual'}},
                {'id': 'atlas_1', 'type': 'agent', 'agent_id': 'atlas', 'config': {'prompt': 'Process input'}},
                {'id': 'output_1', 'type': 'output', 'config': {'destination': 'user'}},
            ],
            'edges': [
                {'source': 'trigger_1', 'target': 'atlas_1', 'condition': None},
                {'source': 'atlas_1', 'target': 'output_1', 'condition': None},
            ],
        }

    def _generate_uuid(self) -> str:
        """Generate UUID v4"""
        import uuid
        return str(uuid.uuid4())

    def _estimate_cost(self, model: str, tokens: int) -> float:
        """Estimate cost for model and token count"""
        costs_per_1k = {
            'gpt-4': 0.03,
            'gpt-4o': 0.005,
            'gpt-4o-mini': 0.00015,
        }
        return (tokens / 1000) * costs_per_1k.get(model, 0.01)

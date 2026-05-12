"""
SpiderNet OS v3.2 - Atlas Agent
Natural Language Compiler: Converts NL commands to AST
"""

import re
from typing import Dict, Any, List, Optional
from core.agent_base import AgentBase, AgentContext, AgentResult


class AtlasAgent(AgentBase):
    """
    Atlas: The Natural Language Compiler Agent
    
    Responsibilities:
    - Parse natural language to Abstract Syntax Trees (AST)
    - Route commands to appropriate agents
    - Maintain conversation context (isolated from UI)
    """
    
    def __init__(self, meta_planner, cost_governor, memory_graph, llm_client):
        super().__init__(
            agent_id='atlas',
            name='Atlas',
            capabilities=['chat', 'parse_command', 'route_intent'],
            meta_planner=meta_planner,
            cost_governor=cost_governor,
            memory_graph=memory_graph,
        )
        self.llm = llm_client
        self.command_patterns = self._compile_patterns()
    
    async def execute(self, context: AgentContext) -> AgentResult:
        """Execute Atlas agent logic"""
        
        start_time = __import__('time').time()
        
        # Retrieve relevant memory
        memories = await self.memory_graph.retrieve(
            tenant_id=context.tenant_id,
            query=context.message,
            agent_id=self.agent_id,
            top_k=3
        )
        
        # Parse command to AST (use LLM if needed, pattern matching for simple cases)
        ast = self._parse_command(context.message, context.ast)
        
        # Route to appropriate agent based on AST type
        if ast['type'] == 'create_flow':
            return await self._route_to_forge(context, ast)
        elif ast['type'] == 'execute':
            return await self._route_to_nexus(context, ast)
        elif ast['type'] == 'query_status':
            return await self._route_to_sentinel(context, ast)
        elif ast['type'] == 'analyze':
            return await self._route_to_prism(context, ast)
        else:
            # General chat - use LLM
            return await self._chat(context, memories)
    
    def _parse_command(
        self,
        message: str,
        pre_parsed_ast: Dict[str, Any]
    ) -> Dict[str, Any]:
        """Parse command to AST (enhance pre-parsed if needed)"""
        
        # Use pre-parsed AST if confident
        if pre_parsed_ast.get('type') != 'chat':
            return pre_parsed_ast
        
        # Try pattern matching
        message_lower = message.lower().strip()
        
        # Flow creation
        if any(p in message_lower for p in ['create flow', 'new flow', 'build flow']):
            name = self._extract_quoted(message) or self._extract_after_keyword(message, 'flow')
            return {
                'type': 'create_flow',
                'params': {'name': name, 'description': message},
            }
        
        # Execution
        if any(p in message_lower for p in ['run ', 'execute ', 'start ']):
            target = self._extract_quoted(message) or self._extract_after_keywords(message, ['run', 'execute', 'start'])
            return {
                'type': 'execute',
                'params': {'target': target},
            }
        
        # Status query
        if any(p in message_lower for p in ['status', 'health', 'how is', 'check']):
            return {
                'type': 'query_status',
                'params': {'scope': 'system'},
            }
        
        # Analysis
        if any(p in message_lower for p in ['analyze', 'review', 'audit']):
            return {
                'type': 'analyze',
                'params': {'scope': self._extract_quoted(message) or 'all'},
            }
        
        # Default: chat
        return {
            'type': 'chat',
            'params': {'message': message},
        }
    
    async def _route_to_forge(
        self,
        context: AgentContext,
        ast: Dict[str, Any]
    ) -> AgentResult:
        """Route flow creation to Forge agent"""
        # Hard Rule #2: Only MetaPlanner can dispatch
        result = await self.meta_planner.dispatch(
            tenant_id=context.tenant_id,
            agent_id='forge',
            intent='create_flow',
            context={
                'session_id': context.session_id,
                'user_id': context.user_id,
                'original_message': context.message,
                'ast': ast,
                'metadata': context.metadata,
            }
        )
        
        return AgentResult(
            status='success' if result['status'] == 'success' else 'error',
            output=result,
            tokens_used=0,
            cost_usd=0.0,
            metadata={'routed_to': 'forge'}
        )
    
    async def _route_to_nexus(
        self,
        context: AgentContext,
        ast: Dict[str, Any]
    ) -> AgentResult:
        """Route execution to Nexus agent"""
        result = await self.meta_planner.dispatch(
            tenant_id=context.tenant_id,
            agent_id='nexus',
            intent='execute_flow',
            context={
                'session_id': context.session_id,
                'user_id': context.user_id,
                'original_message': context.message,
                'ast': ast,
                'metadata': context.metadata,
            }
        )
        
        return AgentResult(
            status='success' if result['status'] == 'success' else 'error',
            output=result,
            tokens_used=0,
            cost_usd=0.0,
            metadata={'routed_to': 'nexus'}
        )
    
    async def _route_to_sentinel(
        self,
        context: AgentContext,
        ast: Dict[str, Any]
    ) -> AgentResult:
        """Route status query to Sentinel agent"""
        result = await self.meta_planner.dispatch(
            tenant_id=context.tenant_id,
            agent_id='sentinel',
            intent='monitor_status',
            context={
                'session_id': context.session_id,
                'user_id': context.user_id,
                'original_message': context.message,
                'ast': ast,
                'metadata': context.metadata,
            }
        )
        
        return AgentResult(
            status='success' if result['status'] == 'success' else 'error',
            output=result,
            tokens_used=0,
            cost_usd=0.0,
            metadata={'routed_to': 'sentinel'}
        )
    
    async def _route_to_prism(
        self,
        context: AgentContext,
        ast: Dict[str, Any]
    ) -> AgentResult:
        """Route analysis to Prism agent"""
        result = await self.meta_planner.dispatch(
            tenant_id=context.tenant_id,
            agent_id='prism',
            intent='analyze_data',
            context={
                'session_id': context.session_id,
                'user_id': context.user_id,
                'original_message': context.message,
                'ast': ast,
                'metadata': context.metadata,
            }
        )
        
        return AgentResult(
            status='success' if result['status'] == 'success' else 'error',
            output=result,
            tokens_used=0,
            cost_usd=0.0,
            metadata={'routed_to': 'prism'}
        )
    
    async def _chat(
        self,
        context: AgentContext,
        memories: List[Dict]
    ) -> AgentResult:
        """General chat response using LLM"""
        
        # Build prompt with memories
        memory_context = '\n'.join([
            f"- {m['content']}" for m in memories[:3]
        ]) if memories else "No relevant memories."
        
        prompt = f"""You are Atlas, the SpiderNet OS assistant. Help the user with their request.

Context from memory:
{memory_context}

User message: {context.message}

Respond helpfully and concisely."""
        
        # Call LLM (with model override if in degraded mode)
        model = context.model_override or 'gemma-4'
        response = await self.llm.complete(prompt, model=model)
        
        # Record cost
        cost = self._estimate_cost(model, response.get('tokens', 0))
        
        return AgentResult(
            status='success',
            output=response.get('text', ''),
            tokens_used=response.get('tokens', 0),
            cost_usd=cost,
            metadata={'model_used': model}
        )
    
    def _compile_patterns(self) -> Dict[str, re.Pattern]:
        """Compile regex patterns for command parsing"""
        return {
            'quoted': re.compile(r'"([^"]+)"'),
            'create_flow': re.compile(r'create\s+(?:a\s+)?(?:new\s+)?flow', re.I),
            'run': re.compile(r'run\s+(.+)', re.I),
        }
    
    def _extract_quoted(self, message: str) -> Optional[str]:
        """Extract quoted string from message"""
        match = self.command_patterns['quoted'].search(message)
        return match.group(1) if match else None
    
    def _extract_after_keyword(self, message: str, keyword: str) -> Optional[str]:
        """Extract text after keyword"""
        pattern = rf'{keyword}\s+(?:called\s+)?(.+?)(?:\s|$|[,.])'
        match = re.search(pattern, message, re.I)
        return match.group(1).strip() if match else None
    
    def _extract_after_keywords(self, message: str, keywords: List[str]) -> Optional[str]:
        """Extract text after any of the keywords"""
        for keyword in keywords:
            result = self._extract_after_keyword(message, keyword)
            if result:
                return result
        return None
    
    def _estimate_cost(self, model: str, tokens: int) -> float:
        """Estimate cost for model and token count"""
        costs_per_1k = {
            'gpt-4': 0.03,
            'gpt-4o': 0.005,
            'gpt-4o-mini': 0.00015,
        }
        return (tokens / 1000) * costs_per_1k.get(model, 0.01)

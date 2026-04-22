"""
SpiderNet OS v3.2 - Hannah Agent
Tutor & Teacher: Educational content and guidance
"""

from typing import Dict, Any, List, Optional
from datetime import datetime
from core.agent_base import AgentBase, AgentContext, AgentResult


class HannahAgent(AgentBase):
    """
    Hannah: The Tutor & Teacher Agent
    
    Responsibilities:
    - Provide educational explanations
    - Guide users through SpiderNet features
    - Answer how-to questions
    - Create learning paths
    """
    
    def __init__(self, meta_planner, cost_governor, memory_graph, llm_client):
        super().__init__(
            agent_id='hannah',
            name='Hannah',
            capabilities=['teaching', 'tutoring', 'guidance', 'explanation', 'onboarding'],
            meta_planner=meta_planner,
            cost_governor=cost_governor,
            memory_graph=memory_graph,
        )
        self.llm = llm_client
        self.knowledge_base = self._load_knowledge_base()
    
    async def execute(self, context: AgentContext) -> AgentResult:
        """Execute Hannah agent logic"""
        
        ast = context.ast
        ast_type = ast.get('type', '')
        message = context.message.lower()
        
        # Intent detection from message
        if ast_type == 'chat' or self._is_how_to_question(message):
            return await self._answer_how_to(context)
        elif self._is_explanation_request(message):
            return await self._provide_explanation(context)
        elif self._is_onboarding_request(message):
            return await self._guide_onboarding(context)
        elif self._is_feature_question(message):
            return await self._explain_feature(context)
        elif ast_type == 'teach':
            return await self._teach_topic(context)
        elif ast_type == 'create_learning_path':
            return await self._create_learning_path(context)
        else:
            return await self._general_help(context)
    
    async def _answer_how_to(self, context: AgentContext) -> AgentResult:
        """Answer how-to questions"""
        
        # Extract topic
        topic = self._extract_topic(context.message)
        
        # Check knowledge base
        kb_entry = self.knowledge_base.get(topic) or self._search_knowledge_base(topic)
        
        if kb_entry:
            # Use knowledge base with LLM enhancement
            prompt = f"""Based on the following information, provide a clear how-to guide:

Topic: {topic}
Knowledge Base: {kb_entry['content']}

User's question: {context.message}

Provide step-by-step instructions in a friendly, helpful tone."""
            
            model = context.model_override or 'gpt-4o-mini'
            response = await self.llm.complete(prompt, model=model)
            
            return AgentResult(
                status='success',
                output={
                    'topic': topic,
                    'answer': response.get('text', kb_entry['content']),
                    'related_topics': kb_entry.get('related', []),
                    'difficulty': kb_entry.get('difficulty', 'beginner'),
                },
                tokens_used=response.get('tokens', 0),
                cost_usd=self._estimate_cost(model, response.get('tokens', 0)),
            )
        
        # Fallback to LLM
        prompt = f"""The user is asking: "{context.message}"

As Hannah, the SpiderNet OS tutor, provide a helpful response explaining how to accomplish this. If you're not certain about specific details, provide general guidance and suggest they consult the documentation."""
        
        model = context.model_override or 'gpt-4o-mini'
        response = await self.llm.complete(prompt, model=model)
        
        return AgentResult(
            status='success',
            output={
                'topic': topic,
                'answer': response.get('text', ''),
                'note': 'Generated response - verify with documentation',
            },
            tokens_used=response.get('tokens', 0),
            cost_usd=self._estimate_cost(model, response.get('tokens', 0)),
        )
    
    async def _provide_explanation(self, context: AgentContext) -> AgentResult:
        """Provide conceptual explanations"""
        
        topic = self._extract_topic(context.message)
        
        prompt = f"""Explain the concept of "{topic}" in the context of SpiderNet OS.

User's question: {context.message}

Provide:
1. A simple definition
2. Why it matters
3. How it fits into the bigger picture
4. An analogy to help understanding

Keep it friendly and educational."""
        
        model = context.model_override or 'gpt-4o-mini'
        response = await self.llm.complete(prompt, model=model)
        
        return AgentResult(
            status='success',
            output={
                'topic': topic,
                'explanation': response.get('text', ''),
                'type': 'conceptual',
            },
            tokens_used=response.get('tokens', 0),
            cost_usd=self._estimate_cost(model, response.get('tokens', 0)),
        )
    
    async def _guide_onboarding(self, context: AgentContext) -> AgentResult:
        """Guide new users through onboarding"""
        
        # Determine onboarding stage
        stage = self._detect_onboarding_stage(context)
        
        onboarding_steps = {
            'welcome': {
                'message': "Welcome to SpiderNet OS! I'm Hannah, your guide. Let me show you around.",
                'next_steps': ['create_first_agent', 'explore_cockpit', 'try_atlas'],
            },
            'create_first_agent': {
                'message': "Let's create your first agent. Think of agents as specialized workers that can help you automate tasks.",
                'action': {
                    'type': 'command',
                    'command': 'create flow "My First Flow"',
                    'description': 'Creates a simple flow to get started',
                },
            },
            'explore_cockpit': {
                'message': "The Cockpit is your command center. Here you can monitor all activity, manage agents, and track usage.",
                'highlight': ['dashboard', 'agent_list', 'usage_graph'],
            },
            'try_atlas': {
                'message': "Atlas is your natural language interface. Try asking me to create something or get status updates.",
                'examples': [
                    'Create a flow for email processing',
                    'Show me system status',
                    'What agents are available?',
                ],
            },
        }
        
        step = onboarding_steps.get(stage, onboarding_steps['welcome'])
        
        return AgentResult(
            status='success',
            output={
                'stage': stage,
                'guidance': step,
                'progress': self._calculate_progress(context),
            },
            tokens_used=0,
            cost_usd=0.0,
        )
    
    async def _explain_feature(self, context: AgentContext) -> AgentResult:
        """Explain a specific feature"""
        
        feature = self._extract_feature(context.message)
        
        feature_docs = {
            'flows': {
                'name': 'Flows',
                'description': 'Visual workflow builder for automating multi-agent processes',
                'key_points': [
                    'Create DAG-based workflows visually',
                    'Connect agent nodes with conditions',
                    'Execute flows on triggers or schedules',
                    'Monitor execution in real-time',
                ],
            },
            'agents': {
                'name': 'Agents',
                'description': 'AI workers with specialized capabilities',
                'key_points': [
                    'Atlas - Natural language compiler',
                    'Forge - Flow builder',
                    'Sentinel - Monitoring',
                    'Prism - Analysis',
                    'Nexus - Execution',
                ],
            },
            'memory': {
                'name': 'Memory Graph',
                'description': 'Hybrid vector + graph memory for context-aware responses',
                'key_points': [
                    'Automatic memory of conversations',
                    'Semantic search across history',
                    'Graph relationships between concepts',
                ],
            },
            'cost_governor': {
                'name': 'Cost Governor',
                'description': 'Budget enforcement and cost optimization',
                'key_points': [
                    'Set daily/monthly spend limits',
                    'Automatic model downgrading when near limit',
                    'Detailed usage tracking and reporting',
                ],
            },
        }
        
        doc = feature_docs.get(feature, {
            'name': feature,
            'description': 'Feature documentation not found',
            'key_points': [],
        })
        
        return AgentResult(
            status='success',
            output=doc,
            tokens_used=0,
            cost_usd=0.0,
        )
    
    async def _teach_topic(self, context: AgentContext) -> AgentResult:
        """Teach a specific topic"""
        
        params = context.ast.get('params', {})
        topic = params.get('topic', self._extract_topic(context.message))
        level = params.get('level', 'beginner')
        
        # Create teaching content
        prompt = f"""Create a lesson about "{topic}" for {level} level users.

Structure:
1. Learning objectives
2. Key concepts (3-5 points)
3. Practical example
4. Try it yourself exercise
5. Further reading suggestions

Make it engaging and educational."""
        
        model = context.model_override or 'gpt-4o-mini'
        response = await self.llm.complete(prompt, model=model)
        
        return AgentResult(
            status='success',
            output={
                'topic': topic,
                'level': level,
                'lesson': response.get('text', ''),
                'estimated_time': '10-15 minutes',
            },
            tokens_used=response.get('tokens', 0),
            cost_usd=self._estimate_cost(model, response.get('tokens', 0)),
        )
    
    async def _create_learning_path(self, context: AgentContext) -> AgentResult:
        """Create a personalized learning path"""
        
        params = context.ast.get('params', {})
        goal = params.get('goal', 'master_spidernet')
        experience = params.get('experience', 'beginner')
        
        paths = {
            'master_spidernet': {
                'beginner': [
                    {'step': 1, 'topic': 'Introduction to SpiderNet', 'duration': '15m'},
                    {'step': 2, 'topic': 'Your First Agent', 'duration': '20m'},
                    {'step': 3, 'topic': 'Building Flows', 'duration': '30m'},
                    {'step': 4, 'topic': 'Understanding Memory', 'duration': '20m'},
                    {'step': 5, 'topic': 'Advanced Orchestration', 'duration': '45m'},
                ],
                'intermediate': [
                    {'step': 1, 'topic': 'Multi-Agent Workflows', 'duration': '30m'},
                    {'step': 2, 'topic': 'Custom Integrations', 'duration': '45m'},
                    {'step': 3, 'topic': 'Cost Optimization', 'duration': '20m'},
                ],
            },
        }
        
        path = paths.get(goal, {}).get(experience, [])
        
        return AgentResult(
            status='success',
            output={
                'goal': goal,
                'experience_level': experience,
                'steps': path,
                'total_duration': self._calculate_total_duration(path),
                'next_step': path[0] if path else None,
            },
            tokens_used=0,
            cost_usd=0.0,
        )
    
    async def _general_help(self, context: AgentContext) -> AgentResult:
        """Provide general help"""
        
        prompt = f"""The user said: "{context.message}"

As Hannah, the SpiderNet OS tutor, provide a helpful, friendly response. If they're asking about something specific, guide them toward the right feature or agent. If it's a general greeting, be welcoming and offer suggestions of what they can do."""
        
        model = context.model_override or 'gpt-4o-mini'
        response = await self.llm.complete(prompt, model=model)
        
        return AgentResult(
            status='success',
            output={
                'response': response.get('text', ''),
                'suggested_commands': [
                    'Hannah, how do I create a flow?',
                    'Hannah, explain agents',
                    'Hannah, start tutorial',
                ],
            },
            tokens_used=response.get('tokens', 0),
            cost_usd=self._estimate_cost(model, response.get('tokens', 0)),
        )
    
    def _is_how_to_question(self, message: str) -> bool:
        """Detect how-to questions"""
        patterns = [
            'how do i', 'how to', 'how can i', "how'd i",
            'steps to', 'guide for', 'tutorial for',
        ]
        return any(p in message for p in patterns)
    
    def _is_explanation_request(self, message: str) -> bool:
        """Detect explanation requests"""
        patterns = [
            'what is', 'what are', 'explain', 'tell me about',
            'how does', 'how is', 'meaning of', 'concept of',
        ]
        return any(p in message for p in patterns)
    
    def _is_onboarding_request(self, message: str) -> bool:
        """Detect onboarding requests"""
        patterns = [
            'getting started', 'start', 'onboard', 'new user',
            'first time', 'introduction', 'tutorial', 'help me begin',
        ]
        return any(p in message for p in patterns)
    
    def _is_feature_question(self, message: str) -> bool:
        """Detect feature questions"""
        patterns = [
            'what does', 'feature', 'functionality', 'capability',
            'can it', 'does it', 'flows?', 'agents?', 'memory?',
        ]
        return any(p in message for p in patterns)
    
    def _extract_topic(self, message: str) -> str:
        """Extract topic from message"""
        # Simple extraction - remove question words
        words = message.lower().split()
        stop_words = {'how', 'to', 'do', 'i', 'what', 'is', 'the', 'a', 'an', 'in', 'of', 'for', 'can', 'explain'}
        topic_words = [w for w in words if w not in stop_words and len(w) > 2]
        return ' '.join(topic_words[:3]) if topic_words else 'general'
    
    def _extract_feature(self, message: str) -> str:
        """Extract feature name from message"""
        features = ['flows', 'agents', 'memory', 'cost_governor', 'atlas', 'cockpit']
        for feature in features:
            if feature in message.lower().replace(' ', '_'):
                return feature
        return 'general'
    
    def _detect_onboarding_stage(self, context: AgentContext) -> str:
        """Detect user's onboarding stage"""
        # Check session context for previous interactions
        session_context = context.metadata.get('session_context', [])
        
        if not session_context:
            return 'welcome'
        
        # Analyze previous messages
        previous = ' '.join(session_context).lower()
        
        if 'flow' in previous or 'create' in previous:
            return 'explore_cockpit'
        if 'agent' in previous:
            return 'try_atlas'
        
        return 'welcome'
    
    def _calculate_progress(self, context: AgentContext) -> Dict:
        """Calculate onboarding progress"""
        # Simple progress tracking
        return {
            'steps_completed': 0,
            'total_steps': 4,
            'percentage': 0,
        }
    
    def _search_knowledge_base(self, topic: str) -> Optional[Dict]:
        """Search knowledge base for topic"""
        # Simple keyword matching
        for key, value in self.knowledge_base.items():
            if key in topic or topic in key:
                return value
        return None
    
    def _load_knowledge_base(self) -> Dict[str, Dict]:
        """Load internal knowledge base"""
        return {
            'create flow': {
                'content': 'To create a flow: 1) Open Cockpit, 2) Navigate to Flows, 3) Click "Create Flow", 4) Add nodes and edges, 5) Publish.',
                'difficulty': 'beginner',
                'related': ['flows', 'nodes', 'agents'],
            },
            'agents': {
                'content': 'Agents are specialized AI workers. Each has capabilities: Atlas (NL), Forge (flows), Sentinel (monitoring), Prism (analysis), Nexus (execution).',
                'difficulty': 'beginner',
                'related': ['capabilities', 'permissions'],
            },
            'cost': {
                'content': 'Cost Governor tracks spending and enforces budgets. Set limits in tenant settings. Near limit? System auto-downgrades to cheaper models.',
                'difficulty': 'intermediate',
                'related': ['budget', 'models', 'usage'],
            },
            'memory': {
                'content': 'Memory Graph stores conversations and facts. Hybrid retrieval: vector similarity + graph traversal. Automatically remembers important information.',
                'difficulty': 'intermediate',
                'related': ['retrieval', 'context', 'embeddings'],
            },
            'meta planner': {
                'content': 'MetaPlanner is the sole decision authority. All agent dispatch goes through it. Agents cannot call other agents directly - prevents sprawl.',
                'difficulty': 'advanced',
                'related': ['architecture', 'hard_rules'],
            },
        }
    
    def _calculate_total_duration(self, path: List[Dict]) -> str:
        """Calculate total duration of learning path"""
        total_minutes = 0
        for step in path:
            duration = step.get('duration', '0m')
            if 'm' in duration:
                total_minutes += int(duration.replace('m', ''))
            elif 'h' in duration:
                total_minutes += int(duration.replace('h', '')) * 60
        
        hours = total_minutes // 60
        minutes = total_minutes % 60
        
        if hours > 0:
            return f"{hours}h {minutes}m"
        return f"{minutes}m"
    
    def _estimate_cost(self, model: str, tokens: int) -> float:
        """Estimate cost for model and token count"""
        costs_per_1k = {
            'gpt-4': 0.03,
            'gpt-4o': 0.005,
            'gpt-4o-mini': 0.00015,
        }
        return (tokens / 1000) * costs_per_1k.get(model, 0.01)

"""
SpiderNet OS v3.2 - Hermes Agent
Communication & Integration Hub: The Messenger of the Gods

Hermes is the communication orchestrator that unifies all interaction modalities
(voice, text, video, email, chat, APIs) and provides natural conversational
interfaces across channels. Acts as the central nervous system for human-AI
collaboration, coordinating complex workflows across specialized agents.

Key Capabilities:
- Multi-modal communication handling (voice, text, video, email, chat, APIs)
- Natural conversational interfaces with context preservation
- External system integration and API orchestration
- Complex workflow coordination across existing agents
- Communication pattern learning via RL infrastructure
- Multi-tenant conversation management with isolation
"""

import asyncio
import json
import logging
from dataclasses import dataclass, field
from datetime import datetime, timedelta
from enum import Enum
from typing import Any, Callable, Dict, List, Optional

import aiohttp
from core.agent_base import AgentBase, AgentContext, AgentResult
from core.deepseek_client import get_deepseek_client

logger = logging.getLogger(__name__)


class CommunicationChannel(Enum):
    """Supported communication channels"""
    VOICE = "voice"
    TEXT = "text"
    VIDEO = "video"
    EMAIL = "email"
    CHAT = "chat"
    API = "api"
    WEBHOOK = "webhook"
    SMS = "sms"


class ConversationState(Enum):
    """Conversation flow states"""
    INITIATING = "initiating"
    ACTIVE = "active"
    WAITING_RESPONSE = "waiting_response"
    COORDINATING = "coordinating"
    ESCALATING = "escalating"
    COMPLETING = "completing"
    TERMINATED = "terminated"


@dataclass
class ConversationContext:
    """Multi-tenant conversation context with channel abstraction"""
    conversation_id: str
    tenant_id: str
    user_id: str
    channel: CommunicationChannel
    channel_metadata: Dict[str, Any] = field(default_factory=dict)
    state: ConversationState = ConversationState.INITIATING
    start_time: datetime = field(default_factory=datetime.utcnow)
    last_activity: datetime = field(default_factory=datetime.utcnow)
    message_history: List[Dict[str, Any]] = field(default_factory=list)
    active_workflows: List[str] = field(default_factory=list)
    context_variables: Dict[str, Any] = field(default_factory=dict)
    sentiment_score: float = 0.0
    urgency_level: str = "normal"
    language: str = "en"


@dataclass
class IntegrationEndpoint:
    """External system integration configuration"""
    name: str
    type: str  # api, webhook, database, messaging
    config: Dict[str, Any]
    auth_method: str
    rate_limits: Dict[str, int]
    retry_policy: Dict[str, Any]


class HermesAgent(AgentBase):
    """
    Hermes: The Communication & Integration Agent

    Responsibilities:
    - Handle multi-modal communications (voice, text, video, email, chat, APIs)
    - Provide natural conversational interfaces with context preservation
    - Integrate external systems and APIs seamlessly
    - Orchestrate complex workflows across specialized agents
    - Learn communication patterns using RL infrastructure
    - Manage multi-tenant conversations with isolation
    """

    def __init__(self, meta_planner, cost_governor, memory_graph, db_pool, llm_client):
        super().__init__(
            agent_id='hermes',
            name='Hermes',
            capabilities=[
                'communication_orchestration',
                'multi_modal_interaction',
                'external_integration',
                'workflow_coordination',
                'conversation_management',
                'sentiment_analysis',
                'language_detection'
            ],
            meta_planner=meta_planner,
            cost_governor=cost_governor,
            memory_graph=memory_graph,
        )

        self.db = db_pool
        self.llm = llm_client
        self.deepseek = get_deepseek_client()

        # Communication handlers
        self.channel_handlers: Dict[CommunicationChannel, Callable] = {
            CommunicationChannel.VOICE: self._handle_voice,
            CommunicationChannel.TEXT: self._handle_text,
            CommunicationChannel.VIDEO: self._handle_video,
            CommunicationChannel.EMAIL: self._handle_email,
            CommunicationChannel.CHAT: self._handle_chat,
            CommunicationChannel.API: self._handle_api,
            CommunicationChannel.WEBHOOK: self._handle_webhook,
            CommunicationChannel.SMS: self._handle_sms,
        }

        # Integration endpoints
        self.integrations: Dict[str, IntegrationEndpoint] = {}

        # Conversation management
        self.active_conversations: Dict[str, ConversationContext] = {}
        self.conversation_timeout = timedelta(hours=24)

        # Communication patterns (learned via RL)
        self.patterns_cache: Dict[str, Any] = {}

        # Initialize integrations
        self._load_integrations()

    async def execute(self, context: AgentContext) -> AgentResult:
        """Main execution entry point for Hermes"""

        start_time = datetime.utcnow()

        try:
            # Extract communication context
            comm_context = await self._extract_communication_context(context)

            # Route to appropriate channel handler
            handler = self.channel_handlers.get(comm_context.channel)
            if not handler:
                return AgentResult(
                    status='error',
                    output={'error': f'Unsupported channel: {comm_context.channel}'},
                    tokens_used=0,
                    cost_usd=0.0,
                    execution_time_ms=(datetime.utcnow() - start_time).total_seconds() * 1000
                )

            # Handle the communication
            result = await handler(comm_context, context)

            # Update conversation state
            await self._update_conversation_state(comm_context)

            # Learn from interaction (RL feedback)
            await self._learn_from_interaction(comm_context, result)

            return result

        except Exception as e:
            logger.error(f"Hermes execution error: {e}")
            return AgentResult(
                status='error',
                output={'error': str(e)},
                tokens_used=0,
                cost_usd=0.0,
                execution_time_ms=(datetime.utcnow() - start_time).total_seconds() * 1000
            )

    async def _extract_communication_context(self, context: AgentContext) -> ConversationContext:
        """Extract communication context from agent context"""

        # Parse channel from metadata or message content
        channel = self._detect_channel(context)

        # Extract or create conversation ID
        conversation_id = context.metadata.get('conversation_id')
        if not conversation_id:
            conversation_id = f"conv_{context.tenant_id}_{context.session_id}_{int(datetime.utcnow().timestamp())}"

        # Get or create conversation context
        if conversation_id not in self.active_conversations:
            self.active_conversations[conversation_id] = ConversationContext(
                conversation_id=conversation_id,
                tenant_id=context.tenant_id,
                user_id=context.user_id or "anonymous",
                channel=channel,
                channel_metadata=context.metadata
            )

        conv_ctx = self.active_conversations[conversation_id]

        # Update activity
        conv_ctx.last_activity = datetime.utcnow()
        conv_ctx.message_history.append({
            'timestamp': datetime.utcnow().isoformat(),
            'direction': 'incoming',
            'content': context.message,
            'metadata': context.metadata
        })

        return conv_ctx

    def _detect_channel(self, context: AgentContext) -> CommunicationChannel:
        """Detect communication channel from context"""

        # Check explicit channel in metadata
        channel_str = context.metadata.get('channel', '').lower()
        if channel_str in [c.value for c in CommunicationChannel]:
            return CommunicationChannel(channel_str)

        # Infer from message content or metadata
        message = context.message.lower()

        if context.metadata.get('voice_call_sid'):
            return CommunicationChannel.VOICE
        elif context.metadata.get('email_from'):
            return CommunicationChannel.EMAIL
        elif context.metadata.get('webhook_source'):
            return CommunicationChannel.WEBHOOK
        elif context.metadata.get('api_request'):
            return CommunicationChannel.API
        elif 'video' in message or context.metadata.get('video_url'):
            return CommunicationChannel.VIDEO
        elif context.metadata.get('sms_sid'):
            return CommunicationChannel.SMS
        elif context.metadata.get('chat_room') or 'chat' in message:
            return CommunicationChannel.CHAT
        else:
            return CommunicationChannel.TEXT

    # Channel Handlers

    async def _handle_voice(self, conv_ctx: ConversationContext, agent_ctx: AgentContext) -> AgentResult:
        """Handle voice communication"""

        # Extract voice-specific data
        transcript = conv_ctx.channel_metadata.get('transcript', '')
        voice_features = conv_ctx.channel_metadata.get('voice_features', {})

        # Use VoiceAgent for voice processing, then coordinate
        voice_result = await self._delegate_to_agent('voice', agent_ctx)

        # Enhance with conversation context
        enhanced_response = await self._enhance_response_with_context(
            voice_result.output, conv_ctx, 'voice'
        )

        return AgentResult(
            status='success',
            output={
                'response': enhanced_response,
                'channel': 'voice',
                'conversation_id': conv_ctx.conversation_id
            },
            tokens_used=voice_result.tokens_used,
            cost_usd=voice_result.cost_usd,
            execution_time_ms=voice_result.execution_time_ms
        )

    async def _handle_text(self, conv_ctx: ConversationContext, agent_ctx: AgentContext) -> AgentResult:
        """Handle text communication"""

        # Analyze sentiment and intent
        sentiment = await self._analyze_sentiment(agent_ctx.message)
        intent = await self._detect_intent(agent_ctx.message, conv_ctx)

        conv_ctx.sentiment_score = sentiment
        conv_ctx.context_variables['last_intent'] = intent

        # Route based on intent and conversation state
        if intent == 'complex_workflow':
            response = await self._coordinate_workflow(conv_ctx, agent_ctx)
        elif intent == 'external_integration':
            response = await self._handle_integration_request(conv_ctx, agent_ctx)
        elif intent == 'multi_agent_coordination':
            response = await self._coordinate_agents(conv_ctx, agent_ctx)
        else:
            # Use Atlas for natural language processing
            response = await self._delegate_to_agent('atlas', agent_ctx)

        return AgentResult(
            status='success',
            output={
                'response': response,
                'channel': 'text',
                'sentiment': sentiment,
                'intent': intent,
                'conversation_id': conv_ctx.conversation_id
            },
            tokens_used=50,  # Estimate
            cost_usd=0.001,
            execution_time_ms=150
        )

    async def _handle_email(self, conv_ctx: ConversationContext, agent_ctx: AgentContext) -> AgentResult:
        """Handle email communication"""

        # Parse email content
        email_data = conv_ctx.channel_metadata
        subject = email_data.get('subject', '')
        body = email_data.get('body', agent_ctx.message)

        # Analyze email intent and priority
        priority = await self._analyze_email_priority(subject, body)
        intent = await self._detect_email_intent(subject, body)

        # Route to appropriate workflow
        if priority == 'urgent':
            response = await self._handle_urgent_email(conv_ctx, agent_ctx)
        elif intent == 'support_request':
            response = await self._handle_support_email(conv_ctx, agent_ctx)
        else:
            response = await self._handle_general_email(conv_ctx, agent_ctx)

        # Send response via email
        await self._send_email_response(
            email_data.get('from'),
            f"Re: {subject}",
            response
        )

        return AgentResult(
            status='success',
            output={
                'response': response,
                'channel': 'email',
                'priority': priority,
                'intent': intent,
                'conversation_id': conv_ctx.conversation_id
            },
            tokens_used=100,
            cost_usd=0.002,
            execution_time_ms=500
        )

    async def _handle_api(self, conv_ctx: ConversationContext, agent_ctx: AgentContext) -> AgentResult:
        """Handle API/webhook communication"""

        # Parse API request
        api_data = conv_ctx.channel_metadata
        method = api_data.get('method', 'POST')
        endpoint = api_data.get('endpoint', '')
        payload = api_data.get('payload', {})

        # Route to appropriate integration
        integration_name = self._find_integration_for_endpoint(endpoint)
        if integration_name:
            response = await self._execute_integration(integration_name, method, endpoint, payload)
        else:
            response = await self._handle_unknown_api_request(conv_ctx, agent_ctx)

        return AgentResult(
            status='success',
            output={
                'response': response,
                'channel': 'api',
                'integration': integration_name,
                'conversation_id': conv_ctx.conversation_id
            },
            tokens_used=25,
            cost_usd=0.0005,
            execution_time_ms=50
        )

    async def _handle_webhook(self, conv_ctx: ConversationContext, agent_ctx: AgentContext) -> AgentResult:
        """Handle webhook events"""

        webhook_data = conv_ctx.channel_metadata
        event_type = webhook_data.get('event_type', '')
        source = webhook_data.get('source', '')

        # Process webhook based on source and event type
        if source == 'stripe':
            response = await self._handle_stripe_webhook(event_type, webhook_data)
        elif source == 'github':
            response = await self._handle_github_webhook(event_type, webhook_data)
        elif source == 'twilio':
            response = await self._handle_twilio_webhook(event_type, webhook_data)
        else:
            response = await self._handle_generic_webhook(conv_ctx, webhook_data)

        return AgentResult(
            status='success',
            output={
                'response': response,
                'channel': 'webhook',
                'event_type': event_type,
                'source': source,
                'conversation_id': conv_ctx.conversation_id
            },
            tokens_used=10,
            cost_usd=0.0002,
            execution_time_ms=25
        )

    # Coordination Methods

    async def _coordinate_workflow(self, conv_ctx: ConversationContext, agent_ctx: AgentContext) -> str:
        """Coordinate complex workflow across multiple agents"""

        # Use DeepSeek to understand workflow requirements
        workflow_analysis = await self.deepseek.analyze_workflow_requirements(
            agent_ctx.message, conv_ctx.message_history
        )

        # Create workflow using Forge agent
        forge_result = await self._delegate_to_agent('forge', AgentContext(
            tenant_id=conv_ctx.tenant_id,
            session_id=conv_ctx.conversation_id,
            user_id=conv_ctx.user_id,
            message=f"Create workflow: {workflow_analysis['description']}",
            ast={'type': 'create_workflow', 'spec': workflow_analysis},
            metadata={'coordinated_by': 'hermes'}
        ))

        # Execute workflow using Nexus agent
        if forge_result.status == 'success':
            workflow_id = forge_result.output.get('workflow_id')
            conv_ctx.active_workflows.append(workflow_id)

            nexus_result = await self._delegate_to_agent('nexus', AgentContext(
                tenant_id=conv_ctx.tenant_id,
                session_id=conv_ctx.conversation_id,
                user_id=conv_ctx.user_id,
                message=f"Execute workflow {workflow_id}",
                ast={'type': 'execute_flow', 'workflow_id': workflow_id},
                metadata={'coordinated_by': 'hermes'}
            ))

            return f"I've initiated a workflow to {workflow_analysis['description']}. The workflow ID is {workflow_id}. I'll coordinate the execution across our specialized agents."

        return "I encountered an issue creating the workflow. Let me connect you with our workflow specialist."

    async def _coordinate_agents(self, conv_ctx: ConversationContext, agent_ctx: AgentContext) -> str:
        """Coordinate multiple agents for complex tasks"""

        # Analyze which agents are needed
        agent_analysis = await self.deepseek.analyze_agent_coordination_needs(
            agent_ctx.message, conv_ctx.message_history
        )

        required_agents = agent_analysis.get('required_agents', [])
        coordination_plan = agent_analysis.get('coordination_plan', '')

        # Execute coordination plan
        results = []
        for agent_id in required_agents:
            result = await self._delegate_to_agent(agent_id, agent_ctx)
            results.append(f"{agent_id}: {result.output}")

        # Synthesize response
        synthesis = await self.llm.generate_completion(
            prompt=f"Synthesize coordinated response from agents: {results}",
            max_tokens=200
        )

        return f"Coordinating across {len(required_agents)} specialized agents: {synthesis}"

    # Integration Methods

    def _load_integrations(self):
        """Load external system integrations"""
        # This would load from database or config
        self.integrations = {
            'stripe': IntegrationEndpoint(
                name='stripe',
                type='api',
                config={'api_key': 'sk_test_...', 'webhook_secret': 'whsec_...'},
                auth_method='bearer',
                rate_limits={'requests_per_minute': 100},
                retry_policy={'max_attempts': 3, 'backoff_factor': 2}
            ),
            'twilio': IntegrationEndpoint(
                name='twilio',
                type='api',
                config={'account_sid': '...', 'auth_token': '...'},
                auth_method='basic',
                rate_limits={'requests_per_minute': 50},
                retry_policy={'max_attempts': 2, 'backoff_factor': 1.5}
            ),
            'slack': IntegrationEndpoint(
                name='slack',
                type='webhook',
                config={'webhook_url': 'https://hooks.slack.com/...'},
                auth_method='none',
                rate_limits={'requests_per_minute': 1},
                retry_policy={'max_attempts': 3, 'backoff_factor': 2}
            )
        }

    async def _execute_integration(self, integration_name: str, method: str,
                                 endpoint: str, payload: Dict[str, Any]) -> Dict[str, Any]:
        """Execute external system integration"""

        if integration_name not in self.integrations:
            return {'error': f'Integration {integration_name} not configured'}

        integration = self.integrations[integration_name]

        # Rate limiting check
        if not await self._check_rate_limit(integration_name, integration.rate_limits):
            return {'error': 'Rate limit exceeded'}

        try:
            # Execute based on integration type
            if integration.type == 'api':
                return await self._execute_api_integration(integration, method, endpoint, payload)
            elif integration.type == 'webhook':
                return await self._execute_webhook_integration(integration, payload)
            else:
                return {'error': f'Unsupported integration type: {integration.type}'}

        except Exception as e:
            # Retry logic
            retry_config = integration.retry_policy
            for attempt in range(retry_config['max_attempts']):
                try:
                    await asyncio.sleep(attempt * retry_config['backoff_factor'])
                    # Retry logic here
                    break
                except Exception:
                    continue

            return {'error': f'Integration failed after retries: {str(e)}'}

    async def _execute_api_integration(self, integration: IntegrationEndpoint,
                                     method: str, endpoint: str, payload: Dict[str, Any]) -> Dict[str, Any]:
        """Execute API integration"""

        headers = {}
        if integration.auth_method == 'bearer':
            headers['Authorization'] = f"Bearer {integration.config['api_key']}"

        async with aiohttp.ClientSession() as session:
            async with session.request(method, endpoint, json=payload, headers=headers) as response:
                return {
                    'status_code': response.status,
                    'data': await response.json() if response.content_type == 'application/json' else await response.text()
                }

    # Learning and Adaptation

    async def _learn_from_interaction(self, conv_ctx: ConversationContext, result: AgentResult):
        """Learn from communication patterns using RL"""

        # Extract learning signal
        learning_data = {
            'conversation_id': conv_ctx.conversation_id,
            'channel': conv_ctx.channel.value,
            'sentiment': conv_ctx.sentiment_score,
            'intent': conv_ctx.context_variables.get('last_intent', ''),
            'success': result.status == 'success',
            'response_quality': await self._evaluate_response_quality(result.output),
            'user_satisfaction': await self._estimate_user_satisfaction(conv_ctx),
            'timestamp': datetime.utcnow().isoformat()
        }

        # Store in memory for RL training
        await self.memory_graph.store(
            tenant_id=conv_ctx.tenant_id,
            content=json.dumps(learning_data),
            metadata={
                'agent_id': self.agent_id,
                'learning_type': 'communication_pattern',
                'conversation_id': conv_ctx.conversation_id
            }
        )

        # Update patterns cache
        pattern_key = f"{conv_ctx.channel.value}_{conv_ctx.context_variables.get('last_intent', '')}"
        self.patterns_cache[pattern_key] = learning_data

    async def _enhance_response_with_context(self, base_response: str,
                                           conv_ctx: ConversationContext,
                                           channel: str) -> str:
        """Enhance response with conversation context and learned patterns"""

        # Get conversation history
        history = conv_ctx.message_history[-5:]  # Last 5 messages

        # Find similar patterns
        similar_patterns = await self._find_similar_patterns(conv_ctx)

        # Use DeepSeek to enhance response
        enhancement_prompt = f"""
        Enhance this response for a {channel} interaction:

        Base Response: {base_response}

        Conversation History: {json.dumps(history, indent=2)}

        Similar Patterns: {json.dumps(similar_patterns, indent=2)}

        Channel: {channel}
        Sentiment: {conv_ctx.sentiment_score}
        Context: {json.dumps(conv_ctx.context_variables, indent=2)}

        Provide an enhanced, contextually appropriate response.
        """

        enhanced = await self.deepseek.generate_completion(
            prompt=enhancement_prompt,
            max_tokens=150
        )

        return enhanced.strip()

    # Utility Methods

    async def _delegate_to_agent(self, agent_id: str, context: AgentContext) -> AgentResult:
        """Delegate to another agent through MetaPlanner"""
        return await self.meta_planner.dispatch_to_agent(agent_id, context)

    async def _analyze_sentiment(self, text: str) -> float:
        """Analyze sentiment of text (-1 to 1)"""
        # Use LLM for sentiment analysis
        prompt = f"Analyze sentiment of this text on a scale of -1 (very negative) to 1 (very positive): {text}"
        response = await self.llm.generate_completion(prompt, max_tokens=10)
        try:
            return float(response.strip())
        except:
            return 0.0

    async def _detect_intent(self, text: str, context: ConversationContext) -> str:
        """Detect user intent"""
        intents = ['simple_query', 'complex_workflow', 'external_integration',
                  'multi_agent_coordination', 'learning_request', 'support_request']

        # Use conversation history and current message to determine intent
        analysis_prompt = f"""
        Analyze this message and conversation context to determine the user's intent:

        Message: {text}
        History: {json.dumps(context.message_history[-3:], indent=2)}
        Current State: {context.state.value}

        Possible intents: {', '.join(intents)}

        Return only the most likely intent.
        """

        intent = await self.llm.generate_completion(analysis_prompt, max_tokens=20)
        return intent.strip().lower()

    def _find_integration_for_endpoint(self, endpoint: str) -> Optional[str]:
        """Find integration that handles this endpoint"""
        for name, integration in self.integrations.items():
            if integration.type == 'api' and 'base_url' in integration.config:
                if endpoint.startswith(integration.config['base_url']):
                    return name
        return None

    async def _check_rate_limit(self, integration_name: str, limits: Dict[str, int]) -> bool:
        """Check if integration rate limit allows request"""
        # Simple in-memory rate limiting (would use Redis in production)
        # This is a simplified implementation
        return True

    async def _update_conversation_state(self, conv_ctx: ConversationContext):
        """Update conversation state and cleanup old conversations"""

        # Update state based on activity
        if conv_ctx.state == ConversationState.INITIATING:
            conv_ctx.state = ConversationState.ACTIVE

        # Cleanup old conversations
        cutoff_time = datetime.utcnow() - self.conversation_timeout
        expired_conversations = [
            conv_id for conv_id, conv in self.active_conversations.items()
            if conv.last_activity < cutoff_time
        ]

        for conv_id in expired_conversations:
            conv = self.active_conversations[conv_id]
            conv.state = ConversationState.TERMINATED
            # Archive conversation to database
            await self._archive_conversation(conv)
            del self.active_conversations[conv_id]

    async def _archive_conversation(self, conv_ctx: ConversationContext):
        """Archive completed conversation to database"""
        # Store conversation summary in database
        await self.db.execute("""
            INSERT INTO conversation_history
            (conversation_id, tenant_id, user_id, channel, start_time, end_time,
             message_count, final_state, sentiment_avg, duration_minutes)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)
        """,
        conv_ctx.conversation_id,
        conv_ctx.tenant_id,
        conv_ctx.user_id,
        conv_ctx.channel.value,
        conv_ctx.start_time,
        datetime.utcnow(),
        len(conv_ctx.message_history),
        conv_ctx.state.value,
        conv_ctx.sentiment_score,
        (datetime.utcnow() - conv_ctx.start_time).total_seconds() / 60
        )

    # Placeholder implementations for other methods
    async def _handle_video(self, conv_ctx, agent_ctx): pass
    async def _handle_chat(self, conv_ctx, agent_ctx): pass
    async def _handle_sms(self, conv_ctx, agent_ctx): pass
    async def _handle_urgent_email(self, conv_ctx, agent_ctx): pass
    async def _handle_support_email(self, conv_ctx, agent_ctx): pass
    async def _handle_general_email(self, conv_ctx, agent_ctx): pass
    async def _handle_unknown_api_request(self, conv_ctx, agent_ctx): pass
    async def _handle_stripe_webhook(self, event_type, data): pass
    async def _handle_github_webhook(self, event_type, data): pass
    async def _handle_twilio_webhook(self, event_type, data): pass
    async def _handle_generic_webhook(self, conv_ctx, data): pass
    async def _send_email_response(self, to_email, subject, body): pass
    async def _analyze_email_priority(self, subject, body): return "normal"
    async def _detect_email_intent(self, subject, body): return "general"
    async def _evaluate_response_quality(self, response): return 0.8
    async def _estimate_user_satisfaction(self, conv_ctx): return 0.7
    async def _find_similar_patterns(self, conv_ctx): return []

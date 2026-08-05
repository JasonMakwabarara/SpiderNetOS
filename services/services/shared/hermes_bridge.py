"""
SpiderNetOS Hermes Integration Bridge
Connects the external Hermes Agent with SpiderNetOS agent coordination
"""

import asyncio
import json
import logging
from typing import Dict, Any, List, Optional
from datetime import datetime
import aiohttp
from fastapi import APIRouter, HTTPException, BackgroundTasks
from pydantic import BaseModel

from core.meta_planner import MetaPlanner
from services.shared.memory_graph import MemoryGraph

logger = logging.getLogger(__name__)

# Configuration
HERMES_AGENT_URL = "http://hermes-agent:8080"
LEARNING_SYNC_INTERVAL = 900  # 15 minutes

class HermesIntegrationBridge:
    """
    Bridge between external Hermes Agent and SpiderNetOS.
    Handles coordination requests, learning sync, and communication routing.
    """

    def __init__(self, meta_planner: MetaPlanner, memory_graph: MemoryGraph):
        self.meta_planner = meta_planner
        self.memory_graph = memory_graph
        self.session: Optional[aiohttp.ClientSession] = None
        self.learning_sync_task: Optional[asyncio.Task] = None

    async def initialize(self):
        """Initialize the Hermes integration bridge."""
        self.session = aiohttp.ClientSession(
            base_url=HERMES_AGENT_URL,
            timeout=aiohttp.ClientTimeout(total=30)
        )

        # Start learning synchronization
        self.learning_sync_task = asyncio.create_task(self._learning_sync_loop())

        logger.info("Hermes integration bridge initialized")

    async def shutdown(self):
        """Shutdown the integration bridge."""
        if self.learning_sync_task:
            self.learning_sync_task.cancel()
            try:
                await self.learning_sync_task
            except asyncio.CancelledError:
                pass

        if self.session:
            await self.session.close()

        logger.info("Hermes integration bridge shutdown")

    async def coordinate_request(self, hermes_request: Dict[str, Any]) -> Dict[str, Any]:
        """
        Process coordination request from Hermes Agent.

        Args:
            hermes_request: Request from Hermes containing message, context, etc.

        Returns:
            Coordination result with response and metadata
        """

        try:
            # Extract coordination requirements
            message = hermes_request.get('message', '')
            channel = hermes_request.get('channel', 'unknown')
            conversation_id = hermes_request.get('conversation_id', '')
            user_context = hermes_request.get('user_context', {})

            # Analyze intent and requirements
            intent_analysis = await self._analyze_intent(message, hermes_request)

            # Route to appropriate SpiderNetOS coordination
            if intent_analysis['type'] == 'complex_workflow':
                result = await self._coordinate_complex_workflow(
                    message, intent_analysis, channel, conversation_id
                )
            elif intent_analysis['type'] == 'multi_agent_coordination':
                result = await self._coordinate_multiple_agents(
                    message, intent_analysis, channel, conversation_id
                )
            elif intent_analysis['type'] == 'external_integration':
                result = await self._handle_external_integration(
                    message, intent_analysis, channel, conversation_id
                )
            else:
                # Simple routing to single agent
                result = await self._route_to_single_agent(
                    message, intent_analysis, channel, conversation_id
                )

            # Add learning signals
            result['learning_signals'] = await self._generate_learning_signals(
                hermes_request, result, intent_analysis
            )

            return result

        except Exception as e:
            logger.error(f"Hermes coordination error: {e}")
            return {
                'status': 'error',
                'response': 'I encountered an issue coordinating with the SpiderNetOS agents. Please try again.',
                'error': str(e),
                'learning_signals': {}
            }

    async def _analyze_intent(self, message: str, request_context: Dict[str, Any]) -> Dict[str, Any]:
        """
        Analyze the user's intent to determine coordination requirements.
        """

        # Use conversation history and message content to determine intent
        conversation_history = request_context.get('conversation_history', [])

        # Simple intent classification (would use LLM in production)
        message_lower = message.lower()

        if any(word in message_lower for word in ['workflow', 'process', 'orchestrate', 'coordinate']):
            intent_type = 'complex_workflow'
        elif any(word in message_lower for word in ['analyze', 'data', 'research', 'find']):
            intent_type = 'multi_agent_coordination'
        elif any(word in message_lower for word in ['integrate', 'connect', 'api', 'webhook']):
            intent_type = 'external_integration'
        else:
            intent_type = 'single_agent_routing'

        # Determine required agents based on intent
        required_agents = self._map_intent_to_agents(intent_type, message_lower)

        return {
            'type': intent_type,
            'required_agents': required_agents,
            'complexity': len(required_agents),
            'confidence': 0.8,  # Placeholder
            'context_variables': self._extract_context_variables(message, conversation_history)
        }

    def _map_intent_to_agents(self, intent_type: str, message: str) -> List[str]:
        """Map intent type to required SpiderNetOS agents."""

        agent_mappings = {
            'complex_workflow': ['nexus', 'atlas'],  # Execution + NL understanding
            'multi_agent_coordination': ['prism', 'sentinel', 'atlas'],  # Analysis + monitoring + NL
            'external_integration': ['nexus', 'dynamic'],  # Execution + flexible handling
            'single_agent_routing': ['atlas']  # NL processing only
        }

        base_agents = agent_mappings.get(intent_type, ['atlas'])

        # Add specialized agents based on message content
        if 'analyze' in message or 'data' in message:
            if 'prism' not in base_agents:
                base_agents.append('prism')

        if 'generate' in message or 'create' in message:
            if 'forge' not in base_agents:
                base_agents.append('forge')

        if 'monitor' in message or 'health' in message:
            if 'sentinel' not in base_agents:
                base_agents.append('sentinel')

        return base_agents

    def _extract_context_variables(self, message: str, history: List[Dict]) -> Dict[str, Any]:
        """Extract context variables from message and history."""

        variables = {}

        # Extract urgency indicators
        urgent_words = ['urgent', 'asap', 'emergency', 'critical', 'immediately']
        variables['urgency'] = any(word in message.lower() for word in urgent_words)

        # Extract complexity indicators
        complex_indicators = ['complex', 'multiple', 'coordinate', 'orchestrate', 'workflow']
        variables['complexity'] = any(word in message.lower() for word in complex_indicators)

        # Extract user preferences from history
        if history:
            # Simple preference extraction (would use ML in production)
            preferred_channels = set()
            for interaction in history[-5:]:  # Last 5 interactions
                if 'channel' in interaction:
                    preferred_channels.add(interaction['channel'])

            variables['preferred_channels'] = list(preferred_channels)

        return variables

    async def _coordinate_complex_workflow(self, message: str, intent_analysis: Dict,
                                         channel: str, conversation_id: str) -> Dict[str, Any]:
        """Coordinate complex multi-agent workflows."""

        required_agents = intent_analysis['required_agents']

        # Create workflow specification
        workflow_spec = {
            'name': f'hermes_coordination_{conversation_id}',
            'description': message,
            'agents': required_agents,
            'channel': channel,
            'conversation_id': conversation_id
        }

        # Use Nexus agent to execute the workflow
        nexus_result = await self.meta_planner.dispatch_to_agent('nexus', {
            'tenant_id': 'system',  # Hermes coordination
            'session_id': conversation_id,
            'message': f'Execute complex workflow: {message}',
            'ast': {
                'type': 'execute_flow',
                'workflow_spec': workflow_spec
            },
            'metadata': {
                'coordinated_by': 'hermes',
                'channel': channel,
                'required_agents': required_agents
            }
        })

        return {
            'status': nexus_result.status,
            'response': self._format_workflow_response(nexus_result, required_agents),
            'agents_used': required_agents,
            'workflow_id': nexus_result.output.get('workflow_id') if nexus_result.output else None,
            'execution_time': getattr(nexus_result, 'execution_time_ms', 0)
        }

    async def _coordinate_multiple_agents(self, message: str, intent_analysis: Dict,
                                        channel: str, conversation_id: str) -> Dict[str, Any]:
        """Coordinate multiple agents for a single request."""

        required_agents = intent_analysis['required_agents']
        results = []

        # Dispatch to each required agent
        for agent_id in required_agents:
            result = await self.meta_planner.dispatch_to_agent(agent_id, {
                'tenant_id': 'system',
                'session_id': conversation_id,
                'message': message,
                'ast': {'type': 'process_request'},
                'metadata': {
                    'coordinated_by': 'hermes',
                    'channel': channel,
                    'coordination_context': {
                        'total_agents': len(required_agents),
                        'agent_position': len(results) + 1
                    }
                }
            })
            results.append({
                'agent': agent_id,
                'result': result
            })

        # Synthesize coordinated response
        synthesized_response = await self._synthesize_agent_responses(results, message)

        return {
            'status': 'success',
            'response': synthesized_response,
            'agents_used': required_agents,
            'individual_results': results,
            'coordination_type': 'parallel'
        }

    async def _handle_external_integration(self, message: str, intent_analysis: Dict,
                                         channel: str, conversation_id: str) -> Dict[str, Any]:
        """Handle external system integration requests."""

        # Use Dynamic agent for flexible external integrations
        dynamic_result = await self.meta_planner.dispatch_to_agent('dynamic', {
            'tenant_id': 'system',
            'session_id': conversation_id,
            'message': f'Handle external integration: {message}',
            'ast': {'type': 'external_integration'},
            'metadata': {
                'coordinated_by': 'hermes',
                'channel': channel,
                'integration_requirements': intent_analysis
            }
        })

        return {
            'status': dynamic_result.status,
            'response': dynamic_result.output or 'External integration processed.',
            'integration_type': 'dynamic',
            'agents_used': ['dynamic']
        }

    async def _route_to_single_agent(self, message: str, intent_analysis: Dict,
                                   channel: str, conversation_id: str) -> Dict[str, Any]:
        """Route to a single appropriate agent."""

        agent_id = intent_analysis['required_agents'][0] if intent_analysis['required_agents'] else 'atlas'

        result = await self.meta_planner.dispatch_to_agent(agent_id, {
            'tenant_id': 'system',
            'session_id': conversation_id,
            'message': message,
            'ast': {'type': 'chat'},
            'metadata': {
                'coordinated_by': 'hermes',
                'channel': channel
            }
        })

        return {
            'status': result.status,
            'response': result.output or 'Request processed.',
            'agents_used': [agent_id],
            'routing_type': 'single_agent'
        }

    def _format_workflow_response(self, nexus_result: Any, agents: List[str]) -> str:
        """Format workflow execution response."""

        if nexus_result.status == 'success':
            workflow_id = nexus_result.output.get('workflow_id', 'unknown') if nexus_result.output else 'unknown'
            return f"I've initiated a coordinated workflow using {len(agents)} specialized agents. The workflow ID is {workflow_id}. I'll monitor the execution and provide updates."

        return "I encountered an issue starting the coordinated workflow. Let me try a different approach."

    async def _synthesize_agent_responses(self, results: List[Dict], original_message: str) -> str:
        """Synthesize responses from multiple agents."""

        # Simple synthesis (would use LLM in production)
        successful_results = [r for r in results if r['result'].status == 'success']

        if not successful_results:
            return "None of the coordinated agents were able to process this request successfully."

        # Combine responses
        combined_response = f"I've coordinated with {len(successful_results)} specialized agents to address your request:\n\n"

        for i, result_data in enumerate(successful_results, 1):
            agent = result_data['agent']
            response = result_data['result'].output or f"Agent {agent} processed the request."
            combined_response += f"{i}. **{agent.title()}**: {response}\n"

        return combined_response

    async def _generate_learning_signals(self, request: Dict, result: Dict,
                                       intent_analysis: Dict) -> Dict[str, Any]:
        """Generate learning signals for RL training."""

        return {
            'conversation_id': request.get('conversation_id', ''),
            'channel': request.get('channel', 'unknown'),
            'intent_type': intent_analysis.get('type', 'unknown'),
            'agents_coordinated': result.get('agents_used', []),
            'successful_outcome': result.get('status') == 'success',
            'response_quality': self._evaluate_response_quality(result),
            'coordination_complexity': len(result.get('agents_used', [])),
            'user_satisfaction': request.get('user_satisfaction', 0.5),
            'timestamp': datetime.utcnow().isoformat()
        }

    def _evaluate_response_quality(self, result: Dict) -> float:
        """Evaluate the quality of the coordination response."""

        if result.get('status') != 'success':
            return 0.2

        agents_used = result.get('agents_used', [])
        if len(agents_used) > 2:
            # Reward complex coordination
            return 0.9
        elif len(agents_used) > 0:
            return 0.7
        else:
            return 0.5

    async def _learning_sync_loop(self):
        """Background loop to sync learning data with Hermes."""

        while True:
            try:
                await self._sync_learning_data()
            except Exception as e:
                logger.error(f"Learning sync error: {e}")

            await asyncio.sleep(LEARNING_SYNC_INTERVAL)

    async def _sync_learning_data(self):
        """Sync accumulated learning data with Hermes Agent."""

        # Fetch learning data from SpiderNetOS memory
        learning_entries = await self.memory_graph.retrieve(
            tenant_id='system',
            query='learning_type:communication_pattern',
            agent_id='hermes_bridge',
            top_k=50
        )

        if not learning_entries:
            return

        # Format for Hermes
        hermes_learning_data = {
            'source': 'spidernet',
            'learning_type': 'communication_pattern',
            'entries': learning_entries,
            'sync_timestamp': datetime.utcnow().isoformat()
        }

        # Send to Hermes
        if self.session:
            try:
                async with self.session.post(
                    '/api/learning/sync',
                    json=hermes_learning_data
                ) as response:
                    if response.status == 200:
                        logger.info(f"Successfully synced {len(learning_entries)} learning entries with Hermes")
                    else:
                        logger.error(f"Hermes learning sync failed: {response.status}")
            except Exception as e:
                logger.error(f"Hermes learning sync error: {e}")

# Global bridge instance
hermes_bridge: Optional[HermesIntegrationBridge] = None

async def get_hermes_bridge() -> HermesIntegrationBridge:
    """Get or create the Hermes integration bridge."""
    global hermes_bridge
    if hermes_bridge is None:
        # Initialize with dependencies (would be injected in real implementation)
        hermes_bridge = HermesIntegrationBridge(None, None)  # Placeholder
        await hermes_bridge.initialize()
    return hermes_bridge
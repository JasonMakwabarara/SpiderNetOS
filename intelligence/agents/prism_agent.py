"""
SpiderNet OS v3.2 - Prism Agent
Analysis & Research: Data processing and insight generation
"""

from typing import Dict, Any, List, Optional
from datetime import datetime, timedelta
import json
import re
from collections import Counter
from core.agent_base import AgentBase, AgentContext, AgentResult


class PrismAgent(AgentBase):
    """
    Prism: The Analysis & Research Agent
    
    Responsibilities:
    - Analyze data and generate insights
    - Research and summarize information
    - Pattern recognition and trend analysis
    - Report generation
    """
    
    def __init__(self, meta_planner, cost_governor, memory_graph, llm_client):
        super().__init__(
            agent_id='prism',
            name='Prism',
            capabilities=['data_analysis', 'research', 'summarization', 'pattern_recognition'],
            meta_planner=meta_planner,
            cost_governor=cost_governor,
            memory_graph=memory_graph,
        )
        self.llm = llm_client
    
    async def execute(self, context: AgentContext) -> AgentResult:
        """Execute Prism agent logic"""
        
        ast = context.ast
        ast_type = ast.get('type', '')
        
        if ast_type == 'analyze':
            return await self._analyze_data(context)
        elif ast_type == 'research':
            return await self._research_topic(context)
        elif ast_type == 'summarize':
            return await self._summarize_content(context)
        elif ast_type == 'generate_report':
            return await self._generate_report(context)
        elif ast_type == 'analyze_data':
            return await self._analyze_data(context)
        else:
            return await self._general_analysis(context)
    
    async def _analyze_data(self, context: AgentContext) -> AgentResult:
        """Analyze data from various sources"""
        
        params = context.ast.get('params', {})
        data_source = params.get('source', 'events')
        analysis_type = params.get('analysis_type', 'summary')
        
        # Retrieve relevant memory
        memories = await self.memory_graph.retrieve(
            tenant_id=context.tenant_id,
            query=f"analyze {data_source} {analysis_type}",
            agent_id=self.agent_id,
            top_k=5
        )
        
        # Gather data
        data = await self._gather_data(context.tenant_id, data_source)
        
        # Perform analysis
        if analysis_type == 'summary':
            result = self._generate_summary_stats(data)
        elif analysis_type == 'trends':
            result = self._analyze_trends(data)
        elif analysis_type == 'correlation':
            result = self._analyze_correlations(data)
        elif analysis_type == 'patterns':
            result = self._identify_patterns(data)
        else:
            result = self._generate_summary_stats(data)
        
        # Enhance with LLM if not in degraded mode
        if not context.degraded_mode and len(data) > 10:
            insights = await self._generate_llm_insights(context, data, result)
            result['llm_insights'] = insights
        
        return AgentResult(
            status='success',
            output={
                'analysis_type': analysis_type,
                'data_source': data_source,
                'data_points': len(data),
                'results': result,
                'memory_context': len(memories),
                'timestamp': datetime.utcnow().isoformat(),
            },
            tokens_used=result.get('tokens_used', 0),
            cost_usd=result.get('cost_usd', 0.0),
            metadata={'analysis_depth': analysis_type}
        )
    
    async def _research_topic(self, context: AgentContext) -> AgentResult:
        """Research a topic using memory and available data"""
        
        params = context.ast.get('params', {})
        topic = params.get('topic', context.message)
        
        # Search memory graph
        memories = await self.memory_graph.retrieve(
            tenant_id=context.tenant_id,
            query=topic,
            agent_id=self.agent_id,
            top_k=10,
            depth=3
        )
        
        # Search events
        events = await self._search_events(context.tenant_id, topic)
        
        # Synthesize findings
        findings = self._synthesize_findings(memories, events, topic)
        
        # Generate research summary with LLM
        if not context.degraded_mode:
            summary = await self._generate_research_summary(context, findings, topic)
        else:
            summary = findings['key_points']
        
        return AgentResult(
            status='success',
            output={
                'topic': topic,
                'memory_sources': len(memories),
                'event_sources': len(events),
                'findings': findings,
                'summary': summary,
                'confidence': findings.get('confidence', 'medium'),
            },
            tokens_used=summary.get('tokens', 0) if isinstance(summary, dict) else 0,
            cost_usd=summary.get('cost', 0.0) if isinstance(summary, dict) else 0.0,
        )
    
    async def _summarize_content(self, context: AgentContext) -> AgentResult:
        """Summarize content from memory or provided data"""
        
        params = context.ast.get('params', {})
        content = params.get('content', context.message)
        max_length = params.get('max_length', 200)
        
        # Use LLM for summarization
        prompt = f"""Summarize the following content in {max_length} words or less:

{content}

Provide a concise summary capturing the key points."""
        
        model = context.model_override or 'gpt-4o-mini'
        response = await self.llm.complete(prompt, model=model)
        
        summary = response.get('text', 'No summary generated')
        
        return AgentResult(
            status='success',
            output={
                'original_length': len(content),
                'summary': summary,
                'max_length': max_length,
            },
            tokens_used=response.get('tokens', 0),
            cost_usd=self._estimate_cost(model, response.get('tokens', 0)),
            metadata={'model': model}
        )
    
    async def _generate_report(self, context: AgentContext) -> AgentResult:
        """Generate comprehensive report"""
        
        params = context.ast.get('params', {})
        report_type = params.get('report_type', 'general')
        time_range = params.get('time_range', '7d')
        
        # Gather data based on report type
        if report_type == 'usage':
            data = await self._gather_usage_data(context.tenant_id, time_range)
        elif report_type == 'performance':
            data = await self._gather_performance_data(context.tenant_id, time_range)
        elif report_type == 'anomalies':
            data = await self._gather_anomaly_data(context.tenant_id, time_range)
        else:
            data = await self._gather_general_data(context.tenant_id, time_range)
        
        # Generate report sections
        report = {
            'title': f"{report_type.title()} Report",
            'time_range': time_range,
            'generated_at': datetime.utcnow().isoformat(),
            'executive_summary': self._generate_executive_summary(data),
            'detailed_analysis': data,
            'recommendations': self._generate_recommendations(data),
        }
        
        return AgentResult(
            status='success',
            output=report,
            tokens_used=0,
            cost_usd=0.0,
        )
    
    async def _general_analysis(self, context: AgentContext) -> AgentResult:
        """General analysis of system state"""
        
        # Retrieve recent activity
        memories = await self.memory_graph.retrieve(
            tenant_id=context.tenant_id,
            query="recent activity system state",
            agent_id=self.agent_id,
            top_k=10
        )
        
        # Analyze patterns
        activity_patterns = self._analyze_activity_patterns(memories)
        
        return AgentResult(
            status='success',
            output={
                'activity_patterns': activity_patterns,
                'recent_memories': len(memories),
                'system_state': 'active' if memories else 'idle',
            },
            tokens_used=0,
            cost_usd=0.0,
        )
    
    async def _gather_data(
        self,
        tenant_id: str,
        source: str
    ) -> List[Dict]:
        """Gather data from specified source"""
        # In production: query from database
        # Return mock data
        return [
            {'timestamp': datetime.utcnow().isoformat(), 'value': 100},
            {'timestamp': (datetime.utcnow() - timedelta(hours=1)).isoformat(), 'value': 95},
        ]
    
    def _generate_summary_stats(self, data: List[Dict]) -> Dict:
        """Generate summary statistics"""
        if not data:
            return {'error': 'No data available'}
        
        values = [d.get('value', 0) for d in data]
        
        return {
            'count': len(data),
            'mean': sum(values) / len(values),
            'min': min(values),
            'max': max(values),
            'range': max(values) - min(values),
        }
    
    def _analyze_trends(self, data: List[Dict]) -> Dict:
        """Analyze trends in data"""
        if len(data) < 2:
            return {'error': 'Insufficient data for trend analysis'}
        
        values = [d.get('value', 0) for d in data]
        
        # Simple trend detection
        first_half = sum(values[:len(values)//2]) / (len(values)//2)
        second_half = sum(values[len(values)//2:]) / (len(values) - len(values)//2)
        
        trend = 'increasing' if second_half > first_half else 'decreasing' if second_half < first_half else 'stable'
        
        return {
            'trend': trend,
            'first_half_avg': first_half,
            'second_half_avg': second_half,
            'change_pct': ((second_half - first_half) / first_half * 100) if first_half else 0,
        }
    
    def _analyze_correlations(self, data: List[Dict]) -> Dict:
        """Analyze correlations in data"""
        # Simplified correlation analysis
        return {'status': 'correlation analysis requires multi-variate data'}
    
    def _identify_patterns(self, data: List[Dict]) -> Dict:
        """Identify patterns in data"""
        # Extract patterns from values
        values = [d.get('value', 0) for d in data]
        
        # Detect repeating values
        value_counts = Counter(values)
        repeats = {v: c for v, c in value_counts.items() if c > 1}
        
        return {
            'repeating_values': repeats,
            'unique_values': len(value_counts),
            'pattern_types': ['repeat'] if repeats else ['none'],
        }
    
    async def _generate_llm_insights(
        self,
        context: AgentContext,
        data: List[Dict],
        analysis: Dict
    ) -> Dict:
        """Generate insights using LLM"""
        
        prompt = f"""Analyze the following data and provide key insights:

Data Points: {len(data)}
Analysis Results: {json.dumps(analysis, indent=2)}

Provide 3-5 key insights in a bulleted list."""
        
        model = context.model_override or 'gpt-4o-mini'
        response = await self.llm.complete(prompt, model=model)
        
        return {
            'insights': response.get('text', ''),
            'tokens': response.get('tokens', 0),
            'cost': self._estimate_cost(model, response.get('tokens', 0)),
        }
    
    async def _search_events(
        self,
        tenant_id: str,
        query: str
    ) -> List[Dict]:
        """Search event log"""
        # In production: query event_log
        return []
    
    def _synthesize_findings(
        self,
        memories: List[Dict],
        events: List[Dict],
        topic: str
    ) -> Dict:
        """Synthesize research findings"""
        
        # Extract key points
        key_points = []
        for m in memories[:5]:
            content = m.get('content', '')
            # Extract sentences containing topic keywords
            sentences = re.split(r'[.!?]+', content)
            for sent in sentences:
                if any(kw in sent.lower() for kw in topic.lower().split()):
                    key_points.append(sent.strip())
        
        # Determine confidence
        confidence = 'high' if len(memories) > 5 else 'medium' if len(memories) > 2 else 'low'
        
        return {
            'topic': topic,
            'key_points': key_points[:5],
            'sources': len(memories) + len(events),
            'confidence': confidence,
        }
    
    async def _generate_research_summary(
        self,
        context: AgentContext,
        findings: Dict,
        topic: str
    ) -> Dict:
        """Generate research summary using LLM"""
        
        prompt = f"""Based on the following findings about "{topic}", provide a comprehensive summary:

Key Points:
{chr(10).join(findings['key_points'])}

Confidence: {findings['confidence']}

Provide a structured summary with: 1) Overview, 2) Key Findings, 3) Recommendations."""
        
        model = context.model_override or 'gpt-4o-mini'
        response = await self.llm.complete(prompt, model=model)
        
        return {
            'summary': response.get('text', ''),
            'tokens': response.get('tokens', 0),
            'cost': self._estimate_cost(model, response.get('tokens', 0)),
        }
    
    async def _gather_usage_data(
        self,
        tenant_id: str,
        time_range: str
    ) -> Dict:
        """Gather usage data"""
        return {'range': time_range, 'metrics': {}}
    
    async def _gather_performance_data(
        self,
        tenant_id: str,
        time_range: str
    ) -> Dict:
        """Gather performance data"""
        return {'range': time_range, 'metrics': {}}
    
    async def _gather_anomaly_data(
        self,
        tenant_id: str,
        time_range: str
    ) -> Dict:
        """Gather anomaly data"""
        return {'range': time_range, 'anomalies': []}
    
    async def _gather_general_data(
        self,
        tenant_id: str,
        time_range: str
    ) -> Dict:
        """Gather general system data"""
        return {'range': time_range, 'overview': {}}
    
    def _generate_executive_summary(self, data: Dict) -> str:
        """Generate executive summary"""
        return "Executive summary generated from available data."
    
    def _generate_recommendations(self, data: Dict) -> List[str]:
        """Generate recommendations"""
        return ["Continue monitoring system metrics."]
    
    def _analyze_activity_patterns(self, memories: List[Dict]) -> Dict:
        """Analyze activity patterns from memories"""
        if not memories:
            return {'status': 'no_recent_activity'}
        
        # Count by type
        types = Counter(m.get('node_type', 'unknown') for m in memories)
        
        return {
            'total_activities': len(memories),
            'by_type': dict(types),
            'most_common': types.most_common(1)[0] if types else None,
        }
    
    def _estimate_cost(self, model: str, tokens: int) -> float:
        """Estimate cost for model and token count"""
        costs_per_1k = {
            'gpt-4': 0.03,
            'gpt-4o': 0.005,
            'gpt-4o-mini': 0.00015,
        }
        return (tokens / 1000) * costs_per_1k.get(model, 0.01)

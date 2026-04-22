"""
SpiderNet OS v3.2 - Sentinel Agent
Monitoring & Anomaly Detection: Apex kernel integration
"""

from typing import Dict, Any, List, Optional
from datetime import datetime, timedelta
import json
from core.agent_base import AgentBase, AgentContext, AgentResult


class SentinelAgent(AgentBase):
    """
    Sentinel: The Monitoring & Anomaly Detection Agent
    
    Responsibilities:
    - Monitor system health and metrics
    - Detect anomalies using statistical and ML methods
    - Trigger alerts and remediation flows
    - Apex kernel integration for observability
    """
    
    def __init__(self, meta_planner, cost_governor, memory_graph, db_pool):
        super().__init__(
            agent_id='sentinel',
            name='Sentinel',
            capabilities=['monitoring', 'anomaly_detection', 'health_checks', 'alerting'],
            meta_planner=meta_planner,
            cost_governor=cost_governor,
            memory_graph=memory_graph,
        )
        self.db = db_pool
        self.anomaly_threshold = 2.5  # Standard deviations
        self.check_interval_seconds = 60
    
    async def execute(self, context: AgentContext) -> AgentResult:
        """Execute Sentinel agent logic"""
        
        ast = context.ast
        ast_type = ast.get('type', '')
        
        if ast_type == 'query_status':
            return await self._check_system_health(context)
        elif ast_type == 'analyze':
            return await self._analyze_anomalies(context)
        elif ast_type == 'monitor_status':
            return await self._monitor_scope(context)
        elif ast_type == 'detect_anomalies':
            return await self._detect_anomalies_batch(context)
        else:
            return await self._general_health_summary(context)
    
    async def _check_system_health(self, context: AgentContext) -> AgentResult:
        """Check overall system health"""
        
        tenant_id = context.tenant_id
        
        # Gather health metrics
        metrics = await self._gather_metrics(tenant_id)
        
        # Check for anomalies
        anomalies = await self._detect_anomalies(tenant_id, metrics)
        
        # Assess health status
        health_status = self._assess_health(metrics, anomalies)
        
        # Store trace
        trace_id = await self._store_trace(tenant_id, metrics, anomalies, health_status)
        
        return AgentResult(
            status='success',
            output={
                'health_status': health_status,
                'metrics': metrics,
                'anomalies_detected': len(anomalies),
                'anomaly_details': anomalies[:5],  # Top 5
                'trace_id': trace_id,
                'timestamp': datetime.utcnow().isoformat(),
            },
            tokens_used=0,
            cost_usd=0.0,
            metadata={'checks_performed': len(metrics)}
        )
    
    async def _analyze_anomalies(self, context: AgentContext) -> AgentResult:
        """Analyze specific anomalies in detail"""
        
        params = context.ast.get('params', {})
        scope = params.get('scope', 'recent')
        
        # Query anomaly database
        anomalies = await self._fetch_anomalies(context.tenant_id, scope)
        
        # Analyze patterns
        patterns = self._identify_patterns(anomalies)
        
        # Generate recommendations
        recommendations = self._generate_recommendations(patterns)
        
        return AgentResult(
            status='success',
            output={
                'anomalies': anomalies,
                'patterns': patterns,
                'recommendations': recommendations,
                'scope': scope,
            },
            tokens_used=0,
            cost_usd=0.0,
        )
    
    async def _monitor_scope(self, context: AgentContext) -> AgentResult:
        """Monitor specific scope (agent, flow, resource)"""
        
        params = context.ast.get('params', {})
        scope = params.get('scope', 'system')
        
        if scope == 'system':
            return await self._check_system_health(context)
        
        # Monitor specific resource
        resource_type, resource_id = self._parse_scope(scope)
        
        metrics = await self._gather_resource_metrics(
            context.tenant_id,
            resource_type,
            resource_id
        )
        
        return AgentResult(
            status='success',
            output={
                'scope': scope,
                'resource_type': resource_type,
                'resource_id': resource_id,
                'metrics': metrics,
                'status': 'healthy' if not metrics.get('errors') else 'degraded',
            },
            tokens_used=0,
            cost_usd=0.0,
        )
    
    async def _detect_anomalies_batch(self, context: AgentContext) -> AgentResult:
        """Batch anomaly detection across all metrics"""
        
        tenant_id = context.tenant_id
        
        # Historical baseline
        baseline = await self._calculate_baseline(tenant_id, days=7)
        
        # Current metrics
        current = await self._gather_metrics(tenant_id)
        
        # Detect anomalies
        detected = []
        for metric_name, current_value in current.items():
            if metric_name in baseline:
                mean = baseline[metric_name]['mean']
                std = baseline[metric_name]['std']
                
                if std > 0:
                    z_score = abs(current_value - mean) / std
                    if z_score > self.anomaly_threshold:
                        detected.append({
                            'metric': metric_name,
                            'value': current_value,
                            'expected': mean,
                            'z_score': z_score,
                            'severity': 'high' if z_score > 3 else 'medium',
                        })
        
        # Store anomalies
        for anomaly in detected:
            await self._store_anomaly(tenant_id, anomaly)
        
        # Trigger alerts if critical
        critical = [a for a in detected if a['severity'] == 'high']
        if critical:
            await self._trigger_alert(tenant_id, critical)
        
        return AgentResult(
            status='success',
            output={
                'anomalies_detected': len(detected),
                'anomalies': detected,
                'critical_count': len(critical),
                'baseline_metrics': len(baseline),
            },
            tokens_used=0,
            cost_usd=0.0,
        )
    
    async def _general_health_summary(self, context: AgentContext) -> AgentResult:
        """Provide general health summary"""
        
        # Quick health check
        health = await self._check_system_health(context)
        
        # Add summary
        output = health.output
        output['summary'] = self._generate_summary(output)
        
        return AgentResult(
            status=health.status,
            output=output,
            tokens_used=health.tokens_used,
            cost_usd=health.cost_usd,
            metadata=health.metadata,
        )
    
    async def _gather_metrics(self, tenant_id: str) -> Dict[str, float]:
        """Gather system metrics"""
        # In production: query from metrics store
        # For now: return synthetic metrics
        return {
            'api_latency_p95': 120.0,
            'api_error_rate': 0.02,
            'db_connection_pool_usage': 0.45,
            'redis_memory_usage': 0.30,
            'queue_depth': 15.0,
            'agent_execution_rate': 45.0,
            'cost_per_hour': 2.50,
            'memory_retrieval_latency': 45.0,
        }
    
    async def _gather_resource_metrics(
        self,
        tenant_id: str,
        resource_type: str,
        resource_id: str
    ) -> Dict[str, Any]:
        """Gather metrics for specific resource"""
        # Query resource-specific metrics
        return {
            'resource_type': resource_type,
            'resource_id': resource_id,
            'uptime_seconds': 86400,
            'error_count': 0,
            'success_rate': 0.99,
        }
    
    async def _detect_anomalies(
        self,
        tenant_id: str,
        metrics: Dict[str, float]
    ) -> List[Dict]:
        """Detect anomalies in metrics"""
        anomalies = []
        
        # Threshold-based checks
        thresholds = {
            'api_error_rate': 0.05,
            'db_connection_pool_usage': 0.80,
            'redis_memory_usage': 0.85,
            'queue_depth': 100,
        }
        
        for metric, threshold in thresholds.items():
            if metric in metrics and metrics[metric] > threshold:
                anomalies.append({
                    'metric': metric,
                    'value': metrics[metric],
                    'threshold': threshold,
                    'type': 'threshold_exceeded',
                })
        
        return anomalies
    
    def _assess_health(
        self,
        metrics: Dict[str, float],
        anomalies: List[Dict]
    ) -> str:
        """Assess overall health status"""
        if not anomalies:
            return 'healthy'
        
        critical = any(
            a.get('type') == 'threshold_exceeded' and 
            a['metric'] in ['api_error_rate', 'db_connection_pool_usage']
            for a in anomalies
        )
        
        return 'critical' if critical else 'degraded'
    
    async def _calculate_baseline(
        self,
        tenant_id: str,
        days: int = 7
    ) -> Dict[str, Dict]:
        """Calculate statistical baseline from historical data"""
        # In production: query time-series data
        # Return mock baseline
        return {
            'api_latency_p95': {'mean': 100.0, 'std': 20.0},
            'api_error_rate': {'mean': 0.01, 'std': 0.005},
            'db_connection_pool_usage': {'mean': 0.40, 'std': 0.10},
            'redis_memory_usage': {'mean': 0.25, 'std': 0.05},
            'queue_depth': {'mean': 10.0, 'std': 5.0},
        }
    
    async def _store_trace(
        self,
        tenant_id: str,
        metrics: Dict,
        anomalies: List,
        health_status: str
    ) -> str:
        """Store observability trace"""
        import uuid
        trace_id = str(uuid.uuid4())[:16]
        
        # In production: INSERT INTO traces
        # await self.db.execute(
        #     """INSERT INTO traces (id, tenant_id, dag_id, status, metadata)
        #        VALUES ($1, $2, $3, $4, $5)""",
        #     trace_id, tenant_id, None, health_status,
        #     json.dumps({'metrics': metrics, 'anomalies': anomalies})
        # )
        
        return trace_id
    
    async def _store_anomaly(self, tenant_id: str, anomaly: Dict) -> None:
        """Store detected anomaly"""
        # In production: INSERT INTO anomalies
        pass
    
    async def _fetch_anomalies(
        self,
        tenant_id: str,
        scope: str
    ) -> List[Dict]:
        """Fetch historical anomalies"""
        # In production: query anomalies table
        return []
    
    async def _trigger_alert(
        self,
        tenant_id: str,
        anomalies: List[Dict]
    ) -> None:
        """Trigger alert for critical anomalies"""
        # Emit alert event
        await self.meta_planner.dispatch(
            tenant_id=tenant_id,
            agent_id='atlas',
            intent='alert',
            context={
                'alert_type': 'anomaly',
                'severity': 'critical',
                'anomalies': anomalies,
            }
        )
    
    def _identify_patterns(self, anomalies: List[Dict]) -> List[Dict]:
        """Identify patterns in anomalies"""
        if not anomalies:
            return []
        
        # Simple pattern: grouping by metric type
        by_metric = {}
        for a in anomalies:
            metric = a.get('metric', 'unknown')
            by_metric.setdefault(metric, []).append(a)
        
        patterns = []
        for metric, items in by_metric.items():
            if len(items) > 2:
                patterns.append({
                    'type': 'recurring_metric',
                    'metric': metric,
                    'occurrences': len(items),
                    'trend': 'increasing' if items[-1]['value'] > items[0]['value'] else 'stable',
                })
        
        return patterns
    
    def _generate_recommendations(self, patterns: List[Dict]) -> List[str]:
        """Generate recommendations based on patterns"""
        recommendations = []
        
        for pattern in patterns:
            if pattern['type'] == 'recurring_metric':
                recommendations.append(
                    f"Investigate recurring {pattern['metric']} issues ({pattern['occurrences']} occurrences)"
                )
        
        return recommendations
    
    def _generate_summary(self, output: Dict) -> str:
        """Generate human-readable summary"""
        status = output.get('health_status', 'unknown')
        anomalies = output.get('anomalies_detected', 0)
        
        if status == 'healthy':
            return "System is operating normally. All metrics within expected ranges."
        elif status == 'degraded':
            return f"System experiencing minor issues. {anomalies} anomalies detected."
        else:
            return f"Critical issues detected. {anomalies} anomalies require immediate attention."
    
    def _parse_scope(self, scope: str) -> tuple:
        """Parse scope string into type and id"""
        # Parse formats: "agent:123", "flow:456", "resource:789"
        if ':' in scope:
            parts = scope.split(':', 1)
            return parts[0], parts[1]
        return 'system', scope

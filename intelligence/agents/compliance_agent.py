"""
SpiderNetOS Financial OS — Fraud Detection & Compliance Agent

Monitors transactions for suspicious activity, enforces KYC/AML
compliance, and manages financial alerts.
"""

import json
from datetime import datetime, timedelta

from core.agent_base import AgentBase, AgentContext, AgentResult


class ComplianceAgent(AgentBase):
    """
    ComplianceAgent: The Financial Compliance & Fraud Detection Engine

    Responsibilities:
    - Monitor transactions for fraud patterns
    - KYC/AML compliance screening
    - Transaction risk scoring
    - Alert management and escalation
    - Audit trail verification
    - Regulatory compliance checks
    """

    def __init__(self, meta_planner, cost_governor, memory_graph, db_pool):
        super().__init__(
            agent_id='compliance',
            name='Compliance',
            capabilities=[
                'transaction-monitoring',
                'fraud-detection',
                'kyc-screening',
                'audit-trail',
                'alert-management',
                'risk-scoring',
            ],
            meta_planner=meta_planner,
            cost_governor=cost_governor,
            memory_graph=memory_graph,
        )
        self.db = db_pool

        self.fraud_thresholds = {
            'large_transaction': 10000,
            'high_frequency_per_hour': 5,
            'new_counterparty_amount': 1000,
            'unusual_hours_start': 2,
            'unusual_hours_end': 5,
            'max_risk_score': 50,
        }

    async def execute(self, context: AgentContext) -> AgentResult:
        ast = context.ast
        ast_type = ast.get('type', '')

        handlers = {
            'screen_transaction': self._screen_transaction,
            'detect_fraud': self._detect_fraud,
            'check_compliance': self._check_compliance,
            'audit_trail': self._audit_trail,
            'manage_alerts': self._manage_alerts,
            'risk_report': self._risk_report,
            'kyc_check': self._kyc_check,
        }

        handler = handlers.get(ast_type, self._risk_report)
        return await handler(context)

    async def _screen_transaction(self, context: AgentContext) -> AgentResult:
        params = context.ast.get('params', {})
        tenant_id = context.tenant_id

        transaction_id = params.get('transaction_id')
        amount = float(params.get('amount', 0))
        method = params.get('method', 'unknown')
        counterparty_email = params.get('counterparty_email')

        risk_score = 0
        flags = []

        if amount > self.fraud_thresholds['large_transaction']:
            risk_score += 20
            flags.append('large_amount')

        if counterparty_email:
            previous = await self._query_db(
                "SELECT COUNT(*) as count FROM transactions WHERE tenant_id = $1 AND counterparty_email = $2",
                [tenant_id, counterparty_email]
            )
            if previous and previous[0]['count'] == 0 and amount > self.fraud_thresholds['new_counterparty_amount']:
                risk_score += 30
                flags.append('new_counterparty_large_amount')

        recent = await self._query_db(
            "SELECT COUNT(*) as count FROM transactions WHERE tenant_id = $1 AND created_at >= $2",
            [tenant_id, (datetime.utcnow() - timedelta(minutes=30)).isoformat()]
        )
        if recent and recent[0]['count'] > self.fraud_thresholds['high_frequency_per_hour']:
            risk_score += 25
            flags.append('high_frequency')

        current_hour = datetime.utcnow().hour
        if (current_hour >= self.fraud_thresholds['unusual_hours_start'] and
            current_hour <= self.fraud_thresholds['unusual_hours_end']):
            risk_score += 15
            flags.append('unusual_hour')

        status = 'approved' if risk_score < self.fraud_thresholds['max_risk_score'] else 'flagged'

        if status == 'flagged':
            await self._create_alert(
                tenant_id, 'fraud_suspected', 'critical',
                f"Suspicious transaction: {transaction_id}",
                f"Amount: ${amount:,.2f}, Method: {method}, Flags: {', '.join(flags)}",
                {'transaction_id': transaction_id, 'risk_score': risk_score, 'flags': flags}
            )

        return AgentResult(
            status='success',
            output={
                'transaction_id': transaction_id,
                'risk_score': risk_score,
                'status': status,
                'flags': flags,
                'threshold': self.fraud_thresholds['max_risk_score'],
            },
            tokens_used=0,
            cost_usd=0.0,
        )

    async def _detect_fraud(self, context: AgentContext) -> AgentResult:
        tenant_id = context.tenant_id
        hours = context.ast.get('params', {}).get('hours', 24)

        transactions = await self._query_db(
            """SELECT t.*, fa.name as source_account_name
               FROM transactions t
               LEFT JOIN financial_accounts fa ON t.source_account_id = fa.id
               WHERE t.tenant_id = $1 AND t.created_at >= $2
               ORDER BY t.created_at DESC""",
            [tenant_id, (datetime.utcnow() - timedelta(hours=hours)).isoformat()]
        )

        flagged = []
        for tx in transactions:
            amount = float(tx['amount'])
            risk_score = 0
            flags = []

            if amount > self.fraud_thresholds['large_transaction']:
                risk_score += 20
                flags.append('large_amount')

            if tx['counterparty_email']:
                same_counterparty = await self._query_db(
                    "SELECT COUNT(*) as count FROM transactions WHERE tenant_id = $1 AND counterparty_email = $2",
                    [tenant_id, tx['counterparty_email']]
                )
                if same_counterparty and same_counterparty[0]['count'] <= 1 and amount > 500:
                    risk_score += 15
                    flags.append('rare_counterparty')

            if risk_score >= 30:
                flagged.append({
                    'transaction_id': tx['id'],
                    'amount': amount,
                    'risk_score': risk_score,
                    'flags': flags,
                    'counterparty': tx.get('counterparty_name'),
                })

        return AgentResult(
            status='success',
            output={
                'total_transactions': len(transactions),
                'flagged_count': len(flagged),
                'flagged_transactions': flagged,
                'period_hours': hours,
            },
            tokens_used=0,
            cost_usd=0.0,
        )

    async def _check_compliance(self, context: AgentContext) -> AgentResult:
        tenant_id = context.tenant_id

        pending_alerts = await self._query_db(
            "SELECT * FROM financial_alerts WHERE tenant_id = $1 AND status = 'unread' ORDER BY created_at DESC LIMIT 50",
            [tenant_id]
        )

        large_transactions = await self._query_db(
            """SELECT COUNT(*) as count, SUM(amount) as total
               FROM transactions WHERE tenant_id = $1 AND amount > $2
               AND created_at >= $3""",
            [tenant_id, self.fraud_thresholds['large_transaction'], (datetime.utcnow() - timedelta(days=7)).isoformat()]
        )

        compliance_status = 'compliant'
        if len(pending_alerts) > 10:
            compliance_status = 'needs_review'
        elif any(a['severity'] == 'critical' for a in pending_alerts):
            compliance_status = 'action_required'

        return AgentResult(
            status='success',
            output={
                'compliance_status': compliance_status,
                'pending_alerts': len(pending_alerts),
                'critical_alerts': sum(1 for a in pending_alerts if a['severity'] == 'critical'),
                'large_transactions_week': large_transactions[0] if large_transactions else {'count': 0, 'total': 0},
                'last_checked': datetime.utcnow().isoformat(),
            },
            tokens_used=0,
            cost_usd=0.0,
        )

    async def _audit_trail(self, context: AgentContext) -> AgentResult:
        params = context.ast.get('params', {})
        tenant_id = context.tenant_id

        aggregate_type = params.get('aggregate_type')
        aggregate_id = params.get('aggregate_id')

        if aggregate_type and aggregate_id:
            events = await self._query_db(
                """SELECT * FROM event_log WHERE tenant_id = $1
                   AND aggregate_type = $2 AND aggregate_id = $3
                   ORDER BY sequence_num ASC""",
                [tenant_id, aggregate_type, aggregate_id]
            )
        else:
            events = await self._query_db(
                """SELECT * FROM event_log WHERE tenant_id = $1
                   AND event_type LIKE 'payment.%' OR event_type LIKE 'invoice.%' OR event_type LIKE 'ledger.%'
                   ORDER BY sequence_num DESC LIMIT 100""",
                [tenant_id]
            )

        hash_valid = True
        for i in range(1, len(events)):
            if events[i].get('previous_hash') != events[i-1].get('hash'):
                hash_valid = False
                break

        return AgentResult(
            status='success',
            output={
                'events': events,
                'event_count': len(events),
                'hash_chain_valid': hash_valid,
                'integrity': 'verified' if hash_valid else 'compromised',
            },
            tokens_used=0,
            cost_usd=0.0,
        )

    async def _manage_alerts(self, context: AgentContext) -> AgentResult:
        tenant_id = context.tenant_id
        action = context.ast.get('params', {}).get('action', 'list')

        if action == 'list':
            alerts = await self._query_db(
                "SELECT * FROM financial_alerts WHERE tenant_id = $1 ORDER BY created_at DESC LIMIT 50",
                [tenant_id]
            )
            return AgentResult(
                status='success',
                output={'alerts': alerts, 'total': len(alerts)},
                tokens_used=0,
                cost_usd=0.0,
            )

        elif action == 'acknowledge':
            alert_id = context.ast.get('params', {}).get('alert_id')
            await self._query_db(
                "UPDATE financial_alerts SET status = 'acknowledged', acknowledged_at = $2 WHERE id = $1 AND tenant_id = $3",
                [alert_id, datetime.utcnow().isoformat(), tenant_id]
            )
            return AgentResult(
                status='success',
                output={'message': f'Alert {alert_id} acknowledged'},
                tokens_used=0,
                cost_usd=0.0,
            )

        return AgentResult(
            status='error',
            output={'error': f'Unknown action: {action}'},
            tokens_used=0,
            cost_usd=0.0,
        )

    async def _risk_report(self, context: AgentContext) -> AgentResult:
        tenant_id = context.tenant_id

        total_transactions = await self._query_db(
            "SELECT COUNT(*) as count, SUM(amount) as total FROM transactions WHERE tenant_id = $1",
            [tenant_id]
        )

        flagged_count = await self._query_db(
            "SELECT COUNT(*) as count FROM financial_alerts WHERE tenant_id = $1 AND type = 'fraud_suspected'",
            [tenant_id]
        )

        return AgentResult(
            status='success',
            output={
                'total_transactions': total_transactions[0] if total_transactions else {'count': 0, 'total': 0},
                'flagged_transactions': flagged_count[0]['count'] if flagged_count else 0,
                'risk_level': 'low' if (flagged_count and flagged_count[0]['count'] < 5) else 'medium' if (flagged_count and flagged_count[0]['count'] < 20) else 'high',
            },
            tokens_used=0,
            cost_usd=0.0,
        )

    async def _kyc_check(self, context: AgentContext) -> AgentResult:
        params = context.ast.get('params', {})
        tenant_id = context.tenant_id
        entity_name = params.get('entity_name', '')

        customers = await self._query_db(
            "SELECT * FROM customers WHERE tenant_id = $1 AND name ILIKE $2",
            [tenant_id, f"%{entity_name}%"]
        )

        results = []
        for customer in customers:
            risk_level = 'low'
            flags = []

            if not customer.get('tax_id'):
                flags.append('missing_tax_id')
                risk_level = 'medium'

            if float(customer.get('credit_limit', 0) or 0) > 50000:
                flags.append('high_credit_limit')
                risk_level = 'medium'

            if float(customer.get('balance', 0) or 0) > 100000:
                flags.append('high_balance')
                risk_level = 'high'

            results.append({
                'customer_id': customer['id'],
                'name': customer['name'],
                'email': customer.get('email'),
                'risk_level': risk_level,
                'flags': flags,
            })

        return AgentResult(
            status='success',
            output={'entities': results, 'count': len(results)},
            tokens_used=0,
            cost_usd=0.0,
        )

    async def _create_alert(self, tenant_id: str, alert_type: str, severity: str, title: str, message: str, context_data: dict = None):
        await self._query_db(
            """INSERT INTO financial_alerts (id, tenant_id, type, severity, title, message, context, status)
               VALUES (gen_random_uuid(), $1, $2, $3, $4, $5, $6, 'unread')""",
            [tenant_id, alert_type, severity, title, message, json.dumps(context_data) if context_data else None]
        )

    async def _query_db(self, query: str, params: list) -> list:
        try:
            async with self.db.acquire() as conn:
                rows = await conn.fetch(query, *params)
                return [dict(r) for r in rows]
        except Exception:
            return []

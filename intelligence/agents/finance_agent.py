"""
SpiderNetOS Financial OS — Finance Agent

Comprehensive financial analysis, reporting, and insights agent.
Provides ledger queries, cash flow analysis, budget optimization,
forecasting, and natural language financial Q&A.
"""

from typing import Dict, Any, List, Optional
from datetime import datetime, timedelta
import json
import re
from core.agent_base import AgentBase, AgentContext, AgentResult


class FinanceAgent(AgentBase):
    """
    FinanceAgent: The Financial Analyst & Intelligence Engine
    
    Responsibilities:
    - Query and analyze financial ledger data
    - Generate financial reports (P&L, balance sheet, cash flow)
    - Provide cash flow analysis and forecasting
    - Budget optimization and variance analysis
    - Invoice aging and collection recommendations
    - Natural language financial Q&A
    """
    
    def __init__(self, meta_planner, cost_governor, memory_graph, db_pool):
        super().__init__(
            agent_id='finance',
            name='Finance',
            capabilities=[
                'ledger-query',
                'report-generation',
                'cash-flow-analysis',
                'budget-optimization',
                'invoice-chasing',
                'forecasting',
                'financial-qna',
            ],
            meta_planner=meta_planner,
            cost_governor=cost_governor,
            memory_graph=memory_graph,
        )
        self.db = db_pool
    
    async def execute(self, context: AgentContext) -> AgentResult:
        ast = context.ast
        ast_type = ast.get('type', '')
        
        handlers = {
            'query_ledger': self._query_ledger,
            'generate_report': self._generate_report,
            'analyze_cashflow': self._analyze_cashflow,
            'analyze_budget': self._analyze_budget,
            'invoice_status': self._invoice_status,
            'forecast': self._forecast,
            'financial_qna': self._financial_qna,
            'trial_balance': self._trial_balance,
            'account_balance': self._account_balance,
            'revenue_analysis': self._revenue_analysis,
        }
        
        handler = handlers.get(ast_type, self._financial_qna)
        return await handler(context)
    
    async def _query_ledger(self, context: AgentContext) -> AgentResult:
        params = context.ast.get('params', {})
        tenant_id = context.tenant_id
        
        account_id = params.get('account_id')
        start_date = params.get('start_date', (datetime.utcnow() - timedelta(days=30)).isoformat())
        end_date = params.get('end_date', datetime.utcnow().isoformat())
        
        query = """
            SELECT le.*, fa.name as account_name, fa.type as account_type
            FROM ledger_entries le
            LEFT JOIN financial_accounts fa ON le.account_id = fa.id
            WHERE le.tenant_id = $1
              AND le.posted_at BETWEEN $2 AND $3
        """
        qparams = [tenant_id, start_date, end_date]
        
        if account_id:
            query += " AND le.account_id = $4"
            qparams.append(account_id)
        
        query += " ORDER BY le.posted_at DESC"
        
        entries = await self._query_db(query, qparams)
        
        total_debits = sum(float(e['amount']) for e in entries if e['side'] == 'debit')
        total_credits = sum(float(e['amount']) for e in entries if e['side'] == 'credit')
        
        return AgentResult(
            status='success',
            output={
                'entries': entries[:50],
                'total_count': len(entries),
                'total_debits': total_debits,
                'total_credits': total_credits,
                'net': total_debits - total_credits,
                'period': {'start': start_date, 'end': end_date},
            },
            tokens_used=0,
            cost_usd=0.0,
        )
    
    async def _generate_report(self, context: AgentContext) -> AgentResult:
        params = context.ast.get('params', {})
        tenant_id = context.tenant_id
        report_type = params.get('report_type', 'profit_and_loss')
        start_date = params.get('start_date', (datetime.utcnow() - timedelta(days=30)).isoformat())
        end_date = params.get('end_date', datetime.utcnow().isoformat())
        
        if report_type == 'profit_and_loss':
            revenue = await self._query_revenue(tenant_id, start_date, end_date)
            expenses = await self._query_expenses(tenant_id, start_date, end_date)
            
            net_profit = revenue - expenses
            
            data = {
                'type': 'profit_and_loss',
                'revenue': revenue,
                'expenses': expenses,
                'net_profit': net_profit,
                'profit_margin': round((net_profit / revenue * 100) if revenue > 0 else 0, 2),
                'period': {'start': start_date, 'end': end_date},
            }
        
        elif report_type == 'balance_sheet':
            assets = await self._query_account_balance(tenant_id, 'asset', end_date)
            liabilities = await self._query_account_balance(tenant_id, 'liability', end_date)
            equity = assets - liabilities
            
            data = {
                'type': 'balance_sheet',
                'assets': assets,
                'liabilities': liabilities,
                'equity': equity,
                'as_of_date': end_date,
            }
        
        elif report_type == 'cash_flow':
            inflows = await self._query_cash_inflows(tenant_id, start_date, end_date)
            outflows = await self._query_cash_outflows(tenant_id, start_date, end_date)
            
            data = {
                'type': 'cash_flow',
                'inflows': inflows,
                'outflows': outflows,
                'net_cash_flow': inflows - outflows,
                'period': {'start': start_date, 'end': end_date},
            }
        else:
            data = {'error': f'Unknown report type: {report_type}'}
        
        return AgentResult(
            status='success',
            output=data,
            tokens_used=0,
            cost_usd=0.0,
        )
    
    async def _analyze_cashflow(self, context: AgentContext) -> AgentResult:
        tenant_id = context.tenant_id
        months = context.ast.get('params', {}).get('months', 3)
        
        start = datetime.utcnow() - timedelta(days=months * 30)
        
        monthly_data = []
        for m in range(months):
            m_start = start + timedelta(days=m * 30)
            m_end = m_start + timedelta(days=30)
            
            inflows = await self._query_cash_inflows(tenant_id, m_start.isoformat(), m_end.isoformat())
            outflows = await self._query_cash_outflows(tenant_id, m_start.isoformat(), m_end.isoformat())
            
            monthly_data.append({
                'month': m_start.strftime('%Y-%m'),
                'inflows': inflows,
                'outflows': outflows,
                'net': inflows - outflows,
            })
        
        trend = self._calculate_trend([d['net'] for d in monthly_data])
        
        return AgentResult(
            status='success',
            output={
                'monthly_data': monthly_data,
                'trend': trend,
                'total_inflows': sum(d['inflows'] for d in monthly_data),
                'total_outflows': sum(d['outflows'] for d in monthly_data),
                'average_monthly_net': sum(d['net'] for d in monthly_data) / max(len(monthly_data), 1),
            },
            tokens_used=0,
            cost_usd=0.0,
        )
    
    async def _analyze_budget(self, context: AgentContext) -> AgentResult:
        tenant_id = context.tenant_id
        budget_id = context.ast.get('params', {}).get('budget_id')
        
        if budget_id:
            budgets = await self._query_db(
                "SELECT * FROM budgets WHERE tenant_id = $1 AND id = $2",
                [tenant_id, budget_id]
            )
        else:
            budgets = await self._query_db(
                "SELECT * FROM budgets WHERE tenant_id = $1 AND status = 'active'",
                [tenant_id]
            )
        
        analysis = []
        for budget in budgets:
            spent = float(budget['spent'])
            amount = float(budget['amount'])
            utilization = (spent / amount * 100) if amount > 0 else 0
            remaining = amount - spent
            days_left = (datetime.fromisoformat(budget['period_end']) - datetime.utcnow()).days
            
            status = 'on_track'
            if utilization >= 100:
                status = 'exceeded'
            elif utilization >= float(budget.get('alert_threshold', 80)):
                status = 'warning'
            elif days_left > 0:
                daily_budget = amount / max((datetime.fromisoformat(budget['period_end']) - datetime.fromisoformat(budget['period_start'])).days, 1)
                daily_spend = spent / max((datetime.utcnow() - datetime.fromisoformat(budget['period_start'])).days, 1)
                if daily_spend > daily_budget * 1.2:
                    status = 'overspending'
            
            analysis.append({
                'budget_id': budget['id'],
                'name': budget['name'],
                'amount': amount,
                'spent': spent,
                'remaining': remaining,
                'utilization': round(utilization, 2),
                'status': status,
                'days_left': days_left,
                'alert_threshold': float(budget.get('alert_threshold', 80)),
            })
        
        return AgentResult(
            status='success',
            output={'budgets': analysis},
            tokens_used=0,
            cost_usd=0.0,
        )
    
    async def _invoice_status(self, context: AgentContext) -> AgentResult:
        tenant_id = context.tenant_id
        
        summary = await self._query_db(
            """SELECT status, COUNT(*) as count, SUM(total_amount) as total
               FROM invoices WHERE tenant_id = $1 GROUP BY status""",
            [tenant_id]
        )
        
        overdue = await self._query_db(
            """SELECT id, invoice_number, customer_name, total_amount, due_date
               FROM invoices WHERE tenant_id = $1
                 AND status NOT IN ('paid', 'cancelled')
                 AND due_date < CURRENT_DATE
               ORDER BY due_date ASC""",
            [tenant_id]
        )
        
        return AgentResult(
            status='success',
            output={
                'summary': {row['status']: {'count': row['count'], 'total': float(row['total'])} for row in summary},
                'overdue_invoices': overdue,
                'overdue_total': sum(float(i['total_amount']) for i in overdue),
                'overdue_count': len(overdue),
            },
            tokens_used=0,
            cost_usd=0.0,
        )
    
    async def _forecast(self, context: AgentContext) -> AgentResult:
        params = context.ast.get('params', {})
        tenant_id = context.tenant_id
        months = params.get('months', 3)
        
        historical = await self._query_db(
            """SELECT DATE_TRUNC('month', paid_at) as month, SUM(amount) as revenue
               FROM payments WHERE tenant_id = $1 AND status = 'completed'
                 AND type = 'received' AND paid_at IS NOT NULL
               GROUP BY DATE_TRUNC('month', paid_at)
               ORDER BY month DESC LIMIT 12""",
            [tenant_id]
        )
        
        if len(historical) < 2:
            return AgentResult(
                status='error',
                output={'error': 'Insufficient historical data for forecasting (need at least 2 months)'},
                tokens_used=0,
                cost_usd=0.0,
            )
        
        revenues = [float(h['revenue']) for h in reversed(historical)]
        trend = self._calculate_trend(revenues)
        avg_revenue = sum(revenues) / len(revenues)
        
        forecast = []
        for m in range(1, months + 1):
            forecast_month = datetime.utcnow() + timedelta(days=m * 30)
            predicted = avg_revenue * (1 + trend * m / 100)
            forecast.append({
                'month': forecast_month.strftime('%Y-%m'),
                'predicted_revenue': round(predicted, 2),
                'confidence': 'low' if len(historical) < 3 else ('medium' if len(historical) < 6 else 'high'),
            })
        
        return AgentResult(
            status='success',
            output={
                'forecast': forecast,
                'trend': trend,
                'average_monthly_revenue': round(avg_revenue, 2),
                'historical_months': len(historical),
            },
            tokens_used=0,
            cost_usd=0.0,
        )
    
    async def _financial_qna(self, context: AgentContext) -> AgentResult:
        tenant_id = context.tenant_id
        query = context.ast.get('params', {}).get('query', '').lower()
        
        result = {'query': query, 'answer': None, 'data': {}}
        
        if any(w in query for w in ['revenue', 'income', 'earnings']):
            revenue = await self._query_revenue(tenant_id, (datetime.utcnow() - timedelta(days=30)).isoformat(), datetime.utcnow().isoformat())
            result['data'] = {'revenue_last_30_days': revenue}
            result['answer'] = f"Revenue in the last 30 days: ${revenue:,.2f}"
        
        elif 'cash flow' in query or 'cashflow' in query:
            inflows = await self._query_cash_inflows(tenant_id, (datetime.utcnow() - timedelta(days=30)).isoformat(), datetime.utcnow().isoformat())
            outflows = await self._query_cash_outflows(tenant_id, (datetime.utcnow() - timedelta(days=30)).isoformat(), datetime.utcnow().isoformat())
            result['data'] = {'inflows': inflows, 'outflows': outflows, 'net': inflows - outflows}
            result['answer'] = f"Cash flow (last 30 days): Inflows ${inflows:,.2f}, Outflows ${outflows:,.2f}, Net ${inflows - outflows:,.2f}"
        
        elif 'outstanding' in query or 'unpaid' in query:
            outstanding = await self._query_db(
                "SELECT COUNT(*) as count, SUM(total_amount) as total FROM invoices WHERE tenant_id = $1 AND status = 'sent'",
                [tenant_id]
            )
            result['data'] = outstanding
            result['answer'] = f"Outstanding invoices: {outstanding[0]['count']} totaling ${float(outstanding[0]['total']):,.2f}"
        
        elif 'overdue' in query:
            overdue = await self._query_db(
                "SELECT COUNT(*) as count, SUM(total_amount) as total FROM invoices WHERE tenant_id = $1 AND status NOT IN ('paid', 'cancelled') AND due_date < CURRENT_DATE",
                [tenant_id]
            )
            result['data'] = overdue
            result['answer'] = f"Overdue invoices: {overdue[0]['count']} totaling ${float(overdue[0]['total']):,.2f}"
        
        elif 'wallet' in query or 'balance' in query:
            wallets = await self._query_db(
                "SELECT currency, balance FROM wallets WHERE tenant_id = $1 AND status = 'active'",
                [tenant_id]
            )
            result['data'] = wallets
            result['answer'] = "Wallet balances:\n" + "\n".join(f"  {w['currency']}: {float(w['balance']):,.2f}" for w in wallets)
        
        elif 'budget' in query:
            budgets = await self._query_db(
                "SELECT name, amount, spent, period_end FROM budgets WHERE tenant_id = $1 AND status = 'active'",
                [tenant_id]
            )
            result['data'] = budgets
            result['answer'] = "Active budgets:\n" + "\n".join(
                f"  {b['name']}: ${float(b['spent']):,.2f} of ${float(b['amount']):,.2f} spent" for b in budgets
            )
        
        else:
            result['answer'] = "I can help you with financial queries about revenue, cash flow, invoices, wallets, budgets, and more. Please be specific about what you'd like to know."
        
        return AgentResult(
            status='success',
            output=result,
            tokens_used=0,
            cost_usd=0.0,
        )
    
    async def _trial_balance(self, context: AgentContext) -> AgentResult:
        tenant_id = context.tenant_id
        
        entries = await self._query_db(
            """SELECT le.account_id, fa.name as account_name, fa.type as account_type,
                      le.side, SUM(le.amount) as total
               FROM ledger_entries le
               LEFT JOIN financial_accounts fa ON le.account_id = fa.id
               WHERE le.tenant_id = $1
               GROUP BY le.account_id, fa.name, fa.type, le.side""",
            [tenant_id]
        )
        
        balances = {}
        for entry in entries:
            aid = entry['account_id']
            if aid not in balances:
                balances[aid] = {
                    'account_id': aid,
                    'account_name': entry['account_name'],
                    'account_type': entry['account_type'],
                    'debits': 0,
                    'credits': 0,
                }
            if entry['side'] == 'debit':
                balances[aid]['debits'] += float(entry['total'])
            else:
                balances[aid]['credits'] += float(entry['total'])
        
        for b in balances.values():
            b['net'] = b['debits'] - b['credits']
        
        total_debits = sum(b['debits'] for b in balances.values())
        total_credits = sum(b['credits'] for b in balances.values())
        
        return AgentResult(
            status='success',
            output={
                'balances': list(balances.values()),
                'total_debits': total_debits,
                'total_credits': total_credits,
                'balanced': abs(total_debits - total_credits) < 0.01,
            },
            tokens_used=0,
            cost_usd=0.0,
        )
    
    async def _account_balance(self, context: AgentContext) -> AgentResult:
        account_id = context.ast.get('params', {}).get('account_id')
        
        account = await self._query_db(
            "SELECT * FROM financial_accounts WHERE id = $1",
            [account_id]
        )
        
        if not account:
            return AgentResult(status='error', output={'error': 'Account not found'}, tokens_used=0, cost_usd=0.0)
        
        return AgentResult(
            status='success',
            output={
                'account_id': account_id,
                'name': account[0]['name'],
                'type': account[0]['type'],
                'balance': float(account[0]['balance']),
                'currency': account[0]['currency'],
                'status': account[0]['status'],
            },
            tokens_used=0,
            cost_usd=0.0,
        )
    
    async def _revenue_analysis(self, context: AgentContext) -> AgentResult:
        tenant_id = context.tenant_id
        
        this_month = await self._query_revenue(tenant_id, datetime.utcnow().replace(day=1).isoformat(), datetime.utcnow().isoformat())
        last_month = await self._query_revenue(tenant_id, (datetime.utcnow().replace(day=1) - timedelta(days=1)).replace(day=1).isoformat(), datetime.utcnow().replace(day=1).isoformat())
        
        growth = ((this_month - last_month) / last_month * 100) if last_month > 0 else 0
        
        monthly = await self._query_db(
            """SELECT DATE_TRUNC('month', paid_at) as month, SUM(amount) as revenue
               FROM payments WHERE tenant_id = $1 AND status = 'completed' AND type = 'received'
               GROUP BY DATE_TRUNC('month', paid_at) ORDER BY month DESC LIMIT 12""",
            [tenant_id]
        )
        
        return AgentResult(
            status='success',
            output={
                'this_month': this_month,
                'last_month': last_month,
                'growth_percent': round(growth, 2),
                'monthly_trend': [{'month': str(m['month']), 'revenue': float(m['revenue'])} for m in monthly],
            },
            tokens_used=0,
            cost_usd=0.0,
        )
    
    async def _query_revenue(self, tenant_id: str, start: str, end: str) -> float:
        result = await self._query_db(
            """SELECT COALESCE(SUM(amount), 0) as total
               FROM payments WHERE tenant_id = $1 AND status = 'completed'
                 AND type = 'received' AND paid_at BETWEEN $2 AND $3""",
            [tenant_id, start, end]
        )
        return float(result[0]['total']) if result else 0.0
    
    async def _query_expenses(self, tenant_id: str, start: str, end: str) -> float:
        result = await self._query_db(
            """SELECT COALESCE(SUM(amount), 0) as total
               FROM payments WHERE tenant_id = $1 AND status = 'completed'
                 AND type = 'sent' AND paid_at BETWEEN $2 AND $3""",
            [tenant_id, start, end]
        )
        return float(result[0]['total']) if result else 0.0
    
    async def _query_cash_inflows(self, tenant_id: str, start: str, end: str) -> float:
        return await self._query_revenue(tenant_id, start, end)
    
    async def _query_cash_outflows(self, tenant_id: str, start: str, end: str) -> float:
        return await self._query_expenses(tenant_id, start, end)
    
    async def _query_account_balance(self, tenant_id: str, account_type: str, as_of: str) -> float:
        entries = await self._query_db(
            """SELECT le.side, SUM(le.amount) as total
               FROM ledger_entries le
               JOIN financial_accounts fa ON le.account_id = fa.id
               WHERE le.tenant_id = $1 AND fa.type = $2 AND le.posted_at <= $3
               GROUP BY le.side""",
            [tenant_id, account_type, as_of]
        )
        
        debits = sum(float(e['total']) for e in entries if e['side'] == 'debit')
        credits = sum(float(e['total']) for e in entries if e['side'] == 'credit')
        return debits - credits if account_type in ('asset', 'expense') else credits - debits
    
    def _calculate_trend(self, values: list) -> float:
        if len(values) < 2:
            return 0.0
        n = len(values)
        x_mean = (n - 1) / 2
        y_mean = sum(values) / n
        
        numerator = sum((i - x_mean) * (values[i] - y_mean) for i in range(n))
        denominator = sum((i - x_mean) ** 2 for i in range(n))
        
        if denominator == 0:
            return 0.0
        
        slope = numerator / denominator
        return (slope / y_mean * 100) if y_mean != 0 else 0.0
    
    async def _query_db(self, query: str, params: list) -> list:
        try:
            async with self.db.acquire() as conn:
                rows = await conn.fetch(query, *params)
                return [dict(r) for r in rows]
        except Exception:
            return []

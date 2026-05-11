<template>
  <div class="financial-dashboard p-6 space-y-6" :style="{ background: 'var(--bg)' }">
    <div>
      <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">Financial OS</h1>
      <p class="text-sm mt-1" :style="{ color: 'var(--text-secondary)' }">
        Comprehensive financial management — ledger, invoices, payments, portfolios
      </p>
    </div>

    <!-- KPI Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
      <div class="dct-card p-5" :style="{ background: 'var(--surface-low)' }">
        <p class="text-xs font-medium uppercase tracking-wider" :style="{ color: 'var(--text-muted)' }">Revenue (This Month)</p>
        <p class="text-2xl font-bold mt-1" :style="{ color: 'var(--charge-vivid)' }">${{ formatNumber(dashboard.revenue_this_month) }}</p>
        <p class="text-xs mt-1" :style="{ color: dashboard.revenue_growth >= 0 ? 'var(--charge-vivid)' : 'var(--dusk-vivid)' }">
          {{ dashboard.revenue_growth >= 0 ? '↑' : '↓' }} {{ Math.abs(dashboard.revenue_growth) }}% vs last month
        </p>
      </div>

      <div class="dct-card p-5" :style="{ background: 'var(--surface-low)' }">
        <p class="text-xs font-medium uppercase tracking-wider" :style="{ color: 'var(--text-muted)' }">Outstanding Invoices</p>
        <p class="text-2xl font-bold mt-1" :style="{ color: '#FFAA00' }">${{ formatNumber(dashboard.outstanding_invoices) }}</p>
        <p class="text-xs mt-1" :style="{ color: 'var(--text-muted)' }">{{ dashboard.invoices_this_month }} created this month</p>
      </div>

      <div class="dct-card p-5" :style="{ background: 'var(--surface-low)' }">
        <p class="text-xs font-medium uppercase tracking-wider" :style="{ color: 'var(--text-muted)' }">Pending Payments</p>
        <p class="text-2xl font-bold mt-1" :style="{ color: 'var(--charge-vivid)' }">${{ formatNumber(dashboard.pending_payments) }}</p>
        <p class="text-xs mt-1" :style="{ color: 'var(--text-muted)' }">Awaiting processing</p>
      </div>

      <div class="dct-card p-5" :style="{ background: 'var(--surface-low)' }">
        <p class="text-xs font-medium uppercase tracking-wider" :style="{ color: 'var(--text-muted)' }">Wallet Balance</p>
        <p class="text-2xl font-bold mt-1" :style="{ color: 'var(--text-primary)' }">${{ formatNumber(dashboard.total_wallet_balance) }}</p>
        <p class="text-xs mt-1" :style="{ color: 'var(--text-muted)' }">Across all wallets</p>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="dct-card p-6 space-y-4">
      <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Quick Actions</h2>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <router-link to="/financial/invoices/new" class="dct-btn-primary py-3 text-center text-sm">
          + New Invoice
        </router-link>
        <router-link to="/financial/payments/new" class="dct-btn-primary py-3 text-center text-sm" :style="{ '--btn-bg': 'var(--tealime-vivid)' }">
          + Record Payment
        </router-link>
        <router-link to="/financial/ledger" class="py-3 rounded-xl font-semibold text-sm border text-center transition-colors" :style="{ borderColor: 'var(--border)', color: 'var(--text-secondary)', background: 'var(--surface-low)' }">
          View Ledger
        </router-link>
        <router-link to="/financial/reports" class="py-3 rounded-xl font-semibold text-sm border text-center transition-colors" :style="{ borderColor: 'var(--border)', color: 'var(--text-secondary)', background: 'var(--surface-low)' }">
          Generate Report
        </router-link>
      </div>
    </div>

    <!-- Recent Activity -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Recent Invoices -->
      <div class="dct-card p-6 space-y-4">
        <div class="flex items-center justify-between">
          <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Recent Invoices</h2>
          <router-link to="/financial/invoices" class="text-sm font-medium" :style="{ color: 'var(--charge-vivid)' }">View All</router-link>
        </div>
        <div v-if="recentInvoices.length === 0" class="py-8 text-center text-sm" :style="{ color: 'var(--text-muted)' }">
          No invoices yet
        </div>
        <div v-else class="space-y-2">
          <div v-for="inv in recentInvoices.slice(0, 5)" :key="inv.id" class="flex items-center justify-between px-3 py-2 rounded-lg" :style="{ background: 'var(--surface-low)' }">
            <div>
              <p class="text-sm font-medium" :style="{ color: 'var(--text-primary)' }">{{ inv.invoice_number }}</p>
              <p class="text-xs" :style="{ color: 'var(--text-muted)' }">{{ inv.customer_name }}</p>
            </div>
            <div class="text-right">
              <p class="text-sm font-semibold" :style="{ color: 'var(--text-primary)' }">${{ formatNumber(inv.total_amount) }}</p>
              <span :class="statusClass(inv.status)">{{ inv.status }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Recent Payments -->
      <div class="dct-card p-6 space-y-4">
        <div class="flex items-center justify-between">
          <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Recent Payments</h2>
          <router-link to="/financial/payments" class="text-sm font-medium" :style="{ color: 'var(--charge-vivid)' }">View All</router-link>
        </div>
        <div v-if="recentPayments.length === 0" class="py-8 text-center text-sm" :style="{ color: 'var(--text-muted)' }">
          No payments yet
        </div>
        <div v-else class="space-y-2">
          <div v-for="pay in recentPayments.slice(0, 5)" :key="pay.id" class="flex items-center justify-between px-3 py-2 rounded-lg" :style="{ background: 'var(--surface-low)' }">
            <div>
              <p class="text-sm font-medium" :style="{ color: 'var(--text-primary)' }">{{ pay.payment_number }}</p>
              <p class="text-xs" :style="{ color: 'var(--text-muted)' }">{{ pay.method }}</p>
            </div>
            <div class="text-right">
              <p class="text-sm font-semibold" :style="{ color: 'var(--text-primary)' }">${{ formatNumber(pay.amount) }}</p>
              <span :class="statusClass(pay.status)">{{ pay.status }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Alerts -->
    <div v-if="alerts.length > 0" class="dct-card p-6 space-y-4">
      <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Financial Alerts</h2>
      <div v-for="alert in alerts.slice(0, 3)" :key="alert.id" class="flex items-start gap-3 px-4 py-3 rounded-xl" :style="{ background: alert.severity === 'critical' ? 'color-mix(in srgb, var(--dusk-vivid) 10%, transparent)' : 'color-mix(in srgb, #FFAA00 10%, transparent)' }">
        <svg class="w-5 h-5 mt-0.5 flex-shrink-0" :style="{ color: alert.severity === 'critical' ? 'var(--dusk-vivid)' : '#FFAA00' }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.834-1.964-.834-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
        </svg>
        <div class="flex-1">
          <p class="text-sm font-medium" :style="{ color: 'var(--text-primary)' }">{{ alert.title }}</p>
          <p class="text-xs" :style="{ color: 'var(--text-secondary)' }">{{ alert.message }}</p>
        </div>
        <button @click="acknowledgeAlert(alert.id)" class="text-xs font-medium" :style="{ color: 'var(--text-muted)' }">Dismiss</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { api } from '../../services/api.js'

const dashboard = ref({
  revenue_this_month: 0,
  revenue_last_month: 0,
  revenue_growth: 0,
  invoices_this_month: 0,
  outstanding_invoices: 0,
  pending_payments: 0,
  total_wallet_balance: 0,
})

const recentInvoices = ref([])
const recentPayments = ref([])
const alerts = ref([])

function formatNumber(n) {
  return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function statusClass(status) {
  const map = {
    paid: 'dct-pill-lime',
    completed: 'dct-pill-lime',
    sent: 'dct-pill-cyan',
    pending: 'dct-pill-cyan',
    draft: 'dct-pill-cyan',
    cancelled: 'dct-pill-pink',
    failed: 'dct-pill-pink',
    overdue: 'dct-pill-pink',
  }
  return `px-2 py-0.5 rounded-full text-xs font-semibold ${map[status] || 'dct-pill-cyan'}`
}

async function acknowledgeAlert(id) {
  try {
    await api.post(`/api/financial/alerts/${id}/acknowledge`)
    alerts.value = alerts.value.filter(a => a.id !== id)
  } catch (e) {
    console.error('Failed to acknowledge alert', e)
  }
}

onMounted(async () => {
  try {
    const [dashRes, invRes, payRes, alertRes] = await Promise.allSettled([
      api.get('/api/financial/dashboard'),
      api.get('/api/financial/invoices?per_page=5'),
      api.get('/api/financial/payments?per_page=5'),
      api.get('/api/financial/alerts?per_page=5'),
    ])

    if (dashRes.status === 'fulfilled') dashboard.value = dashRes.value.data.data || {}
    if (invRes.status === 'fulfilled') recentInvoices.value = invRes.value.data.data?.data || []
    if (payRes.status === 'fulfilled') recentPayments.value = payRes.value.data.data?.data || []
    if (alertRes.status === 'fulfilled') alerts.value = alertRes.value.data.data?.data || []
  } catch (e) {
    console.error('Failed to load financial dashboard', e)
  }
})
</script>

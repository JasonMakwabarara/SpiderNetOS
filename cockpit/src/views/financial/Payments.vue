<template>
  <div class="financial-payments p-6 space-y-6" :style="{ background: 'var(--bg)' }">
    <div>
      <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">Payments</h1>
      <p class="text-sm mt-1" :style="{ color: 'var(--text-secondary)' }">Track and manage all payment transactions</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="dct-card p-5" :style="{ background: 'var(--surface-low)' }">
        <p class="text-xs font-medium uppercase" :style="{ color: 'var(--text-muted)' }">Total Received</p>
        <p class="text-2xl font-bold mt-1" :style="{ color: 'var(--charge-vivid)' }">${{ formatNumber(summary.total_received) }}</p>
      </div>
      <div class="dct-card p-5" :style="{ background: 'var(--surface-low)' }">
        <p class="text-xs font-medium uppercase" :style="{ color: 'var(--text-muted)' }">Total Sent</p>
        <p class="text-2xl font-bold mt-1" :style="{ color: 'var(--dusk-vivid)' }">${{ formatNumber(summary.total_sent) }}</p>
      </div>
      <div class="dct-card p-5" :style="{ background: 'var(--surface-low)' }">
        <p class="text-xs font-medium uppercase" :style="{ color: 'var(--text-muted)' }">Pending</p>
        <p class="text-2xl font-bold mt-1" :style="{ color: '#FFAA00' }">${{ formatNumber(summary.pending) }}</p>
      </div>
    </div>

    <div class="dct-card p-6 space-y-4">
      <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Payment History</h2>
      <div class="overflow-x-auto">
        <table class="min-w-full">
          <thead>
            <tr :style="{ borderBottom: '1px solid var(--border)' }">
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase" :style="{ color: 'var(--text-muted)' }">Payment #</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase" :style="{ color: 'var(--text-muted)' }">Method</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase" :style="{ color: 'var(--text-muted)' }">Date</th>
              <th class="px-4 py-3 text-right text-xs font-semibold uppercase" :style="{ color: 'var(--text-muted)' }">Amount</th>
              <th class="px-4 py-3 text-center text-xs font-semibold uppercase" :style="{ color: 'var(--text-muted)' }">Status</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase" :style="{ color: 'var(--text-muted)' }">Notes</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="pay in payments" :key="pay.id" :style="{ borderBottom: '1px solid var(--border)' }">
              <td class="px-4 py-3 text-sm font-medium" :style="{ color: 'var(--text-primary)' }">{{ pay.payment_number }}</td>
              <td class="px-4 py-3 text-sm" :style="{ color: 'var(--text-secondary)' }">{{ pay.method }}</td>
              <td class="px-4 py-3 text-sm" :style="{ color: 'var(--text-secondary)' }">{{ formatDate(pay.paid_at || pay.created_at) }}</td>
              <td class="px-4 py-3 text-sm text-right font-semibold" :style="{ color: 'var(--text-primary)' }">${{ formatNumber(pay.amount) }}</td>
              <td class="px-4 py-3 text-center"><span :class="statusPill(pay.status)">{{ pay.status }}</span></td>
              <td class="px-4 py-3 text-sm" :style="{ color: 'var(--text-muted)' }">{{ pay.notes || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { api } from '../../services/api.js'

const payments = ref([])
const summary = ref({ total_received: 0, total_sent: 0, pending: 0 })

function formatNumber(n) { return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }
function formatDate(d) { return d ? new Date(d).toLocaleDateString() : '—' }
function statusPill(s) { return `px-2 py-0.5 rounded-full text-xs font-semibold ${s === 'completed' ? 'dct-pill-lime' : s === 'pending' ? 'dct-pill-cyan' : 'dct-pill-pink'}` }

onMounted(async () => {
  const [payRes, sumRes] = await Promise.allSettled([
    api.get('/api/financial/payments'),
    api.get('/api/financial/payments/summary'),
  ])
  if (payRes.status === 'fulfilled') payments.value = payRes.value.data.data?.data || []
  if (sumRes.status === 'fulfilled') summary.value = sumRes.value.data.data || {}
})
</script>

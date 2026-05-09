<template>
  <div class="financial-invoices p-6 space-y-6" :style="{ background: 'var(--bg)' }">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">Invoices</h1>
        <p class="text-sm mt-1" :style="{ color: 'var(--text-secondary)' }">Create, send, and track invoices</p>
      </div>
      <button @click="showNew = true" class="dct-btn-primary px-4 py-2 text-sm">+ New Invoice</button>
    </div>

    <!-- Summary -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
      <div v-for="(val, key) in summary" :key="key" class="dct-card p-4 text-center" :style="{ background: 'var(--surface-low)' }">
        <p class="text-xs font-medium uppercase" :style="{ color: 'var(--text-muted)' }">{{ key }}</p>
        <p class="text-xl font-bold mt-1" :style="{ color: key === 'paid' ? 'var(--charge-vivid)' : key === 'overdue' ? 'var(--dusk-vivid)' : 'var(--text-primary)' }">
          {{ val.count }}
        </p>
        <p class="text-sm" :style="{ color: 'var(--text-secondary)' }">${{ formatNumber(val.total) }}</p>
      </div>
    </div>

    <!-- Invoice List -->
    <div class="dct-card p-6 space-y-4">
      <div class="flex gap-2">
        <button v-for="s in ['all', 'draft', 'sent', 'paid', 'cancelled']" :key="s"
          @click="filter = s; loadInvoices()"
          class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
          :style="{ background: filter === s ? 'var(--charge-vivid)' : 'var(--surface-low)', color: filter === s ? '#000' : 'var(--text-secondary)' }">
          {{ s.charAt(0).toUpperCase() + s.slice(1) }}
        </button>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full">
          <thead>
            <tr :style="{ borderBottom: '1px solid var(--border)' }">
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase" :style="{ color: 'var(--text-muted)' }">Invoice</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase" :style="{ color: 'var(--text-muted)' }">Customer</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase" :style="{ color: 'var(--text-muted)' }">Due Date</th>
              <th class="px-4 py-3 text-right text-xs font-semibold uppercase" :style="{ color: 'var(--text-muted)' }">Amount</th>
              <th class="px-4 py-3 text-center text-xs font-semibold uppercase" :style="{ color: 'var(--text-muted)' }">Status</th>
              <th class="px-4 py-3 text-center text-xs font-semibold uppercase" :style="{ color: 'var(--text-muted)' }">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="inv in invoices" :key="inv.id" :style="{ borderBottom: '1px solid var(--border)' }">
              <td class="px-4 py-3 text-sm font-medium" :style="{ color: 'var(--text-primary)' }">{{ inv.invoice_number }}</td>
              <td class="px-4 py-3 text-sm" :style="{ color: 'var(--text-secondary)' }">{{ inv.customer_name }}</td>
              <td class="px-4 py-3 text-sm" :style="{ color: 'var(--text-secondary)' }">{{ inv.due_date }}</td>
              <td class="px-4 py-3 text-sm text-right font-semibold" :style="{ color: 'var(--text-primary)' }">${{ formatNumber(inv.total_amount) }}</td>
              <td class="px-4 py-3 text-center">
                <span :class="statusPill(inv.status)">{{ inv.status }}</span>
              </td>
              <td class="px-4 py-3 text-center">
                <button v-if="inv.status === 'draft'" @click="sendInvoice(inv.id)" class="text-xs font-medium mr-2" :style="{ color: 'var(--charge-vivid)' }">Send</button>
                <button v-if="inv.status !== 'paid' && inv.status !== 'cancelled'" @click="markPaid(inv.id)" class="text-xs font-medium mr-2" :style="{ color: 'var(--tealime-vivid)' }">Mark Paid</button>
                <button v-if="inv.status === 'draft' || inv.status === 'sent'" @click="cancelInvoice(inv.id)" class="text-xs font-medium" :style="{ color: 'var(--dusk-vivid)' }">Cancel</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- New Invoice Modal -->
    <div v-if="showNew" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
      <div class="dct-card p-6 w-full max-w-2xl space-y-4" :style="{ background: 'var(--bg)' }">
        <h3 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Create Invoice</h3>
        <div class="grid grid-cols-2 gap-3">
          <input v-model="form.customer_name" placeholder="Customer name" class="px-3 py-2 rounded-lg border" :style="{ borderColor: 'var(--border)', background: 'var(--surface-low)', color: 'var(--text-primary)' }" />
          <input v-model="form.customer_email" type="email" placeholder="Customer email" class="px-3 py-2 rounded-lg border" :style="{ borderColor: 'var(--border)', background: 'var(--surface-low)', color: 'var(--text-primary)' }" />
          <input v-model="form.due_date" type="date" class="px-3 py-2 rounded-lg border" :style="{ borderColor: 'var(--border)', background: 'var(--surface-low)', color: 'var(--text-primary)' }" />
          <input v-model="form.tax_rate" type="number" placeholder="Tax rate %" class="px-3 py-2 rounded-lg border" :style="{ borderColor: 'var(--border)', background: 'var(--surface-low)', color: 'var(--text-primary)' }" />
        </div>
        <div class="space-y-2">
          <p class="text-sm font-medium" :style="{ color: 'var(--text-primary)' }">Line Items</p>
          <div v-for="(item, i) in form.line_items" :key="i" class="grid grid-cols-12 gap-2">
            <input v-model="item.description" placeholder="Description" class="col-span-5 px-3 py-2 rounded-lg border" :style="{ borderColor: 'var(--border)', background: 'var(--surface-low)', color: 'var(--text-primary)' }" />
            <input v-model="item.quantity" type="number" placeholder="Qty" class="col-span-2 px-3 py-2 rounded-lg border" :style="{ borderColor: 'var(--border)', background: 'var(--surface-low)', color: 'var(--text-primary)' }" />
            <input v-model="item.unit_price" type="number" placeholder="Price" class="col-span-3 px-3 py-2 rounded-lg border" :style="{ borderColor: 'var(--border)', background: 'var(--surface-low)', color: 'var(--text-primary)' }" />
            <button @click="form.line_items.splice(i, 1)" class="col-span-2 text-sm" :style="{ color: 'var(--dusk-vivid)' }">Remove</button>
          </div>
          <button @click="form.line_items.push({ description: '', quantity: 1, unit_price: 0 })" class="text-sm font-medium" :style="{ color: 'var(--charge-vivid)' }">+ Add Item</button>
        </div>
        <div class="flex gap-3 justify-end">
          <button @click="showNew = false" class="px-4 py-2 rounded-lg text-sm" :style="{ color: 'var(--text-secondary)', background: 'var(--surface-low)' }">Cancel</button>
          <button @click="createInvoice" class="dct-btn-primary px-4 py-2 text-sm">Create</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { api } from '../../services/api.js'

const invoices = ref([])
const summary = ref({ draft: { count: 0, total: 0 }, sent: { count: 0, total: 0 }, paid: { count: 0, total: 0 }, cancelled: { count: 0, total: 0 }, overdue: { count: 0, total: 0 } })
const filter = ref('all')
const showNew = ref(false)
const form = ref({ customer_name: '', customer_email: '', due_date: '', tax_rate: 0, line_items: [{ description: '', quantity: 1, unit_price: 0 }] })

function formatNumber(n) { return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }
function statusPill(s) { return `px-2 py-0.5 rounded-full text-xs font-semibold ${s === 'paid' ? 'dct-pill-lime' : s === 'sent' ? 'dct-pill-cyan' : s === 'overdue' ? 'dct-pill-pink' : 'dct-pill-cyan'}` }

async function loadInvoices() {
  const url = filter.value === 'all' ? '/api/financial/invoices' : `/api/financial/invoices?status=${filter.value}`
  const res = await api.get(url)
  invoices.value = res.data.data?.data || []
}

async function loadSummary() {
  const res = await api.get('/api/financial/invoices/summary')
  summary.value = res.data.data || summary.value
}

async function createInvoice() {
  await api.post('/api/financial/invoices', form.value)
  showNew.value = false
  form.value = { customer_name: '', customer_email: '', due_date: '', tax_rate: 0, line_items: [{ description: '', quantity: 1, unit_price: 0 }] }
  loadInvoices(); loadSummary()
}

async function sendInvoice(id) { await api.post(`/api/financial/invoices/${id}/send`); loadInvoices(); loadSummary() }
async function markPaid(id) { await api.post(`/api/financial/invoices/${id}/mark-paid`); loadInvoices(); loadSummary() }
async function cancelInvoice(id) { await api.post(`/api/financial/invoices/${id}/cancel`); loadInvoices(); loadSummary() }

onMounted(() => { loadInvoices(); loadSummary() })
</script>

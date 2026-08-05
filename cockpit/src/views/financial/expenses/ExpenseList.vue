<template>
  <div class="px-8 py-6 max-w-[1500px] mx-auto">
    <!-- Header -->
    <header class="flex items-start justify-between gap-4 mb-5">
      <div>
        <h1 class="text-[26px] font-heading font-semibold tracking-tight" style="color: var(--text-primary);">Expenses</h1>
        <p class="text-sm mt-1" style="color: var(--text-muted);">
          Submit expense reports with receipts. Policy flags and approvals are applied automatically.
        </p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <span
          v-if="canApprove && expensesStore.pendingApprovalCount"
          class="sn-pill sn-pill-warn"
          data-testid="expenses-pending-count"
        >{{ expensesStore.pendingApprovalCount }} awaiting</span>
        <RouterLink to="/financial/expenses/new" class="sn-btn sn-btn-primary" data-testid="expenses-new-button">
          + New expense
        </RouterLink>
      </div>
    </header>

    <!-- Scope tabs (Mine / Team) -->
    <nav class="flex items-center gap-1 mb-3" aria-label="Expense scope">
      <button
        v-for="t in scopeTabs" :key="t.value"
        class="px-3 py-1.5 rounded-md text-xs font-medium transition-colors"
        :style="activeScope === t.value
          ? 'background: var(--accent-weak); color: var(--accent); border: 1px solid rgba(0,229,200,0.30);'
          : 'background: var(--bg-elevated); color: var(--text-muted); border: 1px solid var(--border);'"
        :data-testid="`expenses-scope-${t.value}`"
        @click="setScope(t.value)"
      >{{ t.label }}</button>
    </nav>

    <!-- Status filter pills -->
    <nav class="flex items-center gap-1 mb-4 flex-wrap" aria-label="Expense status filters">
      <button
        v-for="s in statusTabs" :key="s.value"
        class="px-3 py-1.5 rounded-md text-xs font-medium transition-colors"
        :style="activeStatus === s.value
          ? 'background: var(--accent-weak); color: var(--accent); border: 1px solid rgba(0,229,200,0.30);'
          : 'background: var(--bg-elevated); color: var(--text-muted); border: 1px solid var(--border);'"
        :data-testid="`expenses-status-${s.value}`"
        @click="setStatus(s.value)"
      >{{ s.label }}</button>
    </nav>

    <!-- Table -->
    <section class="sn-card overflow-hidden">
      <DataTable
        :columns="columns"
        :rows="rows"
        :loading="expensesStore.loading"
        caption="Expense reports"
        clickable-rows
        data-testid="expenses-table"
        @row-click="openDetail"
      >
        <template #cell-total_amount="{ row }">
          <span class="mono">{{ row.currency || 'USD' }} {{ formatAmount(row.total_amount) }}</span>
        </template>
        <template #cell-status="{ value }">
          <span class="text-[10px]" :class="statusPill(value)">{{ statusLabel(value) }}</span>
        </template>
        <template #cell-policy_violation_count="{ value }">
          <span v-if="Number(value) > 0" class="sn-pill sn-pill-warn text-[10px]">{{ value }} flags</span>
          <span v-else style="color: var(--text-muted);">—</span>
        </template>
        <template #cell-chain_position="{ row }">
          <span v-if="chainLabel(row)" class="mono text-xs" style="color: var(--text-secondary);">{{ chainLabel(row) }}</span>
          <span v-else style="color: var(--text-muted);">—</span>
        </template>
        <template #empty>
          <EmptyState
            title="No expense reports"
            description="Create your first expense report to get reimbursed."
          >
            <template #actions>
              <RouterLink to="/financial/expenses/new" class="sn-btn sn-btn-primary" data-testid="expenses-empty-new">
                New expense
              </RouterLink>
            </template>
          </EmptyState>
        </template>
      </DataTable>
    </section>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useExpensesStore } from '../../../stores/expenses.js'
import { useAuthStore } from '../../../stores/auth.js'
import DataTable from '../../../components/data/DataTable.vue'
import EmptyState from '../../../components/data/EmptyState.vue'

const router = useRouter()
const expensesStore = useExpensesStore()
const authStore = useAuthStore()

const activeScope = ref('mine')
const activeStatus = ref('all')

const canApprove = computed(() => authStore.has('expenses.approve'))

const scopeTabs = computed(() => {
  const tabs = [{ label: 'Mine', value: 'mine' }]
  if (canApprove.value) tabs.push({ label: 'Team', value: 'team' })
  return tabs
})

const statusTabs = computed(() => {
  const all = expensesStore.expenses || []
  const by = (s) => all.filter((e) => e.status === s).length
  return [
    { label: 'All',       value: 'all' },
    { label: `Draft · ${by('draft')}`,                 value: 'draft' },
    { label: `Awaiting · ${by('awaiting_approval')}`,  value: 'awaiting_approval' },
    { label: `Approved · ${by('approved')}`,           value: 'approved' },
    { label: `Rejected · ${by('rejected')}`,           value: 'rejected' },
  ]
})

const columns = [
  { key: 'created_at',             label: 'Date', sortable: true, format: (v) => formatDate(v) },
  { key: 'title',                  label: 'Title', sortable: true },
  { key: 'total_amount',           label: 'Amount', sortable: true },
  { key: 'status',                 label: 'Status', sortable: true },
  { key: 'policy_violation_count', label: 'Flags', sortable: true },
  { key: 'chain_position',         label: 'Chain' },
]

const rows = computed(() => {
  const all = expensesStore.expenses || []
  if (activeStatus.value === 'all') return all
  return all.filter((e) => e.status === activeStatus.value)
})

function load() {
  const opts = { scope: activeScope.value }
  if (activeStatus.value !== 'all') opts.status = activeStatus.value
  expensesStore.fetchExpenses(opts)
}

function setScope(v) {
  activeScope.value = v
  load()
}

function setStatus(v) {
  activeStatus.value = v
  load()
}

function openDetail(row) {
  router.push(`/financial/expenses/${row.id}`)
}

function formatAmount(v) {
  return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })
}

function formatDate(v) {
  if (!v) return '—'
  try { return new Date(v).toLocaleDateString() } catch { return String(v) }
}

function chainLabel(row) {
  if (row.chain_position && row.chain_total) return `${row.chain_position}/${row.chain_total}`
  if (row.chain_position) return String(row.chain_position)
  return ''
}

function statusLabel(s) {
  return String(s || '').replaceAll('_', ' ')
}

function statusPill(s) {
  if (s === 'approved' || s === 'reimbursed') return 'sn-pill sn-pill-success'
  if (s === 'rejected' || s === 'voided')     return 'sn-pill sn-pill-danger'
  if (s === 'awaiting_approval')              return 'sn-pill sn-pill-warn'
  return 'sn-pill'
}

onMounted(load)
</script>

<template>
  <div class="px-8 py-6 max-w-[1500px] mx-auto">
    <!-- Header -->
    <header class="flex items-start justify-between gap-4 mb-5">
      <div>
        <h1 class="text-[26px] font-heading font-semibold tracking-tight" style="color: var(--text-primary);">Bill pay</h1>
        <p class="text-sm mt-1" style="color: var(--text-muted);">
          Capture vendor bills, route them through approval, then schedule or settle payment.
        </p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <span
          v-if="apStore.overdueCount"
          class="sn-pill sn-pill-danger"
          data-testid="bills-overdue-count"
        >{{ apStore.overdueCount }} overdue</span>
        <button class="sn-btn" data-testid="bills-upload-button" @click="toggleUpload">
          Upload bill
        </button>
        <button class="sn-btn sn-btn-primary" data-testid="bills-new-button" @click="toggleNewBill">
          + New bill
        </button>
      </div>
    </header>

    <!-- Upload panel -->
    <section v-if="showUpload" class="sn-card p-5 mb-4 max-w-[520px]" data-testid="bills-upload-panel">
      <h3 class="text-[10px] uppercase tracking-widest font-semibold mb-3" style="color: var(--text-muted);">
        Upload a bill document
      </h3>
      <ReceiptUpload :progress="uploadProgress" @upload="onUpload" />
      <p class="text-xs mt-2" style="color: var(--text-muted);">
        PDF works best — extraction runs automatically and drafts the bill for review.
      </p>
      <p v-if="uploadError" class="text-xs mt-1" style="color: var(--danger);" data-testid="bills-upload-error">{{ uploadError }}</p>
      <p v-if="uploadNote" class="text-xs mt-1" style="color: var(--success);" data-testid="bills-upload-note">{{ uploadNote }}</p>
    </section>

    <!-- New bill panel -->
    <section v-if="showNewBill" class="sn-card p-5 mb-4 space-y-3" data-testid="bills-new-panel">
      <h3 class="text-[10px] uppercase tracking-widest font-semibold" style="color: var(--text-muted);">New bill</h3>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div>
          <label for="bill-vendor">Vendor</label>
          <select id="bill-vendor" v-model="form.vendor_id" data-testid="bill-vendor-select">
            <option disabled value="">Select vendor…</option>
            <option v-for="v in activeVendors" :key="v.id" :value="v.id">{{ v.name }}</option>
          </select>
        </div>
        <div>
          <label for="bill-number">Bill number</label>
          <input id="bill-number" v-model="form.bill_number" type="text" placeholder="e.g. INV-2041" class="mono" data-testid="bill-number-input" />
        </div>
        <div>
          <label for="bill-due">Due date</label>
          <input id="bill-due" v-model="form.due_date" type="date" data-testid="bill-due-input" />
        </div>
      </div>

      <!-- Lines -->
      <div class="space-y-2">
        <div class="text-[10px] uppercase tracking-widest font-semibold" style="color: var(--text-muted);">Lines</div>
        <div
          v-for="(line, i) in form.lines" :key="i"
          class="grid grid-cols-[1fr_140px_auto] gap-2 items-center"
          :data-testid="`bill-line-${i}`"
        >
          <input v-model="line.description" type="text" placeholder="Line description" :data-testid="`bill-line-desc-${i}`" />
          <input v-model="line.amount" type="number" min="0" step="0.01" placeholder="0.00" class="mono" :data-testid="`bill-line-amount-${i}`" />
          <button
            class="text-xs font-medium px-1"
            style="color: var(--danger);"
            :disabled="form.lines.length === 1"
            :data-testid="`bill-line-remove-${i}`"
            @click="form.lines.splice(i, 1)"
          >Remove</button>
        </div>
        <button class="sn-btn text-xs" data-testid="bill-line-add" @click="form.lines.push({ description: '', amount: '' })">
          + Add line
        </button>
      </div>

      <p v-if="formError" class="text-xs" style="color: var(--danger);" data-testid="bill-form-error">{{ formError }}</p>
      <div class="flex justify-end gap-2">
        <button class="sn-btn" data-testid="bill-form-cancel" @click="showNewBill = false">Cancel</button>
        <button class="sn-btn sn-btn-primary" :disabled="creating" data-testid="bill-form-create" @click="createBill">
          {{ creating ? 'Creating…' : 'Create draft' }}
        </button>
      </div>
    </section>

    <!-- Tabs -->
    <nav class="flex items-center gap-1 mb-4 flex-wrap" aria-label="Bill status tabs">
      <button
        v-for="t in tabs" :key="t.value"
        class="px-3 py-1.5 rounded-md text-xs font-medium transition-colors"
        :style="activeTab === t.value
          ? 'background: var(--accent-weak); color: var(--accent); border: 1px solid rgba(0,214,201,0.30);'
          : 'background: var(--bg-elevated); color: var(--text-muted); border: 1px solid var(--border);'"
        :data-testid="`bills-tab-${t.value}`"
        @click="activeTab = t.value"
      >{{ t.label }} · {{ t.count }}</button>
    </nav>

    <div class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-4 items-start">
      <!-- Table -->
      <section class="sn-card overflow-hidden min-w-0">
        <DataTable
          :columns="columns"
          :rows="rows"
          :loading="apStore.loading"
          caption="Vendor bills"
          clickable-rows
          data-testid="bills-table"
          @row-click="openDetail"
        >
          <template #cell-vendor_name="{ row }">
            <span style="color: var(--text-primary);">{{ vendorName(row) }}</span>
          </template>
          <template #cell-bill_number="{ value }">
            <span class="mono text-xs">{{ value || '—' }}</span>
          </template>
          <template #cell-due_date="{ row, value }">
            <span
              :style="isOverdue(row) ? 'color: var(--danger); font-weight: 600;' : ''"
              :data-testid="isOverdue(row) ? `bill-overdue-${row.id}` : undefined"
            >{{ formatDate(value) }}<span v-if="isOverdue(row)"> · overdue</span></span>
          </template>
          <template #cell-total_amount="{ row }">
            <span class="mono">{{ row.currency || 'USD' }} {{ formatAmount(row.total_amount) }}</span>
          </template>
          <template #cell-status="{ value }">
            <span class="text-[10px]" :class="statusPill(value)">{{ statusLabel(value) }}</span>
          </template>
          <template #empty>
            <EmptyState
              title="No bills here"
              description="Upload a vendor bill or create one manually to get started."
            >
              <template #actions>
                <button class="sn-btn sn-btn-primary" data-testid="bills-empty-new" @click="toggleNewBill">New bill</button>
              </template>
            </EmptyState>
          </template>
        </DataTable>
      </section>

      <!-- Side column: aging -->
      <AgingWidget :aging="apStore.aging" />
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useApStore } from '../../../stores/ap.js'
import DataTable from '../../../components/data/DataTable.vue'
import EmptyState from '../../../components/data/EmptyState.vue'
import ReceiptUpload from '../../../components/financial/ReceiptUpload.vue'
import AgingWidget from '../../../components/financial/AgingWidget.vue'

const router = useRouter()
const apStore = useApStore()

const activeTab = ref('inbox')
const showUpload = ref(false)
const showNewBill = ref(false)
const uploadProgress = ref(0)
const uploadError = ref('')
const uploadNote = ref('')
const creating = ref(false)
const formError = ref('')

const form = reactive({
  vendor_id: '',
  bill_number: '',
  due_date: '',
  lines: [{ description: '', amount: '' }],
})

const activeVendors = computed(() =>
  (apStore.vendors || []).filter((v) => v.status !== 'archived')
)

const tabs = computed(() => {
  const c = apStore.tabCounts
  return [
    { label: 'Inbox',             value: 'inbox',     count: c.draft },
    { label: 'Awaiting approval', value: 'awaiting',  count: c.awaiting_approval },
    { label: 'Scheduled',         value: 'scheduled', count: c.scheduled },
    { label: 'Paid',              value: 'paid',      count: c.paid },
  ]
})

const TAB_STATUSES = {
  inbox: ['draft'],
  awaiting: ['awaiting_approval'],
  scheduled: ['approved', 'scheduled'],
  paid: ['paid'],
}

const rows = computed(() => {
  const statuses = TAB_STATUSES[activeTab.value] || []
  return (apStore.bills || []).filter((b) => statuses.includes(b.status))
})

const columns = [
  { key: 'vendor_name',  label: 'Vendor', sortable: true },
  { key: 'bill_number',  label: 'Bill #', sortable: true },
  { key: 'due_date',     label: 'Due', sortable: true },
  { key: 'total_amount', label: 'Amount', sortable: true },
  { key: 'status',       label: 'Status', sortable: true },
]

function vendorName(row) {
  return row.vendor_name
    || row.vendor?.name
    || apStore.vendors.find((v) => v.id === row.vendor_id)?.name
    || '—'
}

function isOverdue(bill) {
  if (!bill?.due_date || ['paid', 'voided'].includes(bill.status)) return false
  const due = new Date(bill.due_date)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return !Number.isNaN(due.getTime()) && due < today
}

function toggleUpload() {
  showUpload.value = !showUpload.value
  showNewBill.value = false
  uploadError.value = ''
  uploadNote.value = ''
}

function toggleNewBill() {
  showNewBill.value = !showNewBill.value
  showUpload.value = false
  formError.value = ''
}

async function onUpload(file) {
  uploadError.value = ''
  uploadNote.value = ''
  uploadProgress.value = 1
  const res = await apStore.uploadBillDoc(file, (pct) => { uploadProgress.value = pct })
  uploadProgress.value = 0
  if (!res.success) {
    uploadError.value = res.error || 'Upload failed.'
    return
  }
  uploadNote.value = 'Uploaded — extraction is running. The draft will land in the inbox.'
  await Promise.all([apStore.fetchBills(), apStore.fetchSummary()])
}

async function createBill() {
  formError.value = ''
  const lines = form.lines
    .filter((l) => l.description.trim() || Number(l.amount) > 0)
    .map((l) => ({ description: l.description.trim(), amount: String(l.amount) }))
  if (!form.vendor_id || !form.due_date || !lines.length || lines.some((l) => !(Number(l.amount) > 0))) {
    formError.value = 'Vendor, due date, and at least one line with a positive amount are required.'
    return
  }
  creating.value = true
  const res = await apStore.createBill({
    vendor_id: form.vendor_id,
    bill_number: form.bill_number.trim() || undefined,
    due_date: form.due_date,
    lines,
  })
  creating.value = false
  if (!res.success) {
    formError.value = res.error || 'Failed to create bill.'
    return
  }
  router.push(`/financial/bills/${res.data.id}`)
}

function openDetail(row) {
  router.push(`/financial/bills/${row.id}`)
}

function formatAmount(v) {
  return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })
}

function formatDate(v) {
  if (!v) return '—'
  try { return new Date(v).toLocaleDateString() } catch { return String(v) }
}

function statusLabel(s) {
  return String(s || '').replaceAll('_', ' ')
}

function statusPill(s) {
  if (s === 'paid' || s === 'approved')            return 'sn-pill sn-pill-success'
  if (s === 'rejected' || s === 'voided')          return 'sn-pill sn-pill-danger'
  if (s === 'awaiting_approval' || s === 'scheduled') return 'sn-pill sn-pill-warn'
  return 'sn-pill'
}

onMounted(() => {
  apStore.fetchBills()
  apStore.fetchSummary()
  apStore.fetchAging()
  apStore.fetchVendors()
})
</script>

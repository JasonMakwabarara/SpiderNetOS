<template>
  <div class="px-8 py-6 max-w-[1200px] mx-auto">
    <!-- Header -->
    <header class="flex items-start justify-between gap-4 mb-5">
      <div>
        <h1 class="text-[26px] font-heading font-semibold tracking-tight" style="color: var(--text-primary);">Vendors</h1>
        <p class="text-sm mt-1" style="color: var(--text-muted);">
          Supplier directory — payment terms, contacts, and bill history.
        </p>
      </div>
      <button class="sn-btn sn-btn-primary shrink-0" data-testid="vendors-new-button" @click="openCreate">
        + New vendor
      </button>
    </header>

    <!-- Search -->
    <div class="mb-4">
      <FilterBar
        v-model="filters"
        :fields="[{ key: 'search', label: 'Search', type: 'text' }]"
        data-testid="vendors-filter-bar"
      />
    </div>

    <!-- Table -->
    <section class="sn-card overflow-hidden">
      <DataTable
        :columns="columns"
        :rows="rows"
        :loading="apStore.loading"
        caption="Vendor directory"
        clickable-rows
        data-testid="vendors-table"
        @row-click="openDetail"
      >
        <template #cell-name="{ value }">
          <span style="color: var(--text-primary);">{{ value }}</span>
        </template>
        <template #cell-payment_terms="{ value }">
          <span class="mono text-xs">{{ termsLabel(value) }}</span>
        </template>
        <template #cell-open_balance="{ value }">
          <span class="mono">{{ formatAmount(value) }}</span>
        </template>
        <template #cell-status="{ value }">
          <span class="text-[10px]" :class="value === 'archived' ? 'sn-pill sn-pill-danger' : 'sn-pill sn-pill-success'">
            {{ value || 'active' }}
          </span>
        </template>
        <template #empty>
          <EmptyState
            title="No vendors yet"
            description="Add your first supplier to start capturing bills."
          >
            <template #actions>
              <button class="sn-btn sn-btn-primary" data-testid="vendors-empty-new" @click="openCreate">New vendor</button>
            </template>
          </EmptyState>
        </template>
      </DataTable>
    </section>

    <!-- Create modal -->
    <ConfirmDialog
      v-model="createOpen"
      title="New vendor"
      confirm-label="Create vendor"
      data-testid="vendor-create-dialog"
      @confirm="createVendor"
    >
      <div class="space-y-3">
        <div>
          <label for="vendor-name">Name</label>
          <input id="vendor-name" v-model="form.name" type="text" placeholder="e.g. Cardiff Print Co." data-testid="vendor-name-input" />
        </div>
        <div>
          <label for="vendor-email">Email</label>
          <input id="vendor-email" v-model="form.email" type="email" placeholder="billing@vendor.com" data-testid="vendor-email-input" />
        </div>
        <div>
          <label for="vendor-terms">Payment terms</label>
          <select id="vendor-terms" v-model="form.payment_terms" data-testid="vendor-terms-select">
            <option value="due_on_receipt">Due on receipt</option>
            <option value="net_7">Net 7</option>
            <option value="net_14">Net 14</option>
            <option value="net_30">Net 30</option>
            <option value="net_60">Net 60</option>
          </select>
        </div>
        <p v-if="formError" class="text-xs" style="color: var(--danger);" data-testid="vendor-form-error">{{ formError }}</p>
      </div>
    </ConfirmDialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useApStore } from '../../../stores/ap.js'
import DataTable from '../../../components/data/DataTable.vue'
import EmptyState from '../../../components/data/EmptyState.vue'
import FilterBar from '../../../components/data/FilterBar.vue'
import ConfirmDialog from '../../../components/feedback/ConfirmDialog.vue'

const router = useRouter()
const apStore = useApStore()

const filters = ref({})
const createOpen = ref(false)
const formError = ref('')

const form = reactive({ name: '', email: '', payment_terms: 'net_30' })

const columns = [
  { key: 'name',          label: 'Vendor', sortable: true },
  { key: 'email',         label: 'Email', sortable: true },
  { key: 'payment_terms', label: 'Terms', sortable: true },
  { key: 'open_balance',  label: 'Open balance', sortable: true },
  { key: 'status',        label: 'Status', sortable: true },
]

const rows = computed(() => {
  const q = String(filters.value?.search || '').trim().toLowerCase()
  const all = apStore.vendors || []
  if (!q) return all
  return all.filter((v) =>
    String(v.name || '').toLowerCase().includes(q)
    || String(v.email || '').toLowerCase().includes(q)
  )
})

function openCreate() {
  formError.value = ''
  Object.assign(form, { name: '', email: '', payment_terms: 'net_30' })
  createOpen.value = true
}

async function createVendor() {
  formError.value = ''
  if (!form.name.trim()) {
    formError.value = 'Vendor name is required.'
    createOpen.value = true // keep the dialog visible for correction
    return
  }
  const res = await apStore.createVendor({
    name: form.name.trim(),
    email: form.email.trim() || undefined,
    payment_terms: form.payment_terms,
  })
  if (!res.success) {
    formError.value = res.error || 'Failed to create vendor.'
    createOpen.value = true
    return
  }
  if (res.data?.id) router.push(`/financial/vendors/${res.data.id}`)
}

function openDetail(row) {
  router.push(`/financial/vendors/${row.id}`)
}

function termsLabel(v) {
  return String(v || '—').replaceAll('_', ' ')
}

function formatAmount(v) {
  if (v == null) return '—'
  return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })
}

onMounted(() => apStore.fetchVendors())
</script>

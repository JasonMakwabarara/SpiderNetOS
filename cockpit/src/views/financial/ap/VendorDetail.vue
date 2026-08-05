<template>
  <div class="px-8 py-6 max-w-[1100px] mx-auto">
    <div v-if="apStore.loading && !vendor" class="sn-card sn-shimmer h-32" data-testid="vendor-detail-loading"></div>

    <template v-else-if="vendor">
      <!-- Header -->
      <header class="flex items-start justify-between gap-4 mb-5">
        <div class="min-w-0">
          <div class="flex items-center gap-2 mb-1">
            <span
              class="text-[10px]"
              :class="vendor.status === 'archived' ? 'sn-pill sn-pill-danger' : 'sn-pill sn-pill-success'"
              data-testid="vendor-status"
            >{{ vendor.status || 'active' }}</span>
          </div>
          <h1 class="text-[24px] font-heading font-semibold tracking-tight truncate" style="color: var(--text-primary);">
            {{ vendor.name }}
          </h1>
          <p class="text-xs mt-1 mono" style="color: var(--text-muted);">{{ vendor.id }}</p>
        </div>
        <button
          v-if="vendor.status !== 'archived'"
          class="sn-btn sn-btn-danger shrink-0"
          data-testid="vendor-archive-button"
          @click="archiveOpen = true"
        >Archive vendor</button>
      </header>

      <div class="grid grid-cols-1 lg:grid-cols-[320px_1fr] gap-4 items-start">
        <!-- Profile card -->
        <section class="sn-card p-4 space-y-3" data-testid="vendor-profile-card">
          <h3 class="text-[10px] uppercase tracking-widest font-semibold" style="color: var(--text-muted);">Profile</h3>
          <dl class="space-y-2 text-sm">
            <div class="flex items-center justify-between gap-3">
              <dt style="color: var(--text-muted);">Email</dt>
              <dd class="truncate" style="color: var(--text-secondary);">{{ vendor.email || '—' }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
              <dt style="color: var(--text-muted);">Payment terms</dt>
              <dd class="mono text-xs" style="color: var(--text-primary);" data-testid="vendor-payment-terms">
                {{ termsLabel(vendor.payment_terms) }}
              </dd>
            </div>
            <div class="flex items-center justify-between gap-3">
              <dt style="color: var(--text-muted);">Open balance</dt>
              <dd class="mono" style="color: var(--text-primary);" data-testid="vendor-open-balance">
                {{ formatAmount(openBalance) }}
              </dd>
            </div>
            <div class="flex items-center justify-between gap-3">
              <dt style="color: var(--text-muted);">Bills on file</dt>
              <dd class="mono" style="color: var(--text-secondary);">{{ vendorBills.length }}</dd>
            </div>
          </dl>
        </section>

        <!-- Recent bills -->
        <section class="sn-card overflow-hidden min-w-0" data-testid="vendor-bills">
          <div class="px-4 py-2.5 border-b text-[11px] uppercase tracking-widest font-semibold"
               style="border-color: var(--border); color: var(--text-muted);">
            Recent bills · {{ vendorBills.length }}
          </div>
          <DataTable
            :columns="billColumns"
            :rows="vendorBills"
            :loading="apStore.loading"
            caption="Vendor bills"
            clickable-rows
            data-testid="vendor-bills-table"
            @row-click="openBill"
          >
            <template #cell-bill_number="{ value }">
              <span class="mono text-xs">{{ value || '—' }}</span>
            </template>
            <template #cell-due_date="{ value }">
              {{ formatDate(value) }}
            </template>
            <template #cell-total_amount="{ row }">
              <span class="mono">{{ row.currency || 'USD' }} {{ formatAmount(row.total_amount) }}</span>
            </template>
            <template #cell-status="{ value }">
              <span class="text-[10px]" :class="statusPill(value)">{{ statusLabel(value) }}</span>
            </template>
            <template #empty>
              <span class="text-sm" style="color: var(--text-muted);">No bills for this vendor yet.</span>
            </template>
          </DataTable>
        </section>
      </div>
    </template>

    <div v-else class="sn-card p-10 text-center text-sm" style="color: var(--text-muted);" data-testid="vendor-not-found">
      Vendor not found.
      <RouterLink to="/financial/vendors" class="block mt-2" style="color: var(--accent);">Back to vendors</RouterLink>
    </div>

    <!-- Archive confirm -->
    <ConfirmDialog
      v-model="archiveOpen"
      :title="`Archive · ${vendor?.name || 'vendor'}`"
      confirm-label="Archive"
      destructive
      data-testid="vendor-archive-dialog"
      @confirm="doArchive"
    >
      <p class="text-sm" style="color: var(--text-secondary);">
        The vendor is hidden from pickers. Existing bills keep their history.
      </p>
    </ConfirmDialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useApStore } from '../../../stores/ap.js'
import DataTable from '../../../components/data/DataTable.vue'
import ConfirmDialog from '../../../components/feedback/ConfirmDialog.vue'

const route = useRoute()
const router = useRouter()
const apStore = useApStore()

const archiveOpen = ref(false)

const vendor = computed(() => apStore.currentVendor)

const vendorBills = computed(() =>
  (apStore.bills || []).filter((b) => b.vendor_id === route.params.id)
)

const openBalance = computed(() => {
  if (vendor.value?.open_balance != null) return vendor.value.open_balance
  return vendorBills.value
    .filter((b) => !['paid', 'voided'].includes(b.status))
    .reduce((sum, b) => sum + Number(b.total_amount || 0), 0)
})

const billColumns = [
  { key: 'bill_number',  label: 'Bill #', sortable: true },
  { key: 'due_date',     label: 'Due', sortable: true },
  { key: 'total_amount', label: 'Amount', sortable: true },
  { key: 'status',       label: 'Status', sortable: true },
]

function openBill(row) {
  router.push(`/financial/bills/${row.id}`)
}

async function doArchive() {
  const res = await apStore.archiveVendor(vendor.value.id)
  if (res.success) router.push('/financial/vendors')
}

function termsLabel(v) {
  return String(v || '—').replaceAll('_', ' ')
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
  if (s === 'paid' || s === 'approved')               return 'sn-pill sn-pill-success'
  if (s === 'rejected' || s === 'voided')             return 'sn-pill sn-pill-danger'
  if (s === 'awaiting_approval' || s === 'scheduled') return 'sn-pill sn-pill-warn'
  return 'sn-pill'
}

async function load() {
  await apStore.fetchVendor(route.params.id)
  // Recent bills — fetch the inbox and filter client-side by vendor.
  await apStore.fetchBills()
}

watch(() => route.params.id, (id, prev) => { if (id && id !== prev) load() })

onMounted(load)
</script>

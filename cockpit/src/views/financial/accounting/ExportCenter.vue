<template>
  <div class="px-8 py-6 max-w-[1500px] mx-auto space-y-5">
    <!-- Header -->
    <header class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-[26px] font-heading font-semibold tracking-tight" style="color: var(--text-primary);">Export center</h1>
        <p class="text-sm mt-1" style="color: var(--text-muted);">
          Generate accounting exports for QuickBooks, Xero, or a generic CSV — on demand or on a schedule.
        </p>
      </div>
      <RouterLink to="/financial/accounting" class="sn-btn shrink-0" data-testid="export-back-link">
        ← Accounting
      </RouterLink>
    </header>

    <div class="grid grid-cols-1 lg:grid-cols-[380px_1fr] gap-4 items-start">
      <div class="space-y-4 min-w-0">
        <!-- Request form -->
        <section class="sn-card p-5" data-testid="export-request-panel">
          <h3 class="text-[10px] uppercase tracking-widest font-semibold mb-3" style="color: var(--text-muted);">
            Request an export
          </h3>
          <div class="space-y-3">
            <div>
              <label for="export-format">Format</label>
              <select id="export-format" v-model="form.export_type" data-testid="export-format-select">
                <option v-for="(label, value) in EXPORT_TYPES" :key="value" :value="value">{{ label }}</option>
              </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label for="export-from">From</label>
                <input id="export-from" v-model="form.period_start" type="date" data-testid="export-from-input" />
              </div>
              <div>
                <label for="export-to">To</label>
                <input id="export-to" v-model="form.period_end" type="date" data-testid="export-to-input" />
              </div>
            </div>
            <p v-if="formError" class="text-xs" style="color: var(--danger);" data-testid="export-form-error">{{ formError }}</p>
            <p v-else-if="requestedNote" class="text-xs" style="color: var(--success);" data-testid="export-requested-note">
              Export queued — it will appear in the history as soon as it generates.
            </p>
            <button
              class="sn-btn sn-btn-primary w-full"
              :disabled="requesting"
              data-testid="export-request-submit"
              @click="submitRequest"
            >{{ requesting ? 'Requesting…' : 'Generate export' }}</button>
          </div>
        </section>

        <!-- Schedules panel -->
        <section class="sn-card p-5" data-testid="export-schedules-panel">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-[10px] uppercase tracking-widest font-semibold" style="color: var(--text-muted);">
              Scheduled exports
            </h3>
            <button class="sn-btn text-xs" data-testid="schedule-add" @click="addSchedule">+ Add</button>
          </div>

          <div v-if="!accountingStore.schedules.length" class="py-6 text-center text-sm" style="color: var(--text-muted);" data-testid="schedules-empty">
            No schedules yet — add one to export automatically.
          </div>
          <ul v-else class="divide-y" style="border-color: var(--divider);" data-testid="schedules-list">
            <li
              v-for="s in accountingStore.schedules"
              :key="s.id"
              class="py-2.5 flex items-center gap-2.5"
              :data-testid="`schedule-row-${s.id}`"
            >
              <div class="min-w-0 flex-1">
                <div class="text-sm" style="color: var(--text-primary);">{{ EXPORT_TYPES[s.export_type] || s.export_type }}</div>
                <div class="text-[11px] mt-0.5" style="color: var(--text-muted);">
                  {{ s.enabled ? 'Runs' : 'Paused' }} · {{ s.frequency }}
                </div>
              </div>
              <select
                class="w-auto text-xs"
                :value="s.frequency"
                :data-testid="`schedule-frequency-${s.id}`"
                @change="updateSchedule(s, { frequency: $event.target.value })"
              >
                <option v-for="f in FREQUENCIES" :key="f" :value="f">{{ f }}</option>
              </select>
              <label class="flex items-center gap-1.5 cursor-pointer select-none mb-0 shrink-0">
                <input
                  type="checkbox"
                  class="w-auto"
                  :checked="!!s.enabled"
                  :data-testid="`schedule-enabled-${s.id}`"
                  @change="updateSchedule(s, { enabled: $event.target.checked })"
                />
                <span class="text-xs" style="color: var(--text-secondary);">on</span>
              </label>
              <button
                class="text-xs font-medium px-1 shrink-0"
                style="color: var(--danger);"
                :data-testid="`schedule-delete-${s.id}`"
                @click="askDeleteSchedule(s)"
              >Remove</button>
            </li>
          </ul>
        </section>
      </div>

      <!-- History -->
      <section class="sn-card overflow-hidden min-w-0" data-testid="export-history-panel">
        <DataTable
          :columns="columns"
          :rows="accountingStore.exports"
          :loading="accountingStore.loading"
          caption="Export history"
          data-testid="export-history-table"
        >
          <template #cell-export_type="{ value }">
            <span style="color: var(--text-primary);">{{ EXPORT_TYPES[value] || value || '—' }}</span>
          </template>
          <template #cell-period="{ row }">
            <span class="mono text-xs">{{ formatDate(row.period_start) }} → {{ formatDate(row.period_end) }}</span>
          </template>
          <template #cell-status="{ value }">
            <span class="text-[10px]" :class="statusPill(value)">{{ String(value || '').replaceAll('_', ' ') }}</span>
          </template>
          <template #cell-row_count="{ value }">
            <span class="mono">{{ value ?? '—' }}</span>
          </template>
          <template #actions="{ row }">
            <a
              v-if="row.status === 'generated'"
              class="text-xs font-medium"
              style="color: var(--accent);"
              :href="accountingStore.downloadUrl(row.id)"
              target="_blank"
              rel="noopener"
              :data-testid="`export-download-${row.id}`"
            >Download</a>
            <span v-else class="text-xs" style="color: var(--text-muted);">—</span>
          </template>
          <template #empty>
            <EmptyState
              title="No exports yet"
              description="Request your first export to hand your books to QuickBooks, Xero, or a spreadsheet."
            />
          </template>
        </DataTable>
      </section>
    </div>

    <!-- Confirm: delete schedule -->
    <ConfirmDialog
      v-model="showDeleteConfirm"
      title="Remove this schedule?"
      :message="deleteTarget ? `${EXPORT_TYPES[deleteTarget.export_type] || deleteTarget.export_type} · ${deleteTarget.frequency} — future automatic exports will stop.` : ''"
      confirm-label="Remove"
      destructive
      @confirm="confirmDeleteSchedule"
    />
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useAccountingStore, EXPORT_TYPES } from '../../../stores/accounting.js'
import DataTable from '../../../components/data/DataTable.vue'
import EmptyState from '../../../components/data/EmptyState.vue'
import ConfirmDialog from '../../../components/feedback/ConfirmDialog.vue'

const accountingStore = useAccountingStore()

const FREQUENCIES = ['weekly', 'monthly', 'quarterly']

// ── Request form ───────────────────────────────────────────────────
const form = reactive({
  export_type: 'quickbooks',
  period_start: '',
  period_end: '',
})
const requesting = ref(false)
const formError = ref('')
const requestedNote = ref(false)

async function submitRequest() {
  formError.value = ''
  requestedNote.value = false
  if (!form.period_start || !form.period_end) {
    formError.value = 'Both period dates are required.'
    return
  }
  if (form.period_end < form.period_start) {
    formError.value = 'The end date must be on or after the start date.'
    return
  }
  requesting.value = true
  const res = await accountingStore.requestExport({
    export_type: form.export_type,
    period_start: form.period_start,
    period_end: form.period_end,
  })
  requesting.value = false
  if (!res.success) {
    formError.value = res.error || 'Failed to request export.'
    return
  }
  requestedNote.value = true
  setTimeout(() => { requestedNote.value = false }, 5000)
}

// ── History table ──────────────────────────────────────────────────
const columns = [
  { key: 'export_type', label: 'Type', sortable: true },
  { key: 'period',      label: 'Period' },
  { key: 'status',      label: 'Status', sortable: true },
  { key: 'row_count',   label: 'Rows', sortable: true },
]

function statusPill(s) {
  if (s === 'generated') return 'sn-pill sn-pill-success'
  if (s === 'failed')    return 'sn-pill sn-pill-danger'
  if (s === 'generating' || s === 'pending') return 'sn-pill sn-pill-warn'
  return 'sn-pill'
}

// ── Schedules ──────────────────────────────────────────────────────
const showDeleteConfirm = ref(false)
const deleteTarget = ref(null)

function addSchedule() {
  accountingStore.saveSchedule({
    export_type: form.export_type,
    frequency: 'monthly',
    enabled: true,
  })
}

function updateSchedule(schedule, patch) {
  accountingStore.saveSchedule({ id: schedule.id, ...patch })
}

function askDeleteSchedule(schedule) {
  deleteTarget.value = schedule
  showDeleteConfirm.value = true
}

async function confirmDeleteSchedule() {
  if (!deleteTarget.value) return
  await accountingStore.deleteSchedule(deleteTarget.value.id)
  deleteTarget.value = null
}

// ── Helpers ────────────────────────────────────────────────────────
function formatDate(v) {
  if (!v) return '—'
  try { return new Date(v).toLocaleDateString() } catch { return String(v) }
}

onMounted(() => {
  accountingStore.fetchExports()
  accountingStore.fetchSchedules()
})
</script>

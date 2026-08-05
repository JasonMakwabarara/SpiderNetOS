<template>
  <div class="px-8 py-6 max-w-[1500px] mx-auto space-y-5">
    <!-- Header -->
    <header class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-[26px] font-heading font-semibold tracking-tight" style="color: var(--text-primary);">Accounting</h1>
        <p class="text-sm mt-1" style="color: var(--text-muted);">
          Map spend categories to your general ledger, control posting automation, and review journal postings.
        </p>
      </div>
      <RouterLink to="/financial/accounting/export" class="sn-btn shrink-0" data-testid="accounting-export-link">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14"/>
        </svg>
        Export center
      </RouterLink>
    </header>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
      <!-- Panel 1: Category → GL mapping -->
      <section class="sn-card p-5" data-testid="accounting-mappings-panel">
        <div class="flex items-end justify-between mb-4">
          <div>
            <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Chart of accounts</div>
            <h3 class="font-heading font-semibold text-[15px] mt-0.5" style="color: var(--text-primary);">Category → GL mapping</h3>
          </div>
          <div class="flex items-center gap-2">
            <span v-if="mappingsDirty" class="sn-pill sn-pill-warn" data-testid="mappings-dirty-pill">unsaved</span>
            <span v-else-if="mappingsSavedNote" class="sn-pill sn-pill-success" data-testid="mappings-saved-pill">saved</span>
            <button
              class="sn-btn sn-btn-primary text-xs"
              :disabled="!mappingsDirty || savingMappings"
              data-testid="mappings-save-all"
              @click="saveAllMappings"
            >{{ savingMappings ? 'Saving…' : 'Save all' }}</button>
          </div>
        </div>

        <div v-if="accountingStore.loading && !localMappings.length" class="py-8 text-center text-sm" style="color: var(--text-muted);">
          Loading mappings…
        </div>
        <div v-else-if="!localMappings.length" class="py-8 text-center text-sm" style="color: var(--text-muted);" data-testid="mappings-empty">
          No spend categories found yet — create expense categories first.
        </div>
        <table v-else class="w-full text-sm" data-testid="mappings-table">
          <thead>
            <tr class="text-left text-[10px] uppercase tracking-widest" style="color: var(--text-muted);">
              <th class="py-1.5 pr-3 font-semibold">Category</th>
              <th class="py-1.5 font-semibold">GL account</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(m, i) in localMappings"
              :key="m.category_id || m.id || i"
              class="border-t"
              style="border-color: var(--divider);"
              :data-testid="`mapping-row-${i}`"
            >
              <td class="py-2 pr-3" style="color: var(--text-primary);">{{ m.category_name || m.category || '—' }}</td>
              <td class="py-2">
                <select
                  v-model="m.gl_account_id"
                  class="w-full"
                  :data-testid="`mapping-account-${i}`"
                >
                  <option value="">Unmapped</option>
                  <option v-for="a in accountingStore.chartAccounts" :key="a.id" :value="a.id">
                    {{ a.code ? `${a.code} · ` : '' }}{{ a.name }}
                  </option>
                </select>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-if="mappingsError" class="text-xs mt-2" style="color: var(--danger);" data-testid="mappings-error">{{ mappingsError }}</p>
      </section>

      <!-- Panel 2: Posting rules -->
      <section class="sn-card p-5" data-testid="accounting-rules-panel">
        <div class="flex items-end justify-between mb-4">
          <div>
            <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Automation</div>
            <h3 class="font-heading font-semibold text-[15px] mt-0.5" style="color: var(--text-primary);">Posting rules</h3>
          </div>
          <span class="sn-pill" :class="rulesForm.enabled ? (rulesForm.mode === 'auto' ? 'sn-pill-accent' : 'sn-pill') : 'sn-pill-warn'" data-testid="rules-state-pill">
            {{ rulesForm.enabled ? rulesForm.mode : 'off' }}
          </span>
        </div>

        <!-- Mode toggle -->
        <div class="flex rounded-md overflow-hidden border w-fit" style="border-color: var(--border);" data-testid="rules-mode-toggle">
          <button
            v-for="m in ['draft', 'auto']"
            :key="m"
            class="px-4 py-1.5 text-xs font-medium transition-colors"
            :style="rulesForm.mode === m
              ? 'background: var(--accent-weak); color: var(--accent);'
              : 'background: transparent; color: var(--text-muted);'"
            :data-testid="`rules-mode-${m}`"
            @click="setMode(m)"
          >{{ m === 'auto' ? 'Auto-post' : 'Draft first' }}</button>
        </div>
        <p class="text-xs mt-2 max-w-[46ch]" style="color: var(--text-muted);" data-testid="rules-mode-copy">
          <template v-if="rulesForm.mode === 'auto'">
            Journal entries post to the ledger the moment an expense is reimbursed or a bill is paid — no review step. Best once your mappings are stable.
          </template>
          <template v-else>
            Journal entries land as drafts in the postings list below and only hit the ledger when you execute them. Safest while you tune category mappings.
          </template>
        </p>

        <!-- Credit accounts -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-4">
          <div>
            <label for="rules-expense-credit">Expense credit account</label>
            <select id="rules-expense-credit" v-model="rulesForm.expense_credit_account_id" data-testid="rules-expense-credit">
              <option value="">Select account…</option>
              <option v-for="a in accountingStore.chartAccounts" :key="a.id" :value="a.id">
                {{ a.code ? `${a.code} · ` : '' }}{{ a.name }}
              </option>
            </select>
            <p class="text-[11px] mt-1" style="color: var(--text-muted);">Credited when reimbursements post.</p>
          </div>
          <div>
            <label for="rules-ap-credit">Bill payment credit account</label>
            <select id="rules-ap-credit" v-model="rulesForm.ap_credit_account_id" data-testid="rules-ap-credit">
              <option value="">Select account…</option>
              <option v-for="a in accountingStore.chartAccounts" :key="a.id" :value="a.id">
                {{ a.code ? `${a.code} · ` : '' }}{{ a.name }}
              </option>
            </select>
            <p class="text-[11px] mt-1" style="color: var(--text-muted);">Credited when bill payments post.</p>
          </div>
        </div>

        <!-- Enabled switch -->
        <div class="flex items-center justify-between mt-4 pt-3 border-t" style="border-color: var(--border);">
          <label for="rules-enabled" class="flex items-center gap-2 cursor-pointer select-none mb-0">
            <input
              id="rules-enabled"
              v-model="rulesForm.enabled"
              type="checkbox"
              class="w-auto"
              data-testid="rules-enabled-switch"
            />
            <span class="text-sm" style="color: var(--text-primary);">Posting enabled</span>
          </label>
          <button
            class="sn-btn sn-btn-primary text-xs"
            :disabled="savingRules"
            data-testid="rules-save"
            @click="persistRules"
          >{{ savingRules ? 'Saving…' : 'Save rules' }}</button>
        </div>
        <p v-if="rulesError" class="text-xs mt-2" style="color: var(--danger);" data-testid="rules-error">{{ rulesError }}</p>
        <p v-else-if="rulesSavedNote" class="text-xs mt-2" style="color: var(--success);" data-testid="rules-saved-note">Posting rules saved.</p>
      </section>
    </div>

    <!-- Postings sub-list -->
    <section class="sn-card p-5" data-testid="accounting-postings-panel">
      <div class="flex items-end justify-between mb-3">
        <div>
          <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Journal</div>
          <h3 class="font-heading font-semibold text-[15px] mt-0.5" style="color: var(--text-primary);">Postings</h3>
        </div>
        <div class="flex items-center gap-1" data-testid="postings-filter">
          <button
            v-for="f in POSTING_FILTERS"
            :key="f.value"
            class="px-2.5 py-1 rounded-md text-xs font-medium transition-colors"
            :style="postingFilter === f.value
              ? 'background: var(--accent-weak); color: var(--accent); border: 1px solid rgba(0,214,201,0.30);'
              : 'background: var(--bg-elevated); color: var(--text-muted); border: 1px solid var(--border);'"
            :data-testid="`postings-filter-${f.value}`"
            @click="setPostingFilter(f.value)"
          >{{ f.label }}</button>
        </div>
      </div>

      <div v-if="!accountingStore.postings.length" class="py-8 text-center text-sm" style="color: var(--text-muted);" data-testid="postings-empty">
        No journal postings for this filter.
      </div>
      <ul v-else class="divide-y" style="border-color: var(--divider);" data-testid="postings-list">
        <li
          v-for="p in accountingStore.postings"
          :key="p.id"
          class="py-2.5 flex items-center gap-3"
          :data-testid="`posting-row-${p.id}`"
        >
          <span class="text-[10px]" :class="postingPill(p.status)" :data-testid="`posting-status-${p.id}`">
            {{ String(p.status || '').replaceAll('_', ' ') }}
          </span>
          <div class="min-w-0 flex-1">
            <div class="text-sm truncate" style="color: var(--text-primary);">{{ p.description || p.memo || p.id }}</div>
            <div class="text-[11px] mt-0.5 mono" style="color: var(--text-muted);">
              {{ formatDate(p.journal_date || p.date || p.created_at) }}
              <span v-if="p.source_type"> · {{ p.source_type }}</span>
            </div>
          </div>
          <span class="mono text-sm shrink-0" style="color: var(--text-secondary);">
            {{ p.currency || 'USD' }} {{ formatAmount(p.total_amount ?? p.amount) }}
          </span>
          <button
            v-if="p.status === 'draft'"
            class="sn-btn text-xs shrink-0"
            :data-testid="`posting-execute-${p.id}`"
            @click="askExecute(p)"
          >Execute</button>
        </li>
      </ul>
    </section>

    <!-- Confirm: switch to auto-post -->
    <ConfirmDialog
      v-model="showAutoConfirm"
      title="Switch to auto-posting?"
      message="Journal entries will post straight to the ledger without a draft review step. You can switch back to draft mode at any time."
      confirm-label="Switch to auto"
      @confirm="confirmAutoMode"
    />

    <!-- Confirm: execute draft posting -->
    <ConfirmDialog
      v-model="showExecuteConfirm"
      title="Execute this posting?"
      :message="`This writes the journal entry to the ledger${executeTarget ? ` (${executeTarget.currency || 'USD'} ${formatAmount(executeTarget.total_amount ?? executeTarget.amount)})` : ''}. Ledger entries are immutable once posted.`"
      confirm-label="Execute"
      @confirm="confirmExecute"
    />
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useAccountingStore } from '../../../stores/accounting.js'
import ConfirmDialog from '../../../components/feedback/ConfirmDialog.vue'

const accountingStore = useAccountingStore()

// ── Mappings: local draft + dirty tracking ─────────────────────────
const localMappings = ref([])
const originalSnapshot = ref('')
const savingMappings = ref(false)
const mappingsError = ref('')
const mappingsSavedNote = ref(false)

function snapshot(list) {
  return JSON.stringify(list.map((m) => ({ c: m.category_id ?? m.id, a: m.gl_account_id ?? '' })))
}

watch(
  () => accountingStore.mappings,
  (next) => {
    localMappings.value = (next || []).map((m) => ({ ...m, gl_account_id: m.gl_account_id ?? '' }))
    originalSnapshot.value = snapshot(localMappings.value)
  },
  { immediate: true, deep: false },
)

const mappingsDirty = computed(() => snapshot(localMappings.value) !== originalSnapshot.value)

async function saveAllMappings() {
  mappingsError.value = ''
  mappingsSavedNote.value = false
  savingMappings.value = true
  const payload = localMappings.value.map((m) => ({
    id: m.id,
    category_id: m.category_id ?? m.id,
    gl_account_id: m.gl_account_id || null,
  }))
  const res = await accountingStore.saveMappings(payload)
  savingMappings.value = false
  if (!res.success) {
    mappingsError.value = res.error || 'Failed to save mappings.'
    return
  }
  originalSnapshot.value = snapshot(localMappings.value)
  mappingsSavedNote.value = true
  setTimeout(() => { mappingsSavedNote.value = false }, 4000)
}

// ── Posting rules ──────────────────────────────────────────────────
const rulesForm = reactive({
  mode: 'draft',
  enabled: false,
  expense_credit_account_id: '',
  ap_credit_account_id: '',
})
const savingRules = ref(false)
const rulesError = ref('')
const rulesSavedNote = ref(false)
const showAutoConfirm = ref(false)

watch(
  () => accountingStore.rules,
  (r) => {
    if (!r) return
    rulesForm.mode = r.mode || 'draft'
    rulesForm.enabled = !!r.enabled
    rulesForm.expense_credit_account_id = r.expense_credit_account_id || ''
    rulesForm.ap_credit_account_id = r.ap_credit_account_id || ''
  },
  { immediate: true },
)

function setMode(mode) {
  if (mode === rulesForm.mode) return
  if (mode === 'auto') {
    // Guard the jump to unattended posting behind an explicit confirm.
    showAutoConfirm.value = true
    return
  }
  rulesForm.mode = mode
}

function confirmAutoMode() {
  rulesForm.mode = 'auto'
}

async function persistRules() {
  rulesError.value = ''
  rulesSavedNote.value = false
  savingRules.value = true
  const res = await accountingStore.saveRules({
    mode: rulesForm.mode,
    enabled: rulesForm.enabled,
    expense_credit_account_id: rulesForm.expense_credit_account_id || null,
    ap_credit_account_id: rulesForm.ap_credit_account_id || null,
  })
  savingRules.value = false
  if (!res.success) {
    rulesError.value = res.error || 'Failed to save posting rules.'
    return
  }
  rulesSavedNote.value = true
  setTimeout(() => { rulesSavedNote.value = false }, 4000)
}

// ── Postings ───────────────────────────────────────────────────────
const POSTING_FILTERS = [
  { label: 'All', value: '' },
  { label: 'Drafts', value: 'draft' },
  { label: 'Posted', value: 'posted' },
  { label: 'Failed', value: 'failed' },
]
const postingFilter = ref('')
const showExecuteConfirm = ref(false)
const executeTarget = ref(null)

function setPostingFilter(value) {
  postingFilter.value = value
  accountingStore.fetchPostings(value ? { status: value } : {})
}

function askExecute(posting) {
  executeTarget.value = posting
  showExecuteConfirm.value = true
}

async function confirmExecute() {
  if (!executeTarget.value) return
  await accountingStore.executePosting(executeTarget.value.id)
  executeTarget.value = null
}

function postingPill(s) {
  if (s === 'posted')  return 'sn-pill sn-pill-success'
  if (s === 'failed')  return 'sn-pill sn-pill-danger'
  if (s === 'draft')   return 'sn-pill sn-pill-warn'
  return 'sn-pill'
}

// ── Helpers ────────────────────────────────────────────────────────
function formatAmount(v) {
  return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })
}

function formatDate(v) {
  if (!v) return '—'
  try { return new Date(v).toLocaleDateString() } catch { return String(v) }
}

onMounted(() => {
  accountingStore.fetchMappings()
  accountingStore.fetchRules()
  accountingStore.fetchPostings()
})
</script>

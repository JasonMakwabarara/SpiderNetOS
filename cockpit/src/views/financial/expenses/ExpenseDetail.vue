<template>
  <div class="px-8 py-6 max-w-[1200px] mx-auto">
    <div v-if="expensesStore.loading && !expense" class="sn-card sn-shimmer h-40" data-testid="expense-detail-loading"></div>

    <template v-else-if="expense">
      <!-- Header -->
      <header class="flex items-start justify-between gap-4 mb-5">
        <div class="min-w-0">
          <div class="flex items-center gap-2 mb-1 flex-wrap">
            <span class="text-[10px]" :class="statusPill(expense.status)" data-testid="expense-status">
              {{ statusLabel(expense.status) }}
            </span>
            <span v-if="Number(expense.policy_violation_count) > 0" class="sn-pill sn-pill-warn text-[10px]" data-testid="expense-flag-count">
              {{ expense.policy_violation_count }} policy flags
            </span>
          </div>
          <h1 class="text-[24px] font-heading font-semibold tracking-tight truncate" style="color: var(--text-primary);">
            {{ expense.title }}
          </h1>
          <p class="text-xs mt-1 mono" style="color: var(--text-muted);">{{ expense.id }}</p>
        </div>
        <div class="text-right shrink-0">
          <div class="text-[10px] uppercase tracking-widest font-semibold" style="color: var(--text-muted);">Total</div>
          <div class="text-xl font-semibold mono" style="color: var(--text-primary);" data-testid="expense-total">
            {{ expense.currency || 'USD' }} {{ formatAmount(expense.total_amount) }}
          </div>
        </div>
      </header>

      <div class="grid grid-cols-1 lg:grid-cols-[1fr_340px] gap-4">
        <!-- Left: line items + receipts -->
        <div class="space-y-4 min-w-0">
          <!-- Line items -->
          <section class="sn-card overflow-hidden" data-testid="expense-line-items">
            <div class="px-4 py-2.5 border-b text-[11px] uppercase tracking-widest font-semibold"
                 style="border-color: var(--border); color: var(--text-muted);">
              Line items · {{ lineItems.length }}
            </div>
            <div class="overflow-x-auto">
              <table>
                <thead>
                  <tr>
                    <th>Date</th><th>Merchant</th><th>Description</th>
                    <th class="text-right">Amount</th><th>Receipt</th><th>Flags</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="li in lineItems" :key="li.id" :data-testid="`expense-line-${li.id}`">
                    <td class="whitespace-nowrap">{{ li.expense_date }}</td>
                    <td>{{ li.merchant }}</td>
                    <td class="max-w-[240px] truncate">{{ li.description }}</td>
                    <td class="text-right mono">{{ formatAmount(li.amount) }}</td>
                    <td>
                      <span v-if="li.has_receipt" class="sn-pill sn-pill-success text-[10px]">attached</span>
                      <span v-else class="sn-pill sn-pill-warn text-[10px]">missing</span>
                    </td>
                    <td>
                      <div class="flex items-center gap-1 flex-wrap">
                        <PolicyFlagPill v-for="flag in li.policy_flags || []" :key="flag" :code="flag" />
                        <span v-if="!(li.policy_flags || []).length" style="color: var(--text-muted);">—</span>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <!-- Receipt gallery -->
          <section v-if="documents.length" class="sn-card p-4" data-testid="expense-receipts">
            <h3 class="text-[10px] uppercase tracking-widest font-semibold mb-3" style="color: var(--text-muted);">
              Receipts · {{ documents.length }}
            </h3>
            <div class="flex gap-3 flex-wrap">
              <div
                v-for="doc in documents" :key="doc.id"
                class="rounded-lg border overflow-hidden"
                style="border-color: var(--border); background: var(--bg-elevated);"
                :data-testid="`expense-receipt-${doc.id}`"
              >
                <a v-if="isImage(doc)" :href="docUrl(doc)" target="_blank" rel="noopener" class="block">
                  <img
                    :src="docUrl(doc)"
                    :alt="doc.original_filename"
                    class="w-28 h-28 object-cover"
                    style="max-width: 100%;"
                  />
                </a>
                <a
                  v-else
                  :href="docUrl(doc)"
                  target="_blank"
                  rel="noopener"
                  class="flex items-center gap-2 px-3 py-3 w-44 text-xs hover:underline"
                  style="color: var(--accent);"
                >
                  <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M7 3h7l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1z"/>
                  </svg>
                  <span class="truncate">{{ doc.original_filename }}</span>
                </a>
                <div class="px-2 py-1.5 border-t flex items-center justify-between gap-2"
                     style="border-color: var(--border);">
                  <span class="text-[10px] truncate" style="color: var(--text-muted);">{{ doc.original_filename }}</span>
                  <button
                    v-if="expense.status === 'draft'"
                    class="text-[10px] font-medium shrink-0"
                    style="color: var(--danger);"
                    :data-testid="`expense-receipt-remove-${doc.id}`"
                    @click="removeReceipt(doc)"
                  >Remove</button>
                </div>
              </div>
            </div>
          </section>

          <!-- Reimbursement panel -->
          <section
            v-if="expense.reimbursement"
            class="sn-card p-4"
            data-testid="expense-reimbursement"
          >
            <h3 class="text-[10px] uppercase tracking-widest font-semibold mb-2" style="color: var(--text-muted);">
              Reimbursement
            </h3>
            <div class="flex items-center gap-3">
              <span class="text-[10px]" :class="statusPill(expense.reimbursement.status)">
                {{ statusLabel(expense.reimbursement.status) }}
              </span>
              <span v-if="expense.reimbursement.amount" class="mono text-sm" style="color: var(--text-primary);">
                {{ expense.currency || 'USD' }} {{ formatAmount(expense.reimbursement.amount) }}
              </span>
              <span v-if="expense.reimbursement.paid_at" class="text-xs" style="color: var(--text-muted);">
                paid {{ formatDate(expense.reimbursement.paid_at) }}
              </span>
            </div>
          </section>
        </div>

        <!-- Right: approval chain + actions -->
        <aside class="space-y-4">
          <section v-if="expense.approval_id" class="sn-card p-4" data-testid="expense-approval-chain">
            <h3 class="text-[10px] uppercase tracking-widest font-semibold mb-3" style="color: var(--text-muted);">
              Approval chain
            </h3>
            <ApprovalChain :steps="chainSteps" :current-step="currentStep" />
            <p v-if="!chainSteps.length" class="text-xs" style="color: var(--text-muted);">
              Loading chain…
            </p>
          </section>

          <!-- Approve / reject -->
          <section
            v-if="canDecide"
            class="sn-card p-4 space-y-2"
            data-testid="expense-decision-panel"
          >
            <p class="text-xs" style="color: var(--text-muted);">
              <span v-if="expense.requires_typed_confirm">High value or flagged — typed confirm required.</span>
              <span v-else>Approving releases this report to the next step.</span>
            </p>
            <div class="flex items-center gap-2">
              <button
                class="sn-btn flex-1"
                style="border-color: rgba(240,93,94,0.40); color: var(--danger);"
                data-testid="expense-reject-button"
                @click="onReject"
              >Reject</button>
              <button
                class="sn-btn flex-1"
                style="background: rgba(49,214,123,0.16); color: var(--success); border-color: rgba(49,214,123,0.40);"
                data-testid="expense-approve-button"
                @click="onApprove"
              >Approve</button>
            </div>
          </section>

          <!-- Draft actions -->
          <section v-if="expense.status === 'draft'" class="sn-card p-4 space-y-2">
            <button class="sn-btn w-full" data-testid="expense-submit-button" @click="submitDraft">
              Submit for approval
            </button>
            <button class="sn-btn-danger sn-btn w-full" data-testid="expense-void-button" @click="onVoid">
              Void report
            </button>
          </section>
        </aside>
      </div>
    </template>

    <div v-else class="sn-card p-10 text-center text-sm" style="color: var(--text-muted);" data-testid="expense-not-found">
      Expense report not found.
      <RouterLink to="/financial/expenses" class="block mt-2" style="color: var(--accent);">Back to expenses</RouterLink>
    </div>

    <!-- Soft confirm (normal approve / reject / void) -->
    <ConfirmDialog
      v-if="softDialog.open"
      v-model="softDialog.open"
      :title="softDialog.title"
      :confirm-label="softDialog.confirmLabel"
      :destructive="softDialog.destructive"
      data-testid="expense-soft-dialog"
      @confirm="softDialog.onConfirm"
    >
      <p class="text-sm" style="color: var(--text-secondary);">{{ softDialog.body }}</p>
      <textarea
        v-model="softDialog.reason"
        rows="3"
        class="mt-3"
        :placeholder="softDialog.reasonPlaceholder"
        data-testid="expense-soft-reason"
      />
    </ConfirmDialog>

    <!-- Typed confirm (requires_typed_confirm approve) -->
    <TypedConfirmDialog
      v-model="typedDialog.open"
      phrase="I UNDERSTAND"
      :title="typedDialog.title"
      risk-label="High risk"
      confirm-label="Approve"
      reason-required
      data-testid="expense-typed-dialog"
      @confirm="typedDialog.onConfirm"
    >
      <p class="text-sm" style="color: var(--text-secondary);">{{ typedDialog.body }}</p>
      <p class="mt-2 text-xs mono" style="color: var(--text-muted);">
        {{ expense?.currency || 'USD' }} {{ formatAmount(expense?.total_amount) }} · {{ expense?.policy_violation_count || 0 }} policy flags
      </p>
    </TypedConfirmDialog>
  </div>
</template>

<script setup>
import { ref, computed, reactive, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useExpensesStore } from '../../../stores/expenses.js'
import { useAuthStore } from '../../../stores/auth.js'
import ApprovalChain from '../../../components/financial/ApprovalChain.vue'
import PolicyFlagPill from '../../../components/financial/PolicyFlagPill.vue'
import ConfirmDialog from '../../../components/feedback/ConfirmDialog.vue'
import TypedConfirmDialog from '../../../components/feedback/TypedConfirmDialog.vue'
import api from '../../../services/api.js'

const route = useRoute()
const router = useRouter()
const expensesStore = useExpensesStore()
const authStore = useAuthStore()

const expense = computed(() => expensesStore.currentExpense)
const lineItems = computed(() => expense.value?.line_items || [])
const documents = computed(() => expense.value?.documents || [])

const chainSteps = ref([])
const currentStep = computed(() => chainSteps.value.findIndex((s) => s.status === 'pending'))

const canDecide = computed(() =>
  expense.value?.status === 'awaiting_approval' && authStore.has('expenses.approve')
)

// ── Dialogs ───────────────────────────────────────────────────────
const softDialog = reactive({
  open: false, title: '', body: '', confirmLabel: 'Confirm', destructive: false,
  reason: '', reasonPlaceholder: 'Optional comment for the audit log…',
  onConfirm: () => {},
})

const typedDialog = reactive({
  open: false, title: '', body: '',
  onConfirm: () => {},
})

function openSoft({ title, body, confirmLabel, destructive, placeholder, run }) {
  softDialog.title = title
  softDialog.body = body
  softDialog.confirmLabel = confirmLabel
  softDialog.destructive = !!destructive
  softDialog.reason = ''
  softDialog.reasonPlaceholder = placeholder || 'Optional comment for the audit log…'
  softDialog.onConfirm = async () => {
    await run(softDialog.reason.trim())
    softDialog.open = false
  }
  softDialog.open = true
}

function onApprove() {
  const exp = expense.value
  if (!exp) return
  if (exp.requires_typed_confirm) {
    typedDialog.title = `Approve · ${exp.title}`
    typedDialog.body = 'This report is high-value or policy-flagged. Type the phrase and record an audit reason to approve.'
    typedDialog.onConfirm = async ({ reason }) => {
      await decide('approve', reason)
      typedDialog.open = false
    }
    typedDialog.open = true
    return
  }
  openSoft({
    title: `Approve · ${exp.title}`,
    body: 'The report moves to the next approval step (or reimbursement if final).',
    confirmLabel: 'Approve',
    run: (reason) => decide('approve', reason),
  })
}

function onReject() {
  const exp = expense.value
  if (!exp) return
  openSoft({
    title: `Reject · ${exp.title}`,
    body: 'The report returns to the submitter as rejected.',
    confirmLabel: 'Reject',
    destructive: true,
    placeholder: 'Reason for rejection (visible in audit log)…',
    run: (reason) => decide('reject', reason),
  })
}

async function decide(kind, reason) {
  const exp = expense.value
  if (!exp) return
  const res = kind === 'approve'
    ? await expensesStore.approve(exp.id, reason)
    : await expensesStore.reject(exp.id, reason)
  if (res.success) {
    await Promise.all([expensesStore.fetchExpense(exp.id), loadChain()])
  }
}

function submitDraft() {
  openSoft({
    title: `Submit · ${expense.value.title}`,
    body: 'The report enters the approval chain and becomes read-only.',
    confirmLabel: 'Submit',
    run: async () => {
      const res = await expensesStore.submitExpense(expense.value.id)
      if (res.success) await loadChain()
    },
  })
}

function onVoid() {
  openSoft({
    title: `Void · ${expense.value.title}`,
    body: 'The report is archived and cannot be submitted.',
    confirmLabel: 'Void',
    destructive: true,
    run: async () => {
      const res = await expensesStore.voidExpense(expense.value.id)
      if (res.success) router.push('/financial/expenses')
    },
  })
}

async function removeReceipt(doc) {
  await expensesStore.removeReceipt(expense.value.id, doc.id)
  await expensesStore.fetchExpense(expense.value.id)
}

// ── Chain loading ─────────────────────────────────────────────────
async function loadChain() {
  const approvalId = expense.value?.approval_id
  if (!approvalId) { chainSteps.value = []; return }
  try {
    const { data } = await api.get(`/api/approvals/${approvalId}`)
    const approval = data?.data || data || {}
    chainSteps.value = approval.steps || []
  } catch {
    chainSteps.value = []
  }
}

// ── Formatting helpers ────────────────────────────────────────────
function formatAmount(v) {
  return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })
}
function formatDate(v) {
  try { return new Date(v).toLocaleDateString() } catch { return String(v) }
}
function statusLabel(s) {
  return String(s || '').replaceAll('_', ' ')
}
function statusPill(s) {
  if (s === 'approved' || s === 'reimbursed' || s === 'paid') return 'sn-pill sn-pill-success'
  if (s === 'rejected' || s === 'voided' || s === 'failed')   return 'sn-pill sn-pill-danger'
  if (s === 'awaiting_approval' || s === 'pending')           return 'sn-pill sn-pill-warn'
  return 'sn-pill'
}
function isImage(doc) {
  return String(doc.mime_type || '').startsWith('image/')
}
function docUrl(doc) {
  if (doc.url) return doc.url
  const base = api.defaults.baseURL || ''
  return `${base}/api/financial/expenses/${expense.value?.id}/receipts/${doc.id}`
}

async function load() {
  await expensesStore.fetchExpense(route.params.id)
  await loadChain()
}

watch(() => route.params.id, (id, prev) => { if (id && id !== prev) load() })
watch(() => expense.value?.approval_id, (id, prev) => { if (id && id !== prev) loadChain() })

onMounted(load)
</script>

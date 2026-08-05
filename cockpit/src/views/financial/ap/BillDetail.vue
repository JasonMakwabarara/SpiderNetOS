<template>
  <div class="px-8 py-6 max-w-[1200px] mx-auto">
    <div v-if="apStore.loading && !bill" class="sn-card sn-shimmer h-40" data-testid="bill-detail-loading"></div>

    <template v-else-if="bill">
      <!-- Header -->
      <header class="flex items-start justify-between gap-4 mb-5">
        <div class="min-w-0">
          <div class="flex items-center gap-2 mb-1 flex-wrap">
            <span class="text-[10px]" :class="statusPill(bill.status)" data-testid="bill-status">
              {{ statusLabel(bill.status) }}
            </span>
            <span
              v-if="bill.status === 'paid' && methodLabel"
              class="sn-pill text-[10px]"
              data-testid="bill-method-pill"
            >{{ methodLabel }}</span>
            <span v-if="overdue" class="sn-pill sn-pill-danger text-[10px]" data-testid="bill-overdue-pill">overdue</span>
          </div>
          <h1 class="text-[24px] font-heading font-semibold tracking-tight truncate" style="color: var(--text-primary);">
            {{ vendorName }}
          </h1>
          <p class="text-xs mt-1 mono" style="color: var(--text-muted);">
            {{ bill.bill_number || bill.id }}
            <span v-if="bill.due_date"> · due {{ formatDate(bill.due_date) }}</span>
            <span v-if="bill.scheduled_for"> · scheduled {{ formatDate(bill.scheduled_for) }}</span>
          </p>
        </div>
        <div class="text-right shrink-0">
          <div class="text-[10px] uppercase tracking-widest font-semibold" style="color: var(--text-muted);">Total</div>
          <div class="text-xl font-semibold mono" style="color: var(--text-primary);" data-testid="bill-total">
            {{ bill.currency || 'USD' }} {{ formatAmount(bill.total_amount) }}
          </div>
          <div v-if="bill.bank_reference" class="text-[10px] mt-1 mono" style="color: var(--text-muted);" data-testid="bill-bank-reference">
            ref {{ bill.bank_reference }}
          </div>
        </div>
      </header>

      <div class="grid grid-cols-1 lg:grid-cols-[1fr_340px] gap-4">
        <!-- Left: line items + documents -->
        <div class="space-y-4 min-w-0">
          <!-- Line items -->
          <section class="sn-card overflow-hidden" data-testid="bill-line-items">
            <div class="px-4 py-2.5 border-b text-[11px] uppercase tracking-widest font-semibold"
                 style="border-color: var(--border); color: var(--text-muted);">
              Line items · {{ lineItems.length }}
            </div>
            <div class="overflow-x-auto">
              <table>
                <thead>
                  <tr><th>Description</th><th class="text-right">Amount</th></tr>
                </thead>
                <tbody>
                  <tr v-for="(li, i) in lineItems" :key="li.id ?? i" :data-testid="`bill-line-${li.id ?? i}`">
                    <td class="max-w-[380px] truncate">{{ li.description }}</td>
                    <td class="text-right mono">{{ formatAmount(li.amount) }}</td>
                  </tr>
                  <tr v-if="!lineItems.length">
                    <td colspan="2" class="text-center" style="color: var(--text-muted);">No line items.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <!-- Documents -->
          <section v-if="documents.length" class="sn-card p-4" data-testid="bill-documents">
            <h3 class="text-[10px] uppercase tracking-widest font-semibold mb-3" style="color: var(--text-muted);">
              Documents · {{ documents.length }}
            </h3>
            <ul class="space-y-2">
              <li
                v-for="doc in documents" :key="doc.id"
                class="rounded-lg border px-3 py-2.5"
                style="border-color: var(--border); background: var(--bg-elevated);"
                :data-testid="`bill-document-${doc.id}`"
              >
                <div class="flex items-center justify-between gap-3">
                  <div class="flex items-center gap-2 min-w-0">
                    <svg class="w-4 h-4 shrink-0" style="color: var(--text-muted);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M7 3h7l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1z"/>
                    </svg>
                    <span class="text-xs truncate" style="color: var(--text-secondary);">{{ doc.original_filename }}</span>
                  </div>
                  <div class="flex items-center gap-2 shrink-0">
                    <span class="text-[10px]" :class="extractionPill(doc.extraction_status)" :data-testid="`bill-doc-extraction-${doc.id}`">
                      {{ statusLabel(doc.extraction_status || 'pending') }}
                    </span>
                    <button
                      v-if="doc.extraction_status === 'needs_review'"
                      class="text-xs font-medium"
                      style="color: var(--accent);"
                      :data-testid="`bill-doc-review-${doc.id}`"
                      @click="openReview(doc)"
                    >Review</button>
                  </div>
                </div>

                <!-- Inline extraction confirm form -->
                <div
                  v-if="review.docId === doc.id"
                  class="mt-3 pt-3 border-t space-y-2"
                  style="border-color: var(--border);"
                  :data-testid="`bill-doc-review-form-${doc.id}`"
                >
                  <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                    <div>
                      <label :for="`rev-vendor-${doc.id}`">Vendor name</label>
                      <input :id="`rev-vendor-${doc.id}`" v-model="review.vendor_name" type="text" data-testid="review-vendor-input" />
                    </div>
                    <div>
                      <label :for="`rev-amount-${doc.id}`">Amount</label>
                      <input :id="`rev-amount-${doc.id}`" v-model="review.amount" type="number" min="0" step="0.01" class="mono" data-testid="review-amount-input" />
                    </div>
                    <div>
                      <label :for="`rev-due-${doc.id}`">Due date</label>
                      <input :id="`rev-due-${doc.id}`" v-model="review.due_date" type="date" data-testid="review-due-input" />
                    </div>
                  </div>
                  <p v-if="review.error" class="text-xs" style="color: var(--danger);" data-testid="review-error">{{ review.error }}</p>
                  <div class="flex justify-end gap-2">
                    <button class="sn-btn text-xs" data-testid="review-cancel" @click="review.docId = null">Cancel</button>
                    <button class="sn-btn sn-btn-primary text-xs" :disabled="review.saving" data-testid="review-confirm" @click="confirmReview">
                      {{ review.saving ? 'Confirming…' : 'Confirm extraction' }}
                    </button>
                  </div>
                </div>
              </li>
            </ul>
          </section>
        </div>

        <!-- Right: approval chain + actions -->
        <aside class="space-y-4">
          <section v-if="bill.approval_id" class="sn-card p-4" data-testid="bill-approval-chain">
            <h3 class="text-[10px] uppercase tracking-widest font-semibold mb-3" style="color: var(--text-muted);">
              Approval chain
            </h3>
            <ApprovalChain :steps="chainSteps" :current-step="currentStep" />
            <p v-if="!chainSteps.length" class="text-xs" style="color: var(--text-muted);">
              Loading chain…
            </p>
          </section>

          <!-- Draft actions -->
          <section v-if="bill.status === 'draft'" class="sn-card p-4 space-y-2" data-testid="bill-draft-actions">
            <button class="sn-btn w-full" data-testid="bill-submit-button" @click="submitDraft">
              Submit for approval
            </button>
          </section>

          <!-- Schedule (approved, ap.manage) -->
          <section
            v-if="bill.status === 'approved' && canManage"
            class="sn-card p-4 space-y-2"
            data-testid="bill-schedule-panel"
          >
            <h3 class="text-[10px] uppercase tracking-widest font-semibold" style="color: var(--text-muted);">
              Schedule payment
            </h3>
            <label for="bill-schedule-date">Pay on</label>
            <input id="bill-schedule-date" v-model="scheduleDate" type="date" data-testid="bill-schedule-date" />
            <p v-if="scheduleError" class="text-xs" style="color: var(--danger);" data-testid="bill-schedule-error">{{ scheduleError }}</p>
            <button class="sn-btn w-full" :disabled="scheduling" data-testid="bill-schedule-button" @click="onSchedule">
              {{ scheduling ? 'Scheduling…' : 'Schedule' }}
            </button>
          </section>

          <!-- Mark paid (approved/scheduled, ap.manage, step-up gated) -->
          <StepUpGuard
            v-if="['approved', 'scheduled'].includes(bill.status) && canManage"
            reason="Settling a bill records a payment against company funds. Verify it is you."
          >
            <section class="sn-card p-4 space-y-2" data-testid="bill-markpaid-panel">
              <h3 class="text-[10px] uppercase tracking-widest font-semibold" style="color: var(--text-muted);">
                Mark paid
              </h3>
              <label for="bill-bank-ref">Bank reference <span style="color: var(--danger);">*</span></label>
              <input
                id="bill-bank-ref"
                v-model="bankReference"
                type="text"
                placeholder="e.g. FPS-88213-XN"
                class="mono"
                data-testid="bill-bank-ref-input"
              />
              <p v-if="markPaidError" class="text-xs" style="color: var(--danger);" data-testid="bill-markpaid-error">{{ markPaidError }}</p>
              <button class="sn-btn w-full" data-testid="bill-markpaid-button" @click="onMarkPaid">
                Mark paid (manual)
              </button>
            </section>
          </StepUpGuard>

          <!-- Void -->
          <section
            v-if="!['paid', 'voided'].includes(bill.status)"
            class="sn-card p-4"
            data-testid="bill-void-panel"
          >
            <button class="sn-btn sn-btn-danger w-full" data-testid="bill-void-button" @click="onVoid">
              Void bill
            </button>
          </section>
        </aside>
      </div>
    </template>

    <div v-else class="sn-card p-10 text-center text-sm" style="color: var(--text-muted);" data-testid="bill-not-found">
      Bill not found.
      <RouterLink to="/financial/bills" class="block mt-2" style="color: var(--accent);">Back to bill pay</RouterLink>
    </div>

    <!-- Soft confirm (submit) -->
    <ConfirmDialog
      v-if="softDialog.open"
      v-model="softDialog.open"
      :title="softDialog.title"
      :confirm-label="softDialog.confirmLabel"
      :destructive="softDialog.destructive"
      data-testid="bill-soft-dialog"
      @confirm="softDialog.onConfirm"
    >
      <p class="text-sm" style="color: var(--text-secondary);">{{ softDialog.body }}</p>
    </ConfirmDialog>

    <!-- Typed confirm: mark paid -->
    <TypedConfirmDialog
      v-model="markPaidDialog"
      phrase="MARK PAID"
      :title="`Mark paid · ${vendorName}`"
      risk-label="Money movement"
      confirm-label="Mark paid"
      reason-required
      data-testid="bill-markpaid-dialog"
      @confirm="doMarkPaid"
    >
      <p class="text-sm" style="color: var(--text-secondary);">
        Records this bill as settled manually. The bank reference is stored for reconciliation.
      </p>
      <p class="mt-2 text-xs mono" style="color: var(--text-muted);">
        {{ bill?.currency || 'USD' }} {{ formatAmount(bill?.total_amount) }} · ref {{ bankReference }}
      </p>
    </TypedConfirmDialog>

    <!-- Typed confirm: void -->
    <TypedConfirmDialog
      v-model="voidDialog"
      phrase="VOID"
      :title="`Void · ${vendorName}`"
      risk-label="Destructive"
      confirm-label="Void bill"
      reason-required
      data-testid="bill-void-dialog"
      @confirm="doVoid"
    >
      <p class="text-sm" style="color: var(--text-secondary);">
        The bill is archived and can no longer be scheduled or paid.
      </p>
    </TypedConfirmDialog>
  </div>
</template>

<script setup>
import { ref, computed, reactive, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useApStore, PAYMENT_METHODS } from '../../../stores/ap.js'
import { useAuthStore } from '../../../stores/auth.js'
import ApprovalChain from '../../../components/financial/ApprovalChain.vue'
import StepUpGuard from '../../../components/security/StepUpGuard.vue'
import ConfirmDialog from '../../../components/feedback/ConfirmDialog.vue'
import TypedConfirmDialog from '../../../components/feedback/TypedConfirmDialog.vue'
import api from '../../../services/api.js'

const route = useRoute()
const router = useRouter()
const apStore = useApStore()
const authStore = useAuthStore()

const bill = computed(() => apStore.currentBill)
const lineItems = computed(() => bill.value?.line_items || bill.value?.lines || [])
const documents = computed(() => bill.value?.documents || [])

const canManage = computed(() => authStore.has('ap.manage'))

const vendorName = computed(() =>
  bill.value?.vendor_name
  || bill.value?.vendor?.name
  || apStore.vendors.find((v) => v.id === bill.value?.vendor_id)?.name
  || 'Vendor'
)

const methodLabel = computed(() => PAYMENT_METHODS[bill.value?.payment_method] || null)

const overdue = computed(() => {
  const b = bill.value
  if (!b?.due_date || ['paid', 'voided'].includes(b.status)) return false
  const due = new Date(b.due_date)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return !Number.isNaN(due.getTime()) && due < today
})

// ── Approval chain ────────────────────────────────────────────────
const chainSteps = ref([])
const currentStep = computed(() => chainSteps.value.findIndex((s) => s.status === 'pending'))

async function loadChain() {
  const approvalId = bill.value?.approval_id
  if (!approvalId) { chainSteps.value = []; return }
  try {
    const { data } = await api.get(`/api/approvals/${approvalId}`)
    const approval = data?.data || data || {}
    chainSteps.value = approval.steps || []
  } catch {
    chainSteps.value = []
  }
}

// ── Soft dialog (submit) ──────────────────────────────────────────
const softDialog = reactive({
  open: false, title: '', body: '', confirmLabel: 'Confirm', destructive: false,
  onConfirm: () => {},
})

function submitDraft() {
  softDialog.title = `Submit · ${vendorName.value}`
  softDialog.body = 'The bill enters the approval chain and becomes read-only.'
  softDialog.confirmLabel = 'Submit'
  softDialog.destructive = false
  softDialog.onConfirm = async () => {
    const res = await apStore.submitBill(bill.value.id)
    softDialog.open = false
    if (res.success) await loadChain()
  }
  softDialog.open = true
}

// ── Schedule ──────────────────────────────────────────────────────
const scheduleDate = ref('')
const scheduling = ref(false)
const scheduleError = ref('')

async function onSchedule() {
  scheduleError.value = ''
  if (!scheduleDate.value) {
    scheduleError.value = 'Pick a payment date.'
    return
  }
  scheduling.value = true
  const res = await apStore.scheduleBill(bill.value.id, { scheduled_for: scheduleDate.value })
  scheduling.value = false
  if (!res.success) scheduleError.value = res.error || 'Failed to schedule.'
}

// ── Mark paid ─────────────────────────────────────────────────────
const bankReference = ref('')
const markPaidError = ref('')
const markPaidDialog = ref(false)

function onMarkPaid() {
  markPaidError.value = ''
  if (!bankReference.value.trim()) {
    markPaidError.value = 'A bank reference is required for the audit trail.'
    return
  }
  markPaidDialog.value = true
}

async function doMarkPaid({ reason }) {
  const res = await apStore.markPaid(bill.value.id, {
    bank_reference: bankReference.value.trim(),
    method: 'manual',
    reason,
  })
  if (!res.success) {
    markPaidError.value = res.error || 'Failed to mark paid.'
    return
  }
  bankReference.value = ''
}

// ── Void ──────────────────────────────────────────────────────────
const voidDialog = ref(false)

function onVoid() {
  voidDialog.value = true
}

async function doVoid() {
  const res = await apStore.voidBill(bill.value.id)
  if (res.success) router.push('/financial/bills')
}

// ── Extraction review ─────────────────────────────────────────────
const review = reactive({
  docId: null, vendor_name: '', amount: '', due_date: '',
  saving: false, error: '',
})

async function openReview(doc) {
  review.docId = doc.id
  review.error = ''
  let extracted = doc.extracted || doc.extraction || {}
  try {
    const { data } = await api.get(`/api/financial/spend/documents/${doc.id}`)
    const full = data?.data || data || {}
    extracted = full.extracted || full.extraction || extracted
  } catch { /* fall back to what the bill payload carried */ }
  review.vendor_name = extracted.vendor_name || vendorName.value || ''
  review.amount = extracted.amount || extracted.total_amount || bill.value?.total_amount || ''
  review.due_date = extracted.due_date || bill.value?.due_date || ''
}

async function confirmReview() {
  review.error = ''
  if (!review.vendor_name.trim() || !(Number(review.amount) > 0)) {
    review.error = 'Vendor name and a positive amount are required.'
    return
  }
  review.saving = true
  try {
    await api.post(`/api/financial/spend/documents/${review.docId}/confirm`, {
      vendor_name: review.vendor_name.trim(),
      amount: String(review.amount),
      due_date: review.due_date || undefined,
    })
    review.docId = null
    await apStore.fetchBill(bill.value.id)
  } catch (err) {
    review.error = err.response?.data?.message || 'Failed to confirm extraction.'
  } finally {
    review.saving = false
  }
}

// ── Formatting helpers ────────────────────────────────────────────
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
function extractionPill(s) {
  if (s === 'extracted' || s === 'confirmed') return 'sn-pill sn-pill-success'
  if (s === 'failed')                         return 'sn-pill sn-pill-danger'
  if (s === 'needs_review')                   return 'sn-pill sn-pill-warn'
  return 'sn-pill' // pending / queued
}

async function load() {
  await apStore.fetchBill(route.params.id)
  if (!apStore.vendors.length) apStore.fetchVendors()
  await loadChain()
}

watch(() => route.params.id, (id, prev) => { if (id && id !== prev) load() })
watch(() => bill.value?.approval_id, (id, prev) => { if (id && id !== prev) loadChain() })

onMounted(load)
</script>

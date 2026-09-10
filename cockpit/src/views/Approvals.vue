<template>
  <div class="px-8 py-6 max-w-[1500px] mx-auto">
    <!-- Header -->
    <header class="flex items-start justify-between gap-4 mb-5">
      <div>
        <h1 class="text-[26px] font-heading font-semibold tracking-tight" style="color: var(--text-primary);">Approvals</h1>
        <p class="text-sm mt-1" style="color: var(--text-muted);">
          Review and decide on changes Atlas wants to make. High-risk changes require a typed confirm.
        </p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <span v-if="pendingCount" class="sn-pill sn-pill-warn" data-testid="approvals-pending-count">{{ pendingCount }} pending</span>
        <button class="sn-btn" data-testid="approvals-refresh" @click="refresh">
          <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8 8 0 004.582 9M20 20v-5h-.581m0 0a8 8 0 01-15.357-2"/>
          </svg>
          Refresh
        </button>
      </div>
    </header>

    <!-- Filters -->
    <nav class="flex items-center gap-1 mb-4" aria-label="Approval filters">
      <button
        v-for="t in tabs" :key="t.value"
        class="px-3 py-1.5 rounded-md text-xs font-medium transition-colors"
        :style="activeFilter === t.value
          ? 'background: var(--accent-weak); color: var(--accent); border: 1px solid rgba(0,229,200,0.30);'
          : 'background: var(--bg-elevated); color: var(--text-muted); border: 1px solid var(--border);'"
        :data-testid="`approvals-tab-${t.value}`"
        @click="activeFilter = t.value"
      >
        {{ t.label }}
        <span class="ml-1.5 opacity-70">{{ t.count }}</span>
      </button>
    </nav>

    <!-- Split view -->
    <section class="grid grid-cols-1 lg:grid-cols-[420px_1fr] gap-4 min-h-[60vh]">
      <!-- Queue -->
      <aside class="sn-card overflow-hidden flex flex-col">
        <div class="px-3 py-2 border-b text-[11px] uppercase tracking-widest font-semibold"
             style="border-color: var(--border); color: var(--text-muted);">
          Queue · {{ filteredApprovals.length }}
        </div>
        <ul class="flex-1 overflow-y-auto divide-y" style="border-color: var(--divider);">
          <li v-if="!filteredApprovals.length" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
            All clear. Nothing waiting on you.
          </li>
          <li
            v-for="apr in filteredApprovals" :key="apr.id"
            class="px-3 py-2.5 cursor-pointer transition-colors"
            :style="selected?.id === apr.id
              ? 'background: var(--accent-weak); border-left: 2px solid var(--accent);'
              : 'border-left: 2px solid transparent;'"
            :data-testid="`approval-item-${apr.id}`"
            @click="selectedId = apr.id"
          >
            <div class="flex items-center gap-2 mb-1">
              <span class="sn-pill text-[10px]">{{ apr.type }}</span>
              <span :class="riskPill(apr.risk)">{{ apr.risk || 'low' }}</span>
              <span class="ml-auto sn-pill text-[10px]" :class="statusPill(apr.status)">{{ apr.status }}</span>
            </div>
            <div class="text-sm truncate" style="color: var(--text-primary);">
              {{ apr.title || apr.resource_name }}
            </div>
            <div class="text-[11px] mt-0.5" style="color: var(--text-muted);">
              {{ apr.requested_by || 'Atlas' }} · {{ timeAgo(apr.created_at) }}
            </div>
          </li>
        </ul>
      </aside>

      <!-- Detail -->
      <article class="sn-card p-0 overflow-hidden flex flex-col" data-testid="approval-detail">
        <template v-if="selected">
          <header class="px-5 py-4 border-b" style="border-color: var(--border);">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="flex items-center gap-2 mb-1">
                  <span class="sn-pill text-[10px]">{{ selected.type }}</span>
                  <span :class="riskPill(selected.risk)">{{ selected.risk || 'low' }}</span>
                  <span class="sn-pill text-[10px]" :class="statusPill(selected.status)">{{ selected.status }}</span>
                </div>
                <h2 class="font-heading font-semibold text-[18px]" style="color: var(--text-primary);">
                  {{ selected.title || selected.resource_name }}
                </h2>
                <p class="text-xs mt-1 mono" style="color: var(--text-muted);">
                  {{ selected.id }} · {{ selected.requested_by || 'Atlas' }} · {{ timeAgo(selected.created_at) }}
                </p>
              </div>
            </div>
          </header>

          <div class="flex-1 overflow-auto p-5 space-y-4">
            <!-- Summary -->
            <section v-if="selected.summary || selected.reason">
              <h3 class="text-[10px] uppercase tracking-widest font-semibold mb-1.5" style="color: var(--text-muted);">Summary</h3>
              <p class="text-sm" style="color: var(--text-primary);">{{ selected.summary || selected.reason }}</p>
            </section>

            <!-- Partner outreach: recruiter-bot draft / escalation -->
            <section v-if="outreach">
              <h3 class="text-[10px] uppercase tracking-widest font-semibold mb-1.5" style="color: var(--text-muted);">
                {{ selected.resource_type === 'outreach_reply' ? 'Recruiter bot draft' : 'Recruiter bot handoff' }}
              </h3>
              <p class="text-xs" style="color: var(--text-muted);">
                {{ outreach.display_name || (outreach.handle ? '@' + outreach.handle : 'prospect') }}
                <span v-if="outreach.platform"> · {{ outreach.platform }}</span>
                <span v-if="outreach.action"> · action: {{ outreach.action }}</span>
                <span v-if="outreach.reason"> · {{ outreach.reason }}</span>
              </p>
              <p v-if="outreach.inbound_excerpt" class="text-xs mt-2 rounded-md px-3 py-2"
                 style="background: var(--bg-elevated); color: var(--text-secondary); border: 1px solid var(--border); white-space: pre-line;">
                {{ outreach.inbound_excerpt }}
              </p>
              <template v-if="selected.resource_type === 'outreach_reply'">
                <textarea v-if="selected.status === 'pending'" v-model="draftBody" rows="6" class="w-full mt-2 text-sm"
                          data-testid="outreach-draft-body"></textarea>
                <p v-else class="text-sm mt-2" style="color: var(--text-primary); white-space: pre-line;">{{ outreach.draft_body }}</p>
                <div v-if="selected.status === 'pending'" class="flex items-center gap-2 mt-2">
                  <button class="sn-btn" :disabled="draftSaving || draftBody === savedDraft" @click="saveDraft">
                    {{ draftSaving ? 'Saving…' : 'Save edit' }}
                  </button>
                  <span class="text-xs" style="color: var(--text-muted);">{{ draftNotice }}</span>
                </div>
              </template>
              <RouterLink v-if="outreach.prospect_id" :to="`/sales/partners/${outreach.prospect_id}`" class="text-xs underline mt-2 inline-block" style="color: var(--accent);">
                Open the thread
              </RouterLink>
            </section>

            <!-- Diff -->
            <section v-if="selected.diff">
              <h3 class="text-[10px] uppercase tracking-widest font-semibold mb-1.5" style="color: var(--text-muted);">Diff</h3>
              <div class="rounded-md overflow-hidden border" style="border-color: var(--border);">
                <div class="px-3 py-1.5 text-[11px] mono"
                     style="background: var(--bg-elevated); color: var(--text-muted);">
                  field <span style="color: var(--text-secondary);">{{ selected.diff.field }}</span>
                </div>
                <div class="grid grid-cols-2">
                  <div class="px-3 py-2.5 text-xs mono"
                       style="background: rgba(255,90,122,0.06); color: var(--danger); border-right: 1px solid var(--border);">
                    <div class="opacity-60 mb-1">— before</div>
                    <div class="break-all">{{ formatDiff(selected.diff.before) }}</div>
                  </div>
                  <div class="px-3 py-2.5 text-xs mono"
                       style="background: rgba(34,211,155,0.06); color: var(--success);">
                    <div class="opacity-60 mb-1">+ after</div>
                    <div class="break-all">{{ formatDiff(selected.diff.after) }}</div>
                  </div>
                </div>
              </div>
            </section>

            <!-- Review comment for processed -->
            <section v-if="selected.review_comment">
              <h3 class="text-[10px] uppercase tracking-widest font-semibold mb-1.5" style="color: var(--text-muted);">Recorded reason</h3>
              <p class="text-sm rounded-md px-3 py-2"
                 style="background: var(--bg-elevated); color: var(--text-secondary); border: 1px solid var(--border);">
                {{ selected.review_comment }}
              </p>
            </section>
          </div>

          <!-- Action bar -->
          <footer
            v-if="selected.status === 'pending'"
            class="px-5 py-3 border-t flex items-center justify-between gap-3"
            style="border-color: var(--border); background: rgba(0,0,0,0.25);"
          >
            <span class="text-xs" style="color: var(--text-muted);">
              <span v-if="isHigh">High risk — typed confirm required</span>
              <span v-else>Reversible action</span>
            </span>
            <div class="flex items-center gap-2">
              <button
                class="sn-btn"
                style="border-color: rgba(255,90,122,0.40); color: var(--danger);"
                :data-testid="`approval-reject-${selected.id}`"
                @click="onReject"
              >Reject</button>
              <button
                class="sn-btn"
                style="background: rgba(34,211,155,0.16); color: var(--success); border-color: rgba(34,211,155,0.40);"
                :data-testid="`approval-approve-${selected.id}`"
                @click="onApprove"
              >Approve</button>
            </div>
          </footer>
        </template>

        <!-- Empty selection -->
        <div v-else class="flex-1 flex items-center justify-center text-sm" style="color: var(--text-muted);">
          Select an approval from the queue to review the diff.
        </div>
      </article>
    </section>

    <!-- Soft-confirm dialog (low/medium-risk approve) -->
    <ConfirmDialog
      v-if="softDialog.open"
      v-model="softDialog.open"
      :title="softDialog.title"
      :confirm-label="softDialog.confirmLabel"
      data-testid="approval-soft-dialog"
      @confirm="softDialog.onConfirm"
    >
      <p class="text-sm" style="color: var(--text-secondary);">{{ softDialog.body }}</p>
      <textarea
        v-model="softDialog.reason"
        rows="3"
        class="mt-3"
        :placeholder="softDialog.reasonPlaceholder"
        data-testid="approval-soft-reason"
      />
    </ConfirmDialog>

    <!-- Typed confirm dialog (high-risk approve) -->
    <TypedConfirmDialog
      v-model="typedDialog.open"
      :phrase="typedDialog.phrase"
      :title="typedDialog.title"
      :risk-label="typedDialog.riskLabel"
      :confirm-label="typedDialog.confirmLabel"
      reason-required
      data-testid="approval-typed-dialog"
      @confirm="typedDialog.onConfirm"
    >
      <p class="text-sm" style="color: var(--text-secondary);">{{ typedDialog.body }}</p>
      <p v-if="typedDialog.diff" class="mt-2 text-xs mono" style="color: var(--text-muted);">
        {{ typedDialog.diff }}
      </p>
    </TypedConfirmDialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, reactive, watch } from 'vue'
import { useApprovalsStore } from '../stores/approvals.js'
import ConfirmDialog from '../components/feedback/ConfirmDialog.vue'
import TypedConfirmDialog from '../components/feedback/TypedConfirmDialog.vue'
import api from '../services/api.js'

const approvalsStore = useApprovalsStore()

const activeFilter = ref('pending')

// Partner outreach approvals carry the draft in context (JSON string from the raw row).
const draftBody = ref('')
const savedDraft = ref('')
const draftSaving = ref(false)
const draftNotice = ref('')
const outreach = computed(() => {
  const s = selected.value
  if (!s || !['outreach_reply', 'outreach_thread'].includes(s.resource_type)) return null
  let ctx = s.context
  if (typeof ctx === 'string') { try { ctx = JSON.parse(ctx) } catch { ctx = {} } }
  return ctx || {}
})
watch(() => selected.value?.id, () => {
  draftBody.value = outreach.value?.draft_body || ''
  savedDraft.value = draftBody.value
  draftNotice.value = ''
}, { immediate: true })
async function saveDraft() {
  draftSaving.value = true
  draftNotice.value = ''
  try {
    await api.patch(`/api/sales/partners/drafts/${selected.value.resource_id}`, { body: draftBody.value })
    savedDraft.value = draftBody.value
    draftNotice.value = 'Saved; approve to send this version.'
  } catch (err) {
    draftNotice.value = err?.response?.data?.message || 'Could not save.'
  } finally {
    draftSaving.value = false
  }
}
const selectedId = ref(null)

const tabs = computed(() => {
  const all = approvalsStore.approvals || []
  const by = (s) => all.filter((a) => a.status === s).length
  return [
    { label: 'Pending',  value: 'pending',  count: by('pending') },
    { label: 'Approved', value: 'approved', count: by('approved') },
    { label: 'Rejected', value: 'rejected', count: by('rejected') },
    { label: 'All',      value: 'all',      count: all.length },
  ]
})

const filteredApprovals = computed(() => {
  const all = approvalsStore.approvals || []
  if (activeFilter.value === 'all') return all
  return all.filter((a) => a.status === activeFilter.value)
})

const selected = computed(
  () => (approvalsStore.approvals || []).find((a) => a.id === selectedId.value) || filteredApprovals.value[0] || null
)

const pendingCount = computed(
  () => (approvalsStore.approvals || []).filter((a) => a.status === 'pending').length
)

const isHigh = computed(() => (selected.value?.risk || 'low') === 'high')

// ── Dialogs ───────────────────────────────────────────────────────
const softDialog = reactive({
  open: false, title: '', body: '', confirmLabel: 'Confirm',
  reason: '', reasonPlaceholder: 'Optional comment for the audit log…',
  onConfirm: () => {},
})

const typedDialog = reactive({
  open: false, title: '', body: '', diff: '', phrase: 'CONFIRM',
  riskLabel: 'High risk', confirmLabel: 'Confirm',
  onConfirm: () => {},
})

function openSoft({ title, body, confirmLabel, run, placeholder }) {
  softDialog.title = title
  softDialog.body = body
  softDialog.confirmLabel = confirmLabel
  softDialog.reason = ''
  softDialog.reasonPlaceholder = placeholder || 'Optional comment for the audit log…'
  softDialog.onConfirm = async () => {
    await run(softDialog.reason.trim())
    softDialog.open = false
  }
  softDialog.open = true
}

function openTyped({ title, body, diff, phrase, run }) {
  typedDialog.title = title
  typedDialog.body = body
  typedDialog.diff = diff
  typedDialog.phrase = phrase
  typedDialog.riskLabel = 'High risk'
  typedDialog.confirmLabel = 'Approve'
  typedDialog.onConfirm = async ({ reason }) => {
    await run(reason)
    typedDialog.open = false
  }
  typedDialog.open = true
}

async function onApprove() {
  const apr = selected.value
  if (!apr) return
  if ((apr.risk || 'low') === 'high') {
    openTyped({
      title: `Approve · ${apr.title}`,
      body: 'This change is high-risk. Type the phrase below and provide an audit reason to continue.',
      diff: apr.diff ? `${apr.diff.field}: ${formatDiff(apr.diff.before)} → ${formatDiff(apr.diff.after)}` : '',
      phrase: 'I UNDERSTAND',
      run: async (reason) => approvalsStore.approve?.(apr.id, reason),
    })
    return
  }
  openSoft({
    title: `Approve · ${apr.title}`,
    body: 'This change will be applied immediately.',
    confirmLabel: 'Approve',
    placeholder: 'Optional comment for the audit log…',
    run: async (reason) => approvalsStore.approve?.(apr.id, reason),
  })
}

function onReject() {
  const apr = selected.value
  if (!apr) return
  openSoft({
    title: `Reject · ${apr.title}`,
    body: 'The request will be archived as rejected. Atlas may resubmit a different proposal.',
    confirmLabel: 'Reject',
    placeholder: 'Reason for rejection (visible in audit log)…',
    run: async (reason) => approvalsStore.reject?.(apr.id, reason),
  })
}

function refresh() { approvalsStore.fetchApprovals?.() }

function riskPill(r) {
  if (r === 'high')   return 'sn-pill sn-pill-danger text-[10px]'
  if (r === 'medium') return 'sn-pill sn-pill-warn text-[10px]'
  return 'sn-pill sn-pill-success text-[10px]'
}
function statusPill(s) {
  if (s === 'approved') return 'sn-pill-success'
  if (s === 'rejected') return 'sn-pill-danger'
  return 'sn-pill-warn'
}
function timeAgo(ts) {
  if (!ts) return ''
  const s = Math.max(1, Math.floor((Date.now() - new Date(ts).getTime()) / 1000))
  if (s < 60) return `${s}s ago`
  if (s < 3600) return `${Math.floor(s / 60)}m ago`
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`
  return `${Math.floor(s / 86400)}d ago`
}
function formatDiff(v) {
  if (v === null || v === undefined) return '—'
  if (typeof v === 'object') return JSON.stringify(v)
  return String(v)
}

onMounted(() => approvalsStore.fetchApprovals?.())
</script>

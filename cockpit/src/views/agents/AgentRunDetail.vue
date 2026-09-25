<template>
  <div class="px-6 py-7 max-w-6xl mx-auto" data-testid="agent-run-detail-page">
    <div class="mb-6 flex items-start justify-between gap-4">
      <div class="min-w-0">
        <div class="sn-eyebrow">Observe · Agent runs · Run</div>
        <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);" data-testid="agent-run-detail-title">
          {{ run?.skill_name || 'Run' }}
          <span class="mono text-base font-normal" style="color: var(--text-muted);">{{ id }}</span>
        </h1>
        <div v-if="run" class="flex flex-wrap items-center gap-2 mt-2">
          <span class="sn-pill" :class="runStatusPill(run.status)" data-testid="run-detail-status">{{ String(run.status || '').replace(/_/g, ' ') }}</span>
          <RouterLink v-if="run.skill_slug" :to="`/skills/${run.skill_slug}`" class="sn-chip" data-testid="run-detail-skill">{{ run.skill_slug }}</RouterLink>
          <RouterLink v-if="run.agent?.slug" :to="`/agents/${run.agent.slug}/workspace`" class="sn-chip" data-testid="run-detail-agent">
            {{ run.agent.name || run.agent.slug }} · {{ coreAgent(run.agent.core_agent).name }}
          </RouterLink>
          <span class="text-xs" style="color: var(--text-muted);">
            {{ run.trigger_type || 'manual' }} · started {{ timeAgo(run.started_at) }}<span v-if="run.finished_at"> · finished {{ timeAgo(run.finished_at) }}</span>
          </span>
        </div>
      </div>
      <div class="flex gap-2 shrink-0">
        <button
          v-if="isActive"
          type="button"
          class="sn-btn"
          style="border-color: rgba(255,90,122,0.40); color: var(--danger);"
          :disabled="store.busy"
          data-testid="run-cancel"
          @click="onCancel"
        >Cancel</button>
        <button
          v-if="canRetry"
          type="button"
          class="sn-btn"
          :disabled="store.busy"
          data-testid="run-retry"
          @click="onRetry"
        >Retry</button>
        <RouterLink to="/agents/runs" class="sn-btn-secondary text-sm" data-testid="run-detail-back">All runs</RouterLink>
      </div>
    </div>

    <p
      v-if="store.detailError"
      class="text-sm mb-4 rounded-md px-3 py-2"
      style="color: var(--amber); background: rgba(245,165,36,0.08); border: 1px solid rgba(245,165,36,0.25);"
      data-testid="run-detail-error"
    >{{ store.detailError }}</p>
    <p v-if="notice" class="text-xs mb-4" :style="{ color: noticeError ? 'var(--danger)' : 'var(--accent)' }" role="status" data-testid="run-notice">{{ notice }}</p>
    <p v-if="run?.error" class="text-sm mb-4 rounded-md px-3 py-2" style="color: var(--danger); background: rgba(255,90,122,0.08); border: 1px solid rgba(255,90,122,0.30);" data-testid="run-error">{{ run.error }}</p>
    <p v-if="store.detailLoading && !run" class="text-sm" style="color: var(--text-muted);" data-testid="run-detail-loading">Loading run…</p>

    <template v-if="run">
      <!-- Blocked → the questions Atlas is waiting on -->
      <section
        v-if="run.status === 'blocked' && questions.length"
        class="sn-card p-5 mb-4"
        style="border-color: rgba(255,90,122,0.35);"
        aria-labelledby="run-questions-title"
        data-testid="run-questions-form"
      >
        <h2 id="run-questions-title" class="sn-section-title">
          Atlas needs {{ questions.length === 1 ? 'one answer' : `${questions.length} answers` }} to continue
        </h2>
        <form class="space-y-3" @submit.prevent="submitAnswers">
          <div v-for="(q, i) in questions" :key="`${q.path}#${q.section || i}`">
            <label :for="`run-answer-${i}`" class="block text-sm mb-1" style="color: var(--text-primary);">{{ q.question }}</label>
            <p class="mono text-[11px] mb-1" style="color: var(--text-muted);">{{ q.path }}<span v-if="q.section"> › {{ q.section }}</span></p>
            <textarea
              :id="`run-answer-${i}`"
              v-model="answers[i]"
              class="sn-textarea"
              rows="3"
              style="min-height: 4.5rem;"
              :data-testid="`run-answer-${i}`"
            ></textarea>
          </div>
          <button type="submit" class="sn-btn-primary" :disabled="store.busy || !answersComplete" data-testid="run-answers-submit">
            {{ store.busy ? 'Sending…' : 'Answer and continue' }}
          </button>
        </form>
      </section>

      <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_320px] gap-4 items-start">
        <div class="space-y-4 min-w-0">
          <!-- Steps timeline (Traces.vue pattern) -->
          <section class="sn-card p-5" aria-labelledby="run-steps-title" data-testid="run-steps">
            <h2 id="run-steps-title" class="sn-section-title">Steps</h2>
            <ol v-if="run.steps?.length" class="relative pl-5">
              <span class="absolute left-1.5 top-1 bottom-1 w-px" style="background: var(--border);" aria-hidden="true"></span>
              <li v-for="s in run.steps" :key="s.seq" class="relative pb-3" :data-testid="`run-step-${s.seq}`" :data-status="s.status">
                <span class="absolute -left-[15px] top-1.5 w-2 h-2 rounded-full" :style="runStatusDot(s.status)" aria-hidden="true"></span>
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="text-sm" style="color: var(--text-primary);">{{ s.name }}</span>
                  <span class="sn-pill text-[10px]">{{ s.type }}</span>
                  <span class="sn-pill text-[10px]" :class="runStatusPill(s.status)">{{ String(s.status || '').replace(/_/g, ' ') }}</span>
                </div>
                <div class="text-[11px] mt-0.5 mono" style="color: var(--text-muted);">
                  {{ fmtDuration(s.duration_ms) }}<span v-if="s.cost_usd != null"> · ${{ Number(s.cost_usd).toFixed(3) }}</span>
                </div>
              </li>
            </ol>
            <p v-else class="text-xs" style="color: var(--text-muted);">No steps recorded yet.</p>
          </section>

          <!-- Artifacts -->
          <section class="sn-card p-5" aria-labelledby="run-artifacts-title" data-testid="run-artifacts">
            <h2 id="run-artifacts-title" class="sn-section-title">Artifacts</h2>
            <ul v-if="run.artifacts?.length" class="space-y-2">
              <li
                v-for="a in run.artifacts"
                :key="a.id"
                class="rounded-lg px-3 py-2.5"
                style="background: var(--bg-elevated); border: 1px solid var(--border);"
                :data-testid="`run-artifact-${a.id}`"
              >
                <div class="flex items-center justify-between gap-3 flex-wrap">
                  <div class="min-w-0">
                    <p class="mono text-sm truncate" style="color: var(--text-primary);">{{ a.title || a.path || a.id }}</p>
                    <p class="text-[11px] mt-0.5" style="color: var(--text-muted);">
                      <span class="sn-pill text-[10px] mr-1">{{ a.kind }}</span>
                      <span class="sn-pill text-[10px]" :class="artifactPill(a.status)">{{ String(a.status || '').replace(/_/g, ' ') }}</span>
                    </p>
                  </div>
                  <div class="flex items-center gap-1.5 shrink-0">
                    <RouterLink
                      v-if="a.approval_id"
                      :to="{ path: '/approvals', query: { id: a.approval_id } }"
                      class="sn-chip"
                      :data-testid="`run-artifact-approval-${a.id}`"
                    >Approval</RouterLink>
                    <button type="button" class="sn-chip" :data-testid="`run-artifact-preview-${a.id}`" @click="togglePreview(a)">
                      {{ previewOpen[a.id] ? 'Hide' : 'Preview' }}
                    </button>
                  </div>
                </div>

                <div v-if="previewOpen[a.id]" class="mt-3" :data-testid="`run-artifact-body-${a.id}`">
                  <p v-if="!store.artifacts[a.id]" class="text-xs" style="color: var(--text-muted);">Loading…</p>
                  <template v-else-if="editing === a.id">
                    <input
                      v-if="contentOf(a).subject !== undefined"
                      v-model="draft.subject"
                      type="text"
                      class="sn-input text-sm mb-2"
                      aria-label="Subject"
                      :data-testid="`run-artifact-edit-subject-${a.id}`"
                    />
                    <textarea
                      v-model="draft.body"
                      class="sn-textarea"
                      rows="8"
                      aria-label="Body"
                      :data-testid="`run-artifact-edit-body-${a.id}`"
                    ></textarea>
                    <div class="flex items-center gap-2 mt-2">
                      <button type="button" class="sn-btn-primary text-xs" :disabled="store.busy" :data-testid="`run-artifact-save-${a.id}`" @click="saveArtifact(a)">Save edit</button>
                      <button type="button" class="sn-btn-secondary text-xs" @click="editing = null">Cancel</button>
                    </div>
                  </template>
                  <template v-else>
                    <p v-if="contentOf(a).subject" class="text-xs font-medium" style="color: var(--text-secondary);">{{ contentOf(a).subject }}</p>
                    <p class="text-sm mt-1 whitespace-pre-line" style="color: var(--text-primary);" :data-testid="`run-artifact-preview-body-${a.id}`">{{ bodyOf(a) }}</p>
                    <button type="button" class="sn-chip mt-2" :data-testid="`run-artifact-edit-${a.id}`" @click="startEdit(a)">Edit</button>
                  </template>
                </div>
              </li>
            </ul>
            <p v-else class="text-xs" style="color: var(--text-muted);">Nothing produced yet.</p>
          </section>

          <!-- Outputs + next steps -->
          <section class="sn-card p-5" aria-labelledby="run-next-title" data-testid="run-next-steps">
            <h2 id="run-next-title" class="sn-section-title">Goes one step further</h2>
            <p v-if="run.outputs?.summary" class="text-sm" style="color: var(--text-primary); line-height: 1.6;" data-testid="run-summary">{{ run.outputs.summary }}</p>
            <ul v-if="nextSteps.length" class="mt-3 space-y-2">
              <li
                v-for="step in nextSteps"
                :key="step.id"
                class="rounded-lg px-3 py-2.5 flex items-start justify-between gap-3"
                style="background: var(--bg-elevated); border: 1px solid var(--border);"
                :data-testid="`run-next-step-${step.id}`"
                :data-state="step.state"
              >
                <div class="min-w-0">
                  <p class="text-sm font-medium" style="color: var(--text-primary);">{{ step.label }}</p>
                  <p class="text-xs mt-0.5" style="color: var(--text-secondary);">{{ step.does }}</p>
                  <p class="text-[11px] mt-1" style="color: var(--text-muted);">
                    <RouterLink :to="`/skills/${step.skill}`" class="mono hover:underline" style="color: var(--accent);">{{ step.skill }}</RouterLink>
                    · {{ step.origin || 'card' }}
                    · <span class="sn-pill text-[10px]" :class="stepStatePill(step.state)">{{ step.state }}</span>
                  </p>
                  <p
                    v-if="stepResults[step.id]"
                    class="text-xs mt-1.5"
                    role="status"
                    :style="{ color: stepResults[step.id].success ? 'var(--success)' : 'var(--danger)' }"
                    :data-testid="`run-next-step-result-${step.id}`"
                  >
                    <template v-if="stepResults[step.id].success">
                      Started ·
                      <RouterLink v-if="stepResults[step.id].data?.run_id" :to="`/agents/runs/${stepResults[step.id].data.run_id}`" class="underline">view run</RouterLink>
                    </template>
                    <template v-else>{{ stepResults[step.id].error }}</template>
                  </p>
                </div>
                <RouterLink
                  v-if="step.run_id && step.state !== 'proposed'"
                  :to="`/agents/runs/${step.run_id}`"
                  class="sn-chip shrink-0"
                  :data-testid="`run-next-step-link-${step.id}`"
                >Open run</RouterLink>
                <button
                  v-else
                  type="button"
                  class="sn-chip shrink-0"
                  :disabled="skills.running"
                  :data-testid="`run-next-step-run-${step.id}`"
                  @click="runNextStep(step)"
                >{{ step.state === 'skipped' ? 'Run anyway' : 'Run' }}</button>
              </li>
            </ul>
            <p v-else class="text-xs mt-2" style="color: var(--text-muted);">No follow-on steps proposed.</p>
          </section>
        </div>

        <aside class="space-y-4">
          <section class="sn-card p-4" aria-labelledby="run-inputs-title" data-testid="run-inputs">
            <h2 id="run-inputs-title" class="sn-section-title">Inputs</h2>
            <pre class="mono text-[11px] whitespace-pre-wrap break-words" style="color: var(--text-secondary);">{{ formatJSON(run.inputs) }}</pre>
          </section>
          <section class="sn-card p-4" aria-labelledby="run-cost-title" data-testid="run-cost">
            <h2 id="run-cost-title" class="sn-section-title">Cost</h2>
            <dl class="text-xs space-y-1.5">
              <div class="flex justify-between"><dt style="color: var(--text-muted);">Spend</dt><dd class="mono" style="color: var(--text-primary);" data-testid="run-cost-usd">${{ Number(run.cost_usd || 0).toFixed(2) }}</dd></div>
              <div class="flex justify-between"><dt style="color: var(--text-muted);">Tokens in</dt><dd class="mono" style="color: var(--text-primary);">{{ Number(run.tokens_in || 0).toLocaleString() }}</dd></div>
              <div class="flex justify-between"><dt style="color: var(--text-muted);">Tokens out</dt><dd class="mono" style="color: var(--text-primary);">{{ Number(run.tokens_out || 0).toLocaleString() }}</dd></div>
              <div class="flex justify-between"><dt style="color: var(--text-muted);">Took</dt><dd class="mono" style="color: var(--text-primary);">{{ run.started_at ? fmtDuration(durationBetween(run.started_at, run.finished_at)) : '—' }}</dd></div>
              <div class="flex justify-between"><dt style="color: var(--text-muted);">Workspace</dt><dd class="mono" style="color: var(--text-primary);">{{ run.workspace_id || '—' }}</dd></div>
            </dl>
          </section>
        </aside>
      </div>
    </template>
  </div>
</template>

<script setup>
/**
 * One agent run: header + status, the questions form when blocked,
 * steps timeline, artifacts (preview / edit / approval link), next_steps
 * as one-click follow-on runs, cost footer, cancel / retry.
 */
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useRunsStore, ACTIVE_STATUSES } from '../../stores/runs.js'
import { useSkillsStore } from '../../stores/skills.js'
import { timeAgo, fmtDuration, durationBetween, runStatusPill, runStatusDot, coreAgent } from '../../utils/format.js'

const route = useRoute()
const router = useRouter()
const store = useRunsStore()
const skills = useSkillsStore()

const id = computed(() => String(route.params.id || ''))
const run = computed(() => store.details[id.value] || null)
const questions = computed(() => run.value?.questions || [])
const nextSteps = computed(() => run.value?.outputs?.next_steps || [])
const isActive = computed(() => ACTIVE_STATUSES.includes(run.value?.status))
const canRetry = computed(() => ['failed', 'cancelled'].includes(run.value?.status))

const answers = ref([])
const notice = ref('')
const noticeError = ref(false)
const previewOpen = reactive({})
const editing = ref(null)
const draft = reactive({ subject: '', body: '' })
const stepResults = reactive({})

watch(questions, (qs) => { answers.value = qs.map(() => '') }, { immediate: true })
const answersComplete = computed(() =>
  questions.value.length > 0 && questions.value.every((_, i) => String(answers.value[i] || '').trim().length > 0),
)

async function load() {
  notice.value = ''
  Object.keys(previewOpen).forEach((k) => delete previewOpen[k])
  Object.keys(stepResults).forEach((k) => delete stepResults[k])
  editing.value = null
  if (id.value) await store.fetchRun(id.value)
}
watch(id, load, { immediate: true })

async function submitAnswers() {
  if (!answersComplete.value) return
  const payload = questions.value.map((q, i) => ({ path: q.path, section: q.section, text: String(answers.value[i]).trim() }))
  const res = await store.answer(id.value, payload)
  noticeError.value = !res.success
  notice.value = res.success ? 'Answers sent — Atlas is picking the run back up.' : res.error
}

async function onCancel() {
  const res = await store.cancel(id.value)
  noticeError.value = !res.success
  notice.value = res.success ? 'Run cancelled.' : res.error
}

async function onRetry() {
  const res = await store.retry(id.value)
  noticeError.value = !res.success
  if (res.success && res.run_id && res.run_id !== id.value) {
    router.push(`/agents/runs/${res.run_id}`)
    return
  }
  notice.value = res.success ? 'Retry queued.' : res.error
}

// ── Artifacts ────────────────────────────────────────────────────────
function contentOf(a) {
  const c = store.artifacts[a.id]?.content
  if (c == null) return {}
  return typeof c === 'string' ? { body: c } : c
}
function bodyOf(a) {
  const c = contentOf(a)
  return c.body || c.text || c.markdown || '—'
}
function artifactPill(s) {
  if (s === 'approved' || s === 'applied') return 'sn-pill-success'
  if (s === 'rejected') return 'sn-pill-danger'
  if (s === 'pending_approval') return 'sn-pill-warn'
  return ''
}
async function togglePreview(a) {
  if (previewOpen[a.id]) { previewOpen[a.id] = false; return }
  previewOpen[a.id] = true
  if (!store.artifacts[a.id]) await store.fetchArtifact(a.id)
}
function startEdit(a) {
  const c = contentOf(a)
  draft.subject = c.subject ?? ''
  draft.body = c.body ?? c.text ?? c.markdown ?? ''
  editing.value = a.id
}
async function saveArtifact(a) {
  const c = contentOf(a)
  const content = { ...c, body: draft.body }
  if (c.subject !== undefined) content.subject = draft.subject
  const res = await store.patchArtifact(a.id, content)
  noticeError.value = !res.success
  notice.value = res.success ? 'Draft saved — approve it from the approval to send this version.' : res.error
  if (res.success) editing.value = null
}

// ── Next steps ───────────────────────────────────────────────────────
function stepStatePill(state) {
  if (state === 'done') return 'sn-pill-success'
  if (state === 'running') return 'sn-pill-accent'
  if (state === 'skipped') return ''
  return 'sn-pill-warn'
}
async function runNextStep(step) {
  const res = await skills.run(step.skill, step.inputs || {}, { source_run_id: id.value, next_step_id: step.id })
  stepResults[step.id] = res
  if (res.success) {
    store.handleRunUpdated({
      id: id.value,
      outputs: {
        ...(run.value?.outputs || {}),
        next_steps: nextSteps.value.map((s) => (s.id === step.id ? { ...s, state: 'running', run_id: res.data?.run_id || s.run_id } : s)),
      },
    })
  }
}

function formatJSON(o) {
  if (!o) return '{}'
  try { return JSON.stringify(o, null, 2) } catch { return String(o) }
}
</script>

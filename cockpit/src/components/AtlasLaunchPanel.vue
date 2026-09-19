<template>
  <section class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto p-3" data-testid="atlas-launch-panel">
    <!-- Header: where the launch is, and how far along -->
    <div class="sn-card p-4">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="sn-eyebrow">Start a business</p>
          <h3 class="mt-0.5 text-sm font-semibold" style="color: var(--text-primary);" data-testid="launch-stage-title">
            {{ stageTitle }}
          </h3>
        </div>
        <span class="sn-pill shrink-0" :class="statusPillClass" data-testid="launch-status-pill">{{ statusLabel }}</span>
      </div>

      <div
        class="mt-3 h-2 w-full overflow-hidden rounded-full"
        style="background: var(--bg-elevated);"
        role="progressbar"
        :aria-valuenow="progressPct"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-label="`Business launch ${progressPct}% complete`"
        data-testid="launch-progress"
        :data-pct="progressPct"
      >
        <div class="h-full rounded-full transition-all" :style="{ width: progressPct + '%', background: 'var(--accent)' }" />
      </div>
      <p class="mt-1.5 text-xs" style="color: var(--text-secondary);" data-testid="launch-progress-label">
        {{ progressPct }}% — {{ committedCount }} of {{ stages.length }} stages filled in
      </p>

      <p
        v-if="nowFilling"
        class="mono mt-2 truncate text-[11px]"
        style="color: var(--text-muted);"
        data-testid="launch-now-filling"
      >Now filling: {{ nowFilling }}</p>

      <p v-if="error" class="mt-2 text-xs" style="color: var(--amber);" data-testid="launch-error">{{ error }}</p>
    </div>

    <!-- The question Atlas is waiting on -->
    <div v-if="question" class="sn-card p-4" data-testid="launch-question-card">
      <p class="sn-eyebrow">{{ question.required ? 'Needs an answer' : 'Optional' }}</p>
      <p class="mt-1 text-sm" style="color: var(--text-primary);" data-testid="launch-question-prompt">
        {{ question.prompt }}
      </p>

      <div v-if="(question.choices || []).length" class="mt-2 flex flex-wrap gap-1.5">
        <button
          v-for="choice in question.choices"
          :key="choice"
          type="button"
          class="sn-chip"
          :data-testid="`launch-choice-${choice}`"
          @click="send(choice)"
        >{{ choice }}</button>
      </div>

      <textarea
        v-model="draft"
        rows="3"
        class="sn-input mt-2 w-full text-sm"
        :placeholder="question.type === 'money' ? 'A number is fine — say the currency if it is not your local one.' : 'Answer in your own words.'"
        data-testid="launch-answer-input"
        @keydown.enter.meta.prevent="send()"
        @keydown.enter.ctrl.prevent="send()"
      />

      <div class="mt-2 flex items-center gap-2">
        <button
          type="button"
          class="sn-btn-primary text-xs"
          :disabled="busy || !draft.trim()"
          data-testid="launch-answer-send"
          @click="send()"
        >Answer</button>
        <button
          v-if="!question.required"
          type="button"
          class="sn-btn-secondary text-xs"
          :disabled="busy"
          data-testid="launch-answer-skip"
          @click="skip()"
        >Skip this one</button>
      </div>
    </div>

    <div v-else class="sn-card p-4" data-testid="launch-interview-done">
      <p class="text-sm" style="color: var(--text-primary);">
        That is everything Atlas needs to ask. Build the numbers and the plan next.
      </p>
    </div>

    <!-- Where the business will be registered -->
    <div class="sn-card p-4" data-testid="launch-jurisdiction">
      <p class="sn-eyebrow">Registered in</p>
      <div class="mt-2 flex flex-wrap gap-1.5">
        <button
          v-for="code in jurisdictions"
          :key="code"
          type="button"
          class="sn-chip"
          :class="{ 'sn-chip-active': code === jurisdiction }"
          :aria-pressed="code === jurisdiction ? 'true' : 'false'"
          :disabled="busy"
          :data-testid="`launch-jurisdiction-${code}`"
          @click="chooseJurisdiction(code)"
        >{{ JURISDICTION_LABELS[code] || code.toUpperCase() }}</button>
      </div>
      <p class="mt-2 text-[11px]" style="color: var(--text-muted);">
        Atlas maps and checks registrations. It never registers or files for you.
      </p>
    </div>

    <!-- What the brain still does not know -->
    <BrainReadiness
      :files="readinessFiles"
      title="What your brain knows so far"
      @open="openFile"
      @ask="askAboutFile"
    />

    <!-- Generation -->
    <div class="sn-card p-4" data-testid="launch-generate">
      <p class="sn-eyebrow">Build it</p>
      <div class="mt-2 flex flex-wrap gap-1.5">
        <button
          type="button"
          class="sn-btn-secondary text-xs"
          :disabled="busy"
          data-testid="launch-generate-finance"
          @click="generate(['finance'])"
        >Generate finance model</button>
        <button
          type="button"
          class="sn-btn-secondary text-xs"
          :disabled="busy"
          data-testid="launch-generate-plan"
          @click="generate(['plan'])"
        >Generate plan</button>
      </div>
      <p class="mt-2 text-[11px]" style="color: var(--text-muted);">
        Every number comes from the spreadsheet model, never from a guess.
      </p>
    </div>

    <!-- Deliverables -->
    <div class="sn-card p-4" data-testid="launch-deliverables">
      <p class="sn-eyebrow">Deliverables</p>
      <p
        v-if="!deliverables.length"
        class="mt-2 text-xs"
        style="color: var(--text-muted);"
        data-testid="launch-deliverables-empty"
      >Nothing generated yet.</p>
      <ul v-else class="mt-2 space-y-1.5">
        <li
          v-for="item in deliverables"
          :key="item.path"
          class="flex items-center justify-between gap-2 rounded-lg px-3 py-2"
          style="background: var(--bg-elevated); border: 1px solid var(--border);"
          :data-testid="`launch-deliverable-${slug(item.path)}`"
          :data-available="item.available ? 'true' : 'false'"
        >
          <div class="min-w-0">
            <p class="truncate text-sm" style="color: var(--text-primary);">{{ item.title || item.path }}</p>
            <p class="mono truncate text-[11px]" style="color: var(--text-muted);">{{ item.path }}</p>
          </div>
          <span class="sn-pill shrink-0" :class="item.available ? 'sn-pill-success' : 'sn-pill-warn'">
            {{ item.available ? (item.format || 'ready') : 'unavailable' }}
          </span>
        </li>
      </ul>
    </div>

    <!-- Finish -->
    <div v-if="complete" class="sn-card p-4" data-testid="launch-finish">
      <p class="text-sm" style="color: var(--text-primary);">
        Your plan is written and your brain is filled in. See it on the map.
      </p>
      <button type="button" class="sn-btn-primary mt-2 text-xs" data-testid="launch-finish-cta" @click="finish">
        See your business map
      </button>
    </div>

    <p class="px-1 pb-2 text-[11px]" style="color: var(--text-muted);" data-testid="launch-disclaimer">
      {{ disclaimer }}
    </p>

    <BrainFileDrawer
      v-model="drawerOpen"
      :file="drawerFile"
      :saving="brainStore.saving"
      @save="saveFile"
      @ask="askAboutFile"
      @close="drawerOpen = false"
    />
  </section>
</template>

<script setup>
/**
 * AtlasLaunchPanel — the right-hand column of /atlas?mode=launch: "Atlas, I
 * want to start a business" (plan D7 §5, cockpit surface E).
 *
 * It shows where the launch is (status pill, progress bar, the brain file
 * being filled right now), the one question Atlas is waiting on, the
 * jurisdiction, what the brain still does not know, the generate buttons and
 * the deliverables — and a finish CTA to the business map once everything is
 * in.
 *
 * Answers go through `atlasStore.sendMessage(text, { mode: 'launch' })`, the
 * same chat turn the founder could have typed, so the transcript and the
 * panel never disagree; the reply's `metadata.launch` / `metadata.brain`
 * refresh both without another round trip. GET /api/launch falls back to the
 * shipped fixture with an inline notice, like the other cockpit surfaces.
 *
 * Nothing shown here is legal or financial advice, and the panel says so.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api.js'
import { clone, fallbackNotice, describeError } from '../utils/apiFallback.js'
import { useAtlasStore } from '../stores/atlas.js'
import { useBrainStore } from '../stores/brain.js'
import BrainReadiness from './brain/BrainReadiness.vue'
import BrainFileDrawer from './brain/BrainFileDrawer.vue'
import launchFixture from '../../tests/fixtures/launch.json'

const DISCLAIMER = 'Not legal or financial advice.'

const JURISDICTION_LABELS = {
  uk: 'United Kingdom',
  za: 'South Africa',
  zw: 'Zimbabwe',
}

const STATUS_LABELS = {
  purchased: 'Not started',
  interviewing: 'Answering',
  researching: 'Researching',
  modelling: 'Building the numbers',
  drafted: 'Plan drafted',
  awaiting_approval: 'Waiting on you',
  approved: 'Approved',
  live: 'Live',
}

const router = useRouter()
const atlasStore = useAtlasStore()
const brainStore = useBrainStore()

/** GET /api/launch state; `atlasStore.launch` overwrites it on every turn. */
const state = ref(null)
const busy = ref(false)
const error = ref(null)
const draft = ref('')
const drawerOpen = ref(false)
const drawerFile = ref(null)

const launch = computed(() => atlasStore.launch || state.value || {})
const question = computed(() => launch.value.next_question || null)
const stages = computed(() => launch.value.stages || [])
const deliverables = computed(() => launch.value.deliverables || [])
const jurisdiction = computed(() => launch.value.jurisdiction || null)
const jurisdictions = computed(() => launch.value.jurisdictions || Object.keys(JURISDICTION_LABELS))
const nowFilling = computed(() => launch.value.now_filling || question.value?.now_filling || null)
const disclaimer = computed(() => launch.value.disclaimer || DISCLAIMER)
const progressPct = computed(() => Math.max(0, Math.min(100, Number(launch.value.progress_pct) || 0)))
const committedCount = computed(() => stages.value.filter((s) => s.committed).length)
const complete = computed(() => progressPct.value >= 100)
const stageTitle = computed(() => launch.value.stage_title || 'Your business')
const statusLabel = computed(() => STATUS_LABELS[launch.value.status] || 'Not started')
const statusPillClass = computed(() => {
  if (launch.value.status === 'live' || launch.value.status === 'approved') return 'sn-pill-success'
  if (launch.value.status === 'awaiting_approval') return 'sn-pill-warn'
  return 'sn-pill-accent'
})

/**
 * Readiness rows: whatever Atlas last returned, else the brain store's own.
 * Copied, and without a `key` — BrainReadiness derives a stable one from the
 * path so the rows keep the same test ids everywhere they are rendered.
 */
const readinessFiles = computed(() =>
  (atlasStore.brainReadiness?.files || brainStore.readiness?.files || []).map((f) => ({ ...f })),
)

/** A DOM-safe id for one deliverable path (the extension distinguishes them). */
function slug(value) {
  return String(value || '').replace(/[^a-z0-9_-]+/gi, '-').replace(/^-|-$/g, '') || 'file'
}

async function load() {
  try {
    const { data } = await api.get('/api/launch')
    state.value = data?.data || null
    error.value = null
  } catch (err) {
    state.value = clone(launchFixture.data)
    error.value = fallbackNotice(err, 'your business launch')
  }
}

async function send(text) {
  const message = String(text ?? draft.value).trim()
  if (!message || busy.value) return { success: false }
  busy.value = true
  try {
    const result = await atlasStore.sendMessage(message, { mode: 'launch' })
    draft.value = ''
    return result
  } finally {
    busy.value = false
  }
}

/**
 * Skipping is an empty answer: POST /api/launch/answer records the question
 * as asked-and-passed so the runner stops offering it.
 */
async function skip() {
  if (!question.value || busy.value) return
  busy.value = true
  try {
    const { data } = await api.post('/api/launch/answer', {
      question_id: question.value.id,
      answer: '',
      skip: true,
    })
    if (data?.data) state.value = { ...(state.value || {}), ...data.data }
    atlasStore.launch = data?.data ? { ...(atlasStore.launch || {}), ...data.data } : atlasStore.launch
    error.value = null
  } catch (err) {
    error.value = describeError(err, 'Could not skip that question')
  } finally {
    busy.value = false
  }
}

async function chooseJurisdiction(code) {
  if (busy.value || code === jurisdiction.value) return
  busy.value = true
  try {
    const { data } = await api.post('/api/launch/start', { jurisdiction: code })
    if (data?.data) {
      state.value = data.data
      atlasStore.launch = atlasStore.launch ? { ...atlasStore.launch, ...data.data } : data.data
    }
    error.value = null
  } catch (err) {
    error.value = describeError(err, 'Could not set the country')
  } finally {
    busy.value = false
  }
}

async function generate(targets) {
  if (busy.value) return
  busy.value = true
  try {
    const { data } = await api.post('/api/launch/generate', { targets })
    const next = data?.data?.state
    if (next) {
      state.value = { ...(state.value || {}), ...next }
      atlasStore.launch = atlasStore.launch ? { ...atlasStore.launch, ...next } : next
    }
    error.value = null
    return data?.data || null
  } catch (err) {
    error.value = describeError(err, 'Could not generate that yet')
    return null
  } finally {
    busy.value = false
  }
}

async function openFile(file) {
  const path = file?.path
  if (!path) return
  const { file: loaded } = await brainStore.fetchFile(path)
  drawerFile.value = {
    ...file,
    ...(loaded || {}),
    path,
    body_md: loaded?.content ?? '',
  }
  drawerOpen.value = true
}

async function saveFile({ path, body_md: body }) {
  const result = await brainStore.saveFile(path, body)
  if (result.success) {
    drawerOpen.value = false
    await brainStore.fetchReadiness()
  } else {
    error.value = result.error
  }
}

/** "Ask Atlas" — the file's own manifest question, asked in the launch thread. */
function askAboutFile(file) {
  const prompt = file?.ask_prompt || `Help me fill in ${file?.title || file?.path}.`
  drawerOpen.value = false
  return send(prompt)
}

function finish() {
  router?.push?.('/map')
}

// A launch turn's `metadata.launch` is the freshest state there is.
watch(
  () => atlasStore.launch,
  (next) => {
    if (next) state.value = { ...(state.value || {}), ...next }
  },
)

onMounted(load)

defineExpose({ load, send, skip, generate, chooseJurisdiction })
</script>

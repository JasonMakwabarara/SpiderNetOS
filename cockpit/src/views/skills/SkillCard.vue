<template>
  <div class="px-6 py-7 max-w-7xl mx-auto" data-testid="skill-card-page">
    <!-- Header (PartnerThread pattern) -->
    <div class="mb-6 flex items-start justify-between gap-4">
      <div class="min-w-0">
        <div class="sn-eyebrow">Build · Skills · {{ card?.pillar?.label || 'Card' }}</div>
        <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);" data-testid="skill-card-title">
          {{ card?.name || fallbackTitle }}
        </h1>
        <p v-if="card" class="text-sm mt-1" style="color: var(--text-secondary);">{{ card.one_liner }}</p>
        <div v-if="card" class="flex flex-wrap items-center gap-2 mt-2">
          <span
            class="sn-pill"
            :style="{ color: stageInfo.color, borderColor: stageInfo.color }"
            data-testid="skill-card-stage"
          >{{ stageInfo.label }}<span v-if="card.pipeline?.inherited"> · inherited</span></span>
          <span
            class="sn-pill"
            :class="card.enabled ? 'sn-pill-success' : 'sn-pill-warn'"
            data-testid="skill-card-enabled"
          >{{ card.enabled ? 'Enabled' : 'Not enabled' }}</span>
          <span class="sn-pill" data-testid="skill-card-chain" :title="agent.role">
            {{ card.identity?.name || '—' }} → {{ agent.name }} → Atlas
          </span>
          <span v-for="t in card.tags || []" :key="t" class="sn-pill text-[10px]">{{ t }}</span>
        </div>
      </div>
      <RouterLink to="/skills" class="sn-btn-secondary text-sm shrink-0" data-testid="skill-card-back">Back to skills</RouterLink>
    </div>

    <p
      v-if="store.cardError"
      class="text-sm mb-4 rounded-md px-3 py-2"
      style="color: var(--amber); background: rgba(245,165,36,0.08); border: 1px solid rgba(245,165,36,0.25);"
      data-testid="skill-card-error"
    >{{ store.cardError }}</p>
    <p v-if="store.cardLoading && !card" class="text-sm" style="color: var(--text-muted);" data-testid="skill-card-loading">
      Loading card…
    </p>

    <div v-if="card" class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_360px] gap-5 items-start">
      <div class="space-y-4 min-w-0">
        <!-- 1 · At a glance -->
        <section class="sn-card p-5" aria-labelledby="sec-glance" data-testid="skill-section-glance">
          <h2 id="sec-glance" class="sn-section-title">At a glance</h2>
          <p class="text-sm" style="color: var(--text-primary); line-height: 1.6;">{{ card.description || card.one_liner }}</p>
          <dl class="grid sm:grid-cols-2 gap-x-6 gap-y-2 mt-4 text-xs">
            <div>
              <dt class="uppercase tracking-wider" style="color: var(--text-muted);">Pillar · node</dt>
              <dd style="color: var(--text-primary);">{{ card.pillar?.label }} › {{ card.node?.label }}</dd>
            </div>
            <div>
              <dt class="uppercase tracking-wider" style="color: var(--text-muted);">Runs as</dt>
              <dd style="color: var(--text-primary);">{{ card.identity?.name }} · {{ agent.name }} <span style="color: var(--text-muted);">— {{ agent.role }}</span></dd>
            </div>
            <div>
              <dt class="uppercase tracking-wider" style="color: var(--text-muted);">Provisioning</dt>
              <dd style="color: var(--text-primary);">{{ String(card.provisioning || 'on_demand').replace(/_/g, ' ') }}</dd>
            </div>
            <div>
              <dt class="uppercase tracking-wider" style="color: var(--text-muted);">Last run</dt>
              <dd style="color: var(--text-primary);">{{ card.last_run_at ? timeAgo(card.last_run_at) : 'never' }}</dd>
            </div>
          </dl>
          <div v-if="card.recent_runs?.length" class="mt-4">
            <p class="text-[11px] uppercase tracking-wider mb-1.5" style="color: var(--text-muted);">Recent runs</p>
            <ul class="space-y-1">
              <li v-for="r in card.recent_runs.slice(0, 3)" :key="r.id" class="flex items-center gap-2 text-xs">
                <span class="w-1.5 h-1.5 rounded-full shrink-0" :style="runStatusDot(r.status)" aria-hidden="true"></span>
                <RouterLink :to="`/agents/runs/${r.id}`" class="mono hover:underline" style="color: var(--text-primary);" :data-testid="`skill-recent-run-${r.id}`">{{ r.id }}</RouterLink>
                <span class="sn-pill text-[10px]" :class="runStatusPill(r.status)">{{ r.status }}</span>
                <span style="color: var(--text-muted);">{{ timeAgo(r.started_at) }}<span v-if="r.cost_usd != null"> · ${{ Number(r.cost_usd).toFixed(2) }}</span></span>
              </li>
            </ul>
          </div>
        </section>

        <!-- 2 · Covers on the map -->
        <section class="sn-card p-5" aria-labelledby="sec-covers" data-testid="skill-section-covers">
          <h2 id="sec-covers" class="sn-section-title">Covers on the map</h2>
          <ul v-if="card.covers?.length" class="space-y-1.5">
            <li v-for="c in card.covers" :key="c.node_id" class="flex items-center justify-between gap-3 text-sm">
              <span style="color: var(--text-primary);">
                <span class="capitalize" style="color: var(--text-muted);">{{ c.pillar }}</span> › {{ c.label }}
              </span>
              <RouterLink :to="{ path: '/map', query: { node: c.node_id } }" class="sn-chip shrink-0" :data-testid="`skill-cover-${c.node_id}`">Open on map</RouterLink>
            </li>
          </ul>
          <p v-else class="text-xs" style="color: var(--text-muted);">Not placed on the map yet.</p>
        </section>

        <!-- 3 · Breaks into -->
        <section class="sn-card p-5" aria-labelledby="sec-breaks-into" data-testid="skill-section-breaks-into">
          <h2 id="sec-breaks-into" class="sn-section-title">Breaks into</h2>
          <div v-if="card.breaks_into?.length" class="flex flex-wrap gap-2">
            <RouterLink
              v-for="s in card.breaks_into"
              :key="s.slug"
              :to="`/skills/${s.slug}`"
              class="sn-chip"
              :data-testid="`skill-chip-${s.slug}`"
            >
              <span class="inline-block size-1.5 rounded-full" :style="{ background: s.enabled ? 'var(--success)' : 'var(--status-idle)' }" aria-hidden="true"></span>
              {{ s.name }}
            </RouterLink>
          </div>
          <p v-else class="text-xs" style="color: var(--text-muted);">This is an atomic skill — nothing smaller inside it.</p>
        </section>

        <!-- 4 · Builds on -->
        <section class="sn-card p-5" aria-labelledby="sec-builds-on" data-testid="skill-section-builds-on">
          <h2 id="sec-builds-on" class="sn-section-title">Builds on</h2>
          <div v-if="card.builds_on?.length" class="flex flex-wrap gap-2">
            <RouterLink
              v-for="s in card.builds_on"
              :key="s.slug"
              :to="`/skills/${s.slug}`"
              class="sn-chip"
              :data-testid="`skill-chip-${s.slug}`"
            >
              <span class="inline-block size-1.5 rounded-full" :style="{ background: s.enabled ? 'var(--success)' : 'var(--status-idle)' }" aria-hidden="true"></span>
              {{ s.name }}
            </RouterLink>
          </div>
          <p v-else class="text-xs" style="color: var(--text-muted);">Stands on its own.</p>
        </section>

        <!-- 5 · Replaces -->
        <section class="sn-card p-5" aria-labelledby="sec-replaces" data-testid="skill-section-replaces">
          <h2 id="sec-replaces" class="sn-section-title">Replaces</h2>
          <table v-if="card.replaces?.length" class="w-full text-sm">
            <thead>
              <tr class="text-left text-[11px] uppercase tracking-wider" style="color: var(--text-muted);">
                <th class="py-1.5 pr-3 font-medium">What</th>
                <th class="py-1.5 pr-3 font-medium">Cost</th>
                <th class="py-1.5 font-medium">Kind</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(r, i) in card.replaces" :key="i" style="border-top: 1px solid var(--divider);">
                <td class="py-2 pr-3" style="color: var(--text-primary);">{{ r.what }}</td>
                <td class="py-2 pr-3 mono" style="color: var(--text-secondary);">{{ r.cost }}</td>
                <td class="py-2"><span class="sn-pill text-[10px]">{{ r.kind }}</span></td>
              </tr>
            </tbody>
          </table>
          <p v-if="card.replaces_note" class="text-xs italic mt-2" style="color: var(--text-muted);" data-testid="skill-replaces-note">{{ card.replaces_note }}</p>
        </section>

        <!-- 6 · Pipeline -->
        <section class="sn-card p-5" aria-labelledby="sec-pipeline" data-testid="skill-section-pipeline">
          <h2 id="sec-pipeline" class="sn-section-title">Pipeline</h2>
          <PipelineStepper
            :stage="stage"
            :inherited="!!card.pipeline?.inherited"
            :can-change="canManage"
            @change="onStageChange"
          />
          <div class="grid sm:grid-cols-2 gap-3 mt-4">
            <div class="rounded-lg p-3" style="background: var(--bg-elevated); border: 1px solid var(--border);">
              <p class="text-[11px] uppercase tracking-wider" style="color: var(--text-muted);">You, at this stage</p>
              <p class="text-sm mt-1" style="color: var(--text-primary);" data-testid="skill-stage-your-role">{{ currentCopy.your_role || '—' }}</p>
            </div>
            <div class="rounded-lg p-3" style="background: var(--bg-elevated); border: 1px solid var(--border);">
              <p class="text-[11px] uppercase tracking-wider" style="color: var(--text-muted);">Atlas, at this stage</p>
              <p class="text-sm mt-1" style="color: var(--text-primary);" data-testid="skill-stage-atlas-role">{{ currentCopy.atlas_role || '—' }}</p>
            </div>
          </div>
          <div v-if="gate" class="mt-4" data-testid="skill-promotion-gate">
            <div class="flex items-center justify-between text-xs">
              <span style="color: var(--text-secondary);">Promotion gate — approved drafts with no edits</span>
              <span class="mono" style="color: var(--text-primary);">{{ gate.have ?? 0 }} / {{ gate.clean_drafts }}</span>
            </div>
            <div class="mt-1.5 h-1.5 rounded-full overflow-hidden" style="background: var(--bg-elevated);">
              <div class="h-full rounded-full" :style="`width:${gatePct}%; background: ${gatePct >= 100 ? 'var(--success)' : 'var(--accent)'};`"></div>
            </div>
            <p class="text-[11px] mt-1" style="color: var(--text-muted);">Autonomous unlocks after {{ gate.clean_drafts }} clean drafts; an edit resets the count.</p>
          </div>
          <p
            v-if="stageNotice"
            class="text-xs mt-3"
            :style="{ color: stageNoticeError ? 'var(--danger)' : 'var(--accent)' }"
            role="status"
            data-testid="skill-stage-notice"
          >{{ stageNotice }}</p>
        </section>

        <!-- 7 · Your role -->
        <section class="sn-card p-5" aria-labelledby="sec-your-role" data-testid="skill-section-your-role">
          <h2 id="sec-your-role" class="sn-section-title">Your role</h2>
          <p class="text-sm" style="color: var(--text-primary); line-height: 1.6;">{{ card.your_role || currentCopy.your_role || '—' }}</p>
          <div class="grid sm:grid-cols-3 gap-2 mt-4">
            <div
              v-for="s in stageOrder"
              :key="s"
              class="rounded-lg p-3 text-xs"
              :style="stageBoxStyle(s)"
              :data-testid="`skill-role-${s}`"
            >
              <p class="font-semibold mb-1" :style="{ color: stageMeta(s).color }">{{ stageMeta(s).label }}<span v-if="s === stage"> · now</span></p>
              <p style="color: var(--text-secondary);">{{ card.pipeline?.stage_copy?.[s]?.your_role || '—' }}</p>
            </div>
          </div>
        </section>

        <!-- 8 · Goes one step further -->
        <section class="sn-card p-5" aria-labelledby="sec-one-step" data-testid="skill-section-one-step-further">
          <h2 id="sec-one-step" class="sn-section-title">Goes one step further</h2>
          <p v-if="card.one_step_further?.summary" class="text-sm" style="color: var(--text-primary); line-height: 1.6;">{{ card.one_step_further.summary }}</p>
          <ul v-if="steps.length" class="mt-3 space-y-2">
            <li
              v-for="step in steps"
              :key="step.id"
              class="rounded-lg px-3 py-2.5 flex items-start justify-between gap-3"
              style="background: var(--bg-elevated); border: 1px solid var(--border);"
              :data-testid="`one-step-${step.id}`"
            >
              <div class="min-w-0">
                <p class="text-sm font-medium" style="color: var(--text-primary);">{{ step.label }}</p>
                <p class="text-xs mt-0.5" style="color: var(--text-secondary);">{{ step.does }}</p>
                <p class="text-[11px] mt-1 flex flex-wrap items-center gap-1.5" style="color: var(--text-muted);">
                  <RouterLink :to="`/skills/${step.skill}`" class="mono hover:underline" style="color: var(--accent);">{{ step.skill }}</RouterLink>
                  <span>· {{ whenLabel(step.when) }}</span>
                  <span class="text-[10px]" :class="riskPill(step.risk)">{{ step.risk || 'low' }}</span>
                  <span v-if="step.requires_brain?.length">· reads {{ step.requires_brain.join(', ') }}</span>
                </p>
                <p
                  v-if="stepResults[step.id]"
                  class="text-xs mt-1.5"
                  role="status"
                  :style="{ color: stepResults[step.id].success ? 'var(--success)' : 'var(--danger)' }"
                  :data-testid="`one-step-result-${step.id}`"
                >
                  <template v-if="stepResults[step.id].success">
                    Started ·
                    <RouterLink v-if="stepResults[step.id].data?.run_id" :to="`/agents/runs/${stepResults[step.id].data.run_id}`" class="underline">view run</RouterLink>
                  </template>
                  <template v-else>{{ stepResults[step.id].error }}</template>
                </p>
              </div>
              <button
                type="button"
                class="sn-chip shrink-0"
                :disabled="store.running"
                :data-testid="`one-step-run-${step.id}`"
                @click="runStep(step)"
              >Run</button>
            </li>
          </ul>
          <p v-else class="text-xs" style="color: var(--text-muted);">No follow-on steps defined yet.</p>
        </section>

        <!-- 9 · Reads before it writes -->
        <section aria-labelledby="sec-brain" data-testid="skill-section-brain">
          <h2 id="sec-brain" class="sr-only">Reads before it writes</h2>
          <BrainReadiness :files="card.brain?.files || []" @open="openBrainFile" @ask="askAtlas" />
          <p
            v-if="brainNotice"
            class="text-xs mt-2"
            :style="{ color: brainNoticeError ? 'var(--danger)' : 'var(--accent)' }"
            role="status"
            data-testid="skill-brain-notice"
          >{{ brainNotice }}</p>
        </section>

        <!-- 10 · Hands off to -->
        <section class="sn-card p-5" aria-labelledby="sec-hands-off" data-testid="skill-section-hands-off">
          <h2 id="sec-hands-off" class="sn-section-title">Hands off to</h2>
          <ul v-if="card.hands_off_to?.length" class="space-y-2">
            <li
              v-for="h in card.hands_off_to"
              :key="`${h.type}-${h.slug || h.name}`"
              class="flex items-center justify-between gap-3 rounded-lg px-3 py-2"
              style="background: var(--bg-elevated); border: 1px solid var(--border);"
              :data-testid="`skill-handoff-${h.slug || slugify(h.name)}`"
            >
              <div class="min-w-0">
                <p class="text-sm" style="color: var(--text-primary);">
                  <span class="sn-pill text-[10px] mr-1.5">{{ h.type }}</span>
                  <RouterLink v-if="handoffPath(h)" :to="handoffPath(h)" class="hover:underline">{{ h.name }}</RouterLink>
                  <span v-else>{{ h.name }}</span>
                </p>
                <p v-if="h.role" class="text-xs mt-0.5" style="color: var(--text-secondary);">{{ h.role }}</p>
              </div>
              <span class="sn-pill text-[10px] shrink-0" :class="h.available ? 'sn-pill-success' : 'sn-pill-warn'">{{ h.available ? 'Available' : 'Not yet' }}</span>
            </li>
          </ul>
          <p v-else class="text-xs" style="color: var(--text-muted);">Ends with you.</p>
        </section>
      </div>

      <div class="lg:sticky lg:top-6">
        <SkillRunPanel
          :card="card"
          :running="store.running"
          :result="runResult"
          @run="onRun"
          @answer="onAnswer"
          @enable="onEnable"
        />
      </div>
    </div>

    <ConfirmDialog
      v-model="confirmAutonomous"
      title="Go autonomous?"
      confirm-label="Yes, go autonomous"
      @confirm="applyStage('autonomous')"
      @cancel="pendingStage = null"
    >
      <p class="text-sm" style="color: var(--text-secondary);">
        Atlas will run <strong style="color: var(--text-primary);">{{ card?.name }}</strong> without waiting for you on each draft.
        {{ card?.pipeline?.stage_copy?.autonomous?.atlas_role }}
      </p>
      <p
        v-if="gate && (gate.have ?? 0) < gate.clean_drafts"
        class="text-xs mt-2"
        style="color: var(--amber);"
        data-testid="skill-autonomous-gate-warning"
      >The promotion gate is not met yet ({{ gate.have ?? 0 }} / {{ gate.clean_drafts }} clean drafts). The backend may refuse this or hold it in shadow mode.</p>
    </ConfirmDialog>

    <BrainFileDrawer v-model="drawerOpen" :file="drawerFile" :saving="brain.saving" @save="saveBrainFile" />
  </div>
</template>

<script setup>
/**
 * Skill card (surface A) — ten stacked sections in a fixed order plus the
 * sticky run panel. Reloads when the slug changes (`watch(route.params.slug)`).
 */
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSkillsStore, PIPELINE_ORDER } from '../../stores/skills.js'
import { useBrainStore } from '../../stores/brain.js'
import { useRunsStore } from '../../stores/runs.js'
import { useAuthStore } from '../../stores/auth.js'
import PipelineStepper from '../../components/skills/PipelineStepper.vue'
import SkillRunPanel from '../../components/skills/SkillRunPanel.vue'
import BrainReadiness from '../../components/brain/BrainReadiness.vue'
import BrainFileDrawer from '../../components/brain/BrainFileDrawer.vue'
import ConfirmDialog from '../../components/feedback/ConfirmDialog.vue'
import { timeAgo, stageMeta, coreAgent, runStatusPill, runStatusDot, riskPill, getPath, titleCase } from '../../utils/format.js'

const route = useRoute()
const router = useRouter()
const store = useSkillsStore()
const brain = useBrainStore()
const runs = useRunsStore()
const auth = useAuthStore()

const slug = computed(() => String(route.params.slug || ''))
const card = computed(() => store.cards[slug.value] || null)
const fallbackTitle = computed(() => titleCase(slug.value) || 'Skill')

const stage = computed(() => card.value?.pipeline?.stage || 'human_led')
const stageInfo = computed(() => stageMeta(stage.value))
const stageOrder = PIPELINE_ORDER
const agent = computed(() => coreAgent(card.value?.core_agent))
const canManage = computed(() => auth.has('tenant.manage') || auth.isAdmin)
const currentCopy = computed(() => card.value?.pipeline?.stage_copy?.[stage.value] || {})
const gate = computed(() => card.value?.pipeline?.promotion_gate || null)
const gatePct = computed(() => {
  if (!gate.value?.clean_drafts) return 0
  return Math.min(100, Math.round(((gate.value.have ?? 0) / gate.value.clean_drafts) * 100))
})
const steps = computed(() => card.value?.one_step_further?.steps || [])

const runResult = ref(null)
const stageNotice = ref('')
const stageNoticeError = ref(false)
const confirmAutonomous = ref(false)
const pendingStage = ref(null)
const drawerOpen = ref(false)
const drawerFile = ref(null)
const brainNotice = ref('')
const brainNoticeError = ref(false)
const stepResults = reactive({})

// ── Load on slug change ──────────────────────────────────────────────
async function load() {
  runResult.value = null
  stageNotice.value = ''
  brainNotice.value = ''
  Object.keys(stepResults).forEach((k) => delete stepResults[k])
  if (!slug.value) return
  await store.fetchCard(slug.value)
  store.signal({ event: 'card.viewed', skill: slug.value })
}
watch(slug, load, { immediate: true })

// ── Run panel ────────────────────────────────────────────────────────
async function onRun({ inputs, answers }) {
  runResult.value = await store.run(slug.value, inputs, answers ? { answers } : {})
}

async function onAnswer({ run_id, answers }) {
  const res = await runs.answer(run_id, answers)
  runResult.value = res.success
    ? { success: true, data: { run_id, status: res.run?.status || 'queued', entry_path: card.value?.entry_path } }
    : { success: false, error: res.error }
}

async function onEnable() {
  const res = await store.enable(slug.value)
  if (!res.success) runResult.value = { success: false, status: res.status, error: res.error, checkout_hint: res.checkout_hint, pack_id: res.pack_id }
}

// ── Pipeline ─────────────────────────────────────────────────────────
function onStageChange(next) {
  if (next === 'autonomous') {
    pendingStage.value = next
    confirmAutonomous.value = true
    return
  }
  applyStage(next)
}

async function applyStage(next) {
  pendingStage.value = null
  const res = await store.setStage(slug.value, next)
  stageNoticeError.value = !res.success
  stageNotice.value = res.success ? `Pipeline set to ${stageMeta(next).label}.` : res.error
}

function stageBoxStyle(s) {
  const current = s === stage.value
  return {
    background: current ? `color-mix(in srgb, ${stageMeta(s).color} 12%, transparent)` : 'var(--bg-elevated)',
    border: `1px solid ${current ? stageMeta(s).color : 'var(--border)'}`,
  }
}

// ── One step further ─────────────────────────────────────────────────
const lastRun = computed(() => {
  const fromStore = store.runs[slug.value]
  const recent = card.value?.recent_runs?.[0]
  if (fromStore && (fromStore.inputs || fromStore.outputs)) {
    const base = recent && recent.id === fromStore.run_id ? recent : {}
    return { ...base, ...fromStore, id: fromStore.run_id || fromStore.id }
  }
  if (recent) return recent
  return fromStore ? { ...fromStore, id: fromStore.run_id } : null
})

function resolveInputsFrom(map, run) {
  if (!map || !run) return {}
  const ctx = { inputs: run.inputs || {}, outputs: run.outputs || {} }
  const out = {}
  for (const [key, path] of Object.entries(map)) {
    const v = getPath(ctx, path)
    if (v !== undefined) out[key] = v
  }
  return out
}

async function runStep(step) {
  const src = lastRun.value
  const inputs = resolveInputsFrom(step.inputs_from, src)
  stepResults[step.id] = await store.run(step.skill, inputs, {
    inputs_from: step.inputs_from || {},
    source_run_id: src?.id || null,
    next_step_id: step.id,
  })
}

const WHEN = {
  always: 'always',
  on_success: 'after a successful run',
  on_approval_applied: 'once the approval is applied',
}
function whenLabel(when) {
  if (!when) return 'when you say so'
  if (WHEN[when]) return WHEN[when]
  if (String(when).startsWith('on_outcome:')) return `when the outcome is ${when.split(':')[1]}`
  return String(when).replace(/_/g, ' ')
}

// ── Brain ────────────────────────────────────────────────────────────
async function openBrainFile(file) {
  const res = await brain.fetchFile(file.path)
  const f = res.file || brain.files[file.path] || {}
  drawerFile.value = { ...file, body_md: f.content ?? '', version: f.version, status: f.status || file.status }
  drawerOpen.value = true
}

async function saveBrainFile({ path, body_md }) {
  const res = await brain.saveFile(path, body_md, drawerFile.value?.version)
  brainNoticeError.value = !res.success
  if (res.success) {
    drawerOpen.value = false
    brainNotice.value = `Saved ${path} (v${res.file?.version ?? '?'}).`
    const c = card.value
    if (c?.brain?.files) {
      store.cards[slug.value] = {
        ...c,
        brain: { ...c.brain, files: c.brain.files.map((f) => (f.path === path ? { ...f, status: res.file?.status || 'filled' } : f)) },
      }
    }
  } else if (res.conflict) {
    brainNotice.value = `${res.error} Reopen the file to load it.`
  } else {
    brainNotice.value = res.error
  }
}

function askAtlas(file) {
  const prefill = file.ask_prompt || `Help me fill in "${file.title || file.path}" (${file.path}) for my business brain. Ask me what you need, then draft it.`
  router.push({ path: '/atlas', query: { hannah: '1', prefill } })
}

// ── Hands off ────────────────────────────────────────────────────────
function handoffPath(h) {
  if (h.type === 'skill' && h.slug) return `/skills/${h.slug}`
  if (h.type === 'agent' && h.slug) return `/agents/${h.slug}/workspace`
  return null
}
function slugify(s) {
  return String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
}
</script>

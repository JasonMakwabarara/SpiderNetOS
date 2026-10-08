<template>
  <component
    :is="shell"
    :model-value="isOpen"
    :title="node?.label || 'Node'"
    :eyebrow="eyebrow"
    width="420px"
    @update:model-value="(v) => { if (!v) emit('close') }"
  >
    <div v-if="node" data-testid="map-node-drawer" :data-mode="isWide ? 'inline' : 'modal'" :data-node-id="node.id">
      <!-- Status + owner line -->
      <div class="flex items-center gap-2 flex-wrap mb-3" data-testid="map-drawer-meta">
        <span class="inline-flex items-center gap-1.5 text-xs" style="color: var(--text-secondary);">
          <span class="inline-block size-2 rounded-full" :style="{ background: statusInfo.color }" aria-hidden="true" />
          <span data-testid="map-drawer-status">{{ statusInfo.label }}</span>
          <span style="color: var(--text-muted);">— {{ statusInfo.hint }}</span>
        </span>
        <span class="sn-pill text-[10px]" data-testid="map-drawer-owner">Owner: {{ ownerText }}</span>
      </div>
      <p v-if="node.description" class="text-sm mb-3" style="color: var(--text-secondary);">{{ node.description }}</p>

      <p
        v-if="error"
        class="text-xs mb-3 rounded-lg px-3 py-2"
        style="color: var(--amber, var(--warn)); background: rgba(245,165,36,0.08); border: 1px solid rgba(245,165,36,0.25);"
        role="status"
        data-testid="map-drawer-error"
      >{{ error }}</p>

      <!-- Tabs -->
      <div
        class="flex items-center gap-1 mb-4 border-b"
        style="border-color: var(--border);"
        role="tablist"
        aria-label="Node details"
        @keydown="onTabKeydown"
      >
        <button
          v-for="tab in TABS"
          :id="tabId(tab.key)"
          :key="tab.key"
          type="button"
          role="tab"
          class="px-3 py-2 text-xs font-medium -mb-px border-b-2 transition-colors"
          :style="{
            color: activeTab === tab.key ? 'var(--text-primary)' : 'var(--text-muted)',
            borderColor: activeTab === tab.key ? 'var(--status-live)' : 'transparent',
          }"
          :aria-selected="activeTab === tab.key ? 'true' : 'false'"
          :aria-controls="panelId(tab.key)"
          :tabindex="activeTab === tab.key ? 0 : -1"
          :data-testid="`map-drawer-tab-${tab.key}`"
          @click="activeTab = tab.key"
        >
          {{ tab.label }}
          <span class="ml-1 mono" style="color: var(--text-muted);">{{ tabCount(tab.key) }}</span>
        </button>
      </div>

      <p v-if="loading && !node.detailLoaded" class="text-xs mb-3" style="color: var(--text-muted);" data-testid="map-drawer-loading">
        Loading details…
      </p>

      <!-- Skills -->
      <section
        v-show="activeTab === 'skills'"
        :id="panelId('skills')"
        role="tabpanel"
        :aria-labelledby="tabId('skills')"
        class="space-y-3"
        data-testid="map-drawer-panel-skills"
      >
        <p v-if="!skillCards.length" class="text-xs" style="color: var(--text-muted);" data-testid="map-drawer-skills-empty">
          No skills cover this node yet — ask Atlas what could.
        </p>
        <div v-for="skill in skillCards" :key="skill.slug" class="space-y-1.5">
          <SkillMiniCard
            :skill="skill"
            :busy="enabling === skill.slug"
            :notice="notices[skill.slug]?.text || ''"
            :notice-error="!!notices[skill.slug]?.error"
            @enable="onEnable"
          />
          <div class="flex items-center justify-end gap-2">
            <RouterLink
              v-if="notices[skill.slug]?.runId"
              :to="`/agents/runs/${notices[skill.slug].runId}`"
              class="text-[11px] underline"
              style="color: var(--accent);"
              :data-testid="`map-drawer-run-link-${skill.slug}`"
            >View run</RouterLink>
            <button
              type="button"
              class="sn-btn text-xs"
              style="padding: 0.3rem 0.7rem;"
              :disabled="runningSlug === skill.slug || !skill.enabled"
              :title="skill.enabled ? `Run ${skill.name} now` : 'Enable this skill first'"
              :data-testid="`map-drawer-run-${skill.slug}`"
              @click="onRun(skill)"
            >{{ runningSlug === skill.slug ? 'Starting…' : 'Run' }}</button>
          </div>
        </div>
      </section>

      <!-- Brain -->
      <section
        v-show="activeTab === 'brain'"
        :id="panelId('brain')"
        role="tabpanel"
        :aria-labelledby="tabId('brain')"
        data-testid="map-drawer-panel-brain"
      >
        <BrainReadiness :files="brainFiles" title="What this node reads" @open="openBrainFile" @ask="askAtlasAboutFile" />
        <p
          v-if="brainNotice"
          class="text-xs mt-2"
          :style="{ color: brainNoticeError ? 'var(--danger)' : 'var(--accent)' }"
          data-testid="map-drawer-brain-notice"
        >{{ brainNotice }}</p>
      </section>

      <!-- Processes -->
      <section
        v-show="activeTab === 'processes'"
        :id="panelId('processes')"
        role="tabpanel"
        :aria-labelledby="tabId('processes')"
        data-testid="map-drawer-panel-processes"
      >
        <ProcessList
          :processes="processes"
          :busy-process="systemization.busyProcess"
          @automate="onAutomate"
          @run="onRunProcess"
          @escalate="openEscalation"
        />
        <p
          v-if="processNotice"
          class="text-xs mt-2"
          :style="{ color: processNoticeError ? 'var(--danger)' : 'var(--accent)' }"
          data-testid="map-drawer-process-notice"
        >{{ processNotice }}</p>
        <RouterLink
          to="/operate/systems"
          class="inline-block text-[11px] underline mt-3"
          style="color: var(--text-secondary);"
          data-testid="map-drawer-systems-link"
        >All processes in Systems →</RouterLink>
      </section>

      <!-- Runs -->
      <section
        v-show="activeTab === 'runs'"
        :id="panelId('runs')"
        role="tabpanel"
        :aria-labelledby="tabId('runs')"
        data-testid="map-drawer-panel-runs"
      >
        <p v-if="node.run_stats" class="text-xs mb-3" style="color: var(--text-secondary);" data-testid="map-drawer-run-stats">
          {{ node.run_stats.count_7d ?? 0 }} run{{ node.run_stats.count_7d === 1 ? '' : 's' }} in 7 days<span v-if="node.run_stats.last_at"> · last {{ timeAgo(node.run_stats.last_at) }}</span>
        </p>
        <p v-if="!runs.length" class="text-xs" style="color: var(--text-muted);" data-testid="map-drawer-runs-empty">No runs yet.</p>
        <ul v-else class="space-y-1.5">
          <li v-for="run in runs" :key="run.id" :data-testid="`map-drawer-run-row-${run.id}`">
            <RouterLink
              :to="`/agents/runs/${run.id}`"
              class="flex items-center justify-between gap-2 rounded-lg px-3 py-2 text-sm hover:underline"
              style="background: var(--bg-elevated); border: 1px solid var(--border); color: var(--text-primary);"
            >
              <span class="flex items-center gap-2 min-w-0">
                <span class="inline-block size-1.5 rounded-full shrink-0" :style="runStatusDot(run.status)" aria-hidden="true" />
                <span class="truncate">{{ run.skill_name || run.skill_slug }}</span>
              </span>
              <span class="flex items-center gap-2 shrink-0">
                <span class="sn-pill text-[10px]" :class="runStatusPill(run.status)">{{ run.status }}</span>
                <span class="text-[11px]" style="color: var(--text-muted);">{{ timeAgo(run.started_at) }}</span>
              </span>
            </RouterLink>
          </li>
        </ul>
      </section>
    </div>

    <template #footer>
      <div v-if="node" class="flex items-center justify-between gap-2">
        <RouterLink
          :to="improveLink"
          class="sn-btn-secondary text-xs"
          style="padding: 0.4rem 0.75rem; color: var(--accent); border-color: rgba(0,214,201,0.30);"
          data-testid="map-drawer-improve"
        >Improve with Atlas</RouterLink>
        <RouterLink
          v-if="primarySkill"
          :to="`/skills/${primarySkill.slug}`"
          class="sn-btn text-xs"
          style="padding: 0.4rem 0.75rem;"
          data-testid="map-drawer-open-card"
        >Open full card</RouterLink>
      </div>
    </template>
  </component>

  <BrainFileDrawer v-model="brainDrawerOpen" :file="brainDrawerFile" :saving="brain.saving" @save="saveBrainFile" />

  <PromptDialog
    v-model="escalationOpen"
    :title="escalating ? `“${escalating.name}” is stuck` : 'Stuck process'"
    message="Its owner is stuck. What's the answer? It resolves the escalation and becomes the next SOP revision."
    input-label="Your answer"
    placeholder="Explain what to do when this happens…"
    confirm-label="Resolve & update SOP"
    @confirm="submitEscalation"
    @cancel="escalating = null"
  />
</template>

<script setup>
/**
 * MapNodeDrawer — everything about one business-map node (plan D6-C).
 * Inline `<aside class="sn-drawer w-[380px]">` beside the map on ≥lg
 * screens, a Teleported modal SideDrawer below that. Tabs:
 *
 *   Skills     SkillMiniCard per covering skill + inline Run (skills store)
 *   Brain      BrainReadiness → BrainFileDrawer (brain store, read/save)
 *   Processes  ProcessList, actions via the map store → systemization store
 *   Runs       the node's latest runs → /agents/runs/:id
 *
 * Footer: "Improve with Atlas" (Hannah-guided prefill) and "Open full card".
 */
import { computed, getCurrentInstance, onBeforeUnmount, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import SideDrawer from '../data/SideDrawer.vue'
import MapDrawerInline from './MapDrawerInline.vue'
import ProcessList from './ProcessList.vue'
import SkillMiniCard from '../skills/SkillMiniCard.vue'
import BrainReadiness from '../brain/BrainReadiness.vue'
import BrainFileDrawer from '../brain/BrainFileDrawer.vue'
import PromptDialog from '../feedback/PromptDialog.vue'
import { useSkillsStore } from '../../stores/skills.js'
import { useBrainStore } from '../../stores/brain.js'
import { useMapStore } from '../../stores/map.js'
import { useSystemizationStore } from '../../stores/systemization.js'
import { mapStatusMeta, ownerLabel } from '../../utils/mapStatus.js'
import { runStatusDot, runStatusPill, timeAgo, titleCase } from '../../utils/format.js'

defineOptions({ inheritAttrs: false })

const props = defineProps({
  node:    { type: Object, default: null },
  open:    { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  error:   { type: String, default: '' },
  /** Force inline (true) or modal (false); null = follow the lg breakpoint. */
  wide:    { type: Boolean, default: null },
})

const emit = defineEmits(['close'])

const TABS = [
  { key: 'skills',    label: 'Skills' },
  { key: 'brain',     label: 'Brain' },
  { key: 'processes', label: 'Processes' },
  { key: 'runs',      label: 'Runs' },
]

// useRouter() is undefined when mounted outside a router (unit tests).
const router = useRouter()
const skills = useSkillsStore()
const brain = useBrainStore()
const map = useMapStore()
const systemization = useSystemizationStore()

const uid = Math.random().toString(36).slice(2, 8)
const tabId = (key) => `map-drawer-${uid}-tab-${key}`
const panelId = (key) => `map-drawer-${uid}-panel-${key}`

// ── Breakpoint ───────────────────────────────────────────────────────
const LG_QUERY = '(min-width: 1024px)'
const mediaWide = ref(true)
let mq = null
const onMq = (e) => { mediaWide.value = !!e.matches }
try {
  if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
    mq = window.matchMedia(LG_QUERY)
    mediaWide.value = !!mq.matches
    mq.addEventListener?.('change', onMq)
  }
} catch { mq = null }
if (getCurrentInstance()) onBeforeUnmount(() => { try { mq?.removeEventListener?.('change', onMq) } catch { /* noop */ } })

const isWide = computed(() => (props.wide == null ? mediaWide.value : props.wide))
const shell = computed(() => (isWide.value ? MapDrawerInline : SideDrawer))
const isOpen = computed(() => props.open && !!props.node)

// ── Header bits ──────────────────────────────────────────────────────
const statusInfo = computed(() => mapStatusMeta(props.node?.status))
const eyebrow = computed(() => {
  const pillar = props.node?.pillar?.label
  return pillar ? `Business map · ${pillar}` : 'Business map'
})
const ownerText = computed(() => ownerLabel(props.node?.owner_type))

const activeTab = ref('skills')

function onTabKeydown(event) {
  if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return
  event.preventDefault()
  const i = TABS.findIndex((t) => t.key === activeTab.value)
  const next = TABS[(i + (event.key === 'ArrowRight' ? 1 : -1) + TABS.length) % TABS.length]
  activeTab.value = next.key
  document.getElementById(tabId(next.key))?.focus?.()
}

function tabCount(key) {
  if (key === 'skills') return skillCards.value.length
  if (key === 'brain') return brainFiles.value.length
  if (key === 'processes') return processes.value.length || props.node?.process_count || 0
  if (key === 'runs') return runs.value.length
  return ''
}

// ── Skills ───────────────────────────────────────────────────────────
function brainFromNode(node) {
  const missing = (node?.brain_files || []).filter((f) => f.status === 'missing').map((f) => f.path)
  return { ready: missing.length === 0, missing }
}

const skillCards = computed(() => {
  const node = props.node
  if (!node) return []
  return (node.skills || []).map((s) => {
    const summary = skills.summaryOf(s.slug) || {}
    const enabled = s.enabled ?? summary.enabled ?? false
    return {
      ...summary,
      slug: s.slug,
      name: s.name || summary.name || titleCase(s.slug),
      one_liner: summary.one_liner || '',
      enabled,
      provisioning: summary.provisioning || (enabled ? 'installed' : 'on_demand'),
      pipeline: { ...(summary.pipeline || {}), stage: s.stage || summary.pipeline?.stage || 'human_led' },
      node: { id: node.id, label: node.label },
      brain: summary.brain || brainFromNode(node),
    }
  })
})
const primarySkill = computed(() => skillCards.value[0] || null)

const notices = reactive({})
const runningSlug = ref(null)
const enabling = ref(null)

async function onRun(skill) {
  runningSlug.value = skill.slug
  try {
    const res = await skills.run(skill.slug, {})
    if (res.success) {
      notices[skill.slug] = { text: `Run started${res.data?.status ? ` (${res.data.status})` : ''}.`, runId: res.data?.run_id || null }
    } else if (res.missing_brain?.length) {
      notices[skill.slug] = { error: true, text: `Atlas needs ${res.missing_brain.map((m) => m.path).join(', ')} first — see the Brain tab.` }
      activeTab.value = 'brain'
    } else {
      notices[skill.slug] = { error: true, text: res.error || 'Run failed.' }
    }
  } finally {
    runningSlug.value = null
  }
}

async function onEnable(skill) {
  enabling.value = skill.slug
  try {
    const res = await skills.enable(skill.slug)
    notices[skill.slug] = res.success
      ? { text: 'Enabled — it can run now.' }
      : { error: true, text: res.error || 'Could not enable.' }
  } finally {
    enabling.value = null
  }
}

// ── Brain ────────────────────────────────────────────────────────────
const brainFiles = computed(() =>
  (props.node?.brain_files || []).map((f) => ({
    ...f,
    title: f.title || f.path,
    status: f.status || 'missing',
  })),
)

const brainDrawerOpen = ref(false)
const brainDrawerFile = ref(null)
const brainNotice = ref('')
const brainNoticeError = ref(false)

async function openBrainFile(file) {
  const res = await brain.fetchFile(file.path)
  const f = res.file || brain.files[file.path] || {}
  brainDrawerFile.value = { ...file, body_md: f.content ?? '', version: f.version, status: f.status || file.status }
  brainDrawerOpen.value = true
}

async function saveBrainFile({ path, body_md }) {
  const res = await brain.saveFile(path, body_md, brainDrawerFile.value?.version)
  brainNoticeError.value = !res.success
  if (res.success) {
    brainDrawerOpen.value = false
    brainNotice.value = `Saved ${path} (v${res.file?.version ?? '?'}).`
    if (props.node?.id != null) map.fetchNodeDetail(props.node.id, { force: true })
  } else {
    brainNotice.value = res.conflict ? `${res.error} Reopen the file to load it.` : res.error
  }
}

function askAtlasAboutFile(file) {
  const prefill = file.ask_prompt || `Help me fill in "${file.title || file.path}" (${file.path}) for my business brain. Ask me what you need, then draft it.`
  router?.push({ path: '/atlas', query: { hannah: '1', prefill } })
}

// ── Processes ────────────────────────────────────────────────────────
const processes = computed(() => (Array.isArray(props.node?.processes) ? props.node.processes : []))
const processNotice = ref('')
const processNoticeError = ref(false)
const escalationOpen = ref(false)
const escalating = ref(null)

function report(res, ok) {
  processNoticeError.value = !res?.success
  processNotice.value = res?.success ? ok : res?.error || 'That did not work.'
}

async function onAutomate(p) {
  report(await map.automateProcess(p.id), `Compiled a runbook for “${p.name}”.`)
}

async function onRunProcess(p) {
  report(await map.runProcess(p.id), `Started “${p.name}”.`)
}

function openEscalation(p) {
  escalating.value = p
  escalationOpen.value = true
}

async function submitEscalation(answer) {
  const p = escalating.value
  escalating.value = null
  if (!p || !answer) return
  report(await map.resolveProcess(p.id, answer), `Resolved “${p.name}” and updated its SOP.`)
}

// ── Runs ─────────────────────────────────────────────────────────────
const runs = computed(() => (Array.isArray(props.node?.runs) ? props.node.runs.slice(0, 8) : []))

// ── Footer ───────────────────────────────────────────────────────────
const improveLink = computed(() => {
  const n = props.node || {}
  const info = mapStatusMeta(n.status)
  const prefill = `Help me improve "${n.label}" on my business map. It is ${info.label.toLowerCase()} (${info.hint.toLowerCase()}) and owned by ${ownerLabel(n.owner_type)}. What is the one change that would make it run better, and what should I fill in first?`
  return { path: '/atlas', query: { hannah: '1', prefill } }
})
</script>

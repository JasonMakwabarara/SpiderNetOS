/**
 * Agent runs Pinia store — the run feed, one run's detail (steps,
 * questions, artifacts, next_steps), artifact edits and agent workspaces.
 *
 * Endpoints (PR 1 contract):
 *   GET   /api/agent-runs?status=&skill=       → { data: [RunSummary], meta? }
 *   GET   /api/agent-runs/{id}                 → { data: Run }
 *   POST  /api/agent-runs/{id}/answers {answers:[{path,section,text}]}
 *   POST  /api/agent-runs/{id}/cancel | /retry
 *   GET   /api/artifacts/{id}                  → { data: Artifact }
 *   PATCH /api/artifacts/{id} {content}
 *   GET   /api/agent-workspaces/{slug}         → { data: Workspace }
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../services/api.js'
import { describeError, fallbackNotice, clone, cleanParams } from '../utils/apiFallback.js'
import runFixture from '../../tests/fixtures/agent_run.json'

export const RUN_STATUSES = ['queued', 'running', 'blocked', 'waiting_approval', 'succeeded', 'failed', 'cancelled']
export const ACTIVE_STATUSES = ['queued', 'running', 'blocked', 'waiting_approval']

export const useRunsStore = defineStore('runs', () => {
  // ── State ──────────────────────────────────────────────────────────
  const runs = ref([])
  const meta = ref({ statuses: RUN_STATUSES })
  /** id → full run */
  const details = ref({})
  /** id → artifact (with content) */
  const artifacts = ref({})
  /** slug → workspace */
  const workspaces = ref({})
  const filters = ref({ status: '', skill: '' })

  const loading = ref(false)
  const detailLoading = ref(false)
  const busy = ref(false)
  const error = ref(null)
  const detailError = ref(null)
  const fallback = ref(false)

  // ── Getters ────────────────────────────────────────────────────────
  const filtered = computed(() =>
    runs.value.filter((r) => {
      if (filters.value.status && r.status !== filters.value.status) return false
      if (filters.value.skill && r.skill_slug !== filters.value.skill) return false
      return true
    }),
  )
  const activeCount = computed(() => runs.value.filter((r) => ACTIVE_STATUSES.includes(r.status)).length)
  const blockedCount = computed(() => runs.value.filter((r) => r.status === 'blocked').length)
  const totalCost = computed(() => runs.value.reduce((s, r) => s + (Number(r.cost_usd) || 0), 0))
  const skillsSeen = computed(() => {
    const seen = new Map()
    for (const r of runs.value) if (r.skill_slug && !seen.has(r.skill_slug)) seen.set(r.skill_slug, r.skill_name || r.skill_slug)
    return [...seen.entries()].map(([slug, name]) => ({ slug, name }))
  })

  // ── Helpers ────────────────────────────────────────────────────────
  function patchListEntry(id, patch) {
    const i = runs.value.findIndex((r) => r.id === id)
    if (i !== -1) runs.value[i] = { ...runs.value[i], ...patch }
    else if (patch.skill_slug || patch.status) runs.value.unshift({ id, ...patch })
  }

  function patchDetail(id, patch) {
    if (details.value[id]) details.value = { ...details.value, [id]: { ...details.value[id], ...patch } }
  }

  function fallbackRun(id) {
    if (id === runFixture.blocked.data.id) return clone(runFixture.blocked.data)
    if (id === runFixture.succeeded.data.id) return clone(runFixture.succeeded.data)
    const listed = runFixture.list.data.find((r) => r.id === id)
    const base = clone(listed?.status === 'blocked' ? runFixture.blocked.data : runFixture.succeeded.data)
    return { ...base, ...(listed || {}), id }
  }

  // ── Actions ────────────────────────────────────────────────────────
  async function fetchRuns(next) {
    if (next) filters.value = { status: '', skill: '', ...cleanParams(next) }
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get('/api/agent-runs', { params: cleanParams(filters.value) })
      runs.value = data?.data || []
      if (data?.meta) meta.value = { ...meta.value, ...data.meta }
      fallback.value = false
      return { success: true }
    } catch (err) {
      error.value = fallbackNotice(err, 'agent runs')
      fallback.value = true
      runs.value = clone(runFixture.list.data)
      meta.value = clone(runFixture.list.meta)
      return { success: false, error: error.value }
    } finally {
      loading.value = false
    }
  }

  async function fetchRun(id) {
    if (!id) return { success: false, error: 'No run id' }
    detailLoading.value = true
    detailError.value = null
    try {
      const { data } = await api.get(`/api/agent-runs/${encodeURIComponent(id)}`)
      const run = data?.data || data
      details.value = { ...details.value, [id]: run }
      patchListEntry(id, summaryOf(run))
      return { success: true, run }
    } catch (err) {
      detailError.value = fallbackNotice(err, `run ${id}`)
      const run = fallbackRun(id)
      details.value = { ...details.value, [id]: run }
      return { success: false, error: detailError.value, run }
    } finally {
      detailLoading.value = false
    }
  }

  function summaryOf(run) {
    const { id, skill_slug, skill_name, agent, status, trigger_type, cost_usd, started_at, finished_at, error: e } = run || {}
    return cleanParams({ id, skill_slug, skill_name, agent, status, trigger_type, cost_usd, started_at, finished_at, error: e })
  }

  async function answer(id, answers) {
    busy.value = true
    try {
      const { data } = await api.post(`/api/agent-runs/${encodeURIComponent(id)}/answers`, { answers })
      const run = data?.data
      if (run) {
        details.value = { ...details.value, [id]: { ...(details.value[id] || {}), ...run } }
        patchListEntry(id, summaryOf(run))
      } else {
        patchDetail(id, { status: 'queued', questions: [] })
        patchListEntry(id, { status: 'queued' })
      }
      return { success: true, run: details.value[id] }
    } catch (err) {
      return { success: false, error: describeError(err, 'Could not send your answers') }
    } finally {
      busy.value = false
    }
  }

  async function cancel(id) {
    busy.value = true
    try {
      const { data } = await api.post(`/api/agent-runs/${encodeURIComponent(id)}/cancel`)
      const patch = data?.data || { status: 'cancelled', finished_at: new Date().toISOString() }
      patchDetail(id, patch)
      patchListEntry(id, patch)
      return { success: true }
    } catch (err) {
      return { success: false, error: describeError(err, 'Could not cancel the run') }
    } finally {
      busy.value = false
    }
  }

  async function retry(id) {
    busy.value = true
    try {
      const { data } = await api.post(`/api/agent-runs/${encodeURIComponent(id)}/retry`)
      const run = data?.data
      if (run?.id && run.id !== id) {
        details.value = { ...details.value, [run.id]: run }
        runs.value.unshift(summaryOf(run))
        return { success: true, run, run_id: run.id }
      }
      const patch = run || { status: 'queued', error: null, finished_at: null }
      patchDetail(id, patch)
      patchListEntry(id, patch)
      return { success: true, run: details.value[id], run_id: id }
    } catch (err) {
      return { success: false, error: describeError(err, 'Could not retry the run') }
    } finally {
      busy.value = false
    }
  }

  async function fetchArtifact(id) {
    try {
      const { data } = await api.get(`/api/artifacts/${encodeURIComponent(id)}`)
      const art = data?.data || data
      artifacts.value = { ...artifacts.value, [id]: art }
      return { success: true, artifact: art }
    } catch (err) {
      const art = { ...clone(runFixture.artifact.data), id }
      artifacts.value = { ...artifacts.value, [id]: art }
      return { success: false, error: describeError(err, 'Could not load the artifact'), artifact: art }
    }
  }

  async function patchArtifact(id, content) {
    busy.value = true
    try {
      const { data } = await api.patch(`/api/artifacts/${encodeURIComponent(id)}`, { content })
      const art = { ...(artifacts.value[id] || {}), ...(data?.data || {}), id, content: data?.data?.content ?? content }
      artifacts.value = { ...artifacts.value, [id]: art }
      return { success: true, artifact: art }
    } catch (err) {
      return { success: false, error: describeError(err, 'Could not save the artifact') }
    } finally {
      busy.value = false
    }
  }

  async function fetchWorkspace(slug) {
    if (!slug) return { success: false, error: 'No agent slug' }
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get(`/api/agent-workspaces/${encodeURIComponent(slug)}`)
      const ws = data?.data || data
      workspaces.value = { ...workspaces.value, [slug]: ws }
      return { success: true, workspace: ws }
    } catch (err) {
      error.value = fallbackNotice(err, `the ${slug} workspace`)
      const ws = { ...clone(runFixture.workspace.data), slug, agent: { ...runFixture.workspace.data.agent, slug, name: titleCase(slug) } }
      workspaces.value = { ...workspaces.value, [slug]: ws }
      return { success: false, error: error.value, workspace: ws }
    } finally {
      loading.value = false
    }
  }

  function titleCase(slug) {
    return String(slug || '').replace(/[-_]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
  }

  // ── Real-time handlers ─────────────────────────────────────────────
  function handleRunUpdated(data) {
    const id = data?.id || data?.run_id
    if (!id) return
    const { run_id: _r, ...patch } = data
    patchListEntry(id, { ...summaryOf({ id, ...patch }), ...cleanParams({ status: patch.status }) })
    patchDetail(id, { ...patch, id })
    for (const [slug, ws] of Object.entries(workspaces.value)) {
      if (!Array.isArray(ws.recent_runs)) continue
      const i = ws.recent_runs.findIndex((r) => r.id === id)
      if (i === -1) continue
      const recent = [...ws.recent_runs]
      recent[i] = { ...recent[i], ...patch, id }
      workspaces.value = { ...workspaces.value, [slug]: { ...ws, recent_runs: recent } }
    }
  }

  function handleToolInvoked(data) {
    const id = data?.run_id
    const detail = id && details.value[id]
    if (!detail || !data?.step) return
    const steps = [...(detail.steps || [])]
    const i = steps.findIndex((s) => s.seq === data.step.seq)
    if (i === -1) steps.push(data.step); else steps[i] = { ...steps[i], ...data.step }
    patchDetail(id, { steps })
  }

  return {
    // state
    runs, meta, details, artifacts, workspaces, filters,
    loading, detailLoading, busy, error, detailError, fallback,
    // getters
    filtered, activeCount, blockedCount, totalCost, skillsSeen,
    // actions
    fetchRuns, fetchRun, answer, cancel, retry, fetchArtifact, patchArtifact, fetchWorkspace,
    // realtime
    handleRunUpdated, handleToolInvoked,
  }
})

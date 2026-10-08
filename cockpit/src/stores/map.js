/**
 * Business map Pinia store (plan D6-C) — the brain at the core, pillars on
 * ring 1, nodes on ring 2, and the per-node detail the drawer shows.
 *
 * Endpoints (PR 4 contract, Stream E):
 *   GET /api/map             → { data: { core: { name, three_brains, owner }, pillars: [{ key, label, order, status, nodes: [MapNode] }] } }
 *   GET /api/map/nodes/{id}  → { data: MapNode & { processes[], runs[], brain_files[] } }
 *
 *   MapNode = { id, label, status: live|assisted|human|missing, owner_type,
 *               skills: [{ slug, name, enabled, stage }], brain_files: [{ path, status }],
 *               builds_on: [nodeId], runs: { last_at, last_status, count_7d }, process_count }
 *
 * GETs fall back to the shipped fixtures with an inline error, exactly like
 * the PR 1 stores. `selectedNodeId` mirrors the view's `?node=` query (the
 * view owns the router; the store owns the state). Process actions go
 * through the systemization store so the Systems list view and the map
 * drawer never disagree, then the node detail is refreshed.
 */
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '../services/api.js'
import { describeError, fallbackNotice, clone } from '../utils/apiFallback.js'
import { sortPillars } from '../utils/radialLayout.js'
import { useSystemizationStore } from './systemization.js'
import mapFixture from '../../tests/fixtures/map.json'
import nodeFixture from '../../tests/fixtures/map_node.json'

export const MAP_STATUSES = ['live', 'assisted', 'human', 'missing']

export const MAP_FILTERS = [
  { key: 'all',           label: 'All' },
  { key: 'needs_you',     label: 'Needs you' },
  { key: 'automated',     label: 'Automated' },
  { key: 'missing_brain', label: 'Missing brain' },
]

const FILTER_KEYS = MAP_FILTERS.map((f) => f.key)

/** Does this node match a filter chip? Exported for the view + tests. */
export function nodeMatchesFilter(node, filter) {
  switch (filter) {
    case 'needs_you':
      return node?.status === 'human' || node?.owner_type === 'founder'
    case 'automated':
      return node?.status === 'live'
    case 'missing_brain':
      return (node?.brain_files || []).some((f) => f?.status === 'missing')
    default:
      return true
  }
}

export function nodeMatchesQuery(node, q) {
  const needle = String(q || '').trim().toLowerCase()
  if (!needle) return true
  const hay = [node?.label, node?.id, ...(node?.skills || []).flatMap((s) => [s?.name, s?.slug])]
    .filter(Boolean)
    .join(' ')
    .toLowerCase()
  return hay.includes(needle)
}

export const useMapStore = defineStore('map', () => {
  // ── State ──────────────────────────────────────────────────────────
  const core = ref({ name: '', three_brains: {}, owner: {} })
  const pillars = ref([])
  /** node id → detail ({ …node, processes, runs, brain_files }) */
  const nodeDetails = ref({})
  const selectedNodeId = ref(null)
  const filter = ref('all')
  const query = ref('')

  const loading = ref(false)
  const detailLoading = ref(false)
  const error = ref(null)
  const detailError = ref(null)
  const fallback = ref(false)

  // ── Getters ────────────────────────────────────────────────────────
  const orderedPillars = computed(() => sortPillars(pillars.value))

  const nodes = computed(() =>
    orderedPillars.value.flatMap((p) => (p.nodes || []).map((n) => ({ ...n, pillar: { key: p.key, label: p.label } }))),
  )

  const nodeById = computed(() => Object.fromEntries(nodes.value.map((n) => [String(n.id), n])))

  /** Pillars with only the nodes that pass the filter chip + search. */
  const filteredPillars = computed(() =>
    orderedPillars.value.map((p) => ({
      ...p,
      nodes: (p.nodes || []).filter((n) => nodeMatchesFilter(n, filter.value) && nodeMatchesQuery(n, query.value)),
    })),
  )

  const visibleNodeIds = computed(() => new Set(filteredPillars.value.flatMap((p) => p.nodes.map((n) => String(n.id)))))

  /** The shape radialLayout() wants. */
  const layoutInput = computed(() => ({ core: core.value, pillars: filteredPillars.value }))

  const counts = computed(() => {
    const all = nodes.value
    const byStatus = Object.fromEntries(MAP_STATUSES.map((s) => [s, all.filter((n) => n.status === s).length]))
    return {
      total: all.length,
      ...byStatus,
      filters: Object.fromEntries(FILTER_KEYS.map((k) => [k, all.filter((n) => nodeMatchesFilter(n, k)).length])),
    }
  })

  const selectedNode = computed(() => {
    const id = selectedNodeId.value
    if (id == null) return null
    const summary = nodeById.value[String(id)] || null
    const detail = nodeDetails.value[String(id)] || null
    if (!summary && !detail) return null
    // The summary carries `runs` as stats ({ last_at, last_status, count_7d });
    // the detail carries `runs` as a list. Keep both.
    const stats = summary?.runs && !Array.isArray(summary.runs) ? summary.runs : detail?.run_stats || null
    return {
      ...(summary || {}),
      ...(detail || {}),
      pillar: detail?.pillar || summary?.pillar || null,
      runs: Array.isArray(detail?.runs) ? detail.runs : [],
      run_stats: stats,
      detailLoaded: !!detail,
    }
  })

  // ── Helpers ────────────────────────────────────────────────────────
  function setFilter(next) {
    filter.value = FILTER_KEYS.includes(next) ? next : 'all'
  }

  function setQuery(next) {
    query.value = String(next ?? '')
  }

  function patchNode(id, patch) {
    const key = String(id)
    let hit = false
    pillars.value = pillars.value.map((p) => {
      const i = (p.nodes || []).findIndex((n) => String(n.id) === key)
      if (i === -1) return p
      hit = true
      const next = [...p.nodes]
      next[i] = { ...next[i], ...patch }
      return { ...p, nodes: next }
    })
    return hit
  }

  /** Fixture detail re-keyed to the requested node when the map knows it. */
  function fallbackDetail(id) {
    const base = clone(nodeFixture.data)
    const key = String(id)
    if (key === String(base.id)) return base
    const summary = nodeById.value[key]
    if (!summary) return { ...base, id: key }
    return {
      ...base,
      ...clone(summary),
      id: key,
      brain_files: clone(summary.brain_files || []),
      processes: (base.processes || []).map((p, i) => ({ ...p, id: `${key}:proc_${i + 1}` })),
      runs: (base.runs || []).map((r) => ({
        ...r,
        skill_slug: summary.skills?.[0]?.slug || r.skill_slug,
        skill_name: summary.skills?.[0]?.name || r.skill_name,
      })),
    }
  }

  // ── Actions ────────────────────────────────────────────────────────
  async function fetchMap() {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get('/api/map')
      const payload = data?.data || {}
      core.value = payload.core || { name: '', three_brains: {}, owner: {} }
      pillars.value = payload.pillars || []
      fallback.value = false
      return { success: true }
    } catch (err) {
      error.value = fallbackNotice(err, 'the business map')
      fallback.value = true
      const payload = clone(mapFixture.data)
      core.value = payload.core
      pillars.value = payload.pillars
      return { success: false, error: error.value }
    } finally {
      loading.value = false
    }
  }

  async function fetchNodeDetail(id, { force = false } = {}) {
    if (id == null || id === '') return { success: false, error: 'No node id' }
    const key = String(id)
    if (!force && nodeDetails.value[key]) return { success: true, node: nodeDetails.value[key], cached: true }
    detailLoading.value = true
    detailError.value = null
    try {
      const { data } = await api.get(`/api/map/nodes/${encodeURIComponent(key)}`)
      const node = data?.data || data
      nodeDetails.value = { ...nodeDetails.value, [key]: node }
      return { success: true, node }
    } catch (err) {
      detailError.value = fallbackNotice(err, 'this node')
      const node = fallbackDetail(key)
      nodeDetails.value = { ...nodeDetails.value, [key]: node }
      return { success: false, error: detailError.value, node }
    } finally {
      detailLoading.value = false
    }
  }

  /** Select a node (or clear with null) and load its detail. */
  async function select(id) {
    if (id == null || id === '') {
      selectedNodeId.value = null
      return { success: true }
    }
    const key = String(id)
    selectedNodeId.value = key
    return fetchNodeDetail(key)
  }

  function clearSelection() {
    selectedNodeId.value = null
  }

  // ── Process actions (delegated to the systemization store) ─────────
  async function afterProcessAction(result) {
    if (result?.success && selectedNodeId.value) {
      await fetchNodeDetail(selectedNodeId.value, { force: true })
    }
    return result
  }

  async function automateProcess(processId, schedule) {
    const systemization = useSystemizationStore()
    return afterProcessAction(await systemization.automate(processId, schedule))
  }

  async function runProcess(processId) {
    const systemization = useSystemizationStore()
    return afterProcessAction(await systemization.runNow(processId))
  }

  async function resolveProcess(processId, answer) {
    const systemization = useSystemizationStore()
    return afterProcessAction(await systemization.resolveEscalation(processId, answer))
  }

  // ── Real-time handler (.map.node.updated) ──────────────────────────
  function handleNodeUpdated(data) {
    const id = data?.id ?? data?.node_id
    if (id == null) return
    const key = String(id)
    const { node_id: _n, runs, ...rest } = data
    rest.id = nodeById.value[key]?.id ?? id
    // The map summary carries run *stats*; the detail carries a run *list*.
    const isList = Array.isArray(runs)
    const hasStats = !isList && runs && typeof runs === 'object'
    patchNode(key, hasStats ? { ...rest, runs } : rest)
    if (nodeDetails.value[key]) {
      const detailPatch = { ...rest }
      if (isList) detailPatch.runs = runs
      if (hasStats) detailPatch.run_stats = runs
      nodeDetails.value = { ...nodeDetails.value, [key]: { ...nodeDetails.value[key], ...detailPatch } }
    }
  }

  return {
    // state
    core, pillars, nodeDetails, selectedNodeId, filter, query,
    loading, detailLoading, error, detailError, fallback,
    // getters
    orderedPillars, nodes, nodeById, filteredPillars, visibleNodeIds, layoutInput, counts, selectedNode,
    // actions
    setFilter, setQuery, fetchMap, fetchNodeDetail, select, clearSelection,
    automateProcess, runProcess, resolveProcess,
    // realtime
    handleNodeUpdated,
  }
})

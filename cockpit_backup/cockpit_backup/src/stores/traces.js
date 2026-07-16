/**
 * Traces Pinia Store - SpiderNet OS v3.2
 *
 * Manages execution traces and DAG replay for auditing and debugging.
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../services/api.js'

export const useTracesStore = defineStore('traces', () => {
  // ── State ──────────────────────────────────────────────────────────

  /** @type {import('vue').Ref<Array<object>>} All traces */
  const traces = ref([])

  /** @type {import('vue').Ref<object|null>} Currently viewed trace */
  const currentTrace = ref(null)

  /** @type {import('vue').Ref<object|null>} Replay data for the current trace */
  const replayData = ref(null)

  /** @type {import('vue').Ref<boolean>} Loading state */
  const loading = ref(false)

  /** @type {import('vue').Ref<string|null>} Last error message */
  const error = ref(null)

  /** @type {import('vue').Ref<Array<object>>} Events for the current trace */
  const traceEvents = ref([])

  /** @type {import('vue').Ref<number>} Current replay step index (-1 = not replaying) */
  const replayIndex = ref(-1)

  // ── Getters ────────────────────────────────────────────────────────

  const completedTraces = computed(() => traces.value.filter((t) => t.status === 'completed'))
  const failedTraces = computed(() => traces.value.filter((t) => t.status === 'failed'))
  const runningTraces = computed(() => traces.value.filter((t) => t.status === 'running'))

  const totalCost = computed(() => {
    return traces.value.reduce((sum, t) => sum + (t.cost || 0), 0)
  })

  const isReplaying = computed(() => replayIndex.value >= 0)

  // ── Actions ────────────────────────────────────────────────────────

  function normalizeStatus(status) {
    if (['ok', 'warn', 'error'].includes(status)) return status
    if (['completed', 'success', 'succeeded'].includes(status)) return 'ok'
    if (['failed', 'error'].includes(status)) return 'error'
    if (['running', 'pending', 'queued'].includes(status)) return 'warn'
    return 'ok'
  }

  function normalizeTrace(trace) {
    const status = normalizeStatus(trace.status)
    const id = String(trace.id || trace.dag_id || trace.execution?.id || '')

    return {
      ...trace,
      id,
      status,
      kind: trace.kind || trace.event_type || 'flow.execution',
      subject: trace.subject || trace.name || trace.flow_id || `Execution ${id.slice(0, 8)}`,
      actor: trace.actor || trace.user_name || trace.user_id || 'system',
      created_at: trace.created_at || trace.started_at || trace.occurred_at || trace.execution?.started_at,
      duration_ms: trace.duration_ms ?? trace.latency_ms ?? null,
      cost_usd: Number(trace.cost_usd ?? trace.cost ?? 0),
      metadata: trace.metadata || trace.errors || {},
      events: trace.events || [],
    }
  }

  function normalizeTraceList(payload) {
    const list = payload?.data || payload?.traces || payload || []
    return Array.isArray(list) ? list.map(normalizeTrace) : []
  }

  function normalizeTraceDetail(payload) {
    const trace = payload?.data || payload?.trace || payload || null
    if (!trace) return null

    if (trace.execution) {
      return normalizeTrace({
        ...trace.execution,
        metadata: {
          node_states: trace.node_states,
          event_count: trace.event_count,
        },
        events: trace.events || [],
      })
    }

    return normalizeTrace(trace)
  }

  /**
   * Fetch all traces from the API.
   * @param {object} [filters] - Optional query filters.
   */
  async function fetchTraces(filters = {}) {
    loading.value = true
    error.value = null

    try {
      const response = await api.get('/api/traces', { params: filters })
      traces.value = normalizeTraceList(response.data)
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch traces'
      traces.value = []
    } finally {
      loading.value = false
    }
  }

  /**
   * Fetch a single trace by its DAG ID.
   * @param {string} dagId - The trace/DAG ID to fetch.
   * @returns {Promise<object|null>} The trace object or null on error.
   */
  async function fetchTrace(dagId) {
    loading.value = true
    error.value = null

    try {
      const response = await api.get(`/api/traces/${dagId}`)
      const data = normalizeTraceDetail(response.data)
      currentTrace.value = data
      traceEvents.value = data?.events || []
      return currentTrace.value
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch trace'
      return null
    } finally {
      loading.value = false
    }
  }

  /**
   * Fetch replay data for a trace by its DAG ID.
   * @param {string} dagId - The trace/DAG ID to replay.
   * @returns {Promise<object|null>} The replay data or null on error.
   */
  async function fetchReplay(dagId) {
    loading.value = true
    error.value = null

    try {
      const response = await api.get(`/api/traces/${dagId}/replay`)
      replayData.value = response.data.data || response.data || null

      // Also populate trace events from replay data if available
      if (replayData.value?.events) {
        traceEvents.value = replayData.value.events
      }

      return replayData.value
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch replay data'
      return null
    } finally {
      loading.value = false
    }
  }

  /** Start replaying the current trace events from the beginning. */
  function startReplay() {
    if (traceEvents.value.length === 0) return
    replayIndex.value = 0
  }

  /** Step to the next replay event, or stop if at end. */
  function stepReplay() {
    if (replayIndex.value < traceEvents.value.length - 1) {
      replayIndex.value++
    } else {
      replayIndex.value = -1 // End of replay
    }
  }

  /** Stop the current replay. */
  function stopReplay() {
    replayIndex.value = -1
  }

  // ── Real-time handlers ─────────────────────────────────────────────

  function handleTraceUpdate(data) {
    const index = traces.value.findIndex((t) => t.id === data.id)
    if (index !== -1) {
      traces.value[index] = { ...traces.value[index], ...data }
    } else {
      traces.value.unshift(data)
    }
  }

  // ── Expose ─────────────────────────────────────────────────────────
  return {
    // State
    traces,
    currentTrace,
    replayData,
    loading,
    error,
    traceEvents,
    replayIndex,
    // Getters
    completedTraces,
    failedTraces,
    runningTraces,
    totalCost,
    isReplaying,
    // Actions
    fetchTraces,
    fetchTrace,
    fetchReplay,
    startReplay,
    stepReplay,
    stopReplay,
    // Real-time
    handleTraceUpdate,
  }
})

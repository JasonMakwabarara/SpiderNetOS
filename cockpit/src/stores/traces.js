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

  /**
   * Fetch all traces from the API.
   * @param {object} [filters] - Optional query filters.
   */
  async function fetchTraces(filters = {}) {
    loading.value = true
    error.value = null

    try {
      const response = await api.get('/api/traces', { params: filters })
      traces.value = response.data.data || response.data || []
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch traces'
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
      const data = response.data.data || response.data
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

/**
 * God's Eye Pinia store (plan D6 §8) — the live board of every agent at once.
 *
 *   GET /api/godseye/snapshot → { data: { characters, totals, live, needs_you, breaker } }
 *
 * One call returns the whole wall. Six endpoints stitched together on the
 * client would show six different moments, which is exactly the thing a live
 * board is supposed to stop happening.
 *
 * Polling is coarse (10s) and pauses when the tab is hidden: this is a wall to
 * glance at, not a trading screen, and a background tab has no business
 * holding a connection open.
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../services/api.js'
import { fallbackNotice, clone } from '../utils/apiFallback.js'
import godseyeFixture from '../../tests/fixtures/godseye.json'

export const POLL_MS = 10000

/** Every state a character or skill can be in, and how the wall shows it. */
export const STATES = {
  working: { label: 'Working',  pill: 'sn-pill-accent', dot: 'var(--accent)' },
  waiting: { label: 'Waiting on you', pill: 'sn-pill-warn', dot: 'var(--warn)' },
  failing: { label: 'Failing',  pill: 'sn-pill-danger', dot: 'var(--danger)' },
  idle:    { label: 'Idle',     pill: 'sn-pill',       dot: 'var(--text-muted)' },
  off:     { label: 'Nothing on', pill: 'sn-pill',     dot: 'var(--border)' },
}

export const useGodsEyeStore = defineStore('godseye', () => {
  const snapshot = ref({ characters: [], totals: {}, live: [], needs_you: [], breaker: { paused: false, scopes: [] } })
  const loading = ref(false)
  const error = ref(null)
  const fallback = ref(false)
  const disabled = ref(false)
  const lastFetched = ref(null)

  let timer = null

  const characters = computed(() => snapshot.value.characters || [])
  const totals = computed(() => snapshot.value.totals || {})
  const live = computed(() => snapshot.value.live || [])
  const needsYou = computed(() => snapshot.value.needs_you || [])
  const breaker = computed(() => snapshot.value.breaker || { paused: false, scopes: [] })

  /** Nothing enabled anywhere: the wall says so instead of looking broken. */
  const empty = computed(() => characters.value.every((c) => (c.skills || []).length === 0))

  const working = computed(() => characters.value.filter((c) => c.state === 'working'))

  function stateOf(key) {
    return STATES[key] || STATES.idle
  }

  async function fetchSnapshot() {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get('/api/godseye/snapshot')
      snapshot.value = { characters: [], totals: {}, live: [], needs_you: [], breaker: { paused: false, scopes: [] }, ...(data?.data || {}) }
      disabled.value = false
      fallback.value = false
      lastFetched.value = new Date().toISOString()
      return { success: true }
    } catch (err) {
      // 403 is not a failure to show sample data for — the board is simply off.
      if (err?.response?.status === 403) {
        disabled.value = true
        error.value = err?.response?.data?.message || "God's Eye is not switched on for this workspace."
        stopPolling()
        return { success: false, error: error.value }
      }
      error.value = fallbackNotice(err, 'the agent board')
      fallback.value = true
      snapshot.value = clone(godseyeFixture.data)
      return { success: false, error: error.value }
    } finally {
      loading.value = false
    }
  }

  function startPolling() {
    stopPolling()
    if (disabled.value) return
    timer = setInterval(() => {
      if (typeof document !== 'undefined' && document.hidden) return
      fetchSnapshot()
    }, POLL_MS)
  }

  function stopPolling() {
    if (timer) {
      clearInterval(timer)
      timer = null
    }
  }

  return {
    snapshot, loading, error, fallback, disabled, lastFetched,
    characters, totals, live, needsYou, breaker, empty, working,
    stateOf, fetchSnapshot, startPolling, stopPolling,
  }
})

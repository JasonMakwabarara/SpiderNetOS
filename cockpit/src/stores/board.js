/**
 * Board of advisors Pinia store (plan D6 §6).
 *
 *   GET  /api/board/seats                  who sits, for this tenant
 *   GET  /api/board/sessions               past sessions, newest first
 *   POST /api/board/sessions               put a question to the board
 *   GET  /api/board/sessions/{id}          the session, its takes and its verdict
 *
 * Convening is a real spend — five seats × two rounds plus the chairman — so
 * the store never fires it on mount, never retries it automatically, and
 * surfaces the cost of the session that just ran.
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../services/api.js'
import { describeError, fallbackNotice, clone } from '../utils/apiFallback.js'
import boardFixture from '../../tests/fixtures/board.json'

/** Stance → how the verdict table shows it. */
export const STANCES = {
  yes: { label: 'Yes', pill: 'sn-pill-accent' },
  no: { label: 'No', pill: 'sn-pill-danger' },
  not_yet: { label: 'Not yet', pill: 'sn-pill-warn' },
  depends: { label: 'Depends', pill: 'sn-pill' },
}

export const MIN_QUESTION = 12

export const useBoardStore = defineStore('board', () => {
  const seats = ref([])
  const chair = ref(null)
  const disclaimer = ref('Not legal, financial or professional advice.')
  const privateRoster = ref(false)

  const sessions = ref([])
  const session = ref(null)

  const loading = ref(false)
  const convening = ref(false)
  const error = ref(null)
  const fallback = ref(false)
  const disabled = ref(false)

  const hasBoard = computed(() => seats.value.length > 0)
  const verdict = computed(() => session.value?.verdict || null)
  const tableRows = computed(() => verdict.value?.table_rows || [])
  /** Round 1 is the honest record of who thought what before anyone argued. */
  const firstRound = computed(() => (session.value?.takes || []).filter((t) => t.round === 1))

  function stanceOf(key) {
    return STANCES[key] || STANCES.depends
  }

  /** A 403 is "the board is off", not a failure worth showing sample seats for. */
  function handle(err, what) {
    if (err?.response?.status === 403) {
      disabled.value = true
      error.value = err?.response?.data?.message || 'The board of advisors is not switched on for this workspace.'
      return true
    }
    error.value = fallbackNotice(err, what)
    return false
  }

  async function fetchSeats() {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get('/api/board/seats')
      seats.value = data?.data || []
      chair.value = data?.meta?.chair || null
      disclaimer.value = data?.meta?.disclaimer || disclaimer.value
      privateRoster.value = Boolean(data?.meta?.private_roster)
      disabled.value = false
      fallback.value = false
      return { success: true }
    } catch (err) {
      if (!handle(err, 'the board')) {
        fallback.value = true
        seats.value = clone(boardFixture.seats.data)
        chair.value = boardFixture.seats.meta.chair
      }
      return { success: false, error: error.value }
    } finally {
      loading.value = false
    }
  }

  async function fetchSessions() {
    try {
      const { data } = await api.get('/api/board/sessions')
      sessions.value = data?.data || []
      return { success: true }
    } catch (err) {
      if (!handle(err, 'past board sessions')) {
        fallback.value = true
        sessions.value = clone(boardFixture.sessions.data)
      }
      return { success: false, error: error.value }
    }
  }

  async function fetchSession(id) {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get(`/api/board/sessions/${encodeURIComponent(id)}`)
      session.value = data?.data || null
      return { success: true }
    } catch (err) {
      error.value = describeError(err, 'That board session could not be loaded')
      return { success: false, error: error.value }
    } finally {
      loading.value = false
    }
  }

  /**
   * Put a question to the board. Never retried automatically: a retry is
   * another five-seat, two-round spend, and the caller decides that.
   */
  async function convene(question, chosenSeats = null) {
    convening.value = true
    error.value = null
    try {
      const payload = { question }
      if (Array.isArray(chosenSeats) && chosenSeats.length) payload.seats = chosenSeats
      const { data } = await api.post('/api/board/sessions', payload)
      session.value = data?.data || null
      if (session.value) sessions.value = [session.value, ...sessions.value]
      return { success: true, session: session.value }
    } catch (err) {
      error.value = describeError(err, 'The board could not sit')
      return { success: false, error: error.value }
    } finally {
      convening.value = false
    }
  }

  function reset() {
    session.value = null
    error.value = null
  }

  return {
    seats, chair, disclaimer, privateRoster, sessions, session,
    loading, convening, error, fallback, disabled,
    hasBoard, verdict, tableRows, firstRound,
    stanceOf, fetchSeats, fetchSessions, fetchSession, convene, reset,
  }
})

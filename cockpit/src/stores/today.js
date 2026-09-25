/**
 * Needs-You Today Pinia store — the deterministic 07:00 brief (D8 #3).
 *
 *   GET /api/today → { data: { date, items: [...], overnight: [...], one_more_question? } }
 *
 * Items are capped at seven with one action each; dismissals and the
 * "skip" on the one-more-question are session-local (the brief is
 * regenerated every morning, so nothing needs persisting here).
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../services/api.js'
import { fallbackNotice, clone } from '../utils/apiFallback.js'
import todayFixture from '../../tests/fixtures/today.json'

export const MAX_ITEMS = 7

export const ITEM_KINDS = {
  approval:    { label: 'Approval',      pill: 'sn-pill-warn' },
  blocked_run: { label: 'Blocked run',   pill: 'sn-pill-danger' },
  brain_gap:   { label: 'Brain gap',     pill: 'sn-pill-accent' },
  awareness:   { label: 'FYI',           pill: 'sn-pill' },
  undo_window: { label: 'Undo window',   pill: 'sn-pill-warn' },
  stale_draft: { label: 'Stale draft',   pill: 'sn-pill' },
  calendar:    { label: 'Today',         pill: 'sn-pill-accent' },
  overnight:   { label: 'Overnight',     pill: 'sn-pill' },
}

export const useTodayStore = defineStore('today', () => {
  const brief = ref({ date: null, items: [], overnight: [], one_more_question: null })
  const dismissed = ref([])
  const questionSkipped = ref(false)
  const loading = ref(false)
  const error = ref(null)
  const fallback = ref(false)

  const items = computed(() =>
    (brief.value.items || []).filter((i) => !dismissed.value.includes(i.id)).slice(0, MAX_ITEMS),
  )
  const hiddenCount = computed(() =>
    Math.max(0, (brief.value.items || []).filter((i) => !dismissed.value.includes(i.id)).length - MAX_ITEMS),
  )
  const overnight = computed(() => brief.value.overnight || [])
  const oneMoreQuestion = computed(() => (questionSkipped.value ? null : brief.value.one_more_question || null))
  const moneyAtStake = computed(() =>
    items.value.reduce((s, i) => s + (Number(i.money?.amount) || 0), 0),
  )

  async function fetchToday() {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get('/api/today')
      brief.value = { items: [], overnight: [], one_more_question: null, ...(data?.data || {}) }
      fallback.value = false
      return { success: true }
    } catch (err) {
      error.value = fallbackNotice(err, "today's brief")
      fallback.value = true
      brief.value = clone(todayFixture.data)
      return { success: false, error: error.value }
    } finally {
      loading.value = false
    }
  }

  function dismiss(id) {
    if (id && !dismissed.value.includes(id)) dismissed.value = [...dismissed.value, id]
  }

  function skipQuestion() {
    questionSkipped.value = true
  }

  function reset() {
    dismissed.value = []
    questionSkipped.value = false
  }

  return {
    brief, dismissed, questionSkipped, loading, error, fallback,
    items, hiddenCount, overnight, oneMoreQuestion, moneyAtStake,
    fetchToday, dismiss, skipQuestion, reset,
  }
})

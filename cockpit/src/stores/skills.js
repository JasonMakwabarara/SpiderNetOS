/**
 * Skills Pinia store — the catalogue (surface B), the full cards (surface A),
 * the last run per skill and the pipeline-stage / enable / feedback calls.
 *
 * Endpoints (PR 1 contract):
 *   GET  /api/skills?pillar=&q=&enabled=      → { data: [SkillSummary], meta }
 *   GET  /api/skills/{slug}                   → { data: SkillCard }
 *   POST /api/skills/{slug}/run  {inputs}     → 200/202 { data: { run_id, status, approval_id?, entry_path, questions? } }
 *                                             | 422 { missing_brain: [{ path, section, question }] }
 *                                             | 402 { checkout_hint, pack_id }
 *   PUT  /api/skills/{slug}/pipeline {stage}
 *   POST /api/skills/{slug}/enable
 *   POST /api/skills/{slug}/feedback
 *   POST /api/skills/signals
 *
 * Filters live in the route query (the view syncs them here) and are
 * applied both server-side (params) and client-side (so the fixture
 * fallback still filters).
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../services/api.js'
import { describeError, fallbackNotice, clone, cleanParams } from '../utils/apiFallback.js'
import skillsFixture from '../../tests/fixtures/skills.json'
import cardFixture from '../../tests/fixtures/skill_card.json'

export const PIPELINE_ORDER = ['human_led', 'assisted', 'autonomous']

const EMPTY_FILTERS = { pillar: '', q: '', enabled: '' }

export const useSkillsStore = defineStore('skills', () => {
  // ── State ──────────────────────────────────────────────────────────
  const catalogue = ref([])
  const meta = ref({ pillars: [], identities: [], core_agents: [] })
  /** slug → full card */
  const cards = ref({})
  /** slug → last run response ({ run_id, status, approval_id?, entry_path, questions? }) */
  const runs = ref({})
  const filters = ref({ ...EMPTY_FILTERS })

  const loading = ref(false)
  const cardLoading = ref(false)
  const running = ref(false)
  const error = ref(null)
  const cardError = ref(null)
  const fallback = ref(false)

  // ── Getters ────────────────────────────────────────────────────────
  const pillarsInOrder = computed(() =>
    [...(meta.value.pillars || [])].sort((a, b) => (a.order ?? 0) - (b.order ?? 0)),
  )

  const filtered = computed(() => catalogue.value.filter(matchesFilters))

  /** Pillar sections in meta order; pillars missing from meta are appended. */
  const byPillar = computed(() => {
    const known = new Set()
    const groups = pillarsInOrder.value.map((p) => {
      known.add(p.key)
      return { ...p, skills: filtered.value.filter((s) => s.pillar?.key === p.key) }
    })
    for (const s of filtered.value) {
      const key = s.pillar?.key || 'other'
      if (known.has(key)) continue
      known.add(key)
      groups.push({ key, label: s.pillar?.label || 'Other', order: 999, skills: filtered.value.filter((x) => (x.pillar?.key || 'other') === key) })
    }
    return groups.filter((g) => g.skills.length)
  })

  const stats = computed(() => {
    const all = catalogue.value
    return {
      total: all.length,
      enabled: all.filter((s) => s.enabled).length,
      brainReady: all.filter((s) => s.brain?.ready).length,
      autonomous: all.filter((s) => s.pipeline?.stage === 'autonomous').length,
    }
  })

  // ── Helpers ────────────────────────────────────────────────────────
  function matchesFilters(s) {
    const f = filters.value
    if (f.pillar && s.pillar?.key !== f.pillar) return false
    if (f.enabled === '1' || f.enabled === true || f.enabled === 'true') { if (!s.enabled) return false }
    if (f.enabled === '0' || f.enabled === false || f.enabled === 'false') { if (s.enabled) return false }
    if (f.q) {
      const q = String(f.q).toLowerCase()
      const hay = [s.name, s.slug, s.one_liner, s.identity?.name, s.core_agent, ...(s.tags || [])]
        .filter(Boolean).join(' ').toLowerCase()
      if (!hay.includes(q)) return false
    }
    return true
  }

  function setFilters(next = {}) {
    filters.value = { ...EMPTY_FILTERS, ...cleanParams(next) }
  }

  function summaryOf(slug) {
    return catalogue.value.find((s) => s.slug === slug) || null
  }

  function patchSummary(slug, patch) {
    const i = catalogue.value.findIndex((s) => s.slug === slug)
    if (i !== -1) catalogue.value[i] = { ...catalogue.value[i], ...patch }
  }

  function patchCard(slug, patch) {
    if (cards.value[slug]) cards.value[slug] = { ...cards.value[slug], ...patch }
  }

  /** Fixture card, re-keyed to the requested slug when the catalogue knows it. */
  function fallbackCard(slug) {
    const base = clone(cardFixture.data)
    const summary = summaryOf(slug)
    if (!summary || summary.slug === base.slug) return base
    return {
      ...base,
      ...summary,
      pipeline: { ...base.pipeline, ...(summary.pipeline || {}) },
      brain: base.brain,
      entry_path: `/skills/${slug}`,
    }
  }

  // ── Actions ────────────────────────────────────────────────────────
  async function fetchCatalogue(next) {
    if (next) setFilters(next)
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get('/api/skills', { params: cleanParams(filters.value) })
      catalogue.value = data?.data || []
      if (data?.meta) meta.value = { ...meta.value, ...data.meta }
      fallback.value = false
      return { success: true }
    } catch (err) {
      error.value = fallbackNotice(err, 'the skills catalogue')
      fallback.value = true
      catalogue.value = clone(skillsFixture.data)
      meta.value = clone(skillsFixture.meta)
      return { success: false, error: error.value }
    } finally {
      loading.value = false
    }
  }

  async function fetchCard(slug) {
    if (!slug) return { success: false, error: 'No skill slug' }
    cardLoading.value = true
    cardError.value = null
    try {
      const { data } = await api.get(`/api/skills/${encodeURIComponent(slug)}`)
      const card = data?.data || data
      cards.value = { ...cards.value, [slug]: card }
      return { success: true, card }
    } catch (err) {
      cardError.value = fallbackNotice(err, 'this skill card')
      const card = fallbackCard(slug)
      cards.value = { ...cards.value, [slug]: card }
      return { success: false, error: cardError.value, card }
    } finally {
      cardLoading.value = false
    }
  }

  /**
   * Start a run. `extra` carries the optional second-pass fields:
   *   answers: [{ path, section, text }]  — replies to a 422 missing_brain
   *   inputs_from / source_run_id         — one-step-further follow-ons
   */
  async function run(slug, inputs = {}, extra = {}) {
    running.value = true
    try {
      const { data, status } = await api.post(`/api/skills/${encodeURIComponent(slug)}/run`, { inputs, ...extra })
      const result = data?.data || data || {}
      // Keep the submitted inputs next to the response so "one step
      // further" follow-ons can map `inputs_from` off this run.
      runs.value = { ...runs.value, [slug]: { ...result, inputs: result.inputs || inputs } }
      const now = new Date().toISOString()
      patchSummary(slug, { last_run_at: now })
      patchCard(slug, { last_run_at: now })
      return { success: true, status, data: result }
    } catch (err) {
      const status = err?.response?.status
      const body = err?.response?.data || {}
      if (status === 422 && Array.isArray(body.missing_brain)) {
        return { success: false, status, missing_brain: body.missing_brain, error: body.message || 'Atlas needs a few answers first.' }
      }
      if (status === 402) {
        return { success: false, status, checkout_hint: body.checkout_hint, pack_id: body.pack_id, error: body.message || 'This skill needs a pack.' }
      }
      return { success: false, status, error: describeError(err, 'Run failed') }
    } finally {
      running.value = false
    }
  }

  /** Optimistic stage change with rollback. */
  async function setStage(slug, stage) {
    if (!PIPELINE_ORDER.includes(stage)) return { success: false, error: `Unknown stage ${stage}` }
    const summary = summaryOf(slug)
    const card = cards.value[slug]
    const prev = {
      summary: summary?.pipeline ? { ...summary.pipeline } : null,
      card: card?.pipeline ? { ...card.pipeline } : null,
    }
    const apply = (p) => {
      if (summary) patchSummary(slug, { pipeline: { ...(summary.pipeline || {}), ...p } })
      if (card) patchCard(slug, { pipeline: { ...(card.pipeline || {}), ...p } })
    }
    apply({ stage, inherited: false })
    try {
      const { data } = await api.put(`/api/skills/${encodeURIComponent(slug)}/pipeline`, { stage })
      const server = data?.data?.pipeline || data?.pipeline
      if (server) apply(server)
      return { success: true }
    } catch (err) {
      if (prev.summary) patchSummary(slug, { pipeline: prev.summary })
      if (prev.card) patchCard(slug, { pipeline: prev.card })
      return { success: false, error: describeError(err, 'Could not change the pipeline stage') }
    }
  }

  async function enable(slug) {
    try {
      const { data } = await api.post(`/api/skills/${encodeURIComponent(slug)}/enable`)
      const patch = { enabled: true, provisioning: 'installed', ...(data?.data || {}) }
      patchSummary(slug, patch)
      patchCard(slug, patch)
      return { success: true, data: data?.data }
    } catch (err) {
      const status = err?.response?.status
      const body = err?.response?.data || {}
      if (status === 402) return { success: false, status, checkout_hint: body.checkout_hint, pack_id: body.pack_id, error: body.message || 'This skill needs a pack.' }
      return { success: false, status, error: describeError(err, 'Could not enable this skill') }
    }
  }

  async function feedback(slug, payload = {}) {
    try {
      await api.post(`/api/skills/${encodeURIComponent(slug)}/feedback`, payload)
      return { success: true }
    } catch (err) {
      return { success: false, error: describeError(err, 'Feedback not recorded') }
    }
  }

  /** Fire-and-forget usage signals (card viewed, section expanded…). */
  async function signal(payload = {}) {
    try {
      await api.post('/api/skills/signals', payload)
      return { success: true }
    } catch {
      return { success: false }
    }
  }

  // ── Real-time handlers ─────────────────────────────────────────────
  function handleSkillUpdated(data) {
    const slug = data?.slug
    if (!slug) return
    const { slug: _s, ...patch } = data
    if (summaryOf(slug)) patchSummary(slug, patch)
    else catalogue.value.push({ ...data })
    patchCard(slug, patch)
  }

  function handleRunUpdated(data) {
    const slug = data?.skill_slug || data?.skill
    if (!slug) return
    const runId = data.run_id || data.id
    const prev = runs.value[slug] || {}
    if (!prev.run_id || prev.run_id === runId) {
      runs.value = { ...runs.value, [slug]: { ...prev, ...data, run_id: runId } }
    }
    const card = cards.value[slug]
    if (card && Array.isArray(card.recent_runs)) {
      const i = card.recent_runs.findIndex((r) => r.id === runId)
      const entry = { ...(i === -1 ? {} : card.recent_runs[i]), ...data, id: runId }
      const recent = [...card.recent_runs]
      if (i === -1) recent.unshift(entry); else recent[i] = entry
      patchCard(slug, { recent_runs: recent })
    }
    if (data.finished_at || data.started_at) {
      const ts = data.finished_at || data.started_at
      patchSummary(slug, { last_run_at: ts })
      patchCard(slug, { last_run_at: ts })
    }
  }

  // useWebSocket calls this name for `.skill.run.updated`.
  const handleSkillRunUpdated = handleRunUpdated

  return {
    // state
    catalogue, meta, cards, runs, filters,
    loading, cardLoading, running, error, cardError, fallback,
    // getters
    pillarsInOrder, filtered, byPillar, stats,
    // actions
    setFilters, summaryOf, fetchCatalogue, fetchCard, run, setStage, enable, feedback, signal,
    // realtime
    handleSkillUpdated, handleRunUpdated, handleSkillRunUpdated,
  }
})

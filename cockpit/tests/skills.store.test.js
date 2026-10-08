// Skills store — Vitest unit tests.
// Catalogue envelope + params, fixture fallback with an inline error,
// client-side filters, card cache, run outcomes (200/422/402), optimistic
// setStage with rollback, enable, and the realtime handlers.
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('../src/services/api.js', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import api from '../src/services/api.js'
import { useSkillsStore } from '../src/stores/skills.js'
import skillsFixture from './fixtures/skills.json'
import cardFixture from './fixtures/skill_card.json'
import { httpError } from './helpers/harness.js'

describe('skills store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
  })

  it('fetchCatalogue unwraps {data, meta}, passes cleaned params and orders pillars by meta.order', async () => {
    api.get.mockResolvedValueOnce({ data: skillsFixture })
    const store = useSkillsStore()
    const res = await store.fetchCatalogue({ pillar: 'sales', q: '', enabled: '' })
    expect(res.success).toBe(true)
    expect(api.get).toHaveBeenCalledWith('/api/skills', { params: { pillar: 'sales' } })
    expect(store.catalogue).toHaveLength(skillsFixture.data.length)
    expect(store.meta.pillars.map((p) => p.key)).toEqual(skillsFixture.meta.pillars.map((p) => p.key))
    expect(store.pillarsInOrder[0].key).toBe('sales')
    expect(store.fallback).toBe(false)
  })

  it('falls back to the fixture with an inline error when the API 404s', async () => {
    api.get.mockRejectedValueOnce(httpError(404))
    const store = useSkillsStore()
    const res = await store.fetchCatalogue()
    expect(res.success).toBe(false)
    expect(store.fallback).toBe(true)
    expect(store.error).toContain('HTTP 404')
    expect(store.error).toContain('sample data')
    expect(store.catalogue.length).toBeGreaterThan(0)
    expect(store.byPillar[0].key).toBe('sales')
  })

  it('applies pillar / enabled / q filters client-side too', async () => {
    api.get.mockResolvedValue({ data: skillsFixture })
    const store = useSkillsStore()
    await store.fetchCatalogue({ pillar: 'deals' })
    expect(store.filtered.every((s) => s.pillar.key === 'deals')).toBe(true)
    expect(store.byPillar.map((g) => g.key)).toEqual(['deals'])

    store.setFilters({ enabled: '0' })
    expect(store.filtered.every((s) => !s.enabled)).toBe(true)

    store.setFilters({ q: 'newsletter' })
    expect(store.filtered.map((s) => s.slug)).toEqual(['customer-newsletter'])

    store.setFilters({ q: 'no-such-skill' })
    expect(store.byPillar).toEqual([])
  })

  it('stats count enabled / brain-ready / autonomous', async () => {
    api.get.mockResolvedValueOnce({ data: skillsFixture })
    const store = useSkillsStore()
    await store.fetchCatalogue()
    const all = skillsFixture.data
    expect(store.stats).toEqual({
      total: all.length,
      enabled: all.filter((s) => s.enabled).length,
      brainReady: all.filter((s) => s.brain.ready).length,
      autonomous: all.filter((s) => s.pipeline.stage === 'autonomous').length,
    })
  })

  it('fetchCard caches by slug and falls back to the fixture card re-keyed to the slug', async () => {
    api.get.mockResolvedValueOnce({ data: skillsFixture })
    const store = useSkillsStore()
    await store.fetchCatalogue()

    api.get.mockResolvedValueOnce({ data: cardFixture })
    const ok = await store.fetchCard('cold-email-drafting')
    expect(ok.success).toBe(true)
    expect(api.get).toHaveBeenLastCalledWith('/api/skills/cold-email-drafting')
    expect(store.cards['cold-email-drafting'].breaks_into).toHaveLength(5)

    api.get.mockRejectedValueOnce(httpError(500))
    const fb = await store.fetchCard('icp-definition')
    expect(fb.success).toBe(false)
    expect(store.cardError).toContain('HTTP 500')
    expect(store.cards['icp-definition'].slug).toBe('icp-definition')
    expect(store.cards['icp-definition'].name).toBe('ICP Definition')
    expect(store.cards['icp-definition'].pipeline.stage).toBe('human_led')
    // Still carries the ten-section content so the card renders.
    expect(store.cards['icp-definition'].one_step_further.steps.length).toBeGreaterThan(0)
  })

  it('run stores the response with the submitted inputs and stamps last_run_at', async () => {
    api.get.mockResolvedValueOnce({ data: skillsFixture })
    const store = useSkillsStore()
    await store.fetchCatalogue()
    api.post.mockResolvedValueOnce({ status: 202, data: { data: { run_id: 'run_x', status: 'queued', entry_path: '/skills/cold-email-drafting' } } })

    const res = await store.run('cold-email-drafting', { campaign: 'q4' }, { answers: [{ path: 'offer/offer.md', section: 'proof', text: 'x' }] })
    expect(res.success).toBe(true)
    expect(res.status).toBe(202)
    expect(api.post).toHaveBeenCalledWith('/api/skills/cold-email-drafting/run', {
      inputs: { campaign: 'q4' },
      answers: [{ path: 'offer/offer.md', section: 'proof', text: 'x' }],
    })
    expect(store.runs['cold-email-drafting']).toMatchObject({ run_id: 'run_x', inputs: { campaign: 'q4' } })
    expect(store.summaryOf('cold-email-drafting').last_run_at).toBeTruthy()
  })

  it('run surfaces 422 missing_brain and 402 checkout_hint as structured failures', async () => {
    const store = useSkillsStore()
    api.post.mockRejectedValueOnce(httpError(422, { missing_brain: [{ path: 'offer/offer.md', section: 'differentiators', question: 'What makes you different?' }] }))
    const r422 = await store.run('cold-email-drafting', {})
    expect(r422).toMatchObject({ success: false, status: 422 })
    expect(r422.missing_brain).toHaveLength(1)

    api.post.mockRejectedValueOnce(httpError(402, { checkout_hint: 'Install Marketing Studio', pack_id: 'marketing-studio' }))
    const r402 = await store.run('customer-newsletter', {})
    expect(r402).toMatchObject({ success: false, status: 402, checkout_hint: 'Install Marketing Studio', pack_id: 'marketing-studio' })

    api.post.mockRejectedValueOnce(httpError(500, { message: 'boom' }))
    const r500 = await store.run('cold-email-drafting', {})
    expect(r500.success).toBe(false)
    expect(r500.error).toContain('boom')
  })

  it('setStage is optimistic on the summary and the card, and rolls back on failure', async () => {
    api.get.mockResolvedValueOnce({ data: skillsFixture })
    const store = useSkillsStore()
    await store.fetchCatalogue()
    api.get.mockResolvedValueOnce({ data: cardFixture })
    await store.fetchCard('cold-email-drafting')

    // Never resolves during the assertion → we can observe the optimistic state.
    let resolvePut
    api.put.mockReturnValueOnce(new Promise((r) => { resolvePut = r }))
    const pending = store.setStage('cold-email-drafting', 'autonomous')
    expect(store.summaryOf('cold-email-drafting').pipeline).toMatchObject({ stage: 'autonomous', inherited: false })
    expect(store.cards['cold-email-drafting'].pipeline.stage).toBe('autonomous')
    resolvePut({ data: { data: { pipeline: { stage: 'autonomous', inherited: false } } } })
    expect((await pending).success).toBe(true)
    expect(api.put).toHaveBeenCalledWith('/api/skills/cold-email-drafting/pipeline', { stage: 'autonomous' })

    api.put.mockRejectedValueOnce(httpError(403, { message: 'Gate not met' }))
    const res = await store.setStage('cold-email-drafting', 'human_led')
    expect(res.success).toBe(false)
    expect(res.error).toContain('Gate not met')
    expect(store.summaryOf('cold-email-drafting').pipeline.stage).toBe('autonomous')
    expect(store.cards['cold-email-drafting'].pipeline.stage).toBe('autonomous')
    // stage_copy survives the rollback merge
    expect(store.cards['cold-email-drafting'].pipeline.stage_copy.assisted).toBeTruthy()
  })

  it('rejects an unknown stage without calling the API', async () => {
    const store = useSkillsStore()
    const res = await store.setStage('cold-email-drafting', 'yolo')
    expect(res.success).toBe(false)
    expect(api.put).not.toHaveBeenCalled()
  })

  it('enable flips the summary to enabled/installed and passes 402 through', async () => {
    api.get.mockResolvedValueOnce({ data: skillsFixture })
    const store = useSkillsStore()
    await store.fetchCatalogue()

    api.post.mockResolvedValueOnce({ data: { data: {} } })
    const ok = await store.enable('follow-up-drafting')
    expect(ok.success).toBe(true)
    expect(api.post).toHaveBeenCalledWith('/api/skills/follow-up-drafting/enable')
    expect(store.summaryOf('follow-up-drafting')).toMatchObject({ enabled: true, provisioning: 'installed' })

    api.post.mockRejectedValueOnce(httpError(402, { checkout_hint: 'Buy the pack', pack_id: 'marketing-studio' }))
    const paywalled = await store.enable('customer-newsletter')
    expect(paywalled).toMatchObject({ success: false, status: 402, pack_id: 'marketing-studio' })
    expect(store.summaryOf('customer-newsletter').enabled).toBe(false)
  })

  it('feedback and signal post to their endpoints; signal never throws', async () => {
    const store = useSkillsStore()
    api.post.mockResolvedValueOnce({ data: {} })
    expect((await store.feedback('cold-email-drafting', { rating: 'up' })).success).toBe(true)
    expect(api.post).toHaveBeenCalledWith('/api/skills/cold-email-drafting/feedback', { rating: 'up' })

    api.post.mockRejectedValueOnce(new Error('offline'))
    expect((await store.signal({ event: 'card.viewed' })).success).toBe(false)
    expect(api.post).toHaveBeenLastCalledWith('/api/skills/signals', { event: 'card.viewed' })
  })

  it('handleSkillUpdated merges into the summary and card; handleRunUpdated tracks recent runs', async () => {
    api.get.mockResolvedValueOnce({ data: skillsFixture })
    const store = useSkillsStore()
    await store.fetchCatalogue()
    api.get.mockResolvedValueOnce({ data: cardFixture })
    await store.fetchCard('cold-email-drafting')

    store.handleSkillUpdated({ slug: 'cold-email-drafting', enabled: false, pipeline: { stage: 'human_led', inherited: false } })
    expect(store.summaryOf('cold-email-drafting').enabled).toBe(false)
    expect(store.cards['cold-email-drafting'].pipeline.stage).toBe('human_led')
    // Unknown slug is appended rather than dropped
    store.handleSkillUpdated({ slug: 'brand-new', name: 'Brand New', pillar: { key: 'sales', label: 'Sales' } })
    expect(store.summaryOf('brand-new').name).toBe('Brand New')

    store.handleRunUpdated({ run_id: 'run_live', skill_slug: 'cold-email-drafting', status: 'running', started_at: '2026-09-16T09:00:00Z' })
    expect(store.cards['cold-email-drafting'].recent_runs[0]).toMatchObject({ id: 'run_live', status: 'running' })
    expect(store.summaryOf('cold-email-drafting').last_run_at).toBe('2026-09-16T09:00:00Z')
    store.handleSkillRunUpdated({ run_id: 'run_live', skill_slug: 'cold-email-drafting', status: 'succeeded', finished_at: '2026-09-16T09:01:00Z' })
    expect(store.cards['cold-email-drafting'].recent_runs[0].status).toBe('succeeded')
    expect(store.cards['cold-email-drafting'].recent_runs).toHaveLength(2)
  })
})

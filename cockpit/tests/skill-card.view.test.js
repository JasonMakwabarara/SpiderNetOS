// SkillCard view — Vitest + @vue/test-utils (happy-dom) with a memory router.
// Ten sections in order, run panel gating, slug watch reload, 422 answer
// boxes, pipeline stage change (+ autonomous confirm), one-step-further
// follow-on run, brain file drawer, and the fixture fallback.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

vi.mock('../src/services/api.js', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import api from '../src/services/api.js'
import SkillCard from '../src/views/skills/SkillCard.vue'
import { useAuthStore } from '../src/stores/auth.js'
import cardFixture from './fixtures/skill_card.json'
import brainFileFixture from './fixtures/brain_file.json'
import { mountView, settle, q, httpError } from './helpers/harness.js'

const SECTIONS = [
  'skill-section-glance',
  'skill-section-covers',
  'skill-section-breaks-into',
  'skill-section-builds-on',
  'skill-section-replaces',
  'skill-section-pipeline',
  'skill-section-your-role',
  'skill-section-one-step-further',
  'skill-section-brain',
  'skill-section-hands-off',
]

function readyCard() {
  const card = JSON.parse(JSON.stringify(cardFixture))
  card.data.brain.files = card.data.brain.files.map((f) => ({ ...f, status: 'filled' }))
  return card
}

function mockApi({ card = cardFixture, run } = {}) {
  api.get.mockImplementation((url) => {
    if (url.startsWith('/api/skills/')) return Promise.resolve({ data: { data: { ...card.data, slug: url.split('/').pop() } } })
    if (url.startsWith('/api/brain/files/')) return Promise.resolve({ data: brainFileFixture })
    return Promise.reject(httpError(404))
  })
  api.post.mockImplementation((url, body) => {
    if (url === '/api/skills/signals') return Promise.resolve({ data: {} })
    if (url.endsWith('/run')) {
      if (run) return run(url, body)
      return Promise.resolve({ status: 202, data: { data: { run_id: 'run_new', status: 'queued', approval_id: 'apr_new', entry_path: '/skills/cold-email-drafting' } } })
    }
    return Promise.resolve({ data: { data: {} } })
  })
  api.put.mockResolvedValue({ data: { data: {} } })
}

let wrapper
afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  document.body.innerHTML = ''
})
beforeEach(() => vi.resetAllMocks())

describe('SkillCard view', () => {
  it('renders the ten sections in order, each labelled', async () => {
    mockApi()
    ;({ wrapper } = await mountView(SkillCard, { path: '/skills/cold-email-drafting' }))
    await settle()
    const ids = wrapper.findAll('[data-testid^="skill-section-"]').map((s) => s.attributes('data-testid'))
    expect(ids).toEqual(SECTIONS)
    for (const s of wrapper.findAll('[data-testid^="skill-section-"]')) {
      expect(s.element.tagName).toBe('SECTION')
      const labelledBy = s.attributes('aria-labelledby')
      expect(labelledBy).toBeTruthy()
      expect(wrapper.find(`#${labelledBy}`).exists()).toBe(true)
    }
    expect(wrapper.find('[data-testid="skill-card-title"]').text()).toBe('Cold Email Drafting')
    expect(wrapper.find('[data-testid="skill-card-chain"]').text()).toContain('Growth → Nexus → Atlas')
    expect(wrapper.find('[data-testid="skill-replaces-note"]').text()).toBe('…or it never gets done.')
    expect(wrapper.findAll('[data-testid^="skill-chip-"]').length).toBeGreaterThanOrEqual(9)
    expect(wrapper.find('[data-testid="skill-handoff-richard"]').text()).toContain('Not yet')
    expect(api.post).toHaveBeenCalledWith('/api/skills/signals', { event: 'card.viewed', skill: 'cold-email-drafting' })
  })

  it('disables Run with a reason while a required brain file is not filled', async () => {
    mockApi()
    ;({ wrapper } = await mountView(SkillCard, { path: '/skills/cold-email-drafting' }))
    await settle()
    const reason = wrapper.find('[data-testid="skill-run-blocked-reason"]')
    expect(reason.exists()).toBe(true)
    expect(reason.text()).toContain('Offer')
    const btn = wrapper.find('[data-testid="skill-run-button"]')
    expect(btn.attributes('disabled')).toBeDefined()
    await btn.trigger('click')
    expect(api.post.mock.calls.some(([url]) => url.endsWith('/run'))).toBe(false)
  })

  it('reloads the card when the route slug changes', async () => {
    mockApi()
    let router
    ;({ wrapper, router } = await mountView(SkillCard, { path: '/skills/cold-email-drafting' }))
    await settle()
    expect(api.get).toHaveBeenCalledWith('/api/skills/cold-email-drafting')
    await router.push('/skills/icp-definition')
    await settle()
    expect(api.get).toHaveBeenCalledWith('/api/skills/icp-definition')
    expect(api.post).toHaveBeenLastCalledWith('/api/skills/signals', { event: 'card.viewed', skill: 'icp-definition' })
  })

  it('runs with the form inputs, then renders a 422 missing_brain as answer boxes and re-posts with answers', async () => {
    let calls = 0
    mockApi({
      card: readyCard(),
      run: (url, body) => {
        calls++
        if (calls === 1) return Promise.reject(httpError(422, { missing_brain: [
          { path: 'offer/offer.md', section: 'differentiators', question: 'What makes you different?' },
          { path: 'sales/proof.md', section: 'proof', question: 'What proof can I show?' },
        ] }))
        return Promise.resolve({ status: 202, data: { data: { run_id: 'run_after', status: 'queued', entry_path: '/skills/cold-email-drafting' } } })
      },
    })
    ;({ wrapper } = await mountView(SkillCard, { path: '/skills/cold-email-drafting' }))
    await settle()
    expect(wrapper.find('[data-testid="skill-run-blocked-reason"]').exists()).toBe(false)
    // Required input missing → validation, still disabled
    expect(wrapper.find('[data-testid="skill-run-validation"]').text()).toContain('Campaign')
    await wrapper.find('[data-testid="skill-input-campaign"]').setValue('q4-founders')
    await wrapper.find('[data-testid="skill-run-button"]').trigger('click')
    await settle()

    const runCall = api.post.mock.calls.find(([url]) => url === '/api/skills/cold-email-drafting/run')
    expect(runCall[1].inputs).toMatchObject({ campaign: 'q4-founders', segment: 'founders', steps: 3, variants: 2, channel: 'email' })

    expect(wrapper.find('[data-testid="skill-run-questions"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-testid^="skill-run-answer-"]').filter((w) => w.element.tagName === 'TEXTAREA')).toHaveLength(2)
    const submit = wrapper.find('[data-testid="skill-run-answers-submit"]')
    expect(submit.attributes('disabled')).toBeDefined()
    await wrapper.find('[data-testid="skill-run-answer-0"]').setValue('We ship in six weeks.')
    await wrapper.find('[data-testid="skill-run-answer-1"]').setValue('14 founders, 11 shipped.')
    expect(submit.attributes('disabled')).toBeUndefined()
    await wrapper.find('[data-testid="skill-run-questions"]').trigger('submit')
    await settle()

    const second = api.post.mock.calls.filter(([url]) => url === '/api/skills/cold-email-drafting/run')[1]
    expect(second[1].answers).toEqual([
      { path: 'offer/offer.md', section: 'differentiators', text: 'We ship in six weeks.' },
      { path: 'sales/proof.md', section: 'proof', text: '14 founders, 11 shipped.' },
    ])
    expect(second[1].inputs.campaign).toBe('q4-founders')
    const result = wrapper.find('[data-testid="skill-run-result"]')
    expect(result.attributes('role')).toBe('status')
    expect(result.attributes('data-outcome')).toBe('started')
    expect(wrapper.find('[data-testid="skill-run-link-run"]').attributes('href')).toContain('/agents/runs/run_after')
    expect(wrapper.find('[data-testid="skill-run-questions"]').exists()).toBe(false)
  })

  it('links run, approval and draft after a successful run', async () => {
    mockApi({ card: readyCard() })
    ;({ wrapper } = await mountView(SkillCard, { path: '/skills/cold-email-drafting' }))
    await settle()
    await wrapper.find('[data-testid="skill-input-campaign"]').setValue('q4')
    await wrapper.find('[data-testid="skill-run-button"]').trigger('click')
    await settle()
    expect(wrapper.find('[data-testid="skill-run-link-run"]').attributes('href')).toContain('/agents/runs/run_new')
    expect(wrapper.find('[data-testid="skill-run-link-approval"]').attributes('href')).toContain('id=apr_new')
    expect(wrapper.find('[data-testid="skill-run-link-draft"]').attributes('href')).toContain('/skills/cold-email-drafting')
  })

  it('shows the 402 checkout hint with an install link', async () => {
    mockApi({ card: readyCard(), run: () => Promise.reject(httpError(402, { checkout_hint: 'Install Marketing Studio', pack_id: 'marketing-studio' })) })
    ;({ wrapper } = await mountView(SkillCard, { path: '/skills/cold-email-drafting' }))
    await settle()
    await wrapper.find('[data-testid="skill-input-campaign"]').setValue('q4')
    await wrapper.find('[data-testid="skill-run-button"]').trigger('click')
    await settle()
    expect(wrapper.find('[data-testid="skill-run-result"]').attributes('data-outcome')).toBe('checkout')
    expect(wrapper.find('[data-testid="skill-run-checkout"]').attributes('href')).toContain('/feature-packs')
  })

  it('changes the stage through the store, and asks before going autonomous', async () => {
    mockApi()
    ;({ wrapper } = await mountView(SkillCard, {
      path: '/skills/cold-email-drafting',
      attachTo: document.body,
      setup: () => { useAuthStore().capabilities = ['tenant.manage'] },
    }))
    await settle()
    expect(wrapper.find('[data-testid="pipeline-locked"]').exists()).toBe(false)

    await wrapper.find('[data-testid="pipeline-stage-human_led"]').trigger('click')
    await settle()
    expect(api.put).toHaveBeenCalledWith('/api/skills/cold-email-drafting/pipeline', { stage: 'human_led' })
    expect(wrapper.find('[data-testid="skill-stage-notice"]').text()).toContain('Human-led')
    expect(wrapper.find('[data-testid="pipeline-stage-human_led"]').attributes('aria-checked')).toBe('true')

    api.put.mockClear()
    await wrapper.find('[data-testid="pipeline-stage-autonomous"]').trigger('click')
    await settle()
    const dialog = q('[role="dialog"]')
    expect(dialog).not.toBeNull()
    expect(dialog.textContent).toContain('Go autonomous?')
    expect(dialog.querySelector('[data-testid="skill-autonomous-gate-warning"]')).not.toBeNull()
    expect(api.put).not.toHaveBeenCalled()
    const confirm = Array.from(dialog.querySelectorAll('button')).find((b) => b.textContent.includes('Yes, go autonomous'))
    confirm.click()
    await settle()
    expect(api.put).toHaveBeenCalledWith('/api/skills/cold-email-drafting/pipeline', { stage: 'autonomous' })
  })

  it('locks the stepper without tenant.manage', async () => {
    mockApi()
    ;({ wrapper } = await mountView(SkillCard, { path: '/skills/cold-email-drafting' }))
    await settle()
    expect(wrapper.find('[data-testid="pipeline-locked"]').exists()).toBe(true)
    await wrapper.find('[data-testid="pipeline-stage-human_led"]').trigger('click')
    expect(api.put).not.toHaveBeenCalled()
  })

  it('runs a one-step-further follow-on with inputs mapped from the last run', async () => {
    mockApi()
    ;({ wrapper } = await mountView(SkillCard, { path: '/skills/cold-email-drafting' }))
    await settle()
    await wrapper.find('[data-testid="one-step-run-queue-follow-up"]').trigger('click')
    await settle()
    const call = api.post.mock.calls.find(([url]) => url === '/api/skills/follow-up-drafting/run')
    expect(call).toBeTruthy()
    expect(call[1]).toMatchObject({
      inputs: { campaign: 'q4-founders', segment: 'founders', sequence_id: 'seq_q4_founders' },
      inputs_from: { campaign: 'inputs.campaign', segment: 'inputs.segment', sequence_id: 'outputs.sequence_id' },
      source_run_id: 'run_0915_cold',
      next_step_id: 'queue-follow-up',
    })
    const line = wrapper.find('[data-testid="one-step-result-queue-follow-up"]')
    expect(line.attributes('role')).toBe('status')
    expect(line.find('a').attributes('href')).toContain('/agents/runs/run_new')
  })

  it('opens a brain file in the drawer and saves through the brain store', async () => {
    mockApi()
    api.put.mockImplementation((url) => {
      if (url.startsWith('/api/brain/files/')) return Promise.resolve({ data: { data: { path: 'offer/offer.md', version: 3, status: 'filled' } } })
      return Promise.resolve({ data: { data: {} } })
    })
    ;({ wrapper } = await mountView(SkillCard, { path: '/skills/cold-email-drafting', attachTo: document.body }))
    await settle()
    await wrapper.find('[data-testid="brain-file-open-offer"]').trigger('click')
    await settle()
    expect(api.get).toHaveBeenCalledWith('/api/brain/files/offer/offer.md')
    const editor = q('[data-testid="brain-file-editor"]')
    expect(editor).not.toBeNull()
    expect(editor.value).toContain('# Offer')
    editor.value = '# Offer\n\nComplete now.'
    editor.dispatchEvent(new Event('input', { bubbles: true }))
    await settle()
    q('[data-testid="brain-file-save"]').click()
    await settle()
    expect(api.put).toHaveBeenCalledWith('/api/brain/files/offer/offer.md', { content: '# Offer\n\nComplete now.', base_version: 2 })
    expect(q('[data-testid="brain-file-editor"]')).toBeNull()
    expect(wrapper.find('[data-testid="skill-brain-notice"]').text()).toContain('Saved offer/offer.md')
    // The card's readiness row now reads filled and the run gate opens
    expect(wrapper.find('[data-testid="brain-file-offer"]').attributes('data-status')).toBe('filled')
    expect(wrapper.find('[data-testid="skill-run-blocked-reason"]').exists()).toBe(false)
  })

  it('renders from the fixture with an inline error when the card API fails', async () => {
    api.get.mockRejectedValue(httpError(500))
    api.post.mockResolvedValue({ data: {} })
    ;({ wrapper } = await mountView(SkillCard, { path: '/skills/cold-email-drafting' }))
    await settle()
    expect(wrapper.find('[data-testid="skill-card-error"]').text()).toContain('HTTP 500')
    expect(wrapper.findAll('[data-testid^="skill-section-"]').map((s) => s.attributes('data-testid'))).toEqual(SECTIONS)
  })
})

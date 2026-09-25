// AgentRunDetail view — Vitest + @vue/test-utils with a memory router.
// Questions form when blocked → POST answers; steps timeline; artifacts
// with preview / edit / approval link; next_steps chips as one-click
// follow-on runs; cancel / retry; fixture fallback.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'

vi.mock('../src/services/api.js', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import api from '../src/services/api.js'
import AgentRunDetail from '../src/views/agents/AgentRunDetail.vue'
import runFixture from './fixtures/agent_run.json'
import { mountView, settle, httpError } from './helpers/harness.js'

function mockReads() {
  api.get.mockImplementation((url) => {
    if (url === '/api/agent-runs/run_blocked_01') return Promise.resolve({ data: runFixture.blocked })
    if (url === '/api/agent-runs/run_0915_cold') return Promise.resolve({ data: runFixture.succeeded })
    if (url === '/api/artifacts/art_seq_q4_s1a') return Promise.resolve({ data: runFixture.artifact })
    return Promise.reject(httpError(404))
  })
}

let wrapper
afterEach(() => { wrapper?.unmount(); wrapper = null })
beforeEach(() => {
  vi.resetAllMocks()
  mockReads()
})

describe('AgentRunDetail view', () => {
  it('shows the questions form when blocked and posts the answers', async () => {
    api.post.mockResolvedValueOnce({ data: { data: { id: 'run_blocked_01', status: 'queued', questions: [] } } })
    ;({ wrapper } = await mountView(AgentRunDetail, { path: '/agents/runs/run_blocked_01' }))
    await settle()
    expect(wrapper.find('[data-testid="run-detail-status"]').text()).toBe('blocked')
    const form = wrapper.find('[data-testid="run-questions-form"]')
    expect(form.exists()).toBe(true)
    expect(form.findAll('textarea')).toHaveLength(2)
    expect(form.text()).toContain('What makes you different from the obvious alternative?')
    const submit = wrapper.find('[data-testid="run-answers-submit"]')
    expect(submit.attributes('disabled')).toBeDefined()
    await wrapper.find('[data-testid="run-answer-0"]').setValue('We ship in six weeks.')
    await wrapper.find('[data-testid="run-answer-1"]').setValue('14 founders through the sprint.')
    expect(submit.attributes('disabled')).toBeUndefined()
    await form.find('form').trigger('submit')
    await settle()
    expect(api.post).toHaveBeenCalledWith('/api/agent-runs/run_blocked_01/answers', {
      answers: [
        { path: 'offer/offer.md', section: 'differentiators', text: 'We ship in six weeks.' },
        { path: 'sales/proof.md', section: 'proof', text: '14 founders through the sprint.' },
      ],
    })
    expect(wrapper.find('[data-testid="run-notice"]').text()).toContain('Answers sent')
    expect(wrapper.find('[data-testid="run-questions-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="run-detail-status"]').text()).toBe('queued')
  })

  it('offers Cancel on an active run and Retry on a failed one', async () => {
    api.post.mockResolvedValueOnce({ data: {} })
    ;({ wrapper } = await mountView(AgentRunDetail, { path: '/agents/runs/run_blocked_01' }))
    await settle()
    expect(wrapper.find('[data-testid="run-cancel"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="run-retry"]').exists()).toBe(false)
    await wrapper.find('[data-testid="run-cancel"]').trigger('click')
    await settle()
    expect(api.post).toHaveBeenCalledWith('/api/agent-runs/run_blocked_01/cancel')
    expect(wrapper.find('[data-testid="run-detail-status"]').text()).toBe('cancelled')
    expect(wrapper.find('[data-testid="run-cancel"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="run-retry"]').exists()).toBe(true)
  })

  it('renders steps, artifacts and the cost footer for a finished run', async () => {
    ;({ wrapper } = await mountView(AgentRunDetail, { path: '/agents/runs/run_0915_cold' }))
    await settle()
    expect(wrapper.find('[data-testid="run-questions-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="run-cancel"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-testid^="run-step-"]')).toHaveLength(5)
    expect(wrapper.find('[data-testid="run-step-2"]').text()).toContain('Draft sequence')
    expect(wrapper.findAll('[data-testid^="run-artifact-"]').filter((w) => w.element.tagName === 'LI')).toHaveLength(3)
    expect(wrapper.find('[data-testid="run-artifact-approval-art_seq_q4_s1a"]').attributes('href')).toContain('id=apr_seq_q4')
    expect(wrapper.find('[data-testid="run-cost-usd"]').text()).toBe('$0.42')
    expect(wrapper.find('[data-testid="run-cost"]').text()).toContain('18,400')
    expect(wrapper.find('[data-testid="run-detail-agent"]').attributes('href')).toContain('/agents/growth/workspace')
  })

  it('previews and edits an artifact through PATCH /api/artifacts/{id}', async () => {
    api.patch.mockResolvedValueOnce({ data: { data: { id: 'art_seq_q4_s1a', version: 2 } } })
    ;({ wrapper } = await mountView(AgentRunDetail, { path: '/agents/runs/run_0915_cold' }))
    await settle()
    await wrapper.find('[data-testid="run-artifact-preview-art_seq_q4_s1a"]').trigger('click')
    await settle()
    expect(api.get).toHaveBeenCalledWith('/api/artifacts/art_seq_q4_s1a')
    expect(wrapper.find('[data-testid="run-artifact-preview-body-art_seq_q4_s1a"]').text()).toContain('Most founders I talk to')
    await wrapper.find('[data-testid="run-artifact-edit-art_seq_q4_s1a"]').trigger('click')
    await wrapper.find('[data-testid="run-artifact-edit-subject-art_seq_q4_s1a"]').setValue('Shorter subject')
    await wrapper.find('[data-testid="run-artifact-edit-body-art_seq_q4_s1a"]').setValue('Shorter body.')
    await wrapper.find('[data-testid="run-artifact-save-art_seq_q4_s1a"]').trigger('click')
    await settle()
    const [url, body] = api.patch.mock.calls[0]
    expect(url).toBe('/api/artifacts/art_seq_q4_s1a')
    expect(body.content).toMatchObject({ subject: 'Shorter subject', body: 'Shorter body.' })
    expect(wrapper.find('[data-testid="run-artifact-preview-body-art_seq_q4_s1a"]').text()).toBe('Shorter body.')
  })

  it('renders next_steps as chips and runs a proposed one with one click', async () => {
    api.post.mockResolvedValueOnce({ status: 202, data: { data: { run_id: 'run_follow', status: 'queued' } } })
    ;({ wrapper } = await mountView(AgentRunDetail, { path: '/agents/runs/run_0915_cold' }))
    await settle()
    const chips = wrapper.findAll('[data-testid^="run-next-step-"]').filter((w) => w.element.tagName === 'LI')
    expect(chips).toHaveLength(3)
    expect(wrapper.find('[data-testid="run-next-step-queue-follow-up"]').attributes('data-state')).toBe('proposed')
    // A done step links to its run instead of a Run button
    expect(wrapper.find('[data-testid="run-next-step-link-watch-inbox"]').attributes('href')).toContain('/agents/runs/run_0916_triage')
    expect(wrapper.find('[data-testid="run-next-step-run-watch-inbox"]').exists()).toBe(false)
    // A skipped step can still be run
    expect(wrapper.find('[data-testid="run-next-step-run-tighten-subjects"]').text()).toBe('Run anyway')

    await wrapper.find('[data-testid="run-next-step-run-queue-follow-up"]').trigger('click')
    await settle()
    expect(api.post).toHaveBeenCalledWith('/api/skills/follow-up-drafting/run', {
      inputs: { campaign: 'q4-founders', segment: 'founders', sequence_id: 'seq_q4_founders' },
      source_run_id: 'run_0915_cold',
      next_step_id: 'queue-follow-up',
    })
    const result = wrapper.find('[data-testid="run-next-step-result-queue-follow-up"]')
    expect(result.attributes('role')).toBe('status')
    expect(result.find('a').attributes('href')).toContain('/agents/runs/run_follow')
    expect(wrapper.find('[data-testid="run-next-step-queue-follow-up"]').attributes('data-state')).toBe('running')
    expect(wrapper.find('[data-testid="run-next-step-link-queue-follow-up"]').attributes('href')).toContain('/agents/runs/run_follow')
  })

  it('reloads when the route id changes', async () => {
    let router
    ;({ wrapper, router } = await mountView(AgentRunDetail, { path: '/agents/runs/run_blocked_01' }))
    await settle()
    await router.push('/agents/runs/run_0915_cold')
    await settle()
    expect(api.get).toHaveBeenCalledWith('/api/agent-runs/run_0915_cold')
    expect(wrapper.find('[data-testid="run-detail-status"]').text()).toBe('succeeded')
  })

  it('renders from the fixture with an inline error when the API fails', async () => {
    api.get.mockRejectedValue(httpError(500))
    ;({ wrapper } = await mountView(AgentRunDetail, { path: '/agents/runs/run_blocked_01' }))
    await settle()
    expect(wrapper.find('[data-testid="run-detail-error"]').text()).toContain('HTTP 500')
    expect(wrapper.find('[data-testid="run-questions-form"]').exists()).toBe(true)
  })
})

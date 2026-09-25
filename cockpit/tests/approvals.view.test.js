// Approvals view — smoke test for the agent_artifact wiring:
// pending artifacts from one skill+run become a batch card, a lone
// artifact renders the preview + inline edit in the detail pane, ?id=
// deep-links the selection, and edit → PATCH /api/artifacts → approve.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'

vi.mock('../src/services/api.js', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import api from '../src/services/api.js'
import Approvals from '../src/views/Approvals.vue'
import fixture from './fixtures/approvals_agent_artifact.json'
import { mountView, settle } from './helpers/harness.js'

let wrapper
afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  document.body.innerHTML = ''
})
beforeEach(() => {
  vi.resetAllMocks()
  api.get.mockResolvedValue({ data: { data: JSON.parse(JSON.stringify(fixture.data)) } })
})

describe('Approvals view · agent artifacts', () => {
  it('groups the three cold-email drafts into one batch card and leaves the lone post out', async () => {
    ;({ wrapper } = await mountView(Approvals, { path: '/approvals' }))
    await settle()
    const batches = wrapper.findAll('[data-testid^="approval-batch-"]').filter((w) => w.element.tagName === 'ARTICLE')
    expect(batches).toHaveLength(1)
    expect(batches[0].attributes('data-testid')).toBe('approval-batch-cold-email-drafting-run_0915_cold')
    expect(batches[0].findAll('[data-testid^="batch-item-"]')).toHaveLength(3)
    // The social post (single item) stays in the queue and detail pane, not a batch.
    expect(wrapper.find('[data-testid="approval-item-apr_social_17"]').exists()).toBe(true)
  })

  it('deep-links the selection from ?id= and renders the artifact editor', async () => {
    ;({ wrapper } = await mountView(Approvals, { path: '/approvals?id=apr_social_17' }))
    await settle()
    const detail = wrapper.find('[data-testid="approval-artifact"]')
    expect(detail.exists()).toBe(true)
    expect(wrapper.find('[data-testid="approval-artifact-body"]').element.value).toContain('Eleven of fourteen founders')
    expect(wrapper.find('[data-testid="approval-artifact-subject"]').exists()).toBe(false) // social posts have no subject
    expect(wrapper.find('[data-testid="approval-artifact-run"]').attributes('href')).toContain('/agents/runs/run_0916_social')
    expect(wrapper.find('[data-testid="approval-artifact-save"]').attributes('disabled')).toBeDefined()
  })

  it('saves an inline edit through PATCH /api/artifacts/{id} then approves the new version', async () => {
    api.patch.mockResolvedValueOnce({ data: { data: { id: 'art_social_17', version: 2 } } })
    api.post.mockResolvedValueOnce({ data: { data: { id: 'apr_social_17', status: 'approved' } } })
    ;({ wrapper } = await mountView(Approvals, { path: '/approvals?id=apr_social_17', attachTo: document.body }))
    await settle()
    await wrapper.find('[data-testid="approval-artifact-body"]').setValue('One ask per message. That was the difference.')
    await wrapper.find('[data-testid="approval-artifact-save"]').trigger('click')
    await settle()
    expect(api.patch).toHaveBeenCalledWith('/api/artifacts/art_social_17', {
      content: expect.objectContaining({ body: 'One ask per message. That was the difference.' }),
    })
    expect(wrapper.find('[data-testid="approval-artifact-notice"]').text()).toContain('Saved')

    await wrapper.find('[data-testid="approval-approve-apr_social_17"]').trigger('click')
    await settle()
    const dialog = document.body.querySelector('[data-testid="approval-soft-dialog"]') || document.body.querySelector('[role="dialog"]')
    expect(dialog).not.toBeNull()
    Array.from(dialog.querySelectorAll('button')).find((b) => b.textContent.trim() === 'Approve').click()
    await settle()
    expect(api.post).toHaveBeenCalledWith('/api/approvals/apr_social_17/approve', {})
  })

  it('batch approve-all approves each pending draft in turn and reports the count', async () => {
    api.post.mockImplementation((url) => Promise.resolve({ data: { data: { id: url.split('/')[3], status: 'approved' } } }))
    ;({ wrapper } = await mountView(Approvals, { path: '/approvals' }))
    await settle()
    await wrapper.find('[data-testid="batch-approve-all-cold-email-drafting-run_0915_cold"]').trigger('click')
    await settle(4)
    const approved = api.post.mock.calls.map(([url]) => url)
    expect(approved).toEqual([
      '/api/approvals/apr_seq_q4_s1/approve',
      '/api/approvals/apr_seq_q4_s2/approve',
      '/api/approvals/apr_seq_q4_s3/approve',
    ])
    expect(wrapper.find('[data-testid="approval-batch-notice"]').text()).toBe('Approved 3 of 3.')
    // Once approved they leave the batch section
    expect(wrapper.find('[data-testid="approval-batches"]').exists()).toBe(false)
  })
})

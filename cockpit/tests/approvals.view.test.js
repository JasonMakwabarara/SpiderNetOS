// Approvals view — batch grouping and version handling over the hand-written
// fixture. The agent draft editor itself is tested in approvals.contract.test.js
// against the approval the backend really returns: this fixture's
// `context.artifact` is a shape the backend never produced, and the two tests
// that exercised the editor through it passed while the page could not work.
// They were removed when the editor moved to `context.payload`.
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

  it('approves the version the page is showing, and reloads when that version is stale', async () => {
    const data = JSON.parse(JSON.stringify(fixture.data))
    data.find((a) => a.id === 'apr_social_17').version_hash = 'sha256:as-shown'
    api.get.mockResolvedValue({ data: { data } })
    api.post.mockRejectedValueOnce({ response: { status: 409, data: { error: 'Approval changed after it was shown to you.', reason: 'version_stale' } } })
    ;({ wrapper } = await mountView(Approvals, { path: '/approvals?id=apr_social_17', attachTo: document.body }))
    await settle()
    const loadsBefore = api.get.mock.calls.length

    await wrapper.find('[data-testid="approval-approve-apr_social_17"]').trigger('click')
    await settle()
    const dialog = document.body.querySelector('[data-testid="approval-soft-dialog"]') || document.body.querySelector('[role="dialog"]')
    Array.from(dialog.querySelectorAll('button')).find((b) => b.textContent.trim() === 'Approve').click()
    await settle()

    expect(api.post).toHaveBeenCalledWith('/api/approvals/apr_social_17/approve', { version_hash: 'sha256:as-shown' })
    // Stale: the list is fetched again so the current version is what is shown.
    expect(api.get.mock.calls.length).toBeGreaterThan(loadsBefore)
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

// Approvals view against the approval the backend actually returns.
//
// fixtures/contract/approvals_pending_agent_artifact.json is not written by
// hand: backend/tests/Feature/Agents/ApprovalApiContractTest produces it from
// a real run and the real GET /api/approvals response, and fails when the API
// drifts from it. The hand-written fixture this page used to be tested
// against carried the draft under `context.artifact`, a shape the backend
// never produced — every test passed while the page could not work.
//
// What these tests hold the page to: it shows the payload the approval binds
// (context.payload), saves an edit as the text the API takes with the version
// it started from, and decides on the version of what is on screen — never a
// version that arrived afterwards, and never by retrying on its own. And when
// the API accepts a decision whose effect has not finished (202, generated the
// same way into approvals_decide_unfinished_action.json), the page says so
// rather than letting "approved" read as "applied".
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'

vi.mock('../src/services/api.js', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import api from '../src/services/api.js'
import Approvals from '../src/views/Approvals.vue'
import { useApprovalsStore } from '../src/stores/approvals.js'
import contract from './fixtures/contract/approvals_pending_agent_artifact.json'
import unfinished from './fixtures/contract/approvals_decide_unfinished_action.json'
import { mountView, settle } from './helpers/harness.js'

const approval = contract.data[0]
const payload = JSON.parse(approval.context).payload

function listed(overrides = {}) {
  return { data: { data: [{ ...JSON.parse(JSON.stringify(approval)), ...overrides }] } }
}

async function confirmDialog(label) {
  await settle()
  const dialog = document.body.querySelector('[role="dialog"]')
  expect(dialog).not.toBeNull()
  Array.from(dialog.querySelectorAll('button')).find((b) => b.textContent.trim() === label).click()
  await settle()
}

let wrapper
afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  document.body.innerHTML = ''
})
beforeEach(() => {
  vi.resetAllMocks()
  api.get.mockResolvedValue(listed())
})

describe('Approvals view · the real agent_artifact approval', () => {
  it('shows every step of the payload the approval binds, and where it goes', async () => {
    ;({ wrapper } = await mountView(Approvals, { path: `/approvals?id=${approval.id}` }))
    await settle()

    expect(wrapper.find('[data-testid="approval-artifact"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="approval-artifact-destination"]').text()).toContain(payload.destination.campaign_key)
    payload.steps.forEach((step, i) => {
      expect(wrapper.find(`[data-testid="approval-artifact-subject-${i}"]`).element.value).toBe(step.subject)
      expect(wrapper.find(`[data-testid="approval-artifact-body-${i}"]`).element.value).toBe(step.body)
    })
    // Variant b's own subject is shown, not hidden behind variant a.
    expect(wrapper.find('[data-testid="approval-artifact-variants-0"]').text()).toContain(payload.steps[0].variants[1].subject)
    // The row has no title field; it is labelled by what the requester wrote.
    expect(wrapper.find(`[data-testid="approval-item-${approval.id}"]`).text()).toContain('Spring Launch')
  })

  it('saves an edit as text with the version it started from, then shows the server\'s version', async () => {
    api.patch.mockResolvedValueOnce({ data: { data: { id: payload.steps[0].artifact_id, approval_version_hash: 'sha256:after-edit' } } })
    ;({ wrapper } = await mountView(Approvals, { path: `/approvals?id=${approval.id}`, attachTo: document.body }))
    await settle()
    api.get.mockResolvedValue(listed({ version_hash: 'sha256:after-edit' }))

    await wrapper.find('[data-testid="approval-artifact-subject-0"]').setValue('A shorter opener')
    await wrapper.find('[data-testid="approval-artifact-body-0"]').setValue('Slow follow-up costs agencies good work.')
    await wrapper.find('[data-testid="approval-artifact-save-0"]').trigger('click')
    await settle()

    expect(api.patch).toHaveBeenCalledWith(`/api/artifacts/${payload.steps[0].artifact_id}`, {
      content: 'Subject: A shorter opener\n\nSlow follow-up costs agencies good work.',
      expected_version: approval.version_hash,
    })
    // The page reloads what the server holds rather than keeping its own copy.
    expect(api.get).toHaveBeenCalledTimes(2)

    await wrapper.find(`[data-testid="approval-approve-${approval.id}"]`).trigger('click')
    api.post.mockResolvedValueOnce({ data: { data: { id: approval.id, status: 'approved' } } })
    await confirmDialog('Approve')
    expect(api.post).toHaveBeenCalledWith(`/api/approvals/${approval.id}/approve`, { version_hash: 'sha256:after-edit' })
  })

  it('decides on the version on screen even if a newer one arrives while the dialog is open', async () => {
    ;({ wrapper } = await mountView(Approvals, { path: `/approvals?id=${approval.id}`, attachTo: document.body }))
    await settle()

    await wrapper.find(`[data-testid="approval-approve-${approval.id}"]`).trigger('click')
    await settle()
    // A refresh lands while the approver is reading the dialog.
    useApprovalsStore().handleApprovalUpdated({ id: approval.id, version_hash: 'sha256:arrived-later' })
    api.post.mockResolvedValueOnce({ data: { data: { id: approval.id, status: 'approved' } } })
    await confirmDialog('Approve')

    expect(api.post).toHaveBeenCalledWith(`/api/approvals/${approval.id}/approve`, { version_hash: approval.version_hash })
  })

  it('on a stale refusal shows the current version and does not retry', async () => {
    ;({ wrapper } = await mountView(Approvals, { path: `/approvals?id=${approval.id}`, attachTo: document.body }))
    await settle()
    api.post.mockRejectedValueOnce({ response: { status: 409, data: { error: 'Approval changed after it was shown to you.', reason: 'version_stale' } } })
    api.get.mockResolvedValue(listed({ version_hash: 'sha256:current' }))

    await wrapper.find(`[data-testid="approval-approve-${approval.id}"]`).trigger('click')
    await confirmDialog('Approve')

    expect(api.post).toHaveBeenCalledTimes(1)
    expect(api.get).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-testid="approval-artifact-notice"]').text()).toContain('changed after you opened it')
  })

  it('says when a decision is recorded but applying it has not finished', async () => {
    ;({ wrapper } = await mountView(Approvals, { path: `/approvals?id=${approval.id}`, attachTo: document.body }))
    await settle()
    expect(unfinished.status).toBe(202)
    api.post.mockResolvedValueOnce({ status: unfinished.status, data: unfinished.data })

    await wrapper.find(`[data-testid="approval-approve-${approval.id}"]`).trigger('click')
    await confirmDialog('Approve')

    const notice = wrapper.find('[data-testid="approval-batch-notice"]').text()
    expect(unfinished.data.action.status).toBe('pending')
    expect(notice).toContain('Decision recorded')
    expect(notice).toContain('has not finished')
    expect(notice).toContain('waiting to be retried')
    // Nothing here promises the retry will happen by itself.
    expect(notice).not.toContain('automatically')
  })

  it('rejects on the version shown', async () => {
    ;({ wrapper } = await mountView(Approvals, { path: `/approvals?id=${approval.id}`, attachTo: document.body }))
    await settle()
    api.post.mockResolvedValueOnce({ data: { data: { id: approval.id, status: 'rejected' } } })

    await wrapper.find(`[data-testid="approval-reject-${approval.id}"]`).trigger('click')
    await settle()
    expect(document.body.querySelector('[role="dialog"]').textContent).not.toContain('undefined')
    await confirmDialog('Reject')

    expect(api.post).toHaveBeenCalledWith(`/api/approvals/${approval.id}/reject`, { version_hash: approval.version_hash })
  })
})

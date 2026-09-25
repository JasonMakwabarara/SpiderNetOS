// ApprovalBatchCard component — Vitest + @vue/test-utils.
// One card per skill+run: artifact previews (subject/body per step),
// "what changed" line, approve / reject / approve-all emits, inline edit
// before approve, and the keyboard path (j/k move, a approve, r reject, e edit).
import { describe, it, expect, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import ApprovalBatchCard from '../src/components/approvals/ApprovalBatchCard.vue'
import fixture from './fixtures/approvals_agent_artifact.json'
import { makeRouter } from './helpers/harness.js'

const items = fixture.data.filter((a) => a.resource_type === 'agent_artifact' && a.context.run_id === 'run_0915_cold')
const group = { key: 'cold-email-drafting-run_0915_cold', skill_slug: 'cold-email-drafting', skill_name: 'Cold Email Drafting', run_id: 'run_0915_cold', items }

let wrapper
afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  document.body.innerHTML = ''
})

async function mountCard(props = {}) {
  const router = makeRouter()
  await router.push('/approvals')
  await router.isReady()
  wrapper = mount(ApprovalBatchCard, { props: { group, ...props }, global: { plugins: [router] }, attachTo: document.body })
  return wrapper
}

describe('ApprovalBatchCard component', () => {
  it('renders one row per draft with subject, body, step label and what changed', async () => {
    await mountCard()
    expect(wrapper.find('[data-testid="approval-batch-cold-email-drafting-run_0915_cold"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Cold Email Drafting')
    expect(wrapper.find('[data-testid="batch-run-cold-email-drafting-run_0915_cold"]').attributes('href')).toContain('/agents/runs/run_0915_cold')
    expect(wrapper.findAll('[data-testid^="batch-item-"]')).toHaveLength(3)
    expect(wrapper.find('[data-testid="batch-subject-apr_seq_q4_s1"]').text()).toContain("Quick one about {{company}}'s pipeline")
    expect(wrapper.find('[data-testid="batch-body-apr_seq_q4_s1"]').text()).toContain('Most founders I talk to')
    expect(wrapper.find('[data-testid="batch-item-apr_seq_q4_s1"]').text()).toContain('Step 1 · A')
    expect(wrapper.find('[data-testid="batch-changed-apr_seq_q4_s1"]').text()).toContain('Opener shortened')
    expect(wrapper.find('[data-testid="batch-changed-apr_seq_q4_s2"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="batch-approve-all-cold-email-drafting-run_0915_cold"]').text()).toContain('Approve all 3')
  })

  it('emits approve / reject per item and approve-all with the pending items', async () => {
    await mountCard()
    await wrapper.find('[data-testid="batch-approve-apr_seq_q4_s1"]').trigger('click')
    await wrapper.find('[data-testid="batch-reject-apr_seq_q4_s2"]').trigger('click')
    await wrapper.find('[data-testid="batch-approve-all-cold-email-drafting-run_0915_cold"]').trigger('click')
    expect(wrapper.emitted('approve')[0][0].id).toBe('apr_seq_q4_s1')
    expect(wrapper.emitted('reject')[0][0].id).toBe('apr_seq_q4_s2')
    expect(wrapper.emitted('approve-all')[0][0].map((i) => i.id)).toEqual(['apr_seq_q4_s1', 'apr_seq_q4_s2', 'apr_seq_q4_s3'])
  })

  it('edits inline and emits edit(item, content) before approving', async () => {
    await mountCard()
    await wrapper.find('[data-testid="batch-edit-apr_seq_q4_s3"]').trigger('click')
    const subject = wrapper.find('[data-testid="batch-edit-subject-apr_seq_q4_s3"]')
    const body = wrapper.find('[data-testid="batch-edit-body-apr_seq_q4_s3"]')
    expect(subject.element.value).toBe('Closing the loop')
    await subject.setValue('Last note')
    await body.setValue('Short and done.')
    await wrapper.find('[data-testid="batch-edit-save-apr_seq_q4_s3"]').trigger('click')
    const [item, content] = wrapper.emitted('edit')[0]
    expect(item.id).toBe('apr_seq_q4_s3')
    expect(content).toEqual({ subject: 'Last note', body: 'Short and done.' })
    expect(wrapper.find('[data-testid="batch-edit-body-apr_seq_q4_s3"]').exists()).toBe(false)
  })

  it('walks the rows with j/k and approves / rejects / edits the focused one', async () => {
    await mountCard()
    const card = wrapper.find('[data-testid="approval-batch-cold-email-drafting-run_0915_cold"]')
    const first = wrapper.find('[data-testid="batch-item-apr_seq_q4_s1"]')
    first.element.focus()
    await first.trigger('focus')
    await card.trigger('keydown', { key: 'j' })
    expect(document.activeElement).toBe(wrapper.find('[data-testid="batch-item-apr_seq_q4_s2"]').element)
    await card.trigger('keydown', { key: 'a' })
    expect(wrapper.emitted('approve')[0][0].id).toBe('apr_seq_q4_s2')
    await card.trigger('keydown', { key: 'j' })
    await card.trigger('keydown', { key: 'r' })
    expect(wrapper.emitted('reject')[0][0].id).toBe('apr_seq_q4_s3')
    // wraps around
    await card.trigger('keydown', { key: 'j' })
    expect(document.activeElement).toBe(first.element)
    await card.trigger('keydown', { key: 'k' })
    expect(document.activeElement).toBe(wrapper.find('[data-testid="batch-item-apr_seq_q4_s3"]').element)
    await card.trigger('keydown', { key: 'e' })
    expect(wrapper.find('[data-testid="batch-edit-body-apr_seq_q4_s3"]').exists()).toBe(true)
    // Keys typed inside the editor never trigger actions
    const emittedBefore = (wrapper.emitted('approve') || []).length
    await wrapper.find('[data-testid="batch-edit-body-apr_seq_q4_s3"]').trigger('keydown', { key: 'a' })
    expect((wrapper.emitted('approve') || []).length).toBe(emittedBefore)
  })

  it('disables the actions while busy and hides them on non-pending rows', async () => {
    const decided = { ...group, items: [{ ...items[0], status: 'approved' }, items[1]] }
    await mountCard({ group: decided, busy: true })
    expect(wrapper.find('[data-testid="batch-approve-apr_seq_q4_s1"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="batch-approve-apr_seq_q4_s2"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-testid="batch-approve-all-cold-email-drafting-run_0915_cold"]').attributes('disabled')).toBeDefined()
  })
})

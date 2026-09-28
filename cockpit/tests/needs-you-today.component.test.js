// NeedsYouToday component — Vitest + @vue/test-utils with pinia + a
// memory router. Seven-item cap, one action per item (a real link), the
// one-more-question chip (answer via Atlas prefill / skip), what ran
// overnight, dismiss, and the fixture fallback with an inline error.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'

vi.mock('../src/services/api.js', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import api from '../src/services/api.js'
import NeedsYouToday from '../src/components/today/NeedsYouToday.vue'
import todayFixture from './fixtures/today.json'
import { mountView, settle, httpError } from './helpers/harness.js'

let wrapper
afterEach(() => { wrapper?.unmount(); wrapper = null })
beforeEach(() => {
  vi.resetAllMocks()
  api.get.mockResolvedValue({ data: todayFixture })
})

describe('NeedsYouToday component', () => {
  it('fetches /api/today on mount and renders at most seven items with one action each', async () => {
    ;({ wrapper } = await mountView(NeedsYouToday, { path: '/' }))
    await settle()
    expect(api.get).toHaveBeenCalledWith('/api/today')
    const section = wrapper.find('[data-testid="needs-you-today"]')
    expect(section.element.tagName).toBe('SECTION')
    expect(wrapper.find(`#${section.attributes('aria-labelledby')}`).text()).toContain('7 things need a decision')
    const items = wrapper.findAll('[data-testid^="today-item-"]')
    expect(items).toHaveLength(7)
    for (const item of items) {
      const actions = item.findAll('[data-testid^="today-action-"]')
      expect(actions).toHaveLength(1)
      expect(actions[0].element.tagName).toBe('A')
      expect(actions[0].attributes('href')).toBeTruthy()
    }
    expect(wrapper.find('[data-testid="today-action-t_apr_seq_q4"]').attributes('href')).toContain('/approvals?id=apr_seq_q4')
    expect(wrapper.find('[data-testid="today-item-t_apr_seq_q4"]').attributes('data-kind')).toBe('approval')
    expect(wrapper.find('[data-testid="today-hidden"]').text()).toContain('1 more')
    expect(wrapper.find('[data-testid="today-money"]').text()).toContain('8,740')
  })

  it('renders what ran overnight with links', async () => {
    ;({ wrapper } = await mountView(NeedsYouToday, { path: '/' }))
    await settle()
    const overnight = wrapper.find('[data-testid="today-overnight"]')
    expect(overnight.text()).toContain('What ran overnight')
    expect(wrapper.findAll('[data-testid^="today-overnight-"]')).toHaveLength(3)
    expect(wrapper.find('[data-testid="today-overnight-run_0916_triage"]').find('a').attributes('href')).toContain('/agents/runs/run_0916_triage')
    expect(wrapper.find('[data-testid="today-overnight-run_0915_research"]').text()).toContain('Failed on the LinkedIn connector')
  })

  it('shows the one-more-question chip with Answer (Atlas prefill) and Skip', async () => {
    ;({ wrapper } = await mountView(NeedsYouToday, { path: '/' }))
    await settle()
    const chip = wrapper.find('[data-testid="today-question"]')
    expect(chip.text()).toContain('What makes you different from the obvious alternative?')
    const answer = wrapper.find('[data-testid="today-question-answer"]')
    expect(answer.element.tagName).toBe('A')
    const href = decodeURIComponent(answer.attributes('href'))
    expect(href).toContain('/atlas')
    expect(href).toContain('hannah=1')
    expect(href).toContain('offer/offer.md')
    expect(href).not.toContain('(or say skip)')
    await wrapper.find('[data-testid="today-question-skip"]').trigger('click')
    expect(wrapper.find('[data-testid="today-question"]').exists()).toBe(false)
  })

  it('dismisses an item and lets the eighth one in', async () => {
    ;({ wrapper } = await mountView(NeedsYouToday, { path: '/' }))
    await settle()
    await wrapper.find('[data-testid="today-dismiss-t_apr_seq_q4"]').trigger('click')
    expect(wrapper.find('[data-testid="today-item-t_apr_seq_q4"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-testid^="today-item-"]')).toHaveLength(7)
    expect(wrapper.find('[data-testid="today-item-t_awareness_bounce"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="today-hidden"]').exists()).toBe(false)
  })

  it('renders the fixture with an inline error when /api/today fails', async () => {
    api.get.mockRejectedValue(httpError(404))
    ;({ wrapper } = await mountView(NeedsYouToday, { path: '/' }))
    await settle()
    expect(wrapper.find('[data-testid="today-error"]').text()).toContain('HTTP 404')
    expect(wrapper.findAll('[data-testid^="today-item-"]')).toHaveLength(7)
  })

  it('shows the all-clear state when nothing needs you', async () => {
    api.get.mockResolvedValue({ data: { data: { date: '2026-09-17', items: [], overnight: [] } } })
    ;({ wrapper } = await mountView(NeedsYouToday, { path: '/' }))
    await settle()
    expect(wrapper.find('[data-testid="today-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="today-question"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="today-overnight"]').exists()).toBe(false)
  })
})

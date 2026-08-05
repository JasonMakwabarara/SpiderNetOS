// BudgetBar component — Vitest + @vue/test-utils.
// Budget vs actual meter: fill width tracks the burn percentage (capped
// at 100%), threshold coloring flips at >90% (amber) and >100% (danger).
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import BudgetBar from '../src/components/financial/BudgetBar.vue'

function fill(wrapper) {
  return wrapper.find('[data-testid="budget-bar-fill"]')
}

describe('BudgetBar component', () => {
  it('renders the fill width as the burn percentage', () => {
    const wrapper = mount(BudgetBar, { props: { label: 'Travel', budget: 1000, actual: 500 } })
    expect(fill(wrapper).attributes('style')).toContain('width: 50%')
    expect(fill(wrapper).classes()).toContain('bb-ok')
  })

  it('turns amber above 90% (95% burn)', () => {
    const wrapper = mount(BudgetBar, { props: { label: 'Travel', budget: 1000, actual: 950 } })
    expect(fill(wrapper).attributes('style')).toContain('width: 95%')
    expect(fill(wrapper).classes()).toContain('bb-amber')
    expect(fill(wrapper).classes()).not.toContain('bb-danger')
    expect(wrapper.find('[data-testid="budget-bar-near"]').exists()).toBe(true)
  })

  it('turns danger above 100% and caps the width (110% burn)', () => {
    const wrapper = mount(BudgetBar, { props: { label: 'Travel', budget: 1000, actual: 1100 } })
    expect(fill(wrapper).attributes('style')).toContain('width: 100%')
    expect(fill(wrapper).classes()).toContain('bb-danger')
    expect(wrapper.find('[data-testid="budget-bar-pct"]').text()).toBe('110%')
    expect(wrapper.find('[data-testid="budget-bar-over"]').exists()).toBe(true)
  })

  it('renders mono-formatted amounts', () => {
    const wrapper = mount(BudgetBar, { props: { label: 'Software', budget: '2500.00', actual: '1234.5' } })
    expect(wrapper.find('[data-testid="budget-bar-actual"]').text()).toBe('1,234.50')
    expect(wrapper.find('[data-testid="budget-bar-budget"]').text()).toBe('2,500.00')
    // Amounts live inside a .mono span per cockpit style
    expect(wrapper.find('.mono').exists()).toBe(true)
  })

  it('handles a zero budget without exploding (0% fill, ok color)', () => {
    const wrapper = mount(BudgetBar, { props: { label: 'Misc', budget: 0, actual: 300 } })
    expect(fill(wrapper).attributes('style')).toContain('width: 0%')
    expect(fill(wrapper).classes()).toContain('bb-ok')
  })
})

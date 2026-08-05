// AgingWidget component — Vitest + @vue/test-utils.
// Hand-rolled SVG bars: five fixed buckets, widths proportional to the
// largest bucket amount.
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import AgingWidget from '../src/components/financial/AgingWidget.vue'
import fixture from './fixtures/aging_report.json'

const BUCKET_KEYS = ['current', '1_30', '31_60', '61_90', '90_plus']

function barWidth(wrapper, key) {
  return parseFloat(wrapper.find(`[data-testid="aging-bar-${key}"]`).attributes('width'))
}

describe('AgingWidget component', () => {
  it('renders all 5 buckets from the fixture', () => {
    const wrapper = mount(AgingWidget, { props: { aging: fixture } })
    for (const key of BUCKET_KEYS) {
      expect(wrapper.find(`[data-testid="aging-bucket-${key}"]`).exists()).toBe(true)
      expect(wrapper.find(`[data-testid="aging-bar-${key}"]`).exists()).toBe(true)
    }
    // Exactly five bars — no phantom buckets.
    expect(wrapper.findAll('[data-testid^="aging-bar-"]')).toHaveLength(5)
  })

  it('bar widths are proportional to bucket amounts', () => {
    const wrapper = mount(AgingWidget, { props: { aging: fixture } })
    const current = barWidth(wrapper, 'current') // 12500
    const b1_30 = barWidth(wrapper, '1_30')      // 8200
    const b90 = barWidth(wrapper, '90_plus')     // 500

    // Largest bucket takes the widest bar.
    expect(current).toBeGreaterThan(b1_30)
    expect(b1_30).toBeGreaterThan(b90)

    // Ratios track the fixture amounts (within rounding tolerance).
    expect(b1_30 / current).toBeCloseTo(8200 / 12500, 2)
    expect(b90 / current).toBeCloseTo(500 / 12500, 2)
  })

  it('shows mono-formatted bucket amounts and the total', () => {
    const wrapper = mount(AgingWidget, { props: { aging: fixture } })
    expect(wrapper.find('[data-testid="aging-amount-current"]').text()).toBe('12,500.00')
    expect(wrapper.find('[data-testid="aging-total"]').text()).toBe('27,100.00')
  })

  it('renders zero-width bars when there is no data', () => {
    const wrapper = mount(AgingWidget, { props: { aging: null } })
    expect(wrapper.findAll('[data-testid^="aging-bar-"]')).toHaveLength(5)
    for (const key of BUCKET_KEYS) {
      expect(barWidth(wrapper, key)).toBe(0)
    }
  })
})

// BrainReadiness component — Vitest + @vue/test-utils.
// ✓/◐/○ rows per brain file, key derivation from path, Open file / Ask
// Atlas emits, summary + pill counts, empty state.
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import BrainReadiness from '../src/components/brain/BrainReadiness.vue'

const files = [
  { key: 'offer', path: 'brain/offer.md', title: 'Offer', status: 'filled', required: true },
  { key: 'icp', path: 'brain/icp.md', title: 'Ideal customer', status: 'partial', required: true, ask_prompt: 'Help me define my ICP' },
  { path: 'brain/pricing.md', title: 'Pricing', status: 'missing' },
]

describe('BrainReadiness component', () => {
  it('renders one row per file with the right glyph and status', () => {
    const w = mount(BrainReadiness, { props: { files } })
    expect(w.find('[data-testid="brain-file-offer"]').attributes('data-status')).toBe('filled')
    expect(w.find('[data-testid="brain-file-glyph-offer"]').text()).toBe('✓')
    expect(w.find('[data-testid="brain-file-icp"]').attributes('data-status')).toBe('partial')
    expect(w.find('[data-testid="brain-file-glyph-icp"]').text()).toBe('◐')
    // No explicit key → derived from the path basename
    expect(w.find('[data-testid="brain-file-pricing"]').attributes('data-status')).toBe('missing')
    expect(w.find('[data-testid="brain-file-glyph-pricing"]').text()).toBe('○')
  })

  it('treats unknown statuses as missing', () => {
    const w = mount(BrainReadiness, { props: { files: [{ key: 'x', title: 'X', status: 'weird' }] } })
    expect(w.find('[data-testid="brain-file-x"]').attributes('data-status')).toBe('missing')
  })

  it('flags required files and counts readiness in the pill + summary', () => {
    const w = mount(BrainReadiness, { props: { files } })
    expect(w.find('[data-testid="brain-file-required-offer"]').exists()).toBe(true)
    expect(w.find('[data-testid="brain-file-required-pricing"]').exists()).toBe(false)
    expect(w.find('[data-testid="brain-readiness-pill"]').text()).toBe('1/3 ready')
    expect(w.find('[data-testid="brain-readiness-summary"]').text()).toContain('1 required file still needs filling')
  })

  it('emits open(file) from "Open file"', async () => {
    const w = mount(BrainReadiness, { props: { files } })
    await w.find('[data-testid="brain-file-open-icp"]').trigger('click')
    expect(w.emitted('open')).toHaveLength(1)
    // Props arrive through a reactive proxy, so compare by value not identity.
    expect(w.emitted('open')[0][0]).toEqual(files[1])
  })

  it('emits ask(file) from "Ask Atlas" and hides it for filled files', async () => {
    const w = mount(BrainReadiness, { props: { files } })
    expect(w.find('[data-testid="brain-file-ask-offer"]').exists()).toBe(false)
    await w.find('[data-testid="brain-file-ask-icp"]').trigger('click')
    expect(w.emitted('ask')[0][0]).toEqual(files[1])
    expect(w.emitted('ask')[0][0].ask_prompt).toBe('Help me define my ICP')
  })

  it('says so when every file is filled', () => {
    const w = mount(BrainReadiness, { props: { files: files.map((f) => ({ ...f, status: 'filled' })) } })
    expect(w.find('[data-testid="brain-readiness-pill"]').classes()).toContain('sn-pill-success')
    expect(w.find('[data-testid="brain-readiness-summary"]').text()).toContain('Every file this needs is filled in')
  })

  it('renders an empty state without files', () => {
    const w = mount(BrainReadiness)
    expect(w.find('[data-testid="brain-readiness-empty"]').exists()).toBe(true)
    expect(w.find('[data-testid="brain-readiness-pill"]').exists()).toBe(false)
  })
})

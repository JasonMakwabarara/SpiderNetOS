// PipelineStepper component — Vitest + @vue/test-utils.
// WAI-ARIA radio group: aria-checked mirrors `stage`, roving tabindex,
// arrow keys move + emit, Home/End, locked when canChange=false.
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import PipelineStepper from '../src/components/skills/PipelineStepper.vue'

const stage = (w, id) => w.find(`[data-testid="pipeline-stage-${id}"]`)

describe('PipelineStepper component', () => {
  it('renders the three stages as a radiogroup with the current stage checked', () => {
    const w = mount(PipelineStepper, { props: { stage: 'assisted' } })
    const group = w.find('[role="radiogroup"]')
    expect(group.exists()).toBe(true)
    expect(w.findAll('[role="radio"]')).toHaveLength(3)
    expect(stage(w, 'human_led').attributes('aria-checked')).toBe('false')
    expect(stage(w, 'assisted').attributes('aria-checked')).toBe('true')
    expect(stage(w, 'autonomous').attributes('aria-checked')).toBe('false')
    // Roving tabindex: only the checked radio is in the tab order
    expect(stage(w, 'assisted').attributes('tabindex')).toBe('0')
    expect(stage(w, 'human_led').attributes('tabindex')).toBe('-1')
  })

  it('emits change on click and ignores re-selecting the current stage', async () => {
    const w = mount(PipelineStepper, { props: { stage: 'human_led' } })
    await stage(w, 'human_led').trigger('click')
    expect(w.emitted('change')).toBeUndefined()
    await stage(w, 'autonomous').trigger('click')
    expect(w.emitted('change')).toEqual([['autonomous']])
  })

  it('moves with arrow keys and wraps around', async () => {
    const w = mount(PipelineStepper, { props: { stage: 'human_led' }, attachTo: document.body })
    await stage(w, 'human_led').trigger('keydown', { key: 'ArrowRight' })
    expect(w.emitted('change')[0]).toEqual(['assisted'])
    await stage(w, 'human_led').trigger('keydown', { key: 'ArrowLeft' })
    expect(w.emitted('change')[1]).toEqual(['autonomous'])
    await stage(w, 'human_led').trigger('keydown', { key: 'End' })
    expect(w.emitted('change')[2]).toEqual(['autonomous'])
    await stage(w, 'human_led').trigger('keydown', { key: 'ArrowDown' })
    expect(w.emitted('change')[3]).toEqual(['assisted'])
    w.unmount()
  })

  it('supports Home and Enter/Space selection', async () => {
    const w = mount(PipelineStepper, { props: { stage: 'autonomous' } })
    await stage(w, 'autonomous').trigger('keydown', { key: 'Home' })
    expect(w.emitted('change')[0]).toEqual(['human_led'])
    await stage(w, 'assisted').trigger('keydown', { key: 'Enter' })
    expect(w.emitted('change')[1]).toEqual(['assisted'])
  })

  it('does nothing when canChange is false and explains why', async () => {
    const w = mount(PipelineStepper, { props: { stage: 'human_led', canChange: false, lockedReason: 'Ask an admin.' } })
    await stage(w, 'autonomous').trigger('click')
    await stage(w, 'human_led').trigger('keydown', { key: 'ArrowRight' })
    expect(w.emitted('change')).toBeUndefined()
    expect(w.find('[data-testid="pipeline-locked"]').text()).toBe('Ask an admin.')
    expect(w.find('[role="radiogroup"]').attributes('aria-disabled')).toBe('true')
  })

  it('shows the inherited pill when the stage comes from the tenant level', () => {
    expect(mount(PipelineStepper, { props: { inherited: true } }).find('[data-testid="pipeline-inherited"]').exists()).toBe(true)
    expect(mount(PipelineStepper).find('[data-testid="pipeline-inherited"]').exists()).toBe(false)
  })
})

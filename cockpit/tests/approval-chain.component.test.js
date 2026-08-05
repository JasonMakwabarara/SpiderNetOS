// ApprovalChain component — Vitest + @vue/test-utils.
// Renders the fixture's multi-stage chain and asserts per-step
// testids, status pills, and metadata.
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ApprovalChain from '../src/components/financial/ApprovalChain.vue'
import fixture from './fixtures/expense_report.json'

const steps = fixture.approval.steps

describe('ApprovalChain component', () => {
  it('renders one node per step with chain-step-N testids', () => {
    const wrapper = mount(ApprovalChain, { props: { steps, currentStep: 1 } })
    expect(wrapper.find('[data-testid="approval-chain"]').exists()).toBe(true)
    steps.forEach((_, i) => {
      expect(wrapper.find(`[data-testid="chain-step-${i}"]`).exists()).toBe(true)
    })
    expect(wrapper.find(`[data-testid="chain-step-${steps.length}"]`).exists()).toBe(false)
  })

  it('applies per-status pill classes', () => {
    const wrapper = mount(ApprovalChain, { props: { steps, currentStep: 1 } })
    const pill = (i) => wrapper.find(`[data-testid="chain-step-${i}-status"]`)

    expect(pill(0).classes()).toContain('sn-pill-success') // approved
    expect(pill(0).text()).toBe('approved')
    expect(pill(1).classes()).toContain('sn-pill-warn')    // pending
    expect(pill(1).text()).toBe('pending')
    expect(pill(2).classes()).toContain('sn-pill')          // queued
    expect(pill(2).classes()).not.toContain('sn-pill-warn')
    expect(pill(2).text()).toBe('queued')
  })

  it('shows approver name, role, and recorded response', () => {
    const wrapper = mount(ApprovalChain, { props: { steps } })
    const first = wrapper.find('[data-testid="chain-step-0"]')
    expect(first.text()).toContain('Ada Lovelace')
    expect(first.text()).toContain('manager')
    expect(first.text()).toContain('Looks fine, receipts to follow.')
  })

  it('renders rejected steps with danger styling', () => {
    const rejected = [{ id: 's1', role: 'finance', approver_name: 'G. Hopper', status: 'rejected' }]
    const wrapper = mount(ApprovalChain, { props: { steps: rejected } })
    expect(wrapper.find('[data-testid="chain-step-0-status"]').classes()).toContain('sn-pill-danger')
  })

  it('renders nothing for an empty chain', () => {
    const wrapper = mount(ApprovalChain, { props: { steps: [] } })
    expect(wrapper.find('[data-testid="chain-step-0"]').exists()).toBe(false)
  })
})

// ProcessList component — Vitest + @vue/test-utils.
// Row rendering, conditional actions (automate / run / stuck), emits with
// the process, busy labels, owner badge tokens and run-dot status tokens.
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ProcessList from '../src/components/map/ProcessList.vue'

const processes = [
  { id: 'p_sop',   name: 'Follow up leads', owner_type: 'founder', has_published_sop: true },
  { id: 'p_flow',  name: 'Send invoices',   owner_type: 'agent', flow_id: 'f_1', last_run_status: 'passed', schedule_cron: 'daily_morning' },
  { id: 'p_stuck', name: 'Chase overdue',   owner_type: 'team',  flow_id: 'f_2', last_run_status: 'failed', needs_attention: true },
  { id: 'p_raw',   name: 'Untouched',       owner_type: 'founder' },
]

describe('ProcessList component', () => {
  it('renders a row per process with the right action buttons', () => {
    const w = mount(ProcessList, { props: { processes } })
    expect(w.findAll('[data-testid^="process-"][data-testid$="p_sop"]').length).toBeGreaterThan(0)
    expect(w.find('[data-testid="process-p_sop"]').exists()).toBe(true)

    // SOP but no flow → Automate only
    expect(w.find('[data-testid="process-automate-p_sop"]').exists()).toBe(true)
    expect(w.find('[data-testid="process-run-p_sop"]').exists()).toBe(false)
    expect(w.find('[data-testid="process-stuck-p_sop"]').exists()).toBe(false)

    // Flow, healthy → Run now only
    expect(w.find('[data-testid="process-run-p_flow"]').exists()).toBe(true)
    expect(w.find('[data-testid="process-automate-p_flow"]').exists()).toBe(false)
    expect(w.find('[data-testid="process-p_flow"]').text()).toContain('daily morning')

    // Needs attention → Stuck only
    expect(w.find('[data-testid="process-stuck-p_stuck"]').exists()).toBe(true)
    expect(w.find('[data-testid="process-run-p_stuck"]').exists()).toBe(false)

    // Nothing published, no flow → no actions
    expect(w.find('[data-testid="process-p_raw"]').findAll('button')).toHaveLength(0)
  })

  it('emits automate / run / escalate with the process', async () => {
    const w = mount(ProcessList, { props: { processes } })
    await w.find('[data-testid="process-automate-p_sop"]').trigger('click')
    await w.find('[data-testid="process-run-p_flow"]').trigger('click')
    await w.find('[data-testid="process-stuck-p_stuck"]').trigger('click')
    // Props arrive through a reactive proxy, so compare by value not identity.
    expect(w.emitted('automate')[0][0]).toEqual(processes[0])
    expect(w.emitted('run')[0][0]).toEqual(processes[1])
    expect(w.emitted('escalate')[0][0]).toEqual(processes[2])
  })

  it('shows busy labels and disables the busy row only', () => {
    const w = mount(ProcessList, { props: { processes, busyProcess: 'p_flow' } })
    const run = w.find('[data-testid="process-run-p_flow"]')
    expect(run.text()).toBe('Running…')
    expect(run.attributes('disabled')).toBeDefined()
    expect(w.find('[data-testid="process-automate-p_sop"]').attributes('disabled')).toBeUndefined()
  })

  it('labels the founder as "you" and colours owners with --owner-* tokens', () => {
    const w = mount(ProcessList, { props: { processes } })
    expect(w.find('[data-testid="process-owner-p_sop"]').text()).toBe('you')
    expect(w.find('[data-testid="process-owner-p_sop"]').attributes('style')).toContain('--owner-founder')
    expect(w.find('[data-testid="process-owner-p_flow"]').text()).toBe('agent')
    expect(w.find('[data-testid="process-owner-p_flow"]').attributes('style')).toContain('--owner-agent')
    expect(w.find('[data-testid="process-owner-p_stuck"]').attributes('style')).toContain('--owner-team')
  })

  it('maps run state to --status-* tokens and hides the dot without a flow', () => {
    const w = mount(ProcessList, { props: { processes } })
    expect(w.find('[data-testid="process-dot-p_flow"]').attributes('style')).toContain('--status-live')
    expect(w.find('[data-testid="process-dot-p_stuck"]').attributes('style')).toContain('--status-missing')
    expect(w.find('[data-testid="process-dot-p_sop"]').exists()).toBe(false)
  })

  it('renders an empty state', () => {
    const w = mount(ProcessList)
    expect(w.find('[data-testid="process-list-empty"]').exists()).toBe(true)
  })
})

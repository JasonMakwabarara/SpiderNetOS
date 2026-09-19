// MapNodeDrawer component — Vitest + @vue/test-utils with a memory router.
// Inline (≥lg) vs Teleported SideDrawer (<lg), the four tabs, Skills with
// inline Run (200 + 422), Brain → BrainFileDrawer + Ask Atlas, Processes via
// the map → systemization stores (automate + stuck escalation), Runs, and
// the footer links.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'

vi.mock('../src/services/api.js', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import api from '../src/services/api.js'
import MapNodeDrawer from '../src/components/map/MapNodeDrawer.vue'
import { useMapStore } from '../src/stores/map.js'
import mapFixture from './fixtures/map.json'
import nodeFixture from './fixtures/map_node.json'
import fileFixture from './fixtures/brain_file.json'
import { mountView, settle, q, qa, httpError } from './helpers/harness.js'

const summary = mapFixture.data.pillars.flatMap((p) => p.nodes).find((n) => n.id === 'sales.outreach_writing')
const NODE = {
  ...summary,
  ...nodeFixture.data,
  runs: nodeFixture.data.runs,
  run_stats: summary.runs,
  detailLoaded: true,
}

function mockApi() {
  api.get.mockImplementation((url) => {
    if (url === '/api/brain/files/offer/offer.md') return Promise.resolve({ data: fileFixture })
    if (url === '/api/map/nodes/sales.outreach_writing') return Promise.resolve({ data: nodeFixture })
    if (url === '/api/systemization/map') return Promise.resolve({ data: { data: { systems: [] } } })
    if (url === '/api/systemization/snowball') return Promise.resolve({ data: { data: { queue: [] } } })
    return Promise.reject(httpError(404))
  })
  api.post.mockImplementation((url) => {
    if (url === '/api/skills/cold-email-drafting/run') return Promise.resolve({ status: 202, data: { data: { run_id: 'run_new_1', status: 'queued' } } })
    if (url === '/api/skills/linkedin-outreach-specialist/run') {
      return Promise.reject(httpError(422, { message: 'Missing brain', missing_brain: [{ path: 'offer/offer.md', section: 'Pricing', question: 'What does it cost?' }] }))
    }
    return Promise.resolve({ data: {} })
  })
}

let wrapper
afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  document.body.innerHTML = ''
})
beforeEach(() => {
  vi.resetAllMocks()
  mockApi()
})

async function mountDrawer(props = {}) {
  const mounted = await mountView(MapNodeDrawer, {
    path: '/map?node=sales.outreach_writing',
    props: { node: NODE, open: true, wide: true, ...props },
    attachTo: document.body,
  })
  wrapper = mounted.wrapper
  return mounted
}

const panelHidden = (key) => (q(`[data-testid="map-drawer-panel-${key}"]`).getAttribute('style') || '').includes('display: none')

describe('MapNodeDrawer component', () => {
  it('renders inline beside the map on wide screens with status, owner and the error line', async () => {
    await mountDrawer({ error: "Couldn't load this node — Request failed (HTTP 404). Showing sample data." })
    const inline = q('[data-testid="map-drawer-inline"]')
    expect(inline).toBeTruthy()
    expect(inline.tagName.toLowerCase()).toBe('aside')
    expect(inline.className).toContain('sn-drawer')
    expect(inline.className).toContain('w-[380px]')
    expect(q('[data-testid="side-drawer"]')).toBeNull()
    expect(q('[data-testid="map-node-drawer"]').getAttribute('data-mode')).toBe('inline')
    expect(inline.textContent).toContain('Outreach writing')
    expect(inline.textContent).toContain('Business map · Sales')
    expect(q('[data-testid="map-drawer-status"]').textContent).toBe('Assisted')
    expect(q('[data-testid="map-drawer-owner"]').textContent).toBe('Owner: agent')
    expect(q('[data-testid="map-drawer-error"]').textContent).toContain('sample data')
  })

  it('has four tabs with tab semantics; click and arrow keys switch panels', async () => {
    await mountDrawer()
    const tabs = qa('[role="tab"]')
    expect(tabs.map((t) => t.getAttribute('data-testid'))).toEqual([
      'map-drawer-tab-skills', 'map-drawer-tab-brain', 'map-drawer-tab-processes', 'map-drawer-tab-runs',
    ])
    expect(q('[data-testid="map-drawer-tab-skills"]').getAttribute('aria-selected')).toBe('true')
    expect(q('[data-testid="map-drawer-tab-skills"]').getAttribute('aria-controls')).toBe(q('[data-testid="map-drawer-panel-skills"]').id)
    expect(panelHidden('skills')).toBe(false)
    expect(panelHidden('brain')).toBe(true)

    await wrapper.find('[data-testid="map-drawer-tab-runs"]').trigger('click')
    expect(q('[data-testid="map-drawer-tab-runs"]').getAttribute('aria-selected')).toBe('true')
    expect(panelHidden('runs')).toBe(false)
    expect(panelHidden('skills')).toBe(true)

    await wrapper.find('[role="tablist"]').trigger('keydown', { key: 'ArrowRight' })
    expect(q('[data-testid="map-drawer-tab-skills"]').getAttribute('aria-selected')).toBe('true')
    await wrapper.find('[role="tablist"]').trigger('keydown', { key: 'ArrowLeft' })
    expect(q('[data-testid="map-drawer-tab-runs"]').getAttribute('aria-selected')).toBe('true')
  })

  it('Skills: a mini card per skill with an inline Run that links to the new run', async () => {
    await mountDrawer()
    expect(q('[data-testid="skill-mini-cold-email-drafting"]')).toBeTruthy()
    expect(q('[data-testid="skill-mini-linkedin-outreach-specialist"]')).toBeTruthy()
    expect(q('[data-testid="map-drawer-tab-skills"]').textContent).toContain('2')

    await wrapper.find('[data-testid="map-drawer-run-cold-email-drafting"]').trigger('click')
    await settle()
    expect(api.post).toHaveBeenCalledWith('/api/skills/cold-email-drafting/run', { inputs: {} })
    expect(q('[data-testid="skill-notice-cold-email-drafting"]').textContent).toBe('Run started (queued).')
    expect(q('[data-testid="map-drawer-run-link-cold-email-drafting"]').getAttribute('href')).toBe('/agents/runs/run_new_1')
  })

  it('Skills: a 422 names the missing brain file and jumps to the Brain tab; disabled skills cannot run', async () => {
    await mountDrawer()
    await wrapper.find('[data-testid="map-drawer-run-linkedin-outreach-specialist"]').trigger('click')
    await settle()
    expect(q('[data-testid="skill-notice-linkedin-outreach-specialist"]').textContent).toContain('offer/offer.md')
    expect(q('[data-testid="map-drawer-tab-brain"]').getAttribute('aria-selected')).toBe('true')

    const disabled = { ...NODE, skills: [{ slug: 'linkedin-campaign-runner', name: 'LinkedIn Campaign Runner', enabled: false, stage: 'human_led' }] }
    await wrapper.setProps({ node: disabled })
    expect(q('[data-testid="map-drawer-run-linkedin-campaign-runner"]').disabled).toBe(true)
    expect(q('[data-testid="skill-enable-linkedin-campaign-runner"]')).toBeTruthy()
  })

  it('Brain: readiness rows open the BrainFileDrawer and Ask Atlas prefills the Hannah thread', async () => {
    const { router } = await mountDrawer()
    await wrapper.find('[data-testid="map-drawer-tab-brain"]').trigger('click')
    expect(q('[data-testid="brain-file-offer"]').getAttribute('data-status')).toBe('partial')
    expect(q('[data-testid="brain-file-profile"]').getAttribute('data-status')).toBe('filled')

    await wrapper.find('[data-testid="brain-file-open-offer"]').trigger('click')
    await settle()
    expect(api.get).toHaveBeenCalledWith('/api/brain/files/offer/offer.md')
    expect(q('[data-testid="side-drawer"]')).toBeTruthy()
    expect(q('[data-testid="brain-file-drawer-path"]').textContent).toContain('offer/offer.md')

    q('[data-testid="side-drawer-close"]').click()
    await settle()
    await wrapper.find('[data-testid="brain-file-ask-offer"]').trigger('click')
    await settle()
    expect(router.currentRoute.value.path).toBe('/atlas')
    expect(router.currentRoute.value.query.hannah).toBe('1')
    expect(router.currentRoute.value.query.prefill).toContain('offer/offer.md')
  })

  it('Processes: ProcessList actions go through the map store and refresh the node', async () => {
    await mountDrawer()
    const map = useMapStore()
    map.selectedNodeId = 'sales.outreach_writing'
    await wrapper.find('[data-testid="map-drawer-tab-processes"]').trigger('click')
    expect(qa('[data-testid^="process-proc_"]')).toHaveLength(3)

    await wrapper.find('[data-testid="process-automate-proc_outreach_2"]').trigger('click')
    await settle()
    expect(api.post).toHaveBeenCalledWith('/api/systemization/processes/proc_outreach_2/automate', { schedule: 'daily_morning' })
    expect(api.get).toHaveBeenCalledWith('/api/map/nodes/sales.outreach_writing')
    expect(q('[data-testid="map-drawer-process-notice"]').textContent).toContain('Compiled a runbook')

    await wrapper.find('[data-testid="process-stuck-proc_outreach_3"]').trigger('click')
    await settle()
    const input = q('[data-testid="prompt-dialog-input"]')
    input.value = 'Pull subject lines from the approved list'
    input.dispatchEvent(new Event('input'))
    await settle()
    q('[data-testid="prompt-dialog-confirm"]').click()
    await settle()
    expect(api.post).toHaveBeenCalledWith('/api/systemization/processes/proc_outreach_3/resolve-escalation', { answer: 'Pull subject lines from the approved list' })
    expect(q('[data-testid="map-drawer-systems-link"]').getAttribute('href')).toBe('/operate/systems')
  })

  it('Runs: stats line and one row per run linking to the run detail', async () => {
    await mountDrawer()
    await wrapper.find('[data-testid="map-drawer-tab-runs"]').trigger('click')
    expect(q('[data-testid="map-drawer-run-stats"]').textContent).toContain('9 runs in 7 days')
    expect(qa('[data-testid^="map-drawer-run-row-"]')).toHaveLength(3)
    const row = q('[data-testid="map-drawer-run-row-run_blocked_01"]')
    expect(row.querySelector('a').getAttribute('href')).toBe('/agents/runs/run_blocked_01')
    expect(row.textContent).toContain('blocked')

    await wrapper.setProps({ node: { ...NODE, runs: [] } })
    expect(q('[data-testid="map-drawer-runs-empty"]')).toBeTruthy()
  })

  it('footer links to "Improve with Atlas" (Hannah prefill) and the full skill card', async () => {
    await mountDrawer()
    const improve = q('[data-testid="map-drawer-improve"]').getAttribute('href')
    expect(improve.startsWith('/atlas?')).toBe(true)
    const params = new URLSearchParams(improve.split('?')[1])
    expect(params.get('hannah')).toBe('1')
    expect(params.get('prefill')).toContain('"Outreach writing"')
    expect(params.get('prefill')).toContain('assisted')
    expect(q('[data-testid="map-drawer-open-card"]').getAttribute('href')).toBe('/skills/cold-email-drafting')
  })

  it('closes from the inline close button', async () => {
    await mountDrawer()
    await wrapper.find('[data-testid="map-drawer-close"]').trigger('click')
    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('uses the Teleported SideDrawer below lg, and renders nothing when closed', async () => {
    await mountDrawer({ wide: false })
    expect(q('[data-testid="map-drawer-inline"]')).toBeNull()
    const modal = q('[data-testid="side-drawer"]')
    expect(modal).toBeTruthy()
    expect(modal.getAttribute('aria-modal')).toBe('true')
    expect(modal.querySelector('[data-testid="map-node-drawer"]').getAttribute('data-mode')).toBe('modal')
    q('[data-testid="side-drawer-close"]').click()
    await settle()
    expect(wrapper.emitted('close')).toHaveLength(1)

    await wrapper.setProps({ open: false })
    await settle()
    expect(q('[data-testid="map-node-drawer"]')).toBeNull()
  })
})

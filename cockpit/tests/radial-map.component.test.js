// RadialMap component — Vitest + @vue/test-utils.
// SVG application semantics + offscreen instructions, core / pillar / node
// rendering (colour by status, aria-label per node), dashed builds_on
// ribbons only for the hovered or selected node, dimming from visibleIds,
// roving-tabindex keyboard navigation, click-to-select and zoom keys.
import { describe, it, expect, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import RadialMap from '../src/components/map/RadialMap.vue'
import mapFixture from './fixtures/map.json'

let wrapper
afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  document.body.innerHTML = ''
})

function mountMap(props = {}) {
  wrapper = mount(RadialMap, {
    props: { core: mapFixture.data.core, pillars: mapFixture.data.pillars, ...props },
    attachTo: document.body,
  })
  return wrapper
}

const svg = () => wrapper.find('[data-testid="radial-map"]')
const focused = () => wrapper.findAll('[data-focused="true"]').map((w) => w.attributes('data-node-id'))
const key = async (k) => svg().trigger('keydown', { key: k })

describe('RadialMap component', () => {
  it('is an SVG application described by offscreen instructions', () => {
    mountMap()
    const el = svg()
    expect(el.element.tagName.toLowerCase()).toBe('svg')
    expect(el.attributes('role')).toBe('application')
    expect(el.attributes('tabindex')).toBe('0')
    const help = wrapper.find('[data-testid="radial-map-instructions"]')
    expect(el.attributes('aria-describedby')).toBe(help.attributes('id'))
    expect(help.classes()).toContain('sr-only')
    expect(help.text()).toContain('arrow keys')
    expect(el.attributes('aria-label')).toBe('Business map for Apex Synchronia')
    expect(el.attributes('viewBox').split(' ')).toHaveLength(4)
  })

  it('renders the core with its three brain arcs, 9 pillars and 40 nodes', () => {
    mountMap()
    expect(wrapper.find('[data-testid="map-core-name"]').text()).toBe('Apex Synchronia')
    expect(wrapper.find('[data-testid="map-core-arc-knowledge"]').attributes('data-value')).toBe('0.55')
    expect(wrapper.find('[data-testid="map-core-arc-operating"]').attributes('data-value')).toBe('1')
    expect(wrapper.find('[data-testid="map-core-knowledge"]').text()).toBe('Knowledge 55%')
    expect(wrapper.find('[data-testid="map-core-operating"]').text()).toBe('Operating 6 active')
    expect(wrapper.find('[data-testid="map-core-learning"]').text()).toBe('Learning 41 outcomes')
    expect(wrapper.find('[data-testid="map-core"]').attributes('aria-label')).toContain('Knowledge brain 55% filled (4 of 9 files)')

    expect(wrapper.findAll('g[data-testid^="map-pillar-"]')).toHaveLength(9)
    expect(wrapper.findAll('g[data-testid^="map-node-"]')).toHaveLength(40)
    expect(wrapper.findAll('[data-testid^="map-edge-spoke-"]')).toHaveLength(9)
    expect(wrapper.findAll('[data-testid^="map-edge-rib-"]')).toHaveLength(40)
  })

  it('colours nodes by status only and labels each one for assistive tech', () => {
    mountMap()
    const node = wrapper.find('[data-testid="map-node-sales.reply_handling"]')
    expect(node.attributes('data-status')).toBe('live')
    expect(node.attributes('role')).toBe('button')
    const label = node.attributes('aria-label')
    expect(label).toContain('Reply handling')
    expect(label).toContain('Live')
    expect(label).toContain('2 skills')
    expect(label).toContain('owner: agent')
    expect(label).toContain('in Sales')
    expect(wrapper.find('[data-testid="map-node-dot-sales.reply_handling"]').attributes('style')).toContain('var(--status-live)')
    expect(wrapper.find('[data-testid="map-node-dot-deals.proposals"]').attributes('style')).toContain('var(--status-missing)')
    expect(wrapper.find('[data-testid="map-node-skills-sales.reply_handling"]').text()).toBe('2')
    // Founder-owned nodes show the owner's initial.
    expect(wrapper.find('[data-testid="map-node-owner-sales.icp"]').text()).toBe('J')
    expect(wrapper.find('[data-testid="map-node-owner-sales.partner_recruitment"]').text()).toBe('T')
    // Truncated label, full label kept in <title>.
    const long = wrapper.find('[data-testid="map-node-sales.partner_recruitment"]')
    expect(wrapper.find('[data-testid="map-node-label-sales.partner_recruitment"]').text()).toMatch(/…$/)
    expect(long.find('title').text()).toBe('Partner & affiliate recruitment')
  })

  it('shows dashed builds_on ribbons only for the hovered or selected node', async () => {
    mountMap()
    expect(wrapper.findAll('[data-testid^="map-edge-builds-"]')).toHaveLength(0)

    await wrapper.setProps({ selectedId: 'sales.outreach_writing' })
    const ribbons = wrapper.findAll('[data-testid^="map-edge-builds-"]')
    expect(ribbons.length).toBeGreaterThanOrEqual(3)
    const edge = wrapper.find('[data-testid="map-edge-builds-sales.outreach_writing-sales.icp"]')
    expect(edge.exists()).toBe(true)
    expect(edge.attributes('stroke-dasharray')).toBe('5 4')
    // Incoming ribbons too (reply handling builds on outreach writing).
    expect(wrapper.find('[data-testid="map-edge-builds-sales.reply_handling-sales.outreach_writing"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="map-node-sales.outreach_writing"]').attributes('data-selected')).toBe('true')

    await wrapper.setProps({ selectedId: null })
    await wrapper.find('[data-testid="map-node-customer.win_back"]').trigger('mouseenter')
    expect(wrapper.find('[data-testid="map-edge-builds-customer.win_back-customer.churn"]').exists()).toBe(true)
    await wrapper.find('[data-testid="map-node-customer.win_back"]').trigger('mouseleave')
    expect(wrapper.findAll('[data-testid^="map-edge-builds-"]')).toHaveLength(0)
  })

  it('dims nodes outside visibleIds and counts what each pillar still shows', () => {
    mountMap({ visibleIds: ['customer.churn', 'sales.icp'] })
    expect(wrapper.find('[data-testid="map-node-customer.churn"]').attributes('data-dimmed')).toBe('false')
    expect(wrapper.find('[data-testid="map-node-customer.support"]').attributes('data-dimmed')).toBe('true')
    expect(wrapper.find('[data-testid="map-pillar-count-customer"]').text()).toBe('1/4')
    expect(wrapper.find('[data-testid="map-pillar-count-deals"]').text()).toBe('0/5')
    expect(wrapper.find('[data-testid="map-pillar-deals"]').attributes('style')).toContain('opacity: 0.45')
  })

  it('navigates with a roving tabindex: ↓ child, ←/→ siblings (wrapping), ↑ parent, Home core', async () => {
    mountMap()
    expect(focused()).toEqual(['core'])
    expect(wrapper.find('[data-testid="map-core"]').attributes('tabindex')).toBe('0')

    await key('ArrowDown')
    expect(focused()).toEqual(['pillar:sales'])
    expect(wrapper.find('[data-testid="map-pillar-sales"]').attributes('tabindex')).toBe('0')
    expect(wrapper.find('[data-testid="map-core"]').attributes('tabindex')).toBe('-1')

    await key('ArrowRight')
    expect(focused()).toEqual(['pillar:deals'])
    await key('ArrowLeft')
    await key('ArrowLeft')
    expect(focused()).toEqual(['pillar:founder'])
    await key('ArrowRight')
    expect(focused()).toEqual(['pillar:sales'])

    await key('ArrowDown')
    expect(focused()).toEqual(['sales.outreach_writing'])
    await key('ArrowRight')
    expect(focused()).toEqual(['sales.linkedin_campaigns'])
    expect(wrapper.find('[data-testid="map-node-sales.linkedin_campaigns"]').attributes('tabindex')).toBe('0')
    expect(wrapper.findAll('[tabindex="0"]').map((w) => w.attributes('data-testid'))).toEqual(['radial-map', 'map-node-sales.linkedin_campaigns'])
    expect(svg().attributes('aria-activedescendant')).toBe(wrapper.find('[data-testid="map-node-sales.linkedin_campaigns"]').attributes('id'))

    await key('ArrowUp')
    expect(focused()).toEqual(['pillar:sales'])
    await key('ArrowUp')
    expect(focused()).toEqual(['core'])
    await key('ArrowDown')
    await key('Home')
    expect(focused()).toEqual(['core'])
  })

  it('Enter opens a node, Enter on a pillar descends, Escape closes; filtered nodes are skipped', async () => {
    mountMap({ visibleIds: ['deals.lead_scoring', 'deals.proposals'] })
    await key('ArrowDown')
    await key('ArrowRight') // deals
    await key('Enter')      // descend to first visible child
    expect(focused()).toEqual(['deals.lead_scoring'])
    await key('ArrowRight') // skips meetings + pipeline (dimmed)
    expect(focused()).toEqual(['deals.proposals'])
    await key('Enter')
    expect(wrapper.emitted('select')).toEqual([['deals.proposals']])
    await key('Escape')
    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('clicking a node selects it; clicking a pillar only moves focus', async () => {
    mountMap()
    await wrapper.find('[data-testid="map-node-marketing.social"]').trigger('click')
    expect(wrapper.emitted('select')).toEqual([['marketing.social']])
    expect(focused()).toEqual(['marketing.social'])
    await wrapper.find('[data-testid="map-pillar-people"]').trigger('click')
    expect(wrapper.emitted('select')).toHaveLength(1)
    expect(focused()).toEqual(['pillar:people'])
  })

  it('zooms with + / − / 0 and exposes zoomIn / zoomOut / fit', async () => {
    mountMap()
    expect(svg().attributes('data-zoom')).toBe('1')
    await key('+')
    expect(svg().attributes('data-zoom')).toBe('1.25')
    await key('-')
    await key('-')
    expect(svg().attributes('data-zoom')).toBe('0.8')
    await key('0')
    expect(svg().attributes('data-zoom')).toBe('1')
    wrapper.vm.zoomIn()
    await wrapper.vm.$nextTick()
    expect(wrapper.vm.zoom).toBeCloseTo(1.25)
    wrapper.vm.fit()
    await wrapper.vm.$nextTick()
    expect(svg().attributes('data-zoom')).toBe('1')
  })
})

// BusinessMap view — Vitest + @vue/test-utils with a memory router.
// Loads /api/map into the radial map, fixture fallback notice, filter chips
// and search dimming with a polite live count, ?node= as the source of truth
// for the drawer (click, deep link, Enter-on-search, close), zoom toolbar,
// and the List view link.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'

vi.mock('../src/services/api.js', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import api from '../src/services/api.js'
import BusinessMap from '../src/views/map/BusinessMap.vue'
import mapFixture from './fixtures/map.json'
import nodeFixture from './fixtures/map_node.json'
import skillsFixture from './fixtures/skills.json'
import { mountView, settle, q, qa, httpError } from './helpers/harness.js'

function mockReads({ mapFails = false } = {}) {
  api.get.mockImplementation((url) => {
    if (url === '/api/map') return mapFails ? Promise.reject(httpError(503)) : Promise.resolve({ data: mapFixture })
    if (url.startsWith('/api/map/nodes/')) {
      const id = decodeURIComponent(url.slice('/api/map/nodes/'.length))
      const base = mapFixture.data.pillars.flatMap((p) => p.nodes).find((n) => n.id === id)
      return Promise.resolve({ data: { data: { ...nodeFixture.data, ...base, runs: nodeFixture.data.runs, processes: nodeFixture.data.processes } } })
    }
    if (url === '/api/skills') return Promise.resolve({ data: skillsFixture })
    return Promise.reject(httpError(404))
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
  mockReads()
})

async function mountMap(path = '/map') {
  const mounted = await mountView(BusinessMap, { path, attachTo: document.body })
  wrapper = mounted.wrapper
  await settle(3)
  return mounted
}

const dimmed = () => qa('g[data-testid^="map-node-"][data-dimmed="true"]').length

describe('BusinessMap view', () => {
  it('loads the map: title from the core, radial map, legend and filter counts', async () => {
    await mountMap()
    expect(api.get).toHaveBeenCalledWith('/api/map')
    expect(q('[data-testid="map-title"]').textContent).toContain('Apex Synchronia, mapped')
    expect(q('[data-testid="radial-map"]')).toBeTruthy()
    expect(qa('g[data-testid^="map-node-"]')).toHaveLength(40)
    expect(q('[data-testid="map-legend-live"]').textContent).toContain('Live')
    expect(q('[data-testid="map-filter-all"]').getAttribute('aria-pressed')).toBe('true')
    expect(q('[data-testid="map-filter-all"]').textContent).toContain('40')
    expect(q('[data-testid="map-live"]').getAttribute('aria-live')).toBe('polite')
    expect(q('[data-testid="map-live"]').textContent).toBe('Showing all 40 nodes.')
    expect(q('[data-testid="map-error"]')).toBeNull()
    expect(q('[data-testid="map-node-drawer"]')).toBeNull()
  })

  it('falls back to the fixture with an inline notice when /api/map fails', async () => {
    mockReads({ mapFails: true })
    await mountMap()
    expect(q('[data-testid="map-error"]').textContent).toContain('HTTP 503')
    expect(q('[data-testid="map-error"]').textContent).toContain('sample data')
    expect(qa('g[data-testid^="map-node-"]')).toHaveLength(40)
  })

  it('filter chips dim non-matching nodes and announce the count', async () => {
    await mountMap()
    const live = mapFixture.data.pillars.flatMap((p) => p.nodes).filter((n) => n.status === 'live').length
    await wrapper.find('[data-testid="map-filter-automated"]').trigger('click')
    expect(q('[data-testid="map-filter-automated"]').getAttribute('aria-pressed')).toBe('true')
    expect(q('[data-testid="map-filter-all"]').getAttribute('aria-pressed')).toBe('false')
    expect(dimmed()).toBe(40 - live)
    expect(q('[data-testid="map-node-sales.reply_handling"]').getAttribute('data-dimmed')).toBe('false')
    expect(q('[data-testid="map-live"]').textContent).toBe(`Showing ${live} of 40 nodes.`)

    await wrapper.find('[data-testid="map-filter-all"]').trigger('click')
    expect(dimmed()).toBe(0)
  })

  it('search dims everything else; Enter opens the first match via ?node=', async () => {
    const { router } = await mountMap()
    const input = wrapper.find('[data-testid="map-search"]')
    await input.setValue('newsletter')
    expect(dimmed()).toBe(39)
    expect(q('[data-testid="map-node-marketing.newsletter"]').getAttribute('data-dimmed')).toBe('false')

    await input.trigger('keydown', { key: 'Enter' })
    await settle(3)
    expect(router.currentRoute.value.query.node).toBe('marketing.newsletter')
    expect(api.get).toHaveBeenCalledWith('/api/map/nodes/marketing.newsletter')
    expect(q('[data-testid="map-node-drawer"]').getAttribute('data-node-id')).toBe('marketing.newsletter')
  })

  it('clicking a node writes ?node= and opens the drawer; closing clears it', async () => {
    const { router } = await mountMap()
    await wrapper.find('[data-testid="map-node-deals.meetings"]').trigger('click')
    await settle(3)
    expect(router.currentRoute.value.query.node).toBe('deals.meetings')
    expect(api.get).toHaveBeenCalledWith('/api/map/nodes/deals.meetings')
    expect(q('[data-testid="map-node-drawer"]').getAttribute('data-node-id')).toBe('deals.meetings')
    expect(q('[data-testid="map-node-deals.meetings"]').getAttribute('data-selected')).toBe('true')
    // The skills catalogue is fetched once so the mini cards are rich.
    expect(api.get).toHaveBeenCalledWith('/api/skills', expect.anything())

    const close = q('[data-testid="map-drawer-close"]') || q('[data-testid="side-drawer-close"]')
    close.click()
    await settle(3)
    expect(router.currentRoute.value.query.node).toBeUndefined()
    expect(q('[data-testid="map-node-drawer"]')).toBeNull()
  })

  it('opens the drawer from a deep link and Escape on the map closes it', async () => {
    const { router } = await mountMap('/map?node=customer.churn')
    expect(api.get).toHaveBeenCalledWith('/api/map/nodes/customer.churn')
    expect(q('[data-testid="map-node-drawer"]').getAttribute('data-node-id')).toBe('customer.churn')
    expect(q('[data-testid="map-node-customer.churn"]').getAttribute('data-focused')).toBe('true')

    await wrapper.find('[data-testid="radial-map"]').trigger('keydown', { key: 'Escape' })
    await settle(3)
    expect(router.currentRoute.value.query.node).toBeUndefined()
    expect(q('[data-testid="map-node-drawer"]')).toBeNull()
  })

  it('zoom toolbar drives the map and Fit resets it', async () => {
    await mountMap()
    expect(q('[data-testid="map-zoom-level"]').textContent.trim()).toBe('100%')
    await wrapper.find('[data-testid="map-zoom-in"]').trigger('click')
    expect(q('[data-testid="map-zoom-level"]').textContent.trim()).toBe('125%')
    expect(q('[data-testid="radial-map"]').getAttribute('data-zoom')).toBe('1.25')
    await wrapper.find('[data-testid="map-zoom-out"]').trigger('click')
    await wrapper.find('[data-testid="map-zoom-out"]').trigger('click')
    expect(q('[data-testid="map-zoom-level"]').textContent.trim()).toBe('80%')
    await wrapper.find('[data-testid="map-zoom-fit"]').trigger('click')
    expect(q('[data-testid="map-zoom-level"]').textContent.trim()).toBe('100%')
  })

  it('links to the list view', async () => {
    await mountMap()
    expect(q('[data-testid="map-list-view"]').getAttribute('href')).toBe('/operate/systems')
  })
})

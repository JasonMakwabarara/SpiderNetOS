// Business map store — Vitest unit tests.
// GET /api/map envelope + pillar order, fixture fallback with an inline
// error, filter chips + search, node detail cache / fallback, selection
// (summary + detail merge), process actions delegated to the systemization
// store, and the .map.node.updated handler.
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('../src/services/api.js', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import api from '../src/services/api.js'
import { useMapStore, nodeMatchesFilter, nodeMatchesQuery } from '../src/stores/map.js'
import { useSystemizationStore } from '../src/stores/systemization.js'
import mapFixture from './fixtures/map.json'
import nodeFixture from './fixtures/map_node.json'
import { httpError } from './helpers/harness.js'

const allNodes = mapFixture.data.pillars.flatMap((p) => p.nodes)

describe('map store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
  })

  it('fetchMap unwraps {data: {core, pillars}} and orders pillars by `order`', async () => {
    api.get.mockResolvedValueOnce({ data: mapFixture })
    const store = useMapStore()
    const res = await store.fetchMap()
    expect(res.success).toBe(true)
    expect(api.get).toHaveBeenCalledWith('/api/map')
    expect(store.core.name).toBe('Apex Synchronia')
    expect(store.core.three_brains.knowledge.pct).toBe(55)
    expect(store.orderedPillars[0].key).toBe('sales')
    expect(store.nodes).toHaveLength(40)
    expect(store.nodes[0].pillar).toEqual({ key: 'sales', label: 'Sales' })
    expect(store.fallback).toBe(false)
    expect(store.layoutInput.pillars).toHaveLength(9)
  })

  it('falls back to the fixture with an inline error when the API fails', async () => {
    api.get.mockRejectedValueOnce(httpError(500))
    const store = useMapStore()
    const res = await store.fetchMap()
    expect(res.success).toBe(false)
    expect(store.fallback).toBe(true)
    expect(store.error).toContain('the business map')
    expect(store.error).toContain('HTTP 500')
    expect(store.error).toContain('sample data')
    expect(store.pillars).toHaveLength(9)
    // The fixture is cloned — mutating the store never leaks into it.
    store.pillars[0].nodes[0].label = 'changed'
    expect(mapFixture.data.pillars[0].nodes[0].label).not.toBe('changed')
  })

  it('counts statuses and filter chips, and filters by chip + search', async () => {
    api.get.mockResolvedValueOnce({ data: mapFixture })
    const store = useMapStore()
    await store.fetchMap()

    const live = allNodes.filter((n) => n.status === 'live').length
    const needsYou = allNodes.filter((n) => n.status === 'human' || n.owner_type === 'founder').length
    const missingBrain = allNodes.filter((n) => n.brain_files.some((f) => f.status === 'missing')).length
    expect(store.counts.total).toBe(40)
    expect(store.counts.live).toBe(live)
    expect(store.counts.filters).toEqual({ all: 40, needs_you: needsYou, automated: live, missing_brain: missingBrain })

    store.setFilter('automated')
    expect(store.visibleNodeIds.size).toBe(live)
    expect(store.filteredPillars.flatMap((p) => p.nodes).every((n) => n.status === 'live')).toBe(true)
    // Pillars stay in place (so the layout doesn't jump); only their nodes filter.
    expect(store.filteredPillars).toHaveLength(9)

    store.setFilter('missing_brain')
    expect(store.visibleNodeIds.size).toBe(missingBrain)

    store.setFilter('bogus')
    expect(store.filter).toBe('all')

    store.setQuery('churn')
    expect([...store.visibleNodeIds]).toEqual(['customer.churn'])
    // Search matches skill names and slugs too.
    store.setQuery('Inbox Triage')
    expect([...store.visibleNodeIds]).toEqual(['sales.reply_handling'])
    store.setQuery('')
    expect(store.visibleNodeIds.size).toBe(40)
  })

  it('exports the filter / query predicates', () => {
    expect(nodeMatchesFilter({ status: 'assisted', owner_type: 'founder' }, 'needs_you')).toBe(true)
    expect(nodeMatchesFilter({ status: 'live' }, 'needs_you')).toBe(false)
    expect(nodeMatchesFilter({ brain_files: [{ status: 'filled' }] }, 'missing_brain')).toBe(false)
    expect(nodeMatchesFilter({}, 'all')).toBe(true)
    expect(nodeMatchesQuery({ label: 'Deal pipeline' }, '  PIPE ')).toBe(true)
    expect(nodeMatchesQuery({ label: 'Deal pipeline' }, 'nope')).toBe(false)
  })

  it('fetchNodeDetail encodes the id, caches, and can be forced', async () => {
    api.get.mockResolvedValue({ data: nodeFixture })
    const store = useMapStore()
    const res = await store.fetchNodeDetail('sales.outreach_writing')
    expect(res.success).toBe(true)
    expect(api.get).toHaveBeenCalledWith('/api/map/nodes/sales.outreach_writing')
    expect(store.nodeDetails['sales.outreach_writing'].processes).toHaveLength(3)

    const cached = await store.fetchNodeDetail('sales.outreach_writing')
    expect(cached.cached).toBe(true)
    expect(api.get).toHaveBeenCalledTimes(1)

    await store.fetchNodeDetail('sales.outreach_writing', { force: true })
    expect(api.get).toHaveBeenCalledTimes(2)

    await store.fetchNodeDetail('a b/c')
    expect(api.get).toHaveBeenLastCalledWith('/api/map/nodes/a%20b%2Fc')
    expect((await store.fetchNodeDetail('')).success).toBe(false)
  })

  it('falls back to the node fixture re-keyed to the requested node', async () => {
    api.get.mockImplementation((url) => (url === '/api/map' ? Promise.resolve({ data: mapFixture }) : Promise.reject(httpError(404))))
    const store = useMapStore()
    await store.fetchMap()
    const res = await store.fetchNodeDetail('customer.churn')
    expect(res.success).toBe(false)
    expect(store.detailError).toContain('sample data')
    const node = store.nodeDetails['customer.churn']
    expect(node.id).toBe('customer.churn')
    expect(node.label).toBe('Churn radar')
    expect(node.processes.map((p) => p.id)).toEqual(['customer.churn:proc_1', 'customer.churn:proc_2', 'customer.churn:proc_3'])
    expect(node.runs.every((r) => r.skill_slug === 'churn-radar')).toBe(true)
  })

  it('select() loads the node and selectedNode merges summary stats with the detail lists', async () => {
    api.get.mockImplementation((url) => {
      if (url === '/api/map') return Promise.resolve({ data: mapFixture })
      if (url === '/api/map/nodes/sales.outreach_writing') return Promise.resolve({ data: nodeFixture })
      return Promise.reject(httpError(404))
    })
    const store = useMapStore()
    await store.fetchMap()
    expect(store.selectedNode).toBeNull()

    await store.select('sales.outreach_writing')
    expect(store.selectedNodeId).toBe('sales.outreach_writing')
    const node = store.selectedNode
    expect(node.label).toBe('Outreach writing')
    expect(node.detailLoaded).toBe(true)
    expect(node.runs).toHaveLength(3)
    expect(node.run_stats).toEqual({ last_at: '2026-09-16T06:00:00Z', last_status: 'blocked', count_7d: 9 })
    expect(node.pillar).toEqual({ key: 'sales', label: 'Sales' })

    await store.select(null)
    expect(store.selectedNodeId).toBeNull()
    expect(store.selectedNode).toBeNull()
  })

  it('process actions go through the systemization store and refresh the open node', async () => {
    api.get.mockImplementation((url) => {
      if (url === '/api/map/nodes/sales.outreach_writing') return Promise.resolve({ data: nodeFixture })
      if (url === '/api/systemization/map') return Promise.resolve({ data: { data: { systems: [] } } })
      if (url === '/api/systemization/snowball') return Promise.resolve({ data: { data: { queue: [] } } })
      return Promise.reject(httpError(404))
    })
    api.post.mockResolvedValue({ data: {} })
    const store = useMapStore()
    const systemization = useSystemizationStore()
    const automate = vi.spyOn(systemization, 'automate')
    await store.select('sales.outreach_writing')
    const detailCalls = () => api.get.mock.calls.filter(([u]) => u === '/api/map/nodes/sales.outreach_writing').length
    expect(detailCalls()).toBe(1)

    const res = await store.automateProcess('proc_outreach_2')
    expect(res.success).toBe(true)
    expect(automate).toHaveBeenCalledWith('proc_outreach_2', undefined)
    expect(api.post).toHaveBeenCalledWith('/api/systemization/processes/proc_outreach_2/automate', { schedule: 'daily_morning' })
    expect(detailCalls()).toBe(2)

    await store.runProcess('proc_outreach_1')
    expect(api.post).toHaveBeenCalledWith('/api/systemization/processes/proc_outreach_1/run')

    await store.resolveProcess('proc_outreach_3', 'Use the backup list')
    expect(api.post).toHaveBeenCalledWith('/api/systemization/processes/proc_outreach_3/resolve-escalation', { answer: 'Use the backup list' })

    api.post.mockRejectedValueOnce(httpError(500, { message: 'nope' }))
    const failed = await store.runProcess('proc_outreach_1')
    expect(failed).toEqual({ success: false, error: 'nope' })
  })

  it('handleNodeUpdated patches the map node and the cached detail', async () => {
    api.get.mockImplementation((url) => (url === '/api/map' ? Promise.resolve({ data: mapFixture }) : Promise.resolve({ data: nodeFixture })))
    const store = useMapStore()
    await store.fetchMap()
    await store.fetchNodeDetail('sales.outreach_writing')

    store.handleNodeUpdated({ id: 'sales.outreach_writing', status: 'live', runs: { last_at: '2026-09-16T09:00:00Z', last_status: 'succeeded', count_7d: 10 } })
    expect(store.nodeById['sales.outreach_writing'].status).toBe('live')
    expect(store.nodeDetails['sales.outreach_writing'].status).toBe('live')
    // Stats patch the summary; the detail's run list survives.
    expect(store.nodeById['sales.outreach_writing'].runs.count_7d).toBe(10)
    expect(store.nodeDetails['sales.outreach_writing'].runs).toHaveLength(3)
    expect(store.nodeDetails['sales.outreach_writing'].run_stats.count_7d).toBe(10)
    expect(store.counts.live).toBe(allNodes.filter((n) => n.status === 'live').length + 1)

    store.handleNodeUpdated({ node_id: 'customer.churn', status: 'human' })
    expect(store.nodeById['customer.churn'].status).toBe('human')

    expect(() => store.handleNodeUpdated({})).not.toThrow()
    expect(() => store.handleNodeUpdated(null)).not.toThrow()
  })
})

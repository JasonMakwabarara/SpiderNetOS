// Brain store — Vitest unit tests.
// Tree/readiness/gaps envelopes with fixture fallback, path encoding,
// saveFile with base_version + 409 conflict handling, versions/revert,
// sync, and the realtime file handler.
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('../src/services/api.js', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import api from '../src/services/api.js'
import { useBrainStore, encodeBrainPath } from '../src/stores/brain.js'
import treeFixture from './fixtures/brain_tree.json'
import fileFixture from './fixtures/brain_file.json'
import { httpError } from './helpers/harness.js'

function mockReads() {
  api.get.mockImplementation((url) => {
    if (url === '/api/brain/tree') return Promise.resolve({ data: { data: treeFixture.data } })
    if (url === '/api/brain/readiness') return Promise.resolve({ data: treeFixture.readiness })
    if (url === '/api/brain/gaps') return Promise.resolve({ data: treeFixture.gaps })
    if (url === '/api/brain/files/offer/offer.md') return Promise.resolve({ data: fileFixture })
    if (url === '/api/brain/files/offer/offer.md/versions') return Promise.resolve({ data: fileFixture.versions })
    return Promise.reject(httpError(404))
  })
}

describe('brain store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
  })

  it('encodes each path segment but keeps the slashes', () => {
    expect(encodeBrainPath('offer/offer.md')).toBe('offer/offer.md')
    expect(encodeBrainPath('notes/a b#c.md')).toBe('notes/a%20b%23c.md')
  })

  it('fetchAll loads tree, readiness and gaps and derives counts', async () => {
    mockReads()
    const store = useBrainStore()
    await store.fetchAll()
    expect(store.tree.folders).toHaveLength(treeFixture.data.folders.length)
    expect(store.readiness.pct).toBe(55)
    expect(store.gaps).toHaveLength(3)
    expect(store.fileCount).toBe(10)
    expect(store.filledCount).toBe(4)
    expect(store.missingCount).toBe(4)
    expect(store.treeEntry('offer/offer.md').status).toBe('partial')
    expect(store.fallback).toBe(false)
  })

  it('falls back to the fixture tree with an inline error when the API is down', async () => {
    api.get.mockRejectedValue(httpError(503))
    const store = useBrainStore()
    await store.fetchAll()
    expect(store.fallback).toBe(true)
    expect(store.error).toContain('HTTP 503')
    expect(store.tree.folders.length).toBeGreaterThan(0)
    expect(store.readiness.files.length).toBeGreaterThan(0)
    expect(store.gaps.length).toBeGreaterThan(0)
  })

  it('fetchFile hits the encoded path and caches the record', async () => {
    mockReads()
    const store = useBrainStore()
    const res = await store.fetchFile('offer/offer.md')
    expect(res.success).toBe(true)
    expect(api.get).toHaveBeenCalledWith('/api/brain/files/offer/offer.md')
    expect(store.files['offer/offer.md'].version).toBe(2)
    expect(store.files['offer/offer.md'].content).toContain('# Offer')
  })

  it('fetchFile falls back to a fixture-shaped record for an unknown path', async () => {
    mockReads()
    const store = useBrainStore()
    await store.fetchTree()
    const res = await store.fetchFile('people/user.md')
    expect(res.success).toBe(false)
    expect(store.fileError).toContain('people/user.md')
    expect(store.files['people/user.md']).toMatchObject({ path: 'people/user.md', title: 'You', status: 'missing', content: '' })
  })

  it('saveFile sends base_version from the loaded record and patches the tree + readiness', async () => {
    mockReads()
    const store = useBrainStore()
    await store.fetchAll()
    await store.fetchFile('offer/offer.md')
    api.put.mockResolvedValueOnce({ data: { data: { path: 'offer/offer.md', version: 3, status: 'filled', updated_at: '2026-09-16T10:00:00Z' } } })

    const res = await store.saveFile('offer/offer.md', '# Offer\n\nfull')
    expect(res.success).toBe(true)
    expect(api.put).toHaveBeenCalledWith('/api/brain/files/offer/offer.md', { content: '# Offer\n\nfull', base_version: 2 })
    expect(store.files['offer/offer.md']).toMatchObject({ version: 3, status: 'filled', content: '# Offer\n\nfull' })
    expect(store.treeEntry('offer/offer.md')).toMatchObject({ version: 3, status: 'filled' })
    expect(store.readiness.files.find((f) => f.path === 'offer/offer.md').status).toBe('filled')
  })

  it('saveFile reports a 409 conflict with current_version and leaves state untouched', async () => {
    mockReads()
    const store = useBrainStore()
    await store.fetchTree()
    await store.fetchFile('offer/offer.md')
    api.put.mockRejectedValueOnce(httpError(409, { current_version: 5 }))

    const res = await store.saveFile('offer/offer.md', 'new', 2)
    expect(res).toMatchObject({ success: false, conflict: true, status: 409, current_version: 5 })
    expect(res.error).toContain('v5')
    expect(store.files['offer/offer.md'].version).toBe(2)
    expect(store.files['offer/offer.md'].content).toContain('# Offer')
    expect(store.treeEntry('offer/offer.md').version).toBe(2)
    expect(store.saving).toBe(false)
  })

  it('saveFile returns a plain error for other failures', async () => {
    const store = useBrainStore()
    api.put.mockRejectedValueOnce(httpError(500, { message: 'disk full' }))
    const res = await store.saveFile('offer/offer.md', 'x', 1)
    expect(res.success).toBe(false)
    expect(res.conflict).toBeUndefined()
    expect(res.error).toContain('disk full')
  })

  it('fetchVersions + revert post the version and refresh the list', async () => {
    mockReads()
    const store = useBrainStore()
    await store.fetchVersions('offer/offer.md')
    expect(store.versions['offer/offer.md']).toHaveLength(2)

    api.post.mockResolvedValueOnce({ data: { data: { path: 'offer/offer.md', version: 3, status: 'partial', updated_at: '2026-09-16T11:00:00Z', content: '# Offer v1' } } })
    const res = await store.revert('offer/offer.md', 1)
    expect(res.success).toBe(true)
    expect(api.post).toHaveBeenCalledWith('/api/brain/files/offer/offer.md/revert', { version: 1 })
    expect(store.files['offer/offer.md'].content).toBe('# Offer v1')
    expect(api.get).toHaveBeenCalledWith('/api/brain/files/offer/offer.md/versions')
  })

  it('sync posts then reloads everything', async () => {
    mockReads()
    api.post.mockResolvedValueOnce({ data: { data: { synced: 3 } } })
    const store = useBrainStore()
    const res = await store.sync()
    expect(res.success).toBe(true)
    expect(api.post).toHaveBeenCalledWith('/api/brain/sync')
    expect(api.get).toHaveBeenCalledWith('/api/brain/tree')
    expect(store.syncing).toBe(false)
  })

  it('handleFileUpdated patches the tree entry, readiness and cached file', async () => {
    mockReads()
    const store = useBrainStore()
    await store.fetchAll()
    await store.fetchFile('offer/offer.md')
    store.handleFileUpdated({ path: 'offer/offer.md', status: 'filled', version: 7, updated_at: '2026-09-16T12:00:00Z' })
    expect(store.treeEntry('offer/offer.md')).toMatchObject({ status: 'filled', version: 7 })
    expect(store.readiness.files.find((f) => f.path === 'offer/offer.md').status).toBe('filled')
    expect(store.files['offer/offer.md'].version).toBe(7)
    // Unknown path is ignored without throwing
    expect(() => store.handleFileUpdated({ path: 'nope.md', status: 'filled' })).not.toThrow()
  })
})

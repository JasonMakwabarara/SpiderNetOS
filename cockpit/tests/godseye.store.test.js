// God's Eye store — Vitest unit tests.
// The /api/godseye/snapshot envelope, the six-column contract, the "off"
// state being distinct from an error, and polling that stops when it should.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('../src/services/api.js', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import api from '../src/services/api.js'
import { useGodsEyeStore, STATES, POLL_MS } from '../src/stores/godseye.js'
import godseyeFixture from './fixtures/godseye.json'
import { httpError } from './helpers/harness.js'

describe("god's eye store", () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('unwraps the snapshot and keeps the six characters in order', async () => {
    api.get.mockResolvedValueOnce({ data: godseyeFixture })
    const store = useGodsEyeStore()

    const res = await store.fetchSnapshot()

    expect(res.success).toBe(true)
    expect(api.get).toHaveBeenCalledWith('/api/godseye/snapshot')
    expect(store.characters.map((c) => c.slug)).toEqual(['atlas', 'hannah', 'forge', 'sentinel', 'prism', 'nexus'])
    expect(store.totals.active).toBe(2)
    expect(store.live).toHaveLength(2)
    expect(store.needsYou).toHaveLength(2)
    expect(store.fallback).toBe(false)
    expect(store.lastFetched).toBeTruthy()
  })

  it('exposes only the characters that are actually working', async () => {
    api.get.mockResolvedValueOnce({ data: godseyeFixture })
    const store = useGodsEyeStore()
    await store.fetchSnapshot()

    expect(store.working.map((c) => c.slug)).toEqual(['nexus'])
    // An empty column is still a column: Forge and Sentinel are on the board.
    expect(store.characters.filter((c) => c.skills.length === 0).map((c) => c.slug)).toEqual(['forge', 'sentinel'])
    expect(store.empty).toBe(false)
  })

  it('every state has a label and a dot colour', () => {
    const store = useGodsEyeStore()
    for (const key of ['working', 'waiting', 'failing', 'idle', 'off']) {
      expect(STATES[key]).toBeTruthy()
      expect(store.stateOf(key).label).toBeTruthy()
      expect(store.stateOf(key).dot).toBeTruthy()
    }
    // An unknown state never renders blank.
    expect(store.stateOf('nonsense')).toEqual(STATES.idle)
  })

  it('a board with nothing switched on reports empty rather than broken', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: {
          characters: [{ slug: 'atlas', display_name: 'Atlas', state: 'off', skills: [], runs: 0, cost_usd: 0 }],
          totals: { runs: 0 }, live: [], needs_you: [], breaker: { paused: false, scopes: [] },
        },
      },
    })
    const store = useGodsEyeStore()
    await store.fetchSnapshot()

    expect(store.empty).toBe(true)
    expect(store.error).toBeNull()
  })

  it('a 403 means the board is off, not that the request failed', async () => {
    api.get.mockRejectedValueOnce(httpError(403, { message: "God's Eye is off for Wall Co." }))
    const store = useGodsEyeStore()

    const res = await store.fetchSnapshot()

    expect(res.success).toBe(false)
    expect(store.disabled).toBe(true)
    // Not a fallback: showing sample agents for a board that is switched off
    // would be worse than showing nothing.
    expect(store.fallback).toBe(false)
    expect(store.characters).toEqual([])
    // The server's own words, not the client's placeholder.
    expect(store.error).toBe("God's Eye is off for Wall Co.")
  })

  it('any other failure falls back to the fixture with an inline notice', async () => {
    api.get.mockRejectedValueOnce(httpError(500, { message: 'boom' }))
    const store = useGodsEyeStore()

    await store.fetchSnapshot()

    expect(store.fallback).toBe(true)
    expect(store.disabled).toBe(false)
    expect(store.characters).toHaveLength(6)
    expect(store.error).toContain('Showing sample data')
  })

  it('polls on an interval, skips hidden tabs, and stops when told to', async () => {
    vi.useFakeTimers()
    api.get.mockResolvedValue({ data: godseyeFixture })
    const store = useGodsEyeStore()

    store.startPolling()
    await vi.advanceTimersByTimeAsync(POLL_MS)
    expect(api.get).toHaveBeenCalledTimes(1)

    // A background tab has no business holding the wire open.
    const hidden = vi.spyOn(document, 'hidden', 'get').mockReturnValue(true)
    await vi.advanceTimersByTimeAsync(POLL_MS)
    expect(api.get).toHaveBeenCalledTimes(1)
    hidden.mockReturnValue(false)

    await vi.advanceTimersByTimeAsync(POLL_MS)
    expect(api.get).toHaveBeenCalledTimes(2)

    store.stopPolling()
    await vi.advanceTimersByTimeAsync(POLL_MS * 3)
    expect(api.get).toHaveBeenCalledTimes(2)
  })

  it('does not poll a board that is switched off', async () => {
    vi.useFakeTimers()
    api.get.mockRejectedValue(httpError(403, { message: 'off' }))
    const store = useGodsEyeStore()

    await store.fetchSnapshot()
    store.startPolling()
    await vi.advanceTimersByTimeAsync(POLL_MS * 3)

    expect(api.get).toHaveBeenCalledTimes(1)
  })
})

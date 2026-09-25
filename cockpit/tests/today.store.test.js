// Today store — Vitest unit tests.
// /api/today envelope, the seven-item cap, dismiss / skip, money at
// stake, and the fixture fallback with an inline error.
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('../src/services/api.js', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import api from '../src/services/api.js'
import { useTodayStore, MAX_ITEMS } from '../src/stores/today.js'
import todayFixture from './fixtures/today.json'
import { httpError } from './helpers/harness.js'

describe('today store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
  })

  it('fetchToday unwraps the brief and caps items at seven', async () => {
    api.get.mockResolvedValueOnce({ data: todayFixture })
    const store = useTodayStore()
    const res = await store.fetchToday()
    expect(res.success).toBe(true)
    expect(api.get).toHaveBeenCalledWith('/api/today')
    expect(todayFixture.data.items.length).toBeGreaterThan(MAX_ITEMS)
    expect(store.items).toHaveLength(MAX_ITEMS)
    expect(store.hiddenCount).toBe(todayFixture.data.items.length - MAX_ITEMS)
    expect(store.overnight).toHaveLength(3)
    expect(store.oneMoreQuestion.path).toBe('offer/offer.md')
    expect(store.brief.date).toBe('2026-09-16')
  })

  it('every item carries exactly one action', async () => {
    api.get.mockResolvedValueOnce({ data: todayFixture })
    const store = useTodayStore()
    await store.fetchToday()
    for (const item of store.items) {
      expect(typeof item.action.path).toBe('string')
      expect(typeof item.action.label).toBe('string')
    }
  })

  it('dismiss drops an item and lets the next one in; skipQuestion hides the chip', async () => {
    api.get.mockResolvedValueOnce({ data: todayFixture })
    const store = useTodayStore()
    await store.fetchToday()
    const first = store.items[0].id
    store.dismiss(first)
    expect(store.items.map((i) => i.id)).not.toContain(first)
    expect(store.items).toHaveLength(MAX_ITEMS)
    expect(store.hiddenCount).toBe(0)
    store.dismiss(first) // idempotent
    expect(store.dismissed).toEqual([first])

    store.skipQuestion()
    expect(store.oneMoreQuestion).toBeNull()
    store.reset()
    expect(store.oneMoreQuestion).not.toBeNull()
    expect(store.items[0].id).toBe(first)
  })

  it('sums money at stake across the visible items', async () => {
    api.get.mockResolvedValueOnce({ data: todayFixture })
    const store = useTodayStore()
    await store.fetchToday()
    expect(store.moneyAtStake).toBe(240 + 8500)
  })

  it('falls back to the fixture with an inline error when /api/today fails', async () => {
    api.get.mockRejectedValueOnce(httpError(404))
    const store = useTodayStore()
    const res = await store.fetchToday()
    expect(res.success).toBe(false)
    expect(store.fallback).toBe(true)
    expect(store.error).toContain('HTTP 404')
    expect(store.items).toHaveLength(MAX_ITEMS)
    expect(store.loading).toBe(false)
  })

  it('tolerates a sparse payload', async () => {
    api.get.mockResolvedValueOnce({ data: { data: { date: '2026-09-17' } } })
    const store = useTodayStore()
    await store.fetchToday()
    expect(store.items).toEqual([])
    expect(store.overnight).toEqual([])
    expect(store.oneMoreQuestion).toBeNull()
  })
})

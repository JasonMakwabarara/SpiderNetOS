/**
 * Usage store tests (Vitest + @pinia/testing)
 *
 * Verifies:
 *   - Correct response-path parsing (v2 contract: daily_usage.breakdown)
 *   - total_calls mapped, NOT request_count
 *   - usageChartData uses total_calls
 *   - usageByResource groups from flat breakdown (§11.8 Option A)
 *   - dailyTotals populated separately from breakdown
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useUsageStore } from '../src/stores/usage.js'
import fixture from './fixtures/usage_daily_v2.json'

// Mock axios
vi.mock('axios', () => {
  const axiosMock = {
    get: vi.fn(),
    put: vi.fn(),
    post: vi.fn(),
  }
  return { default: axiosMock }
})

import axios from 'axios'

describe('useUsageStore — v2 contract', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('parses breakdown from daily_usage.breakdown (not data.data)', async () => {
    axios.get.mockResolvedValueOnce({ data: fixture })

    const store = useUsageStore()
    await store.fetchDailyUsage(30)

    expect(store.dailyUsage).toHaveLength(3)  // 3 rows in fixture
    expect(store.dailyUsage[0]).toHaveProperty('total_calls')
    expect(store.dailyUsage[0]).not.toHaveProperty('request_count')  // v1 field gone
  })

  it('populates dailyTotals from daily_usage.daily_totals', async () => {
    axios.get.mockResolvedValueOnce({ data: fixture })

    const store = useUsageStore()
    await store.fetchDailyUsage(30)

    expect(store.dailyTotals).toHaveLength(2)
    expect(store.dailyTotals[0]).toHaveProperty('total_calls')
    expect(store.dailyTotals[0]).not.toHaveProperty('request_count')
  })

  it('usageChartData maps total_calls to requests field', async () => {
    axios.get.mockResolvedValueOnce({ data: fixture })

    const store = useUsageStore()
    await store.fetchDailyUsage(30)

    const chart = store.usageChartData
    // Chart data uses daily_totals when available
    expect(chart.length).toBeGreaterThan(0)
    chart.forEach(row => {
      expect(row).toHaveProperty('requests')
      expect(typeof row.requests).toBe('number')
      // Must not be NaN or undefined
      expect(Number.isFinite(row.requests)).toBe(true)
    })
  })

  it('usageByResource groups flat breakdown by resource_type', async () => {
    axios.get.mockResolvedValueOnce({ data: fixture })

    const store = useUsageStore()
    await store.fetchDailyUsage(30)

    const byResource = store.usageByResource
    // Fixture has 'inference' and 'embedding'
    expect(byResource).toHaveProperty('inference')
    expect(byResource).toHaveProperty('embedding')

    // inference totals: 1234 + 900 = 2134 calls
    expect(byResource.inference.calls).toBe(2134)
    // embedding totals: 88 calls
    expect(byResource.embedding.calls).toBe(88)
  })

  it('handles empty response gracefully', async () => {
    axios.get.mockResolvedValueOnce({ data: { contract_version: '2', daily_usage: {} } })

    const store = useUsageStore()
    await store.fetchDailyUsage(30)

    expect(store.dailyUsage).toEqual([])
    expect(store.dailyTotals).toEqual([])
    expect(store.usageChartData).toEqual([])
  })

  it('sends X-Usage-Contract: 2 header', async () => {
    axios.get.mockResolvedValueOnce({ data: fixture })

    const store = useUsageStore()
    await store.fetchDailyUsage(30)

    const callArgs = axios.get.mock.calls[0]
    const config   = callArgs[1] || {}
    expect(config.headers?.['X-Usage-Contract']).toBe('2')
  })
})

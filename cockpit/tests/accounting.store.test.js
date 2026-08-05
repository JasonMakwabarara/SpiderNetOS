// Accounting store — Vitest unit tests.
// Covers the mappings round-trip payload, export request payload,
// spend-summary query params, posting execution patching, and the
// download URL helper.
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

// Mock the api service used by the accounting store
vi.mock('../src/services/api.js', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    defaults: { baseURL: 'https://api.spidernetos.test' },
  },
}))

import api from '../src/services/api.js'
import { useAccountingStore } from '../src/stores/accounting.js'

const MAPPINGS_PAYLOAD = {
  mappings: [
    { id: 'map_1', category_id: 'cat_travel', category_name: 'Travel', gl_account_id: 'gl_6100' },
    { id: 'map_2', category_id: 'cat_meals', category_name: 'Meals', gl_account_id: null },
  ],
  chart_accounts: [
    { id: 'gl_6100', code: '6100', name: 'Travel Expense' },
    { id: 'gl_6200', code: '6200', name: 'Meals & Entertainment' },
  ],
}

describe('accounting store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('fetchMappings unwraps mappings + chart accounts from the envelope', async () => {
    api.get.mockResolvedValueOnce({ data: { data: MAPPINGS_PAYLOAD } })
    const store = useAccountingStore()

    const res = await store.fetchMappings()
    expect(res.success).toBe(true)
    expect(api.get).toHaveBeenCalledWith('/api/financial/accounting/mappings')
    expect(store.mappings).toHaveLength(2)
    expect(store.mappings[0].category_id).toBe('cat_travel')
    expect(store.chartAccounts).toHaveLength(2)
    expect(store.chartAccounts[1].code).toBe('6200')
  })

  it('saveMappings round-trips the {mappings: list} payload and updates state', async () => {
    const list = [
      { id: 'map_1', category_id: 'cat_travel', gl_account_id: 'gl_6100' },
      { id: 'map_2', category_id: 'cat_meals', gl_account_id: 'gl_6200' },
    ]
    api.put.mockResolvedValueOnce({
      data: { data: { ...MAPPINGS_PAYLOAD, mappings: list } },
    })
    const store = useAccountingStore()

    const res = await store.saveMappings(list)
    expect(res.success).toBe(true)
    expect(api.put).toHaveBeenCalledWith('/api/financial/accounting/mappings', { mappings: list })
    expect(store.mappings).toEqual(list)
  })

  it('saveMappings falls back to the submitted list when the response is empty', async () => {
    const list = [{ id: 'map_1', category_id: 'cat_travel', gl_account_id: 'gl_6200' }]
    api.put.mockResolvedValueOnce({ data: {} })
    const store = useAccountingStore()

    await store.saveMappings(list)
    expect(store.mappings).toEqual(list)
  })

  it('requestExport posts the {export_type, period_start, period_end} payload and prepends the run', async () => {
    api.post.mockResolvedValueOnce({
      data: { data: { id: 'exp_run_1', export_type: 'quickbooks', status: 'pending' } },
    })
    const store = useAccountingStore()
    store.exports = [{ id: 'exp_run_0', status: 'generated' }]

    const res = await store.requestExport({
      export_type: 'quickbooks',
      period_start: '2026-07-01',
      period_end: '2026-07-31',
    })
    expect(res.success).toBe(true)
    expect(api.post).toHaveBeenCalledWith('/api/financial/accounting/exports', {
      export_type: 'quickbooks',
      period_start: '2026-07-01',
      period_end: '2026-07-31',
    })
    expect(store.exports[0].id).toBe('exp_run_1')
    expect(store.exports).toHaveLength(2)
  })

  it('fetchSpendSummary passes group_by + period params', async () => {
    api.get.mockResolvedValueOnce({ data: { data: { total: '1200.00', series: [] } } })
    const store = useAccountingStore()

    const res = await store.fetchSpendSummary({ group_by: 'vendor', period: '90d' })
    expect(res.success).toBe(true)
    expect(api.get).toHaveBeenCalledWith('/api/financial/spend/summary', {
      params: { group_by: 'vendor', period: '90d' },
    })
    expect(store.spendSummary.total).toBe('1200.00')
  })

  it('fetchSpendSummary omits params it was not given', async () => {
    api.get.mockResolvedValueOnce({ data: { data: {} } })
    const store = useAccountingStore()

    await store.fetchSpendSummary()
    expect(api.get).toHaveBeenCalledWith('/api/financial/spend/summary', { params: {} })
  })

  it('fetchPostings passes the status filter and unwraps the list', async () => {
    api.get.mockResolvedValueOnce({
      data: { data: [{ id: 'post_1', status: 'draft' }] },
    })
    const store = useAccountingStore()

    await store.fetchPostings({ status: 'draft' })
    expect(api.get).toHaveBeenCalledWith('/api/financial/accounting/postings', {
      params: { status: 'draft' },
    })
    expect(store.postings).toHaveLength(1)
    expect(store.draftPostings).toHaveLength(1)
  })

  it('executePosting patches the posting status in place', async () => {
    api.post.mockResolvedValueOnce({
      data: { data: { id: 'post_1', status: 'posted' } },
    })
    const store = useAccountingStore()
    store.postings = [{ id: 'post_1', status: 'draft', description: 'July reimbursements' }]

    const res = await store.executePosting('post_1')
    expect(res.success).toBe(true)
    expect(api.post).toHaveBeenCalledWith('/api/financial/accounting/postings/post_1/execute')
    expect(store.postings[0].status).toBe('posted')
    // Untouched fields survive the merge
    expect(store.postings[0].description).toBe('July reimbursements')
  })

  it('downloadUrl builds the export download path off the api base', () => {
    const store = useAccountingStore()
    expect(store.downloadUrl('exp_run_9')).toBe(
      'https://api.spidernetos.test/api/financial/accounting/exports/exp_run_9/download',
    )
  })

  it('saveSchedule PUTs when the payload carries an id and merges the result', async () => {
    api.put.mockResolvedValueOnce({
      data: { data: { id: 'sch_1', frequency: 'weekly', enabled: false } },
    })
    const store = useAccountingStore()
    store.schedules = [{ id: 'sch_1', export_type: 'xero', frequency: 'monthly', enabled: true }]

    await store.saveSchedule({ id: 'sch_1', enabled: false, frequency: 'weekly' })
    expect(api.put).toHaveBeenCalledWith('/api/financial/accounting/schedules/sch_1', {
      id: 'sch_1',
      enabled: false,
      frequency: 'weekly',
    })
    expect(store.schedules[0].frequency).toBe('weekly')
    expect(store.schedules[0].enabled).toBe(false)
    expect(store.schedules[0].export_type).toBe('xero')
  })

  it('surfaces {success:false, error} on failure', async () => {
    api.get.mockRejectedValueOnce({ response: { data: { message: 'nope' } } })
    const store = useAccountingStore()

    const res = await store.fetchMappings()
    expect(res.success).toBe(false)
    expect(res.error).toBe('nope')
    expect(store.error).toBe('nope')
  })
})

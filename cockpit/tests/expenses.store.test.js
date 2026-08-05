// Expenses store — Vitest unit tests.
// Covers envelope unwrapping, scope param passing, multipart receipt
// upload, optimistic approve patching, and realtime patch fanout.
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

// Mock the api service used by the expenses store
vi.mock('../src/services/api.js', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}))

import api from '../src/services/api.js'
import { useExpensesStore } from '../src/stores/expenses.js'
import fixture from './fixtures/expense_report.json'

describe('expenses store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
  })

  it('unwraps the Laravel paginated envelope ({data:{data:[...]}})', async () => {
    api.get.mockResolvedValueOnce({ data: { data: { data: [fixture] } } })
    const store = useExpensesStore()
    const res = await store.fetchExpenses()
    expect(res.success).toBe(true)
    expect(store.expenses).toHaveLength(1)
    expect(store.expenses[0].id).toBe('exp_001')
  })

  it('unwraps the plain envelope ({data:[...]})', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [fixture] } })
    const store = useExpensesStore()
    await store.fetchExpenses()
    expect(store.expenses[0].id).toBe('exp_001')
  })

  it('passes scope + status params and persists scope', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [] } })
    const store = useExpensesStore()
    await store.fetchExpenses({ scope: 'team', status: 'awaiting_approval' })
    expect(api.get).toHaveBeenCalledWith('/api/financial/expenses', {
      params: { scope: 'team', status: 'awaiting_approval' },
    })
    expect(store.scope).toBe('team')
  })

  it('uploadReceipt sends FormData with explicit multipart header', async () => {
    api.post.mockResolvedValueOnce({ data: { data: fixture } })
    const store = useExpensesStore()
    const file = new File(['x'], 'receipt.jpg', { type: 'image/jpeg' })

    const res = await store.uploadReceipt('exp_001', 'li_1', file)
    expect(res.success).toBe(true)

    const [url, body, config] = api.post.mock.calls[0]
    expect(url).toBe('/api/financial/expenses/exp_001/items/li_1/receipt')
    expect(body).toBeInstanceOf(FormData)
    expect(body.get('file')).toBe(file)
    expect(config.headers['Content-Type']).toBe('multipart/form-data')
    expect(typeof config.onUploadProgress).toBe('function')
  })

  it('approve posts to the approval engine and optimistically patches status', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [fixture] } })
    api.post.mockResolvedValueOnce({ data: { data: { id: 'apr_777', status: 'approved' } } })
    const store = useExpensesStore()
    await store.fetchExpenses()

    const res = await store.approve('exp_001', 'ok by me')
    expect(res.success).toBe(true)
    expect(api.post).toHaveBeenCalledWith('/api/approvals/apr_777/approve', { comment: 'ok by me' })
    expect(store.expenses[0].status).toBe('approved')
  })

  it('approve fails cleanly when no approval_id is attached', async () => {
    const store = useExpensesStore()
    store.expenses = [{ id: 'exp_x', status: 'awaiting_approval' }]
    const res = await store.approve('exp_x')
    expect(res.success).toBe(false)
    expect(api.post).not.toHaveBeenCalled()
  })

  it('handleExpenseUpdated patches both the list and currentExpense', async () => {
    const store = useExpensesStore()
    store.expenses = [{ ...fixture }]
    store.currentExpense = { ...fixture }

    store.handleExpenseUpdated({ id: 'exp_001', status: 'approved', total_amount: '412.50' })

    expect(store.expenses[0].status).toBe('approved')
    expect(store.currentExpense.status).toBe('approved')
    // Untouched fields survive the merge
    expect(store.currentExpense.title).toBe(fixture.title)
  })

  it('handleExpenseCreated prepends without duplicating', () => {
    const store = useExpensesStore()
    store.handleExpenseCreated({ ...fixture })
    store.handleExpenseCreated({ ...fixture })
    expect(store.expenses).toHaveLength(1)
  })
})

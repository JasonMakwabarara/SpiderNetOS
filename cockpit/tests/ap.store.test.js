// AP (bill pay) store — Vitest unit tests.
// Covers envelope unwrapping, tab counts (summary + fallback), the
// manual mark-paid payload, multipart bill upload, and realtime patching.
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

// Mock the api service used by the AP store
vi.mock('../src/services/api.js', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}))

import api from '../src/services/api.js'
import { useApStore, PAYMENT_METHODS } from '../src/stores/ap.js'

const bill = {
  id: 'bill_001',
  vendor_id: 'ven_001',
  vendor_name: 'Cardiff Print Co.',
  bill_number: 'INV-2041',
  status: 'draft',
  currency: 'USD',
  total_amount: '640.00',
  due_date: '2026-08-20',
  approval_id: null,
  line_items: [{ id: 'li_1', description: 'Brochures', amount: '640.00' }],
  documents: [],
}

describe('ap store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
  })

  it('exposes the payment method vocabulary', () => {
    expect(PAYMENT_METHODS).toEqual({ manual: 'Manual', dodo: 'Dodo' })
  })

  it('unwraps the Laravel paginated envelope ({data:{data:[...]}})', async () => {
    api.get.mockResolvedValueOnce({ data: { data: { data: [bill] } } })
    const store = useApStore()
    const res = await store.fetchBills()
    expect(res.success).toBe(true)
    expect(store.bills).toHaveLength(1)
    expect(store.bills[0].id).toBe('bill_001')
  })

  it('unwraps the plain envelope ({data:[...]})', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [bill] } })
    const store = useApStore()
    await store.fetchBills()
    expect(store.bills[0].id).toBe('bill_001')
  })

  it('passes the status param through fetchBills', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [] } })
    const store = useApStore()
    await store.fetchBills({ status: 'awaiting_approval' })
    expect(api.get).toHaveBeenCalledWith('/api/financial/bills', {
      params: { status: 'awaiting_approval' },
    })
  })

  it('tabCounts falls back to counting loaded bills by status', () => {
    const store = useApStore()
    store.bills = [
      { id: 'b1', status: 'draft' },
      { id: 'b2', status: 'draft' },
      { id: 'b3', status: 'awaiting_approval' },
      { id: 'b4', status: 'approved' },
      { id: 'b5', status: 'scheduled' },
      { id: 'b6', status: 'paid' },
    ]
    expect(store.tabCounts).toEqual({
      draft: 2,
      awaiting_approval: 1,
      scheduled: 2, // approved + scheduled
      paid: 1,
    })
  })

  it('tabCounts prefers the server summary when loaded', () => {
    const store = useApStore()
    store.bills = [{ id: 'b1', status: 'draft' }]
    store.summary = { by_status: { draft: 7, awaiting_approval: 3, approved: 1, scheduled: 2, paid: 9 } }
    expect(store.tabCounts).toEqual({
      draft: 7,
      awaiting_approval: 3,
      scheduled: 3,
      paid: 9,
    })
  })

  it('overdueCount counts unpaid bills past their due date', () => {
    const store = useApStore()
    store.bills = [
      { id: 'b1', status: 'awaiting_approval', due_date: '2001-01-01' }, // overdue
      { id: 'b2', status: 'paid', due_date: '2001-01-01' },              // settled
      { id: 'b3', status: 'draft', due_date: '2999-01-01' },             // future
    ]
    expect(store.overdueCount).toBe(1)
  })

  it('markPaid posts method manual + bank_reference', async () => {
    api.post.mockResolvedValueOnce({ data: { data: { ...bill, status: 'paid', payment_method: 'manual' } } })
    const store = useApStore()
    store.bills = [{ ...bill, status: 'scheduled' }]

    const res = await store.markPaid('bill_001', { bank_reference: 'FPS-88213-XN' })
    expect(res.success).toBe(true)

    const [url, payload] = api.post.mock.calls[0]
    expect(url).toBe('/api/financial/bills/bill_001/mark-paid')
    expect(payload.method).toBe('manual')
    expect(payload.bank_reference).toBe('FPS-88213-XN')
    expect(payload.paid_at).toBeUndefined()
    expect(store.bills[0].status).toBe('paid')
  })

  it('markPaid includes paid_at only when provided', async () => {
    api.post.mockResolvedValueOnce({ data: { data: { ...bill, status: 'paid' } } })
    const store = useApStore()
    await store.markPaid('bill_001', { bank_reference: 'REF-1', paid_at: '2026-08-01' })
    const [, payload] = api.post.mock.calls[0]
    expect(payload).toMatchObject({ method: 'manual', bank_reference: 'REF-1', paid_at: '2026-08-01' })
  })

  it('uploadBillDoc sends FormData with explicit multipart header', async () => {
    api.post.mockResolvedValueOnce({ data: { data: { id: 'doc_1', extraction_status: 'pending' } } })
    const store = useApStore()
    const file = new File(['x'], 'bill.pdf', { type: 'application/pdf' })

    const res = await store.uploadBillDoc(file)
    expect(res.success).toBe(true)

    const [url, body, config] = api.post.mock.calls[0]
    expect(url).toBe('/api/financial/bills/upload')
    expect(body).toBeInstanceOf(FormData)
    expect(body.get('file')).toBe(file)
    expect(config.headers['Content-Type']).toBe('multipart/form-data')
    expect(typeof config.onUploadProgress).toBe('function')
  })

  it('scheduleBill posts scheduled_for', async () => {
    api.post.mockResolvedValueOnce({ data: { data: { ...bill, status: 'scheduled', scheduled_for: '2026-09-01' } } })
    const store = useApStore()
    await store.scheduleBill('bill_001', { scheduled_for: '2026-09-01' })
    expect(api.post).toHaveBeenCalledWith('/api/financial/bills/bill_001/schedule', { scheduled_for: '2026-09-01' })
  })

  it('handleBillUpdated patches both the list and currentBill', () => {
    const store = useApStore()
    store.bills = [{ ...bill }]
    store.currentBill = { ...bill }

    store.handleBillUpdated({ id: 'bill_001', status: 'approved', total_amount: '655.00' })

    expect(store.bills[0].status).toBe('approved')
    expect(store.currentBill.status).toBe('approved')
    expect(store.currentBill.total_amount).toBe('655.00')
    // Untouched fields survive the merge
    expect(store.currentBill.vendor_name).toBe(bill.vendor_name)
  })

  it('handleBillCreated prepends without duplicating', () => {
    const store = useApStore()
    store.handleBillCreated({ ...bill })
    store.handleBillCreated({ ...bill })
    expect(store.bills).toHaveLength(1)
  })

  it('fetchAging + fetchSummary land in state', async () => {
    api.get
      .mockResolvedValueOnce({ data: { data: { buckets: { current: { amount: '10.00' } } } } })
      .mockResolvedValueOnce({ data: { data: { by_status: { draft: 4 } } } })
    const store = useApStore()
    await store.fetchAging()
    await store.fetchSummary()
    expect(api.get).toHaveBeenNthCalledWith(1, '/api/financial/bills/aging')
    expect(api.get).toHaveBeenNthCalledWith(2, '/api/financial/bills/summary')
    expect(store.aging.buckets.current.amount).toBe('10.00')
    expect(store.summary.by_status.draft).toBe(4)
  })
})

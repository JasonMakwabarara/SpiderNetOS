/**
 * Accounts Payable (Bill Pay) Pinia Store - SpiderNet OS
 *
 * Bill lifecycle: draft → submitted (awaiting_approval) → approved →
 * scheduled → paid; voided at any pre-paid stage. Vendor directory and
 * the AP aging report live here too.
 *
 * Backend is the Laravel financial pack ({data: ...} envelope; amounts
 * are decimal strings). Approval decisions run through the shared
 * ApprovalEngine — the bill payload carries `approval_id`.
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../services/api.js'

/** Payment method vocabulary → display labels. */
export const PAYMENT_METHODS = {
  manual: 'Manual',
  dodo: 'Dodo',
}

/** Unwrap the Laravel envelope (paginated or plain). */
function unwrapList(data) {
  return data?.data?.data || data?.data || []
}
function unwrapOne(data) {
  return data?.data || data || null
}

/** Overdue = due in the past and not yet settled/voided. */
function isOverdue(bill) {
  if (!bill?.due_date) return false
  if (['paid', 'voided'].includes(bill.status)) return false
  const due = new Date(bill.due_date)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return !Number.isNaN(due.getTime()) && due < today
}

export const useApStore = defineStore('ap', () => {
  // ── State ──────────────────────────────────────────────────────────

  /** @type {import('vue').Ref<Array<object>>} Bills (AP inbox) */
  const bills = ref([])

  /** @type {import('vue').Ref<object|null>} Currently viewed bill */
  const currentBill = ref(null)

  /** @type {import('vue').Ref<Array<object>>} Vendor directory */
  const vendors = ref([])

  /** @type {import('vue').Ref<object|null>} Currently viewed vendor */
  const currentVendor = ref(null)

  /** @type {import('vue').Ref<object|null>} AP aging report */
  const aging = ref(null)

  /** @type {import('vue').Ref<object|null>} Bill counts / totals summary */
  const summary = ref(null)

  /** @type {import('vue').Ref<boolean>} Loading state */
  const loading = ref(false)

  /** @type {import('vue').Ref<string|null>} Last error message */
  const error = ref(null)

  // ── Getters ────────────────────────────────────────────────────────

  /** Bills grouped by status: { draft: [...], awaiting_approval: [...], ... } */
  const billsByStatus = computed(() => {
    const by = {}
    for (const bill of bills.value) {
      const s = bill.status || 'draft'
      ;(by[s] ||= []).push(bill)
    }
    return by
  })

  /**
   * Counts for the inbox tabs. Prefers the server summary
   * (GET /bills/summary), falls back to counting loaded bills.
   * `scheduled` includes approved-but-unscheduled bills, mirroring the
   * Scheduled tab filter.
   */
  const tabCounts = computed(() => {
    const s = summary.value?.by_status || summary.value || {}
    const fromBills = (status) => (billsByStatus.value[status] || []).length
    const count = (status) => Number(s[status] ?? fromBills(status))
    return {
      draft: count('draft'),
      awaiting_approval: count('awaiting_approval'),
      scheduled: count('approved') + count('scheduled'),
      paid: count('paid'),
    }
  })

  /** Bills past due and not yet paid/voided. */
  const overdueCount = computed(() => bills.value.filter(isOverdue).length)

  // ── Actions: bills ─────────────────────────────────────────────────

  /**
   * Fetch bills.
   * @param {{status?: string}} [opts]
   */
  async function fetchBills({ status } = {}) {
    loading.value = true
    error.value = null
    try {
      const params = {}
      if (status) params.status = status
      const { data } = await api.get('/api/financial/bills', { params })
      bills.value = unwrapList(data)
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch bills'
      return { success: false, error: error.value }
    } finally {
      loading.value = false
    }
  }

  /** Fetch a single bill into currentBill. */
  async function fetchBill(id) {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get(`/api/financial/bills/${id}`)
      currentBill.value = unwrapOne(data)
      return { success: true, data: currentBill.value }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch bill'
      return { success: false, error: error.value }
    }
    finally {
      loading.value = false
    }
  }

  /** Create a draft bill. */
  async function createBill(payload) {
    try {
      const { data } = await api.post('/api/financial/bills', payload)
      const created = unwrapOne(data)
      if (created) bills.value.unshift(created)
      currentBill.value = created
      return { success: true, data: created }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to create bill'
      return { success: false, error: error.value }
    }
  }

  /** Update a draft bill. */
  async function updateBill(id, payload) {
    try {
      const { data } = await api.put(`/api/financial/bills/${id}`, payload)
      const updated = unwrapOne(data)
      _patchBill(updated)
      return { success: true, data: updated }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to update bill'
      return { success: false, error: error.value }
    }
  }

  /** Submit a draft bill into the approval chain. */
  async function submitBill(id) {
    try {
      const { data } = await api.post(`/api/financial/bills/${id}/submit`)
      const updated = unwrapOne(data)
      _patchBill(updated)
      return { success: true, data: updated }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to submit bill'
      return { success: false, error: error.value }
    }
  }

  /**
   * Schedule an approved bill for payment.
   * @param {string} id - Bill id.
   * @param {{scheduled_for: string}} payload - ISO date.
   */
  async function scheduleBill(id, { scheduled_for }) {
    try {
      const { data } = await api.post(`/api/financial/bills/${id}/schedule`, { scheduled_for })
      const updated = unwrapOne(data)
      _patchBill(updated)
      return { success: true, data: updated }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to schedule bill'
      return { success: false, error: error.value }
    }
  }

  /**
   * Mark a bill paid (manual settlement outside a payment rail).
   * @param {string} id - Bill id.
   * @param {{bank_reference: string, paid_at?: string, method?: string}} opts
   */
  async function markPaid(id, { bank_reference, paid_at, method = 'manual' } = {}) {
    try {
      const payload = { method, bank_reference }
      if (paid_at) payload.paid_at = paid_at
      const { data } = await api.post(`/api/financial/bills/${id}/mark-paid`, payload)
      const updated = unwrapOne(data)
      _patchBill(updated)
      return { success: true, data: updated }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to mark bill paid'
      return { success: false, error: error.value }
    }
  }

  /** Void a bill (draft or stuck pre-payment). */
  async function voidBill(id) {
    try {
      const { data } = await api.post(`/api/financial/bills/${id}/void`)
      const updated = unwrapOne(data)
      _patchBill(updated)
      return { success: true, data: updated }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to void bill'
      return { success: false, error: error.value }
    }
  }

  /**
   * Upload a bill document (pdf-first) for AI extraction.
   * NOTE: the shared axios instance defaults Content-Type to JSON, so the
   * multipart override is REQUIRED for the file to reach Laravel intact.
   * @param {File} file - Bill document (pdf/jpeg/png/webp).
   * @param {(pct:number)=>void} [onProgress] - 0-100 progress callback.
   */
  async function uploadBillDoc(file, onProgress) {
    try {
      const form = new FormData()
      form.append('file', file)
      const { data } = await api.post('/api/financial/bills/upload', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
        onUploadProgress: (e) => {
          if (onProgress && e.total) onProgress(Math.round((e.loaded / e.total) * 100))
        },
      })
      const created = unwrapOne(data)
      // Upload may return a spend document or a draft bill shell — if it
      // carries a bill, surface it in the inbox immediately.
      const bill = created?.bill || (created?.status && created?.vendor_id !== undefined ? created : null)
      if (bill?.id && !bills.value.some((b) => b.id === bill.id)) {
        bills.value.unshift(bill)
      }
      return { success: true, data: created }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to upload bill document'
      return { success: false, error: error.value }
    }
  }

  // ── Actions: vendors ───────────────────────────────────────────────

  /** Fetch the vendor directory. */
  async function fetchVendors() {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get('/api/financial/vendors')
      vendors.value = unwrapList(data)
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch vendors'
      return { success: false, error: error.value }
    } finally {
      loading.value = false
    }
  }

  /** Fetch a single vendor into currentVendor. */
  async function fetchVendor(id) {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get(`/api/financial/vendors/${id}`)
      currentVendor.value = unwrapOne(data)
      return { success: true, data: currentVendor.value }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch vendor'
      return { success: false, error: error.value }
    } finally {
      loading.value = false
    }
  }

  /** Create a vendor. */
  async function createVendor(payload) {
    try {
      const { data } = await api.post('/api/financial/vendors', payload)
      const created = unwrapOne(data)
      if (created) vendors.value.unshift(created)
      return { success: true, data: created }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to create vendor'
      return { success: false, error: error.value }
    }
  }

  /** Update a vendor. */
  async function updateVendor(id, payload) {
    try {
      const { data } = await api.put(`/api/financial/vendors/${id}`, payload)
      const updated = unwrapOne(data)
      _patchVendor(updated)
      return { success: true, data: updated }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to update vendor'
      return { success: false, error: error.value }
    }
  }

  /** Archive a vendor (soft-hide from the directory). */
  async function archiveVendor(id) {
    try {
      const { data } = await api.post(`/api/financial/vendors/${id}/archive`)
      const updated = unwrapOne(data)
      if (updated?.id) {
        _patchVendor(updated)
      } else {
        _patchVendor({ id, status: 'archived' })
      }
      return { success: true, data: updated }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to archive vendor'
      return { success: false, error: error.value }
    }
  }

  // ── Actions: reports ───────────────────────────────────────────────

  /** Fetch the AP aging report. */
  async function fetchAging() {
    try {
      const { data } = await api.get('/api/financial/bills/aging')
      aging.value = unwrapOne(data)
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch aging report'
      return { success: false, error: error.value }
    }
  }

  /** Fetch the bills summary (tab counts / totals). */
  async function fetchSummary() {
    try {
      const { data } = await api.get('/api/financial/bills/summary')
      summary.value = unwrapOne(data)
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch bills summary'
      return { success: false, error: error.value }
    }
  }

  // ── Internal helpers ───────────────────────────────────────────────

  /** Merge a partial/updated bill into the list AND currentBill. */
  function _patchBill(updated) {
    if (!updated?.id) return
    const index = bills.value.findIndex((b) => b.id === updated.id)
    if (index !== -1) {
      bills.value[index] = { ...bills.value[index], ...updated }
    }
    if (currentBill.value?.id === updated.id) {
      currentBill.value = { ...currentBill.value, ...updated }
    }
  }

  /** Merge a partial/updated vendor into the list AND currentVendor. */
  function _patchVendor(updated) {
    if (!updated?.id) return
    const index = vendors.value.findIndex((v) => v.id === updated.id)
    if (index !== -1) {
      vendors.value[index] = { ...vendors.value[index], ...updated }
    }
    if (currentVendor.value?.id === updated.id) {
      currentVendor.value = { ...currentVendor.value, ...updated }
    }
  }

  // ── Real-time handlers ─────────────────────────────────────────────

  function handleBillCreated(data) {
    if (!data?.id) return
    if (!bills.value.some((b) => b.id === data.id)) {
      bills.value.unshift(data)
    }
  }

  function handleBillUpdated(data) {
    _patchBill(data)
  }

  // ── Expose ─────────────────────────────────────────────────────────
  return {
    // State
    bills,
    currentBill,
    vendors,
    currentVendor,
    aging,
    summary,
    loading,
    error,
    // Getters
    billsByStatus,
    tabCounts,
    overdueCount,
    // Actions: bills
    fetchBills,
    fetchBill,
    createBill,
    updateBill,
    submitBill,
    scheduleBill,
    markPaid,
    voidBill,
    uploadBillDoc,
    // Actions: vendors
    fetchVendors,
    fetchVendor,
    createVendor,
    updateVendor,
    archiveVendor,
    // Actions: reports
    fetchAging,
    fetchSummary,
    // Real-time
    handleBillCreated,
    handleBillUpdated,
  }
})

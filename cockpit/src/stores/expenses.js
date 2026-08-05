/**
 * Expenses Pinia Store - SpiderNet OS
 *
 * Expense report lifecycle: draft → submitted (awaiting_approval) →
 * approved / rejected → reimbursed. Receipts attach per line item.
 *
 * Backend is the Laravel financial pack ({data: ...} envelope; amounts
 * are decimal strings). Approval decisions run through the shared
 * ApprovalEngine — the expense payload carries `approval_id`.
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../services/api.js'

/** Unwrap the Laravel envelope (paginated or plain). */
function unwrapList(data) {
  return data?.data?.data || data?.data || []
}
function unwrapOne(data) {
  return data?.data || data || null
}

export const useExpensesStore = defineStore('expenses', () => {
  // ── State ──────────────────────────────────────────────────────────

  /** @type {import('vue').Ref<Array<object>>} Expense reports (current scope) */
  const expenses = ref([])

  /** @type {import('vue').Ref<object|null>} Currently viewed expense report */
  const currentExpense = ref(null)

  /** @type {import('vue').Ref<Array<object>>} Expense categories */
  const categories = ref([])

  /** @type {import('vue').Ref<boolean>} Loading state */
  const loading = ref(false)

  /** @type {import('vue').Ref<string|null>} Last error message */
  const error = ref(null)

  /** @type {import('vue').Ref<string>} Active scope: 'mine' | 'team' */
  const scope = ref('mine')

  // ── Getters ────────────────────────────────────────────────────────

  const myExpenses = computed(() =>
    scope.value === 'mine' ? expenses.value : []
  )
  const teamQueue = computed(() =>
    expenses.value.filter((e) => e.status === 'awaiting_approval')
  )
  const pendingApprovalCount = computed(() => teamQueue.value.length)

  // ── Actions ────────────────────────────────────────────────────────

  /**
   * Fetch expense reports.
   * @param {{scope?: string, status?: string}} [opts]
   */
  async function fetchExpenses({ scope: nextScope, status } = {}) {
    loading.value = true
    error.value = null
    try {
      if (nextScope) scope.value = nextScope
      const params = { scope: scope.value }
      if (status) params.status = status
      const { data } = await api.get('/api/financial/expenses', { params })
      expenses.value = unwrapList(data)
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch expenses'
      return { success: false, error: error.value }
    } finally {
      loading.value = false
    }
  }

  /** Fetch a single expense report into currentExpense. */
  async function fetchExpense(id) {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get(`/api/financial/expenses/${id}`)
      currentExpense.value = unwrapOne(data)
      return { success: true, data: currentExpense.value }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch expense'
      return { success: false, error: error.value }
    } finally {
      loading.value = false
    }
  }

  /** Fetch expense categories for the line-item select. */
  async function fetchCategories() {
    try {
      const { data } = await api.get('/api/financial/expense-categories')
      categories.value = unwrapList(data)
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch categories'
      return { success: false, error: error.value }
    }
  }

  /** Create a draft expense report. */
  async function createExpense(payload) {
    try {
      const { data } = await api.post('/api/financial/expenses', payload)
      const created = unwrapOne(data)
      if (created) expenses.value.unshift(created)
      currentExpense.value = created
      return { success: true, data: created }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to create expense'
      return { success: false, error: error.value }
    }
  }

  /** Update a draft expense report header. */
  async function updateExpense(id, payload) {
    try {
      const { data } = await api.put(`/api/financial/expenses/${id}`, payload)
      const updated = unwrapOne(data)
      _patch(updated)
      return { success: true, data: updated }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to update expense'
      return { success: false, error: error.value }
    }
  }

  /** Add a line item to an expense report. */
  async function addItem(id, item) {
    try {
      const { data } = await api.post(`/api/financial/expenses/${id}/items`, item)
      const updated = unwrapOne(data)
      _patch(updated)
      return { success: true, data: updated }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to add line item'
      return { success: false, error: error.value }
    }
  }

  /** Remove a line item. */
  async function removeItem(id, itemId) {
    try {
      const { data } = await api.delete(`/api/financial/expenses/${id}/items/${itemId}`)
      const updated = unwrapOne(data)
      if (updated?.id) {
        _patch(updated)
      } else if (currentExpense.value?.id === id) {
        currentExpense.value = {
          ...currentExpense.value,
          line_items: (currentExpense.value.line_items || []).filter((li) => li.id !== itemId),
        }
      }
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to remove line item'
      return { success: false, error: error.value }
    }
  }

  /** Submit a draft report into the approval chain. */
  async function submitExpense(id) {
    try {
      const { data } = await api.post(`/api/financial/expenses/${id}/submit`)
      const updated = unwrapOne(data)
      _patch(updated)
      return { success: true, data: updated }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to submit expense'
      return { success: false, error: error.value }
    }
  }

  /** Void a report (draft or stuck). */
  async function voidExpense(id) {
    try {
      const { data } = await api.post(`/api/financial/expenses/${id}/void`)
      const updated = unwrapOne(data)
      _patch(updated)
      return { success: true, data: updated }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to void expense'
      return { success: false, error: error.value }
    }
  }

  /**
   * Approve the expense's pending approval step.
   * The decision endpoint lives on the shared ApprovalEngine — the
   * expense payload carries `approval_id`.
   * @param {string} id - Expense id.
   * @param {string} [comment] - Optional audit comment.
   */
  async function approve(id, comment) {
    const expense = _find(id)
    const approvalId = expense?.approval_id || currentExpense.value?.approval_id
    if (!approvalId) {
      error.value = 'Expense has no approval attached'
      return { success: false, error: error.value }
    }
    try {
      const payload = {}
      if (comment) payload.comment = comment
      const { data } = await api.post(`/api/approvals/${approvalId}/approve`, payload)
      // Optimistic status patch; realtime .expense.updated will reconcile.
      _patch({ id, status: 'approved' })
      return { success: true, data }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to approve expense'
      return { success: false, error: error.value }
    }
  }

  /**
   * Reject the expense's pending approval step.
   * @param {string} id - Expense id.
   * @param {string} reason - Required audit reason.
   */
  async function reject(id, reason) {
    const expense = _find(id)
    const approvalId = expense?.approval_id || currentExpense.value?.approval_id
    if (!approvalId) {
      error.value = 'Expense has no approval attached'
      return { success: false, error: error.value }
    }
    try {
      const payload = {}
      if (reason) payload.reason = reason
      const { data } = await api.post(`/api/approvals/${approvalId}/reject`, payload)
      _patch({ id, status: 'rejected' })
      return { success: true, data }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to reject expense'
      return { success: false, error: error.value }
    }
  }

  /**
   * Upload a receipt for a line item.
   * NOTE: the shared axios instance defaults Content-Type to JSON, so the
   * multipart override is REQUIRED for the file to reach Laravel intact.
   * @param {string} id - Expense id.
   * @param {string} itemId - Line item id.
   * @param {File} file - Receipt file (jpeg/png/webp/pdf).
   * @param {(pct:number)=>void} [onProgress] - 0-100 progress callback.
   */
  async function uploadReceipt(id, itemId, file, onProgress) {
    try {
      const form = new FormData()
      form.append('file', file)
      const { data } = await api.post(
        `/api/financial/expenses/${id}/items/${itemId}/receipt`,
        form,
        {
          headers: { 'Content-Type': 'multipart/form-data' },
          onUploadProgress: (e) => {
            if (onProgress && e.total) onProgress(Math.round((e.loaded / e.total) * 100))
          },
        },
      )
      const updated = unwrapOne(data)
      if (updated?.id) _patch(updated)
      return { success: true, data: updated }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to upload receipt'
      return { success: false, error: error.value }
    }
  }

  /** Remove an uploaded receipt document. */
  async function removeReceipt(id, documentId) {
    try {
      const { data } = await api.delete(`/api/financial/expenses/${id}/receipts/${documentId}`)
      const updated = unwrapOne(data)
      if (updated?.id) {
        _patch(updated)
      } else if (currentExpense.value?.id === id) {
        currentExpense.value = {
          ...currentExpense.value,
          documents: (currentExpense.value.documents || []).filter((d) => d.id !== documentId),
        }
      }
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to remove receipt'
      return { success: false, error: error.value }
    }
  }

  // ── Internal helpers ───────────────────────────────────────────────

  function _find(id) {
    return expenses.value.find((e) => e.id === id)
  }

  /** Merge a partial/updated expense into the list AND currentExpense. */
  function _patch(updated) {
    if (!updated?.id) return
    const index = expenses.value.findIndex((e) => e.id === updated.id)
    if (index !== -1) {
      expenses.value[index] = { ...expenses.value[index], ...updated }
    }
    if (currentExpense.value?.id === updated.id) {
      currentExpense.value = { ...currentExpense.value, ...updated }
    }
  }

  // ── Real-time handlers ─────────────────────────────────────────────

  function handleExpenseCreated(data) {
    if (!data?.id) return
    if (!expenses.value.some((e) => e.id === data.id)) {
      expenses.value.unshift(data)
    }
  }

  function handleExpenseUpdated(data) {
    _patch(data)
  }

  // ── Expose ─────────────────────────────────────────────────────────
  return {
    // State
    expenses,
    currentExpense,
    categories,
    loading,
    error,
    scope,
    // Getters
    myExpenses,
    teamQueue,
    pendingApprovalCount,
    // Actions
    fetchExpenses,
    fetchExpense,
    fetchCategories,
    createExpense,
    updateExpense,
    addItem,
    removeItem,
    submitExpense,
    voidExpense,
    approve,
    reject,
    uploadReceipt,
    removeReceipt,
    // Real-time
    handleExpenseCreated,
    handleExpenseUpdated,
  }
})

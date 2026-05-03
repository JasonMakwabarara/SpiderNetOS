/**
 * Approvals Pinia Store - SpiderNet OS v3.2
 *
 * Manages approval workflows for agent actions that require
 * human-in-the-loop confirmation.
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000'

export const useApprovalsStore = defineStore('approvals', () => {
  // ── State ──────────────────────────────────────────────────────────

  /** @type {import('vue').Ref<Array<object>>} All approvals */
  const approvals = ref([])

  /** @type {import('vue').Ref<object|null>} Currently viewed approval */
  const currentApproval = ref(null)

  /** @type {import('vue').Ref<boolean>} Loading state */
  const loading = ref(false)

  /** @type {import('vue').Ref<string|null>} Last error message */
  const error = ref(null)

  // ── Getters ────────────────────────────────────────────────────────

  const pendingApprovals = computed(() => approvals.value.filter((a) => a.status === 'pending'))
  const approvedApprovals = computed(() => approvals.value.filter((a) => a.status === 'approved'))
  const rejectedApprovals = computed(() => approvals.value.filter((a) => a.status === 'rejected'))
  const pendingCount = computed(() => pendingApprovals.value.length)

  // ── Actions ────────────────────────────────────────────────────────

  /**
   * Fetch approvals from the API, optionally filtered by status.
   * @param {string} [status] - Filter by approval status ('pending', 'approved', 'rejected').
   */
  async function fetchApprovals(status) {
    loading.value = true
    error.value = null

    try {
      const params = {}
      if (status) {
        params.status = status
      }
      const response = await axios.get(`${API_URL}/api/approvals`, { params })
      approvals.value = response.data.data || response.data || []
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch approvals'
    } finally {
      loading.value = false
    }
  }

  /**
   * Approve an approval request.
   * @param {string} id - The approval ID.
   * @param {string} [comment] - Optional approval comment.
   * @returns {Promise<object>} Result with success flag.
   */
  async function approve(id, comment) {
    try {
      const payload = {}
      if (comment) {
        payload.comment = comment
      }
      const response = await axios.post(`${API_URL}/api/approvals/${id}/approve`, payload)

      // Update local state
      const index = approvals.value.findIndex((a) => a.id === id)
      if (index !== -1) {
        approvals.value[index] = response.data.data || {
          ...approvals.value[index],
          status: 'approved',
          comment,
          resolved_at: new Date().toISOString(),
        }
      }

      return { success: true, data: response.data }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to approve'
      return { success: false, error: error.value }
    }
  }

  /**
   * Reject an approval request.
   * @param {string} id - The approval ID.
   * @param {string} [reason] - Reason for rejection.
   * @returns {Promise<object>} Result with success flag.
   */
  async function reject(id, reason) {
    try {
      const payload = {}
      if (reason) {
        payload.reason = reason
      }
      const response = await axios.post(`${API_URL}/api/approvals/${id}/reject`, payload)

      // Update local state
      const index = approvals.value.findIndex((a) => a.id === id)
      if (index !== -1) {
        approvals.value[index] = response.data.data || {
          ...approvals.value[index],
          status: 'rejected',
          reason,
          resolved_at: new Date().toISOString(),
        }
      }

      return { success: true, data: response.data }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to reject'
      return { success: false, error: error.value }
    }
  }

  // ── Real-time handlers ─────────────────────────────────────────────

  function handleApprovalCreated(data) {
    approvals.value.unshift(data)
  }

  function handleApprovalUpdated(data) {
    const index = approvals.value.findIndex((a) => a.id === data.id)
    if (index !== -1) {
      approvals.value[index] = { ...approvals.value[index], ...data }
    }
  }

  // ── Expose ─────────────────────────────────────────────────────────
  return {
    // State
    approvals,
    currentApproval,
    loading,
    error,
    // Getters
    pendingApprovals,
    approvedApprovals,
    rejectedApprovals,
    pendingCount,
    // Actions
    fetchApprovals,
    approve,
    reject,
    // Real-time
    handleApprovalCreated,
    handleApprovalUpdated,
  }
})

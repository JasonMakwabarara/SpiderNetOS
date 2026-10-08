/**
 * Approvals Pinia Store - SpiderNet OS v3.2
 *
 * Manages approval workflows for agent actions that require
 * human-in-the-loop confirmation.
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../services/api.js'

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
      const response = await api.get('/api/approvals', { params })
      approvals.value = response.data.data || response.data || []
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch approvals'
    } finally {
      loading.value = false
    }
  }

  /**
   * Decide an approval on the version the approver was shown.
   *
   * `version` is the version of the content the approver saw — taken from
   * the review snapshot the page captured when it showed that content, never
   * looked up here at click time: a refresh between display and click would
   * otherwise pair the new version with the old content still on screen. The
   * server refuses a decision whose version is not the one that exists now;
   * on that refusal the list is reloaded so the current version is shown,
   * and nothing is retried — the approver decides again on what they see.
   *
   * @param {'approve'|'reject'} action
   * @param {string} id - The approval ID.
   * @param {string} [reason] - Comment or rejection reason, kept in the audit log.
   * @param {string|null} [version] - The version shown to the approver.
   * @returns {Promise<object>} { success, data } or { success: false, error, reason, stale }
   */
  async function decide(action, id, reason, version) {
    try {
      const payload = {}
      if (reason) {
        // The API reads `reason`; this used to send `comment`, which it ignored.
        payload.reason = reason
      }
      if (version) {
        payload.version_hash = version
      }
      const response = await api.post(`/api/approvals/${id}/${action}`, payload)

      const index = approvals.value.findIndex((a) => a.id === id)
      if (index !== -1) {
        approvals.value[index] = response.data.data || {
          ...approvals.value[index],
          status: action === 'approve' ? 'approved' : 'rejected',
          reason,
          resolved_at: new Date().toISOString(),
        }
      }

      return { success: true, data: response.data }
    } catch (err) {
      const body = err.response?.data || {}
      error.value = body.message || body.error || (action === 'approve' ? 'Failed to approve' : 'Failed to reject')
      const stale = String(body.reason || '').startsWith('version_')
      if (stale) {
        await fetchApprovals()
      }
      return { success: false, error: error.value, reason: body.reason, stale }
    }
  }

  /** Approve on the version shown. See decide(). */
  function approve(id, reason, version) {
    return decide('approve', id, reason, version)
  }

  /** Reject the version shown; without a version it cancels the request. See decide(). */
  function reject(id, reason, version) {
    return decide('reject', id, reason, version)
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

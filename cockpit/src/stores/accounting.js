/**
 * Accounting Pinia Store - SpiderNet OS
 *
 * Phase 3 accounting automation: category → GL mappings, posting rules
 * (draft vs auto journal posting), journal postings, export runs
 * (QuickBooks / Xero / Generic CSV), export schedules, and the spend
 * analytics summary that powers /financial/spend.
 *
 * Backend is the Laravel financial pack ({data: ...} envelope; amounts
 * are decimal strings). Every action resolves to {success, error?}.
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../services/api.js'

/** Export format vocabulary → display labels. */
export const EXPORT_TYPES = {
  quickbooks: 'QuickBooks',
  xero: 'Xero',
  generic: 'Generic CSV',
}

/** Unwrap the Laravel envelope (paginated or plain). */
function unwrapList(data) {
  return data?.data?.data || data?.data || []
}
function unwrapOne(data) {
  return data?.data || data || null
}

export const useAccountingStore = defineStore('accounting', () => {
  // ── State ──────────────────────────────────────────────────────────

  /** @type {import('vue').Ref<Array<object>>} Category → GL account mappings */
  const mappings = ref([])

  /** @type {import('vue').Ref<Array<object>>} Chart of accounts (select options) */
  const chartAccounts = ref([])

  /** @type {import('vue').Ref<object|null>} Posting rules (mode/credit accounts) */
  const rules = ref(null)

  /** @type {import('vue').Ref<Array<object>>} Journal postings */
  const postings = ref([])

  /** @type {import('vue').Ref<Array<object>>} Export runs (history) */
  const exports = ref([])

  /** @type {import('vue').Ref<Array<object>>} Export schedules */
  const schedules = ref([])

  /** @type {import('vue').Ref<object|null>} Spend analytics summary */
  const spendSummary = ref(null)

  /** @type {import('vue').Ref<boolean>} Loading state */
  const loading = ref(false)

  /** @type {import('vue').Ref<string|null>} Last error message */
  const error = ref(null)

  // ── Getters ────────────────────────────────────────────────────────

  /** Draft postings awaiting execution. */
  const draftPostings = computed(() =>
    postings.value.filter((p) => p.status === 'draft')
  )

  /** Whether posting automation is fully on (auto mode + enabled). */
  const autoPostingOn = computed(() =>
    rules.value?.mode === 'auto' && !!rules.value?.enabled
  )

  // ── Actions: mappings ──────────────────────────────────────────────

  /**
   * Fetch category → GL mappings. The payload also carries the chart of
   * accounts used by the mapping selects:
   *   { mappings: [...], chart_accounts: [...] }
   */
  async function fetchMappings() {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get('/api/financial/accounting/mappings')
      const payload = unwrapOne(data)
      if (Array.isArray(payload)) {
        mappings.value = payload
      } else {
        mappings.value = payload?.mappings || []
        if (payload?.chart_accounts) chartAccounts.value = payload.chart_accounts
      }
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch GL mappings'
      return { success: false, error: error.value }
    } finally {
      loading.value = false
    }
  }

  /**
   * Save the full mapping list (save-all semantics).
   * @param {Array<object>} list - [{id?, category_id, gl_account_id}, ...]
   */
  async function saveMappings(list) {
    try {
      const { data } = await api.put('/api/financial/accounting/mappings', { mappings: list })
      const payload = unwrapOne(data)
      if (Array.isArray(payload)) {
        mappings.value = payload
      } else if (payload?.mappings) {
        mappings.value = payload.mappings
        if (payload.chart_accounts) chartAccounts.value = payload.chart_accounts
      } else {
        mappings.value = list
      }
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to save GL mappings'
      return { success: false, error: error.value }
    }
  }

  // ── Actions: posting rules ─────────────────────────────────────────

  /** Fetch posting rules (mode auto/draft, credit accounts, enabled). */
  async function fetchRules() {
    try {
      const { data } = await api.get('/api/financial/accounting/rules')
      rules.value = unwrapOne(data)
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch posting rules'
      return { success: false, error: error.value }
    }
  }

  /**
   * Save posting rules.
   * @param {{mode: string, enabled: boolean}} payload - plus credit account ids.
   */
  async function saveRules(payload) {
    try {
      const { data } = await api.put('/api/financial/accounting/rules', payload)
      rules.value = unwrapOne(data) || { ...(rules.value || {}), ...payload }
      return { success: true, data: rules.value }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to save posting rules'
      return { success: false, error: error.value }
    }
  }

  // ── Actions: postings ──────────────────────────────────────────────

  /**
   * Fetch journal postings.
   * @param {{status?: string}} [opts]
   */
  async function fetchPostings({ status } = {}) {
    loading.value = true
    error.value = null
    try {
      const params = {}
      if (status) params.status = status
      const { data } = await api.get('/api/financial/accounting/postings', { params })
      postings.value = unwrapList(data)
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch postings'
      return { success: false, error: error.value }
    } finally {
      loading.value = false
    }
  }

  /** Execute (post) a draft journal posting. */
  async function executePosting(id) {
    try {
      const { data } = await api.post(`/api/financial/accounting/postings/${id}/execute`)
      const updated = unwrapOne(data)
      _patchPosting(updated?.id ? updated : { id, status: 'posted' })
      return { success: true, data: updated }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to execute posting'
      return { success: false, error: error.value }
    }
  }

  // ── Actions: exports ───────────────────────────────────────────────

  /** Fetch export history. */
  async function fetchExports() {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get('/api/financial/accounting/exports')
      exports.value = unwrapList(data)
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch exports'
      return { success: false, error: error.value }
    } finally {
      loading.value = false
    }
  }

  /**
   * Request a new export run.
   * @param {{export_type: string, period_start: string, period_end: string}} payload
   */
  async function requestExport({ export_type, period_start, period_end }) {
    try {
      const { data } = await api.post('/api/financial/accounting/exports', {
        export_type,
        period_start,
        period_end,
      })
      const created = unwrapOne(data)
      if (created?.id) exports.value.unshift(created)
      return { success: true, data: created }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to request export'
      return { success: false, error: error.value }
    }
  }

  /**
   * Download URL for a generated export file (used as a plain <a href>).
   * @param {string} id - Export run id.
   */
  function downloadUrl(id) {
    const base = (api.defaults?.baseURL || '').replace(/\/$/, '')
    return `${base}/api/financial/accounting/exports/${id}/download`
  }

  // ── Actions: schedules ─────────────────────────────────────────────

  /** Fetch export schedules. */
  async function fetchSchedules() {
    try {
      const { data } = await api.get('/api/financial/accounting/schedules')
      schedules.value = unwrapList(data)
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch schedules'
      return { success: false, error: error.value }
    }
  }

  /**
   * Create or update an export schedule (id present → update).
   * @param {{id?: string, export_type?: string, frequency?: string, enabled?: boolean}} payload
   */
  async function saveSchedule(payload) {
    try {
      const { data } = payload?.id
        ? await api.put(`/api/financial/accounting/schedules/${payload.id}`, payload)
        : await api.post('/api/financial/accounting/schedules', payload)
      const saved = unwrapOne(data)
      if (saved?.id) {
        const index = schedules.value.findIndex((s) => s.id === saved.id)
        if (index !== -1) {
          schedules.value[index] = { ...schedules.value[index], ...saved }
        } else {
          schedules.value.unshift(saved)
        }
      }
      return { success: true, data: saved }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to save schedule'
      return { success: false, error: error.value }
    }
  }

  /** Delete an export schedule. */
  async function deleteSchedule(id) {
    try {
      await api.delete(`/api/financial/accounting/schedules/${id}`)
      schedules.value = schedules.value.filter((s) => s.id !== id)
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to delete schedule'
      return { success: false, error: error.value }
    }
  }

  // ── Actions: spend analytics ───────────────────────────────────────

  /**
   * Fetch the spend analytics summary.
   * @param {{group_by?: string, period?: string}} [opts] - group_by
   *   category|vendor, period 30d|90d|12m.
   */
  async function fetchSpendSummary({ group_by, period } = {}) {
    loading.value = true
    error.value = null
    try {
      const params = {}
      if (group_by) params.group_by = group_by
      if (period) params.period = period
      const { data } = await api.get('/api/financial/spend/summary', { params })
      spendSummary.value = unwrapOne(data)
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch spend summary'
      return { success: false, error: error.value }
    } finally {
      loading.value = false
    }
  }

  // ── Internal helpers ───────────────────────────────────────────────

  /** Merge a partial/updated posting into the list. */
  function _patchPosting(updated) {
    if (!updated?.id) return
    const index = postings.value.findIndex((p) => p.id === updated.id)
    if (index !== -1) {
      postings.value[index] = { ...postings.value[index], ...updated }
    }
  }

  // ── Expose ─────────────────────────────────────────────────────────
  return {
    // State
    mappings,
    chartAccounts,
    rules,
    postings,
    exports,
    schedules,
    spendSummary,
    loading,
    error,
    // Getters
    draftPostings,
    autoPostingOn,
    // Actions
    fetchMappings,
    saveMappings,
    fetchRules,
    saveRules,
    fetchPostings,
    executePosting,
    fetchExports,
    requestExport,
    downloadUrl,
    fetchSchedules,
    saveSchedule,
    deleteSchedule,
    fetchSpendSummary,
  }
})

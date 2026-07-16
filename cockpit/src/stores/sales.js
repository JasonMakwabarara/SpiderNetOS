/**
 * Sales CRM Pinia Store - SpiderNetOS Lead-to-Sale Funnel bundle.
 *
 * Manages the lead pipeline surfaced by the sales-crm feature pack.
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../services/api.js'

export const STAGES = [
  'captured', 'qualified', 'engaged', 'meeting_booked',
  'proposal', 'won', 'lost', 'recycled',
]

export const useSalesStore = defineStore('sales', () => {
  // ── State ──────────────────────────────────────────────────────────
  const leads = ref([])
  const summary = ref({ by_stage: {}, stale_leads: 0, open_deals: 0 })
  const loading = ref(false)
  const error = ref(null)
  const stageFilter = ref('')

  // ── Getters ────────────────────────────────────────────────────────
  const leadsByStage = computed(() => {
    const grouped = Object.fromEntries(STAGES.map((s) => [s, []]))
    for (const lead of leads.value) {
      ;(grouped[lead.stage] ||= []).push(lead)
    }
    return grouped
  })

  // ── Actions ────────────────────────────────────────────────────────
  async function fetchLeads(stage) {
    loading.value = true
    error.value = null
    stageFilter.value = stage || ''
    try {
      const params = stage ? { stage, per_page: 100 } : { per_page: 100 }
      const { data } = await api.get('/api/sales/leads', { params })
      leads.value = data?.data?.data || data?.data || []
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch leads'
    } finally {
      loading.value = false
    }
  }

  async function fetchSummary() {
    try {
      const { data } = await api.get('/api/sales/leads/pipeline-summary')
      summary.value = data?.data || summary.value
    } catch {
      // defaults retained
    }
  }

  async function createLead(payload) {
    try {
      const { data } = await api.post('/api/sales/leads', payload)
      leads.value.unshift(data.data)
      return { success: true, data: data.data }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to create lead'
      return { success: false, error: error.value }
    }
  }

  async function transitionStage(id, stage, reason) {
    try {
      const { data } = await api.post(`/api/sales/leads/${id}/stage`, { stage, reason })
      const idx = leads.value.findIndex((l) => l.id === id)
      if (idx !== -1) leads.value[idx] = data.data
      return { success: true, data: data.data }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to update lead stage'
      return { success: false, error: error.value }
    }
  }

  return {
    leads, summary, loading, error, stageFilter,
    leadsByStage,
    fetchLeads, fetchSummary, createLead, transitionStage,
  }
})

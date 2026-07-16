/**
 * Operating Model Pinia Store — Priestley Five A's (Alignment, Awareness,
 * Accountability, Activity, Assets) productized for the tenant.
 */
import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '../services/api.js'

export const useOperatingStore = defineStore('operating', () => {
  const alignment = ref(null)
  const orgChart = ref(null)
  const scoreboard = ref([])
  const awareness = ref([])
  const weeklyRhythm = ref([])
  const assets = ref({})
  const loading = ref(false)
  const error = ref(null)

  async function fetchAll() {
    loading.value = true
    error.value = null
    try {
      const [a, o, s, w, r, as] = await Promise.all([
        api.get('/api/operating/alignment'),
        api.get('/api/operating/org-chart'),
        api.get('/api/operating/scoreboard'),
        api.get('/api/operating/awareness'),
        api.get('/api/operating/weekly-rhythm'),
        api.get('/api/operating/assets'),
      ])
      alignment.value = a.data?.data || null
      orgChart.value = o.data?.data || null
      scoreboard.value = s.data?.data || []
      awareness.value = w.data?.data || []
      weeklyRhythm.value = r.data?.data || []
      assets.value = as.data?.data || {}
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load operating model'
    } finally {
      loading.value = false
    }
  }

  async function saveAlignment(payload) {
    try {
      const { data } = await api.put('/api/operating/alignment', payload)
      alignment.value = data.data
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to save alignment'
      return { success: false, error: error.value }
    }
  }

  async function raiseAwareness(payload) {
    try {
      const { data } = await api.post('/api/operating/awareness', payload)
      awareness.value.unshift(data.data)
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to raise awareness item'
      return { success: false, error: error.value }
    }
  }

  async function resolveAwareness(id) {
    try {
      const { data } = await api.post(`/api/operating/awareness/${id}/resolve`)
      const idx = awareness.value.findIndex((i) => i.id === id)
      if (idx !== -1) awareness.value[idx] = data.data
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to resolve awareness item'
      return { success: false, error: error.value }
    }
  }

  return {
    alignment, orgChart, scoreboard, awareness, weeklyRhythm, assets, loading, error,
    fetchAll, saveAlignment, raiseAwareness, resolveAwareness,
  }
})

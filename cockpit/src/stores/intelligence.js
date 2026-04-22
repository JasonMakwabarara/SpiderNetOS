import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000'

export const useIntelligenceStore = defineStore('intelligence', () => {
  // State
  const dailyBrief = ref(null)
  const anomalies = ref([])
  const learningLog = ref([])
  const systemHealth = ref(null)
  const isLoading = ref(false)
  const error = ref(null)

  // Getters
  const criticalAnomalies = computed(() => anomalies.value.filter(a => a.severity === 'critical'))
  const highAnomalies = computed(() => anomalies.value.filter(a => a.severity === 'high'))
  const mediumAnomalies = computed(() => anomalies.value.filter(a => a.severity === 'medium'))
  const lowAnomalies = computed(() => anomalies.value.filter(a => a.severity === 'low'))

  const unresolvedAnomalies = computed(() => anomalies.value.filter(a => !a.resolved))
  const anomalyCount = computed(() => unresolvedAnomalies.value.length)

  const healthScore = computed(() => {
    if (!systemHealth.value) return null
    return systemHealth.value.overall_score || 0
  })

  // Actions
  async function fetchDailyBrief() {
    isLoading.value = true
    error.value = null

    try {
      const response = await axios.get(`${API_URL}/api/intelligence/brief`)
      dailyBrief.value = response.data.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch daily brief'
    } finally {
      isLoading.value = false
    }
  }

  async function fetchAnomalies(filters = {}) {
    try {
      const response = await axios.get(`${API_URL}/api/intelligence/anomalies`, { params: filters })
      anomalies.value = response.data.data || []
    } catch (err) {
      console.error('Failed to fetch anomalies:', err)
    }
  }

  async function fetchLearningLog(limit = 20) {
    try {
      const response = await axios.get(`${API_URL}/api/intelligence/learning`, { params: { limit } })
      learningLog.value = response.data.data || []
    } catch (err) {
      console.error('Failed to fetch learning log:', err)
    }
  }

  async function fetchSystemHealth() {
    try {
      const response = await axios.get(`${API_URL}/api/intelligence/health`)
      systemHealth.value = response.data.data
    } catch (err) {
      console.error('Failed to fetch system health:', err)
    }
  }

  async function acknowledgeAnomaly(id) {
    try {
      const response = await axios.post(`${API_URL}/api/intelligence/anomalies/${id}/acknowledge`)
      const index = anomalies.value.findIndex(a => a.id === id)
      if (index !== -1) {
        anomalies.value[index] = response.data.data
      }
      return { success: true }
    } catch (err) {
      return { success: false, error: err.response?.data?.message }
    }
  }

  // Real-time handlers
  function handleAnomalyCreated(data) {
    anomalies.value.unshift(data)
  }

  function handleHealthUpdate(data) {
    systemHealth.value = { ...systemHealth.value, ...data }
  }

  return {
    dailyBrief,
    anomalies,
    learningLog,
    systemHealth,
    isLoading,
    error,
    criticalAnomalies,
    highAnomalies,
    mediumAnomalies,
    lowAnomalies,
    unresolvedAnomalies,
    anomalyCount,
    healthScore,
    fetchDailyBrief,
    fetchAnomalies,
    fetchLearningLog,
    fetchSystemHealth,
    acknowledgeAnomaly,
    handleAnomalyCreated,
    handleHealthUpdate
  }
})

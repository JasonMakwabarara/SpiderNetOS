import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000'

export const useUsageStore = defineStore('usage', () => {
  // State
  const dailyUsage = ref([])
  const dailyTotals = ref([])
  const monthlyUsage = ref([])
  const monthlyTotals = ref([])
  const budget = ref(null)
  const currentSpend = ref({ daily: 0, monthly: 0 })
  const isLoading = ref(false)
  const error = ref(null)

  // Getters
  const dailyRemaining = computed(() => {
    if (!budget.value) return 0
    return Math.max(0, budget.value.daily_limit - currentSpend.value.daily)
  })

  const monthlyRemaining = computed(() => {
    if (!budget.value) return 0
    return Math.max(0, budget.value.monthly_limit - currentSpend.value.monthly)
  })

  const dailyPercentUsed = computed(() => {
    if (!budget.value || budget.value.daily_limit === 0) return 0
    return (currentSpend.value.daily / budget.value.daily_limit) * 100
  })

  const monthlyPercentUsed = computed(() => {
    if (!budget.value || budget.value.monthly_limit === 0) return 0
    return (currentSpend.value.monthly / budget.value.monthly_limit) * 100
  })

  const isNearLimit = computed(() => {
    if (!budget.value) return false
    const threshold = budget.value.alert_threshold || 0.8
    return dailyPercentUsed.value >= threshold * 100 || monthlyPercentUsed.value >= threshold * 100
  })

  const isDegraded = computed(() => {
    if (!budget.value) return false
    return budget.value.action_at_limit === 'degrade' && 
           (dailyPercentUsed.value >= 100 || monthlyPercentUsed.value >= 100)
  })

  // Actions
  async function fetchBudget() {
    isLoading.value = true
    error.value = null
    
    try {
      const response = await axios.get(`${API_URL}/api/usage/budget`)
      budget.value = response.data.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch budget'
    } finally {
      isLoading.value = false
    }
  }

  async function fetchCurrentSpend() {
    try {
      const response = await axios.get(`${API_URL}/api/usage/current`)
      currentSpend.value = response.data.data
    } catch (err) {
      console.error('Failed to fetch current spend:', err)
    }
  }

  async function fetchDailyUsage(days = 30) {
    try {
      const response = await axios.get(`${API_URL}/api/usage/daily`, {
        params: { days },
        headers: { 'X-Usage-Contract': '2' }
      })
      const payload = response.data.daily_usage || {}
      dailyUsage.value   = payload.breakdown    || []
      dailyTotals.value  = payload.daily_totals || []
    } catch (err) {
      console.error('Failed to fetch daily usage:', err)
    }
  }

  async function fetchMonthlyUsage(months = 12) {
    try {
      const response = await axios.get(`${API_URL}/api/usage/monthly`, {
        params: { months },
        headers: { 'X-Usage-Contract': '2' }
      })
      const payload = response.data.monthly_usage || {}
      monthlyUsage.value  = payload.breakdown      || []
      monthlyTotals.value = payload.monthly_totals || []
    } catch (err) {
      console.error('Failed to fetch monthly usage:', err)
    }
  }

  async function updateBudget(budgetData) {
    isLoading.value = true
    error.value = null
    
    try {
      const response = await axios.put(`${API_URL}/api/usage/budget`, budgetData)
      budget.value = response.data.data
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to update budget'
      return { success: false, error: error.value }
    } finally {
      isLoading.value = false
    }
  }

  // Real-time updates
  function handleUsageUpdate(data) {
    currentSpend.value = {
      daily: data.daily || currentSpend.value.daily,
      monthly: data.monthly || currentSpend.value.monthly
    }
  }

  function handleBudgetAlert(data) {
    // Trigger notification or alert UI
    console.warn('Budget alert:', data)
  }

  // Helper functions for charts
  const usageChartData = computed(() => {
    // Use pre-computed daily totals when available; fall back to summing breakdown
    const source = dailyTotals.value.length ? dailyTotals.value : dailyUsage.value
    return source.map(day => ({
      date:     day.date,
      cost:     day.total_cost   ?? 0,
      tokens:   day.total_tokens ?? 0,
      requests: day.total_calls  ?? 0,  // canonical field — was request_count
    }))
  })

  // Derive per-resource view from v2 flat breakdown (§11.8 — Option A)
  const usageByResource = computed(() => {
    const grouped = {}
    dailyUsage.value.forEach(row => {
      const key = row.resource_type || 'unclassified'
      if (!grouped[key]) {
        grouped[key] = { cost: 0, tokens: 0, calls: 0 }
      }
      grouped[key].cost   += row.total_cost   ?? 0
      grouped[key].tokens += row.total_tokens ?? 0
      grouped[key].calls  += row.total_calls  ?? 0
    })
    return grouped
  })

  return {
    dailyUsage,
    dailyTotals,
    monthlyUsage,
    monthlyTotals,
    budget,
    currentSpend,
    isLoading,
    error,
    dailyRemaining,
    monthlyRemaining,
    dailyPercentUsed,
    monthlyPercentUsed,
    isNearLimit,
    isDegraded,
    fetchBudget,
    fetchCurrentSpend,
    fetchDailyUsage,
    fetchMonthlyUsage,
    updateBudget,
    handleUsageUpdate,
    handleBudgetAlert,
    usageChartData,
    usageByResource
  }
})

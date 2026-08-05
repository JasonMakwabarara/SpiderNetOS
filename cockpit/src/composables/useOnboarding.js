import { ref, computed } from 'vue'
import axios from 'axios'

const API_URL = import.meta.env.VITE_API_URL || ''

/**
 * useOnboarding
 *
 * Phase 1 onboarding persistence wire-up.
 * Manages the 6-step wizard state and API communication.
 */
export function useOnboarding() {
  const onboarding = ref(null)
  const completed = ref(false)
  const automationLevel = ref('assisted')
  const isLoading = ref(false)
  const error = ref(null)
  const currentStep = ref(0)

  const PERSISTED_STEPS = ['tenant', 'budget', 'invites', 'strictness', 'branding']
  const TOTAL_STEPS = 6 // 5 persisted + 1 observation

  const progress = computed(() => {
    if (!onboarding.value) return 0
    const completedSteps = PERSISTED_STEPS.filter(s => onboarding.value[s]).length
    return Math.min((completedSteps / PERSISTED_STEPS.length) * 100, 100)
  })

  const canComplete = computed(() => {
    if (!onboarding.value) return false
    return PERSISTED_STEPS.every(s => onboarding.value[s])
  })

  const isStepValid = computed(() => {
    const step = PERSISTED_STEPS[currentStep.value]
    if (!step) return true // Observability step doesn't need validation
    return !!onboarding.value?.[step]
  })

  async function load() {
    isLoading.value = true
    error.value = null
    try {
      const response = await axios.get(`${API_URL}/api/admin/onboarding`)
      onboarding.value = response.data.onboarding
      completed.value = response.data.completed
      automationLevel.value = response.data.automation_level || 'assisted'
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load onboarding state'
      throw err
    } finally {
      isLoading.value = false
    }
  }

  async function saveStep(step, data) {
    isLoading.value = true
    error.value = null
    try {
      const response = await axios.put(`${API_URL}/api/admin/onboarding`, {
        version: 1,
        step,
        data,
      })
      onboarding.value = response.data.onboarding
      return response.data
    } catch (err) {
      error.value = err.response?.data?.error || `Failed to save ${step}`
      throw err
    } finally {
      isLoading.value = false
    }
  }

  async function observe(step) {
    try {
      await axios.post(`${API_URL}/api/admin/onboarding/observe`, {
        version: 1,
        step,
      })
    } catch (err) {
      // Non-blocking: observations are for analytics only
      console.warn('Observation failed:', err)
    }
  }

  async function complete() {
    isLoading.value = true
    error.value = null
    try {
      const response = await axios.post(`${API_URL}/api/admin/onboarding/complete`)
      completed.value = true
      return response.data
    } catch (err) {
      error.value = err.response?.data?.error || 'Failed to complete onboarding'
      throw err
    } finally {
      isLoading.value = false
    }
  }

  async function updateAutomationLevel(level) {
    isLoading.value = true
    error.value = null
    try {
      const response = await axios.put(`${API_URL}/api/admin/tenant/automation-level`, {
        automation_level: level,
      })
      automationLevel.value = level
      return response.data
    } catch (err) {
      error.value = err.response?.data?.error || 'Failed to update automation level'
      throw err
    } finally {
      isLoading.value = false
    }
  }

  function goToStep(index) {
    if (index >= 0 && index < TOTAL_STEPS) {
      currentStep.value = index
    }
  }

  function nextStep() {
    if (currentStep.value < TOTAL_STEPS - 1) {
      currentStep.value++
    }
  }

  function prevStep() {
    if (currentStep.value > 0) {
      currentStep.value--
    }
  }

  return {
    // State
    onboarding,
    completed,
    automationLevel,
    isLoading,
    error,
    currentStep,
    // Computed
    progress,
    canComplete,
    isStepValid,
    totalSteps: TOTAL_STEPS,
    persistedSteps: PERSISTED_STEPS,
    // Actions
    load,
    saveStep,
    observe,
    complete,
    updateAutomationLevel,
    goToStep,
    nextStep,
    prevStep,
  }
}

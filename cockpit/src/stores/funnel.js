/**
 * Funnel Setup Pinia Store - discovery interview -> script draft -> approval
 * -> go-live pipeline for the sales-crm Lead-to-Sale Funnel bundle.
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../services/api.js'

export const useFunnelStore = defineStore('funnel', () => {
  const setup = ref(null)
  const next = ref(null)
  const loading = ref(false)
  const error = ref(null)

  const status = computed(() => setup.value?.status || 'purchased')
  const activeScript = computed(() => setup.value?.active_script)

  async function fetchStatus() {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get('/api/sales/funnel-setup')
      setup.value = data.data.funnel_setup
      next.value = data.data.next
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load funnel setup'
    } finally {
      loading.value = false
    }
  }

  async function startInterview() {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.post('/api/sales/funnel-setup/start')
      setup.value = data.data.funnel_setup
      next.value = data.data.next
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to start interview'
      return { success: false, error: error.value }
    } finally {
      loading.value = false
    }
  }

  async function answer(questionId, answerText) {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.post('/api/sales/funnel-setup/answer', {
        question_id: questionId,
        answer: answerText,
      })
      setup.value = data.data.funnel_setup
      next.value = data.data.next
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to save your answer'
      return { success: false, error: error.value }
    } finally {
      loading.value = false
    }
  }

  async function draftScript() {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.post('/api/sales/funnel-setup/draft-script')
      await fetchStatus()
      return { success: true, script: data.data.script }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to draft the sales script'
      return { success: false, error: error.value }
    } finally {
      loading.value = false
    }
  }

  async function reviseScript(scriptId, sections) {
    try {
      const { data } = await api.post(`/api/sales/scripts/${scriptId}/revise`, { sections })
      return { success: true, script: data.data.script }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to save script edits'
      return { success: false, error: error.value }
    }
  }

  async function submitScript(scriptId) {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.post(`/api/sales/scripts/${scriptId}/submit`)
      await fetchStatus()
      return { success: true, approval: data.data.approval }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to submit for approval'
      return { success: false, error: error.value }
    } finally {
      loading.value = false
    }
  }

  async function requestRevision() {
    try {
      const { data } = await api.post('/api/sales/funnel-setup/request-revision')
      setup.value = data.data.funnel_setup
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to request a revision'
      return { success: false, error: error.value }
    }
  }

  return {
    setup, next, loading, error, status, activeScript,
    fetchStatus, startInterview, answer, draftScript, reviseScript, submitScript, requestRevision,
  }
})

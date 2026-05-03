import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000'

export const useFlowsStore = defineStore('flows', () => {
  // State
  const flows = ref([])
  const currentFlow = ref(null)
  const executions = ref([])
  const currentExecution = ref(null)
  const isLoading = ref(false)
  const error = ref(null)
  const isExecuting = ref(false)

  // Getters
  const publishedFlows = computed(() => flows.value.filter(f => f.status === 'published'))
  const draftFlows = computed(() => flows.value.filter(f => f.status === 'draft'))
  const flowById = computed(() => (id) => flows.value.find(f => f.id === id))
  
  const recentExecutions = computed(() => {
    return executions.value
      .sort((a, b) => new Date(b.started_at) - new Date(a.started_at))
      .slice(0, 10)
  })

  // Actions
  async function fetchFlows() {
    isLoading.value = true
    error.value = null
    
    try {
      const response = await axios.get(`${API_URL}/api/flows`)
      flows.value = response.data.data || []
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch flows'
    } finally {
      isLoading.value = false
    }
  }

  async function fetchFlow(id) {
    isLoading.value = true
    error.value = null
    
    try {
      const response = await axios.get(`${API_URL}/api/flows/${id}`)
      currentFlow.value = response.data.data
      return currentFlow.value
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch flow'
      return null
    } finally {
      isLoading.value = false
    }
  }

  async function fetchExecutions(flowId) {
    try {
      const response = await axios.get(`${API_URL}/api/flows/${flowId}/executions`)
      executions.value = response.data.data || []
    } catch (err) {
      console.error('Failed to fetch executions:', err)
    }
  }

  async function createFlow(flowData) {
    isLoading.value = true
    error.value = null
    
    try {
      const response = await axios.post(`${API_URL}/api/flows`, flowData)
      flows.value.push(response.data.data)
      return { success: true, flow: response.data.data }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to create flow'
      return { success: false, error: error.value }
    } finally {
      isLoading.value = false
    }
  }

  async function updateFlow(id, flowData) {
    isLoading.value = true
    error.value = null
    
    try {
      const response = await axios.put(`${API_URL}/api/flows/${id}`, flowData)
      const index = flows.value.findIndex(f => f.id === id)
      if (index !== -1) {
        flows.value[index] = response.data.data
      }
      if (currentFlow.value?.id === id) {
        currentFlow.value = response.data.data
      }
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to update flow'
      return { success: false, error: error.value }
    } finally {
      isLoading.value = false
    }
  }

  async function deleteFlow(id) {
    try {
      await axios.delete(`${API_URL}/api/flows/${id}`)
      flows.value = flows.value.filter(f => f.id !== id)
      return { success: true }
    } catch (err) {
      return { success: false, error: err.response?.data?.message }
    }
  }

  async function publishFlow(id) {
    try {
      const response = await axios.post(`${API_URL}/api/flows/${id}/publish`)
      const index = flows.value.findIndex(f => f.id === id)
      if (index !== -1) {
        flows.value[index] = response.data.data
      }
      return { success: true }
    } catch (err) {
      return { success: false, error: err.response?.data?.message }
    }
  }

  async function executeFlow(flowId, context = {}) {
    isExecuting.value = true
    
    try {
      const response = await axios.post(`${API_URL}/api/flows/${flowId}/execute`, { context })
      currentExecution.value = response.data.data
      return { success: true, execution: response.data.data }
    } catch (err) {
      return { success: false, error: err.response?.data?.message }
    } finally {
      isExecuting.value = false
    }
  }

  async function fetchExecutionStatus(executionId) {
    try {
      const response = await axios.get(`${API_URL}/api/executions/${executionId}`)
      currentExecution.value = response.data.data
      return response.data.data
    } catch (err) {
      console.error('Failed to fetch execution status:', err)
      return null
    }
  }

  // DAG Builder helpers
  function addNode(flowId, nodeData) {
    const flow = flows.value.find(f => f.id === flowId)
    if (!flow) return
    
    if (!flow.dag) flow.dag = { nodes: [], edges: [] }
    if (!flow.dag.nodes) flow.dag.nodes = []
    
    flow.dag.nodes.push({
      id: `node_${Date.now()}`,
      ...nodeData
    })
  }

  function addEdge(flowId, sourceId, targetId, condition = null) {
    const flow = flows.value.find(f => f.id === flowId)
    if (!flow) return
    
    if (!flow.dag) flow.dag = { nodes: [], edges: [] }
    if (!flow.dag.edges) flow.dag.edges = []
    
    flow.dag.edges.push({
      id: `edge_${Date.now()}`,
      source: sourceId,
      target: targetId,
      condition
    })
  }

  function removeNode(flowId, nodeId) {
    const flow = flows.value.find(f => f.id === flowId)
    if (!flow || !flow.dag?.nodes) return
    
    flow.dag.nodes = flow.dag.nodes.filter(n => n.id !== nodeId)
    flow.dag.edges = flow.dag.edges.filter(e => e.source !== nodeId && e.target !== nodeId)
  }

  // Real-time updates
  function handleFlowUpdate(data) {
    const index = flows.value.findIndex(f => f.id === data.id)
    if (index !== -1) {
      flows.value[index] = { ...flows.value[index], ...data }
    }
  }

  function handleExecutionUpdate(data) {
    if (currentExecution.value?.id === data.id) {
      currentExecution.value = { ...currentExecution.value, ...data }
    }
    
    const index = executions.value.findIndex(e => e.id === data.id)
    if (index !== -1) {
      executions.value[index] = { ...executions.value[index], ...data }
    } else {
      executions.value.unshift(data)
    }
  }

  return {
    flows,
    currentFlow,
    executions,
    currentExecution,
    isLoading,
    error,
    isExecuting,
    publishedFlows,
    draftFlows,
    flowById,
    recentExecutions,
    fetchFlows,
    fetchFlow,
    fetchExecutions,
    createFlow,
    updateFlow,
    deleteFlow,
    publishFlow,
    executeFlow,
    fetchExecutionStatus,
    addNode,
    addEdge,
    removeNode,
    handleFlowUpdate,
    handleExecutionUpdate
  }
})

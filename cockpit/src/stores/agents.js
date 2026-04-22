import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000'

export const useAgentsStore = defineStore('agents', () => {
  // State
  const agents = ref([])
  const currentAgent = ref(null)
  const delegations = ref([])
  const sessions = ref([])
  const isLoading = ref(false)
  const error = ref(null)

  // Getters
  const activeAgents = computed(() => agents.value.filter(a => a.status === 'active'))
  const agentById = computed(() => (id) => agents.value.find(a => a.id === id))
  
  const agentsByCapability = computed(() => {
    const grouped = {}
    agents.value.forEach(agent => {
      const caps = agent.capabilities || []
      caps.forEach(cap => {
        if (!grouped[cap]) grouped[cap] = []
        grouped[cap].push(agent)
      })
    })
    return grouped
  })

  // Actions
  async function fetchAgents() {
    isLoading.value = true
    error.value = null
    
    try {
      const response = await axios.get(`${API_URL}/api/agents`)
      agents.value = response.data.data || []
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch agents'
    } finally {
      isLoading.value = false
    }
  }

  async function fetchAgent(id) {
    isLoading.value = true
    error.value = null
    
    try {
      const response = await axios.get(`${API_URL}/api/agents/${id}`)
      currentAgent.value = response.data.data
      return currentAgent.value
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch agent'
      return null
    } finally {
      isLoading.value = false
    }
  }

  async function fetchDelegations(agentId) {
    try {
      const response = await axios.get(`${API_URL}/api/agents/${agentId}/delegations`)
      delegations.value = response.data.data || []
    } catch (err) {
      console.error('Failed to fetch delegations:', err)
    }
  }

  async function fetchSessions(agentId) {
    try {
      const response = await axios.get(`${API_URL}/api/agents/${agentId}/sessions`)
      sessions.value = response.data.data || []
    } catch (err) {
      console.error('Failed to fetch sessions:', err)
    }
  }

  async function createAgent(agentData) {
    isLoading.value = true
    error.value = null
    
    try {
      const response = await axios.post(`${API_URL}/api/agents`, agentData)
      agents.value.push(response.data.data)
      return { success: true, agent: response.data.data }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to create agent'
      return { success: false, error: error.value }
    } finally {
      isLoading.value = false
    }
  }

  async function updateAgent(id, agentData) {
    isLoading.value = true
    error.value = null
    
    try {
      const response = await axios.put(`${API_URL}/api/agents/${id}`, agentData)
      const index = agents.value.findIndex(a => a.id === id)
      if (index !== -1) {
        agents.value[index] = response.data.data
      }
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to update agent'
      return { success: false, error: error.value }
    } finally {
      isLoading.value = false
    }
  }

  async function toggleAgentStatus(id) {
    const agent = agents.value.find(a => a.id === id)
    if (!agent) return
    
    const newStatus = agent.status === 'active' ? 'paused' : 'active'
    
    try {
      await axios.patch(`${API_URL}/api/agents/${id}/status`, { status: newStatus })
      agent.status = newStatus
      return { success: true }
    } catch (err) {
      return { success: false, error: err.response?.data?.message }
    }
  }

  async function deleteAgent(id) {
    try {
      await axios.delete(`${API_URL}/api/agents/${id}`)
      agents.value = agents.value.filter(a => a.id !== id)
      return { success: true }
    } catch (err) {
      return { success: false, error: err.response?.data?.message }
    }
  }

  async function dispatchAgent(agentId, intent, context = {}) {
    try {
      const response = await axios.post(`${API_URL}/api/agents/${agentId}/dispatch`, {
        intent,
        context
      })
      return { success: true, result: response.data }
    } catch (err) {
      return { success: false, error: err.response?.data?.message }
    }
  }

  // Real-time updates via WebSocket
  function handleAgentUpdate(data) {
    const index = agents.value.findIndex(a => a.id === data.id)
    if (index !== -1) {
      agents.value[index] = { ...agents.value[index], ...data }
    }
  }

  function handleAgentCreated(data) {
    agents.value.push(data)
  }

  function handleAgentDeleted(data) {
    agents.value = agents.value.filter(a => a.id !== data.id)
  }

  return {
    agents,
    currentAgent,
    delegations,
    sessions,
    isLoading,
    error,
    activeAgents,
    agentById,
    agentsByCapability,
    fetchAgents,
    fetchAgent,
    fetchDelegations,
    fetchSessions,
    createAgent,
    updateAgent,
    toggleAgentStatus,
    deleteAgent,
    dispatchAgent,
    handleAgentUpdate,
    handleAgentCreated,
    handleAgentDeleted
  }
})

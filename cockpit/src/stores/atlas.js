/**
 * Atlas Pinia Store - SpiderNet OS v3.2
 *
 * Manages Atlas chat sessions, messages, plans, and suggestions.
 * Upgraded to support sessions map, plan execution/cancellation,
 * and structured intent metadata from AtlasIntentCompiler.
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000'

export const useAtlasStore = defineStore('atlas', () => {
  // ── State ──────────────────────────────────────────────────────────
  /** @type {import('vue').Ref<Map<string, object>>} Session ID → session object */
  const sessions = ref(new Map())

  /** @type {import('vue').Ref<string|null>} Currently active session ID */
  const currentSessionId = ref(null)

  /** @type {import('vue').Ref<Array<object>>} Messages in the current session */
  const messages = ref([])

  /** @type {import('vue').Ref<boolean>} Whether Atlas is currently generating a response */
  const isTyping = ref(false)

  /** @type {import('vue').Ref<boolean>} General loading state */
  const isLoading = ref(false)

  /** @type {import('vue').Ref<string|null>} Last error message */
  const error = ref(null)

  /** @type {import('vue').Ref<object|null>} The current execution plan (multi-step) */
  const currentPlan = ref(null)

  /** @type {import('vue').Ref<Array<object>>} Contextual suggestions from Atlas */
  const suggestions = ref([])

  // AST / context state (carried over from v3.1)
  const commandAst = ref(null)
  const tasks = ref([])
  const activeAgent = ref(null)
  const slashCommands = ref([
    { command: '/create', description: 'Create a new flow or resource' },
    { command: '/run', description: 'Run / execute an existing flow' },
    { command: '/execute', description: 'Execute a flow (alias for /run)' },
    { command: '/status', description: 'Show system status overview' },
    { command: '/health', description: 'System health check' },
    { command: '/help', description: 'Show available commands or explain a topic' },
    { command: '/explain', description: 'Explain a concept or component' },
    { command: '/analyze', description: 'Analyze data or metrics' },
    { command: '/monitor', description: 'Monitor system activity in real-time' },
    { command: '/agents', description: 'List and manage agents' },
    { command: '/clear', description: 'Clear conversation history' },
    { command: '/trace', description: 'Show recent execution traces' },
  ])

  // ── Getters ────────────────────────────────────────────────────────

  /** Get the current session object */
  const currentSession = computed(() => {
    if (!currentSessionId.value) return null
    return sessions.value.get(currentSessionId.value) || null
  })

  /** Get messages for the current session */
  const currentMessages = computed(() => messages.value)

  /** Pending tasks from the current plan */
  const pendingTasks = computed(() => {
    if (currentPlan.value && currentPlan.value.steps) {
      return currentPlan.value.steps.filter(
        (step) => step.status === 'pending' || step.status === 'in_progress'
      )
    }
    return tasks.value.filter((t) => t.status === 'pending')
  })

  /** Currently active plan */
  const activePlan = computed(() => currentPlan.value)

  const completedTasks = computed(() => tasks.value.filter((t) => t.status === 'completed'))
  const runningTasks = computed(() => tasks.value.filter((t) => t.status === 'running'))
  const failedTasks = computed(() => tasks.value.filter((t) => t.status === 'failed'))

  const planProgress = computed(() => {
    if (tasks.value.length === 0) return 0
    return Math.round((completedTasks.value.length / tasks.value.length) * 100)
  })

  const totalCost = computed(() => {
    return messages.value
      .filter((m) => m.metadata?.cost)
      .reduce((sum, m) => sum + m.metadata.cost, 0)
  })

  // ── Actions ────────────────────────────────────────────────────────

  /**
   * Send a message to Atlas and receive a response.
   * @param {string} text - The user message text.
   */
  async function sendMessage(text) {
    if (!text || !text.trim()) return { success: false }

    const userMessage = {
      id: `msg_${Date.now()}`,
      role: 'user',
      content: text.trim(),
      timestamp: new Date().toISOString(),
    }
    messages.value.push(userMessage)
    isTyping.value = true
    error.value = null

    try {
      const response = await axios.post(`${API_URL}/api/atlas/chat`, {
        message: text.trim(),
        session_id: currentSessionId.value,
      })

      const data = response.data

      // Update session ID if server assigned one
      if (data.session_id) {
        currentSessionId.value = data.session_id
      }

      // Update AST if returned
      if (data.ast) {
        commandAst.value = data.ast
      }

      // Update tasks if a plan was generated
      if (data.tasks) {
        tasks.value = data.tasks
      }

      // Handle plan if returned
      if (data.plan) {
        currentPlan.value = data.plan
      }

      // Update active agent
      if (data.agent) {
        activeAgent.value = {
          name: data.agent,
          model: data.model || null,
          cost: data.cost || 0,
        }
      }

      // Handle suggestions if returned
      if (data.suggestions && Array.isArray(data.suggestions)) {
        suggestions.value = data.suggestions
      }

      // Add assistant response message
      const assistantMessage = {
        id: data.message_id || `msg_${Date.now() + 1}`,
        role: 'assistant',
        content: data.message || data.content || data.result?.output || 'I processed your request.',
        timestamp: new Date().toISOString(),
        metadata: {
          agent: data.agent || data.agent_target || null,
          intent: data.intent || null,
          confidence: data.confidence || null,
          cost: data.cost || null,
          model: data.model || null,
          execution_time: data.execution_time_ms || null,
          tokens_used: data.tokens_used || null,
        },
      }
      messages.value.push(assistantMessage)

      // Update session in map
      if (currentSessionId.value) {
        sessions.value.set(currentSessionId.value, {
          id: currentSessionId.value,
          lastMessage: assistantMessage.content,
          updatedAt: assistantMessage.timestamp,
          messageCount: messages.value.length,
        })
      }

      return { success: true, data }
    } catch (err) {
      const errorMessage = {
        id: `msg_${Date.now() + 1}`,
        role: 'assistant',
        content:
          err.response?.data?.message || 'Sorry, I encountered an error processing your request.',
        timestamp: new Date().toISOString(),
        isError: true,
      }
      messages.value.push(errorMessage)
      error.value = err.response?.data?.message || err.message
      return { success: false, error: error.value }
    } finally {
      isTyping.value = false
    }
  }

  /**
   * Create a new chat session.
   * @returns {Promise<string>} The new session ID.
   */
  async function createSession() {
    isLoading.value = true
    error.value = null

    try {
      const response = await axios.post(`${API_URL}/api/atlas/sessions`)
      const newId = response.data.data?.id || response.data.session_id || crypto.randomUUID()

      sessions.value.set(newId, {
        id: newId,
        lastMessage: null,
        createdAt: new Date().toISOString(),
        updatedAt: new Date().toISOString(),
        messageCount: 0,
      })

      currentSessionId.value = newId
      messages.value = []
      tasks.value = []
      currentPlan.value = null
      commandAst.value = null
      suggestions.value = response.data.data?.suggestions || []

      return newId
    } catch (err) {
      // Fallback to local session if API unavailable
      const localId = crypto.randomUUID()
      sessions.value.set(localId, {
        id: localId,
        lastMessage: null,
        createdAt: new Date().toISOString(),
        updatedAt: new Date().toISOString(),
        messageCount: 0,
      })
      currentSessionId.value = localId
      messages.value = []
      tasks.value = []
      currentPlan.value = null
      suggestions.value = []
      error.value = err.response?.data?.message || 'Failed to create session on server'
      return localId
    } finally {
      isLoading.value = false
    }
  }

  /**
   * Load an existing session by ID.
   * @param {string} id - The session ID to load.
   */
  async function loadSession(id) {
    isLoading.value = true
    error.value = null

    try {
      const response = await axios.get(`${API_URL}/api/atlas/sessions/${id}`)
      const session = response.data.data || response.data

      currentSessionId.value = id
      messages.value = session.messages || []
      tasks.value = session.tasks || []
      commandAst.value = session.last_ast || null
      currentPlan.value = session.plan || null
      suggestions.value = session.suggestions || []

      sessions.value.set(id, {
        id,
        lastMessage:
          messages.value.length > 0 ? messages.value[messages.value.length - 1].content : null,
        updatedAt: session.updated_at || new Date().toISOString(),
        messageCount: messages.value.length,
      })

      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load session'
      return { success: false, error: error.value }
    } finally {
      isLoading.value = false
    }
  }

  /**
   * Execute a plan step or entire plan.
   * @param {string} planId - The plan ID to execute.
   */
  async function executePlan(planId) {
    try {
      const response = await axios.post(`${API_URL}/api/atlas/execute`, {
        plan_id: planId,
        session_id: currentSessionId.value,
      })

      const data = response.data

      if (data.plan) {
        currentPlan.value = data.plan
      }

      if (data.tasks) {
        tasks.value = data.tasks
      }

      // Add execution status message
      messages.value.push({
        id: `msg_${Date.now()}`,
        role: 'system',
        content: data.message || `Plan ${planId} execution started.`,
        timestamp: new Date().toISOString(),
        metadata: { type: 'plan_execution', planId },
      })

      return { success: true, data }
    } catch (err) {
      error.value = err.response?.data?.message || `Failed to execute plan ${planId}`
      return { success: false, error: error.value }
    }
  }

  /**
   * Cancel an active plan.
   * @param {string} planId - The plan ID to cancel.
   */
  async function cancelPlan(planId) {
    try {
      const response = await axios.post(`${API_URL}/api/atlas/cancel`, {
        plan_id: planId,
        session_id: currentSessionId.value,
      })

      const data = response.data
      currentPlan.value = null

      messages.value.push({
        id: `msg_${Date.now()}`,
        role: 'system',
        content: data.message || `Plan ${planId} cancelled.`,
        timestamp: new Date().toISOString(),
        metadata: { type: 'plan_cancelled', planId },
      })

      return { success: true, data }
    } catch (err) {
      error.value = err.response?.data?.message || `Failed to cancel plan ${planId}`
      return { success: false, error: error.value }
    }
  }

  /**
   * Execute a slash command.
   * @param {string} command - The slash command string.
   */
  async function executeSlashCommand(command) {
    if (command === '/clear') {
      clearSession()
      return { success: true }
    }
    return sendMessage(command)
  }

  /** Clear the current session state. */
  function clearSession() {
    messages.value = []
    tasks.value = []
    commandAst.value = null
    activeAgent.value = null
    currentPlan.value = null
    suggestions.value = []
    error.value = null
  }

  /**
   * Dismiss a suggestion by ID or index.
   * @param {string|number} id - The suggestion ID or index to dismiss.
   */
  function dismissSuggestion(id) {
    if (typeof id === 'number') {
      suggestions.value.splice(id, 1)
    } else {
      suggestions.value = suggestions.value.filter((s) => s.id !== id)
    }
  }

  /**
   * Execute a suggestion as a message.
   * @param {object} suggestion - The suggestion object.
   */
  async function executeSuggestion(suggestion) {
    return sendMessage(suggestion.command || suggestion.text)
  }

  // ── Real-time handlers ─────────────────────────────────────────────

  function handleTaskUpdate(data) {
    const index = tasks.value.findIndex((t) => t.id === data.id)
    if (index !== -1) {
      tasks.value[index] = { ...tasks.value[index], ...data }
    } else {
      tasks.value.push(data)
    }
  }

  function handleAgentUpdate(data) {
    activeAgent.value = { ...activeAgent.value, ...data }
  }

  function handleStreamChunk(data) {
    const lastMsg = messages.value[messages.value.length - 1]
    if (lastMsg && lastMsg.role === 'assistant' && lastMsg.streaming) {
      lastMsg.content += data.chunk
    }
  }

  // ── Expose ─────────────────────────────────────────────────────────
  return {
    // State
    sessions,
    currentSessionId,
    messages,
    isTyping,
    isLoading,
    error,
    currentPlan,
    suggestions,
    commandAst,
    tasks,
    activeAgent,
    slashCommands,
    // Getters
    currentSession,
    currentMessages,
    pendingTasks,
    activePlan,
    completedTasks,
    runningTasks,
    failedTasks,
    planProgress,
    totalCost,
    // Actions
    sendMessage,
    createSession,
    loadSession,
    executePlan,
    cancelPlan,
    executeSlashCommand,
    clearSession,
    dismissSuggestion,
    executeSuggestion,
    handleTaskUpdate,
    handleAgentUpdate,
    handleStreamChunk,
  }
})

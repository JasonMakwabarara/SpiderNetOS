/**
 * Atlas Pinia Store - SpiderNet OS v3.2
 *
 * Manages Atlas chat sessions, messages, plans, and suggestions.
 * Upgraded to support sessions map, plan execution/cancellation,
 * and structured intent metadata from AtlasIntentCompiler.
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../services/api.js'
import { useBrainStore } from './brain.js'

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

  /** @type {import('vue').Ref<object|null>} Suggested next automation from discovery */
  const suggestedNext = ref(null)

  /** @type {import('vue').Ref<object|null>} Pending confirm-before-act action */
  const pendingAction = ref(null)

  /** @type {import('vue').Ref<Array<string>>} Discovery questions from Atlas */
  const discoveryQuestions = ref([])

  /** @type {import('vue').Ref<Array<object>>} Contextual suggestions from Atlas */
  const suggestions = ref([])

  /**
   * "One step further" (plan D8): the context thread this conversation is
   * answering. Sent back as `thread_id` so Atlas can tie an answer to the
   * question it asked.
   * @type {import('vue').Ref<string|null>}
   */
  const threadId = ref(null)

  /**
   * `metadata.launch` from the business-launch interview (plan D7 §5):
   * { id, status, stage, stage_title, next_question, now_filling,
   *   progress_pct, jurisdiction, jurisdictions, stages, deliverables }.
   * @type {import('vue').Ref<object|null>}
   */
  const launch = ref(null)

  /**
   * `metadata.brain` — BrainGapAnalyzer::readiness for this tenant, mirrored
   * into the brain store so the readiness rows update as answers land.
   * @type {import('vue').Ref<{pct: number, files: Array<object>}|null>}
   */
  const brainReadiness = ref(null)

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
    { command: '/research', description: 'Deep analysis with citations' },
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

  // ── Helpers ────────────────────────────────────────────────────────

  function formatContractContent(payload) {
    const contract = payload?.message?.contract
    if (!contract) {
      return payload?.message?.content || payload?.content || 'I processed your request.'
    }
    const parts = [
      contract.action_summary,
      contract.value ? `\n\nValue: ${contract.value}` : '',
      contract.future_state ? `\n\nOutcome: ${contract.future_state}` : '',
    ].filter(Boolean)
    return parts.join('') || contract.action_summary || 'Done.'
  }

  /**
   * @param {string} text
   * @param {{mode?: string, thread_id?: string|null}} [options]
   *   `mode` is one of chat | answer | skip | run | launch; `launch` routes
   *   the turn into the business-launch interview instead of the planner.
   */
  async function sendMessage(text, options = {}) {
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
      const payload = {
        message: text.trim(),
        session_id: currentSessionId.value,
      }
      if (options.mode) payload.mode = options.mode
      const thread = options.thread_id ?? threadId.value
      if (thread) payload.thread_id = thread
      const response = await api.post('/api/atlas/chat', payload)

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

      if (data.message?.metadata?.intent || data.message?.metadata?.agent_used) {
        activeAgent.value = {
          name: data.message.metadata.agent_used || 'atlas',
          model: data.message.metadata.model || null,
          cost: data.message.metadata.estimated_cost_usd || 0,
        }
      }

      const assistantMessage = {
        id: data.message?.id || data.message_id || `msg_${Date.now() + 1}`,
        role: 'assistant',
        content: formatContractContent(data),
        timestamp: data.message?.timestamp || new Date().toISOString(),
        contract: data.message?.contract || null,
        metadata: {
          agent: data.message?.metadata?.agent_used || null,
          intent: data.message?.metadata?.intent || null,
          cost: data.message?.metadata?.estimated_cost_usd ?? null,
          model: data.message?.metadata?.model || null,
          mode: data.message?.metadata?.mode || null,
          questions: data.message?.metadata?.questions || [],
          profile_pct: data.message?.metadata?.profile_pct ?? null,
          pending_action: data.message?.metadata?.pending_action || data.pending_action || null,
          status: data.message?.metadata?.status || null,
          launch: data.message?.metadata?.launch || null,
          brain: data.message?.metadata?.brain || null,
          thread_id: data.message?.metadata?.thread_id || data.one_step?.thread_id || null,
        },
      }
      messages.value.push(assistantMessage)

      suggestedNext.value = data.suggested_next || null
      discoveryQuestions.value = data.message?.metadata?.questions || []
      pendingAction.value = assistantMessage.metadata.pending_action || null
      captureContext(data)

      if (data.suggestions && Array.isArray(data.suggestions)) {
        suggestions.value = data.suggestions
      }

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
   * Pull the envelope's side-channels off one reply: the thread id the next
   * answer belongs to, `metadata.launch`, and `metadata.brain` — which is
   * handed straight to the brain store so the readiness rows on the launch
   * panel move as the interview fills them.
   */
  function captureContext(data) {
    const metadata = data?.message?.metadata || {}

    const thread = metadata.thread_id || data?.one_step?.thread_id || null
    if (thread) threadId.value = thread

    if (metadata.launch) launch.value = metadata.launch

    if (metadata.brain && Array.isArray(metadata.brain.files)) {
      brainReadiness.value = metadata.brain
      try {
        useBrainStore().applyReadiness(metadata.brain)
      } catch {
        // The brain store is optional here — never break a chat turn over it.
      }
    }

    return { thread, launch: metadata.launch || null, brain: metadata.brain || null }
  }

  async function confirmAction(actionId) {
    if (!actionId) return { success: false }
    isTyping.value = true
    error.value = null
    try {
      const response = await api.post('/api/atlas/confirm', {
        action_id: actionId,
        decision: 'proceed',
        session_id: currentSessionId.value,
      })
      const data = response.data
      pendingAction.value = null
      const assistantMessage = {
        id: data.message?.id || `msg_${Date.now()}`,
        role: 'assistant',
        content: formatContractContent(data),
        timestamp: data.message?.timestamp || new Date().toISOString(),
        contract: data.message?.contract || null,
        metadata: {
          agent: data.message?.metadata?.agent_used || null,
          intent: data.message?.metadata?.intent || null,
          mode: data.message?.metadata?.mode || 'act',
          status: data.message?.metadata?.status || null,
        },
      }
      messages.value.push(assistantMessage)
      if (data.session_id) currentSessionId.value = data.session_id
      return { success: true, data }
    } catch (err) {
      error.value = err.response?.data?.message || err.message
      return { success: false, error: error.value }
    } finally {
      isTyping.value = false
    }
  }

  async function cancelAction(actionId) {
    if (!actionId) return { success: false }
    isTyping.value = true
    error.value = null
    try {
      const response = await api.post('/api/atlas/confirm', {
        action_id: actionId,
        decision: 'cancel',
        session_id: currentSessionId.value,
      })
      const data = response.data
      pendingAction.value = null
      const assistantMessage = {
        id: data.message?.id || `msg_${Date.now()}`,
        role: 'assistant',
        content: formatContractContent(data),
        timestamp: data.message?.timestamp || new Date().toISOString(),
        contract: data.message?.contract || null,
        metadata: {
          mode: data.message?.metadata?.mode || 'held',
          status: data.message?.metadata?.status || 'cancelled',
        },
      }
      messages.value.push(assistantMessage)
      return { success: true, data }
    } catch (err) {
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
  /** Alias used by Atlas.vue — creates a chat session when none is loaded yet. */
  async function initSession() {
    return createSession()
  }

  async function createSession() {
    isLoading.value = true
    error.value = null

    try {
      const response = await api.post('/api/atlas/sessions')
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
      const response = await api.get(`/api/atlas/sessions/${id}`)
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
      const response = await api.post('/api/atlas/execute', {
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
        metadata: {
          type: 'plan_execution',
          planId,
          execution_id: data.execution_id || null,
          flow_id: data.flow_id || null,
        },
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
      const response = await api.post('/api/atlas/cancel', {
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
    pendingAction.value = null
    suggestions.value = []
    error.value = null
    threadId.value = null
    launch.value = null
    brainReadiness.value = null
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
    suggestedNext,
    discoveryQuestions,
    commandAst,
    tasks,
    activeAgent,
    pendingAction,
    slashCommands,
    threadId,
    launch,
    brainReadiness,
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
    initSession,
    createSession,
    loadSession,
    executePlan,
    cancelPlan,
    executeSlashCommand,
    clearSession,
    dismissSuggestion,
    executeSuggestion,
    confirmAction,
    cancelAction,
    captureContext,
    handleTaskUpdate,
    handleAgentUpdate,
    handleStreamChunk,
  }
})

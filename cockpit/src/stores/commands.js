/**
 * Commands Pinia Store - SpiderNet OS v3.2
 *
 * Manages slash command history and execution.
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useAtlasStore } from './atlas'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000'

export const useCommandsStore = defineStore('commands', () => {
  // ── State ──────────────────────────────────────────────────────────

  /** @type {import('vue').Ref<Array<object>>} History of executed commands */
  const commandHistory = ref([])

  /** @type {import('vue').Ref<boolean>} Whether a command is currently executing */
  const isExecuting = ref(false)

  /** @type {import('vue').Ref<string|null>} Last error message */
  const error = ref(null)

  /** @type {import('vue').Ref<Array<object>>} Available slash commands */
  const slashCommands = ref([
    {
      command: '/create',
      description: 'Create a new flow or resource',
      intent: 'create_flow',
      usage: '/create flow <name> [description]',
      examples: ['/create flow data-pipeline ETL flow for user analytics'],
    },
    {
      command: '/run',
      description: 'Run / execute an existing flow',
      intent: 'execute_flow',
      usage: '/run <flow-name|flow-id>',
      examples: ['/run data-pipeline', '/run flow_abc123'],
    },
    {
      command: '/execute',
      description: 'Execute a flow (alias for /run)',
      intent: 'execute_flow',
      usage: '/execute <flow-name|flow-id>',
      examples: ['/execute data-pipeline'],
    },
    {
      command: '/status',
      description: 'Show system status overview',
      intent: 'query_status',
      usage: '/status [component]',
      examples: ['/status', '/status agents', '/status flows'],
    },
    {
      command: '/health',
      description: 'System health check',
      intent: 'query_status',
      usage: '/health',
      examples: ['/health'],
    },
    {
      command: '/help',
      description: 'Show available commands or explain a topic',
      intent: 'teach',
      usage: '/help [topic]',
      examples: ['/help', '/help flows', '/help agents'],
    },
    {
      command: '/explain',
      description: 'Explain a concept or component',
      intent: 'teach',
      usage: '/explain <topic>',
      examples: ['/explain memory graph', '/explain cost governor'],
    },
    {
      command: '/analyze',
      description: 'Analyze data or metrics',
      intent: 'analyze_data',
      usage: '/analyze <target>',
      examples: ['/analyze usage metrics', '/analyze flow performance'],
    },
    {
      command: '/monitor',
      description: 'Monitor system activity in real-time',
      intent: 'monitor',
      usage: '/monitor [component]',
      examples: ['/monitor', '/monitor agents'],
    },
    {
      command: '/agents',
      description: 'List and manage agents',
      intent: 'manage_agent',
      usage: '/agents [action]',
      examples: ['/agents', '/agents list', '/agents status'],
    },
  ])

  // ── Getters ────────────────────────────────────────────────────────

  /** Recently executed commands (last 50) */
  const recentCommands = computed(() => commandHistory.value.slice(-50).reverse())

  /** Get matching slash commands for autocomplete */
  const getMatches = computed(() => {
    return (input) => {
      if (!input || !input.startsWith('/')) return []
      const lower = input.toLowerCase()
      return slashCommands.value.filter((cmd) => cmd.command.startsWith(lower))
    }
  })

  // ── Actions ────────────────────────────────────────────────────────

  /**
   * Execute a command (slash command or natural language) through Atlas.
   * @param {string} text - The command text to execute.
   * @returns {Promise<object>} Execution result.
   */
  async function executeCommand(text) {
    if (!text || !text.trim()) return { success: false, error: 'Empty command' }

    const trimmed = text.trim()
    isExecuting.value = true
    error.value = null

    const historyEntry = {
      id: `cmd_${Date.now()}`,
      text: trimmed,
      timestamp: new Date().toISOString(),
      status: 'executing',
      result: null,
    }
    commandHistory.value.push(historyEntry)

    try {
      // Delegate to the Atlas store for actual execution
      const atlasStore = useAtlasStore()
      const result = await atlasStore.sendMessage(trimmed)

      // Update history entry
      historyEntry.status = result.success ? 'completed' : 'failed'
      historyEntry.result = result.data || null
      historyEntry.error = result.error || null
      historyEntry.intent = result.data?.intent || null
      historyEntry.agent = result.data?.agent || result.data?.agent_target || null

      return result
    } catch (err) {
      historyEntry.status = 'failed'
      historyEntry.error = err.message
      error.value = err.message
      return { success: false, error: err.message }
    } finally {
      isExecuting.value = false
    }
  }

  /**
   * Load command history from the server.
   */
  async function loadHistory() {
    try {
      const { default: axios } = await import('axios')
      const response = await axios.get(`${API_URL}/api/atlas/commands/history`)
      const data = response.data.data || response.data || []
      commandHistory.value = Array.isArray(data) ? data : []
    } catch (err) {
      // Non-critical: history is also maintained locally
      console.warn('Failed to load command history from server:', err.message)
    }
  }

  /**
   * Clear command history.
   */
  function clearHistory() {
    commandHistory.value = []
  }

  // ── Expose ─────────────────────────────────────────────────────────
  return {
    // State
    commandHistory,
    slashCommands,
    isExecuting,
    error,
    // Getters
    recentCommands,
    getMatches,
    // Actions
    executeCommand,
    loadHistory,
    clearHistory,
  }
})

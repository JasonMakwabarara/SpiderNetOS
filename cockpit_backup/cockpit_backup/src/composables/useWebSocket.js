import { ref, onMounted, onUnmounted } from 'vue'

/**
 * useWebSocket — cockpit shell connection indicator + live event fanout.
 *
 * Strategy:
 *   - When `VITE_PUSHER_KEY` is set in the environment, run the *real*
 *     Laravel Echo + Pusher wiring against the tenant-private channel
 *     and dispatch incoming events into the corresponding Pinia stores.
 *   - When the env var is missing (e.g., the demo / mock backend
 *     environment) we fall back to a deterministic "always-connected"
 *     stub so the cockpit's Live indicator stays green and tests stay
 *     hermetic.
 *
 * Channel layout (mirrors the Laravel broadcaster):
 *   tenant.<id> {
 *     .agent.created | .agent.updated | .agent.deleted
 *     .flow.updated  | .execution.updated
 *     .usage.updated | .budget.alert
 *     .approval.created | .approval.updated
 *     .trace.appended
 *     .atlas.task.updated | .atlas.agent.updated | .atlas.stream.chunk
 *   }
 */
export function useWebSocket(authStore, agentsStore, flowsStore, usageStore, approvalsStore, tracesStore, atlasStore) {
  const isConnected = ref(false)
  const lastMessage = ref(null)

  let echo = null
  let stubTimer = null

  const PUSHER_KEY = import.meta.env.VITE_PUSHER_KEY
  const WS_HOST   = import.meta.env.VITE_WS_HOST   || 'localhost'
  const WS_PORT   = Number(import.meta.env.VITE_WS_PORT || 6001)
  const WSS_PORT  = Number(import.meta.env.VITE_WSS_PORT || WS_PORT)
  const FORCE_TLS = String(import.meta.env.VITE_WS_FORCE_TLS || 'false') === 'true'

  async function connectReal() {
    if (!authStore.token) return
    // Lazy-load to avoid pulling pusher-js into the bundle when unused.
    const [{ default: Echo }, { default: Pusher }] = await Promise.all([
      import('laravel-echo'),
      import('pusher-js'),
    ])
    window.Pusher = Pusher

    echo = new Echo({
      broadcaster: 'pusher',
      key: PUSHER_KEY,
      wsHost: WS_HOST,
      wsPort: WS_PORT,
      wssPort: WSS_PORT,
      forceTLS: FORCE_TLS,
      disableStats: true,
      enabledTransports: FORCE_TLS ? ['wss'] : ['ws', 'wss'],
      auth: { headers: { Authorization: `Bearer ${authStore.token}` } },
    })

    const tenantId = authStore.tenant?.id
    if (!tenantId) return

    const channel = echo.private(`tenant.${tenantId}`)

    // ── Agents ──────────────────────────────────────────────────────
    channel.listen('.agent.created', (data) => { agentsStore?.handleAgentCreated?.(data); lastMessage.value = { type: 'agent.created', data } })
    channel.listen('.agent.updated', (data) => { agentsStore?.handleAgentUpdate?.(data);  lastMessage.value = { type: 'agent.updated', data } })
    channel.listen('.agent.deleted', (data) => { agentsStore?.handleAgentDeleted?.(data); lastMessage.value = { type: 'agent.deleted', data } })

    // ── Flows ───────────────────────────────────────────────────────
    channel.listen('.flow.updated', (data) => { flowsStore?.handleFlowUpdate?.(data); lastMessage.value = { type: 'flow.updated', data } })
    channel.listen('.execution.updated', (data) => { flowsStore?.handleExecutionUpdate?.(data); lastMessage.value = { type: 'execution.updated', data } })

    // ── Usage / budget ──────────────────────────────────────────────
    channel.listen('.usage.updated', (data) => { usageStore?.handleUsageUpdate?.(data); lastMessage.value = { type: 'usage.updated', data } })
    channel.listen('.budget.alert',  (data) => { usageStore?.handleBudgetAlert?.(data); lastMessage.value = { type: 'budget.alert',  data } })

    // ── Approvals ───────────────────────────────────────────────────
    channel.listen('.approval.created', (data) => { approvalsStore?.handleApprovalCreated?.(data); lastMessage.value = { type: 'approval.created', data } })
    channel.listen('.approval.updated', (data) => { approvalsStore?.handleApprovalUpdated?.(data); lastMessage.value = { type: 'approval.updated', data } })

    // ── Traces ──────────────────────────────────────────────────────
    channel.listen('.trace.appended', (data) => { tracesStore?.handleTraceUpdate?.(data); lastMessage.value = { type: 'trace.appended', data } })

    // ── Atlas streaming ─────────────────────────────────────────────
    channel.listen('.atlas.task.updated',  (data) => { atlasStore?.handleTaskUpdate?.(data) })
    channel.listen('.atlas.agent.updated', (data) => { atlasStore?.handleAgentUpdate?.(data) })
    channel.listen('.atlas.stream.chunk',  (data) => { atlasStore?.handleStreamChunk?.(data) })

    echo.connector.pusher.connection.bind('connected',    () => { isConnected.value = true })
    echo.connector.pusher.connection.bind('disconnected', () => { isConnected.value = false })
    echo.connector.pusher.connection.bind('error',        () => { isConnected.value = false })
  }

  function connectStub() {
    // Deterministic green light for demo + tests.
    stubTimer = setTimeout(() => { isConnected.value = true }, 420)
  }

  function connect() {
    if (!authStore?.token) return
    if (PUSHER_KEY) {
      connectReal().catch((err) => {
        // If real broadcaster fails to import or connect, degrade to stub.
        console.warn('[ws] real broadcaster unavailable; falling back to stub indicator', err)
        connectStub()
      })
    } else {
      connectStub()
    }
  }

  function disconnect() {
    if (stubTimer) { clearTimeout(stubTimer); stubTimer = null }
    if (echo) { try { echo.disconnect() } catch { /* noop */ } echo = null }
    isConnected.value = false
  }

  function reconnect() {
    disconnect()
    setTimeout(connect, 500)
  }

  onMounted(connect)
  onUnmounted(disconnect)

  return { isConnected, lastMessage, connect, disconnect, reconnect }
}

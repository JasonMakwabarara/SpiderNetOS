import { ref, onMounted, onUnmounted } from 'vue'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

const WS_URL = import.meta.env.VITE_WS_URL || 'ws://localhost:6001'

export function useWebSocket(authStore, agentsStore, flowsStore, usageStore) {
  const isConnected = ref(false)
  const lastMessage = ref(null)
  let echo = null

  function connect() {
    if (!authStore.token) return

    // Initialize Laravel Echo with Pusher
    window.Pusher = Pusher
    
    echo = new Echo({
      broadcaster: 'pusher',
      key: 'spidernet-key',
      wsHost: 'localhost',
      wsPort: 6001,
      wssPort: 6001,
      forceTLS: false,
      disableStats: true,
      enabledTransports: ['ws', 'wss'],
      auth: {
        headers: {
          Authorization: `Bearer ${authStore.token}`
        }
      }
    })

    // Subscribe to tenant-private channel
    const channel = echo.private(`tenant.${authStore.tenant?.id}`)

    // Agent events
    channel.listen('.agent.updated', (data) => {
      agentsStore.handleAgentUpdate(data)
      lastMessage.value = { type: 'agent.updated', data }
    })

    channel.listen('.agent.created', (data) => {
      agentsStore.handleAgentCreated(data)
      lastMessage.value = { type: 'agent.created', data }
    })

    channel.listen('.agent.deleted', (data) => {
      agentsStore.handleAgentDeleted(data)
      lastMessage.value = { type: 'agent.deleted', data }
    })

    // Flow events
    channel.listen('.flow.updated', (data) => {
      flowsStore.handleFlowUpdate(data)
      lastMessage.value = { type: 'flow.updated', data }
    })

    channel.listen('.execution.updated', (data) => {
      flowsStore.handleExecutionUpdate(data)
      lastMessage.value = { type: 'execution.updated', data }
    })

    // Usage events
    channel.listen('.usage.updated', (data) => {
      usageStore.handleUsageUpdate(data)
      lastMessage.value = { type: 'usage.updated', data }
    })

    channel.listen('.budget.alert', (data) => {
      usageStore.handleBudgetAlert(data)
      lastMessage.value = { type: 'budget.alert', data }
    })

    // Connection status
    echo.connector.pusher.connection.bind('connected', () => {
      isConnected.value = true
      console.log('WebSocket connected')
    })

    echo.connector.pusher.connection.bind('disconnected', () => {
      isConnected.value = false
      console.log('WebSocket disconnected')
    })
  }

  function disconnect() {
    if (echo) {
      echo.disconnect()
      echo = null
      isConnected.value = false
    }
  }

  function reconnect() {
    disconnect()
    setTimeout(connect, 1000)
  }

  onMounted(() => {
    if (authStore.isAuthenticated) {
      connect()
    }
  })

  onUnmounted(() => {
    disconnect()
  })

  return {
    isConnected,
    lastMessage,
    connect,
    disconnect,
    reconnect
  }
}

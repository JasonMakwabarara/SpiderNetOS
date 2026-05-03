import { ref, onMounted, onUnmounted } from 'vue'

/**
 * useWebSocket — cockpit shell connection indicator.
 *
 * In this environment no Laravel/Pusher broadcaster is available.
 * To keep the cockpit deterministic and noise-free, this composable
 * simulates a stable "Live" connection when the user is authenticated.
 *
 * TODO (Laravel): replace this body with the real Echo + Pusher setup
 * from the project's auth-gated broadcaster. Wire the channel.listen
 * callbacks back to agentsStore / flowsStore / usageStore / atlasStore
 * so events flow into Pinia without page reloads.
 */
export function useWebSocket(authStore /*, agentsStore, flowsStore, usageStore */) {
  const isConnected = ref(false)
  const lastMessage = ref(null)
  let timer = null

  function connect() {
    if (!authStore.token) return
    // Simulate handshake latency, then "connected"
    timer = setTimeout(() => { isConnected.value = true }, 420)
  }

  function disconnect() {
    if (timer) { clearTimeout(timer); timer = null }
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

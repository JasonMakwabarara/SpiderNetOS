import { ref } from 'vue'
import axios from 'axios'

const API_URL = import.meta.env.VITE_API_URL || ''

/**
 * useAtlasChat
 *
 * v1 Atlas cognitive contract wire-up (B1/B2/B5/B9-core).
 *
 * The chat endpoint returns a structured contract:
 *   message.contract = {
 *     future_state, value, emotional_shift, action_summary, details?
 *   }
 *
 * The frontend emits behavioral events against interaction_id. Events are
 * fire-and-forget (non-blocking).
 */
export function useAtlasChat() {
  const messages = ref([])
  const isTyping = ref(false)
  const sessionId = ref(null)

  async function enhancePrompt(text, mode = 'balanced') {
    if (!text || !text.trim()) return { original: text, enhanced: text }

    const response = await axios.post(`${API_URL}/api/command/enhance`, {
      prompt: text,
      mode,
    })

    return response.data
  }

  /**
   * Fire-and-forget event emission. Failures do not affect UX.
   */
  function trackEvent(interactionId, eventType, data = {}) {
    if (!interactionId) return
    axios
      .post(`${API_URL}/api/atlas/events`, {
        interaction_id: interactionId,
        event_type: eventType,
        data,
      })
      .catch(() => {
        // Never block UI on telemetry failures
      })
  }

  async function sendMessage(text, style = undefined) {
    if (!text.trim()) return

    const userMessage = {
      id: Date.now(),
      role: 'user',
      content: text,
      timestamp: new Date().toISOString(),
    }
    messages.value.push(userMessage)

    isTyping.value = true

    try {
      const response = await axios.post(`${API_URL}/api/atlas/chat`, {
        message: text,
        session_id: sessionId.value,
        ...(style ? { style } : {}),
      })

      if (response.data.session_id) {
        sessionId.value = response.data.session_id
      }

      const msg = response.data.message || {}
      const contract = msg.contract || null

      const atlasMessage = {
        id: msg.id || Date.now() + 1,
        role: 'assistant',
        interaction_id: response.data.interaction_id || null,
        contract,
        detailsExpanded: false,
        actionAccepted: false,
        viewedAt: Date.now(),
        timestamp: msg.timestamp || new Date().toISOString(),
        metadata: msg.metadata || {},
      }
      messages.value.push(atlasMessage)

      // Emit RESPONSE_VIEWED asynchronously
      trackEvent(atlasMessage.interaction_id, 'RESPONSE_VIEWED')

      return { success: true, interaction_id: atlasMessage.interaction_id }
    } catch (err) {
      const errorMessage = {
        id: Date.now() + 1,
        role: 'assistant',
        contract: {
          future_state: 'Your request is paused — I could not reach the system right now.',
          value: 'You avoid partial or incorrect action.',
          emotional_shift: 'You stay in control while I recover.',
          action_summary: 'I will retry when the connection is back.',
          details: null,
        },
        interaction_id: null,
        isError: true,
        timestamp: new Date().toISOString(),
      }
      messages.value.push(errorMessage)
      return { success: false, error: err.response?.data?.message }
    } finally {
      isTyping.value = false
    }
  }

  function expandDetails(message) {
    if (!message || message.detailsExpanded) return
    message.detailsExpanded = true
    trackEvent(message.interaction_id, 'DETAILS_EXPANDED')
  }

  function acceptAction(message) {
    if (!message || message.actionAccepted) return
    message.actionAccepted = true
    trackEvent(message.interaction_id, 'ACTION_ACCEPTED')
  }

  function rateMessage(message, rating, comment = '') {
    if (!message) return
    trackEvent(message.interaction_id, 'RATING', { rating, comment })
  }

  function markTimeSpent(message, durationMs) {
    if (!message) return
    trackEvent(message.interaction_id, 'TIME_SPENT', { duration_ms: durationMs })
  }

  function clearChat() {
    messages.value = []
    sessionId.value = null
  }

  function suggestCommand(command) {
    sendMessage(command)
  }

  function quickStatus() {
    return sendMessage('What is the system status?')
  }

  function quickCreateFlow(name) {
    return sendMessage(`Create a flow named "${name}"`)
  }

  function quickHelp() {
    return sendMessage('What can you help me with?')
  }

  return {
    messages,
    isTyping,
    sessionId,
    sendMessage,
    enhancePrompt,
    expandDetails,
    acceptAction,
    rateMessage,
    markTimeSpent,
    clearChat,
    suggestCommand,
    quickStatus,
    quickCreateFlow,
    quickHelp,
  }
}

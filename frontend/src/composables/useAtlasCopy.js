/**
 * useAtlasCopy — Atlas copy delivery composable (§11.4)
 *
 * Requests a transformation-scored copy variant from the Atlas Copy API for
 * the given surface and context.  Falls back to a bundled static template
 * within 500 ms if the API is unavailable.
 *
 * Usage:
 *   const emptyCopy = useAtlasCopy('empty_state', computed(() => ({ page: 'usage' })))
 *
 *   <p>{{ emptyCopy.copy?.text }}</p>
 *   <button @click="emptyCopy.reportEvent('click')">CTA</button>
 */

import { ref, watch, toValue } from 'vue'
import axios from 'axios'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000'
const FALLBACK_TIMEOUT_MS = 500

// Bundled fallback templates (must stay in sync with intelligence/atlas/fallback_copy.json)
const FALLBACK_COPY = {
  empty_state: {
    text: 'Your usage insights will appear here once your first request is processed — see exactly where your spend goes, every day.',
    cta: { label: 'View documentation', action: 'navigate:/docs' },
    fallback: true
  },
  banner: {
    text: 'Your usage insights are live — see where your spend is going today.',
    cta: { label: 'Open dashboard', action: 'navigate:/usage' },
    fallback: true
  },
  modal: {
    text: 'Your usage data is now accurate and up to date.',
    cta: { label: 'See my usage', action: 'navigate:/usage' },
    fallback: true
  },
  tooltip: {
    text: 'Total API requests processed on this day.',
    fallback: true
  },
  success_state: {
    text: 'Your usage insights are live and accurate.',
    cta: { label: 'Explore usage trends', action: 'scroll:#usage-history' },
    fallback: true
  },
  error_state: {
    text: 'Usage data is temporarily unavailable. We\'re working on it.',
    cta: { label: 'Refresh', action: 'reload' },
    fallback: true
  }
}

/**
 * @param {string}                surface  - Atlas surface key (e.g. 'empty_state')
 * @param {import('vue').Ref|object} contextRef - Reactive context (or plain object)
 * @returns {{ copy: Ref, loading: Ref, reportEvent: Function }}
 */
export function useAtlasCopy(surface, contextRef) {
  const copy    = ref(null)
  const loading = ref(true)
  let   variantId = null
  let   abortController = null

  async function load() {
    loading.value = true
    copy.value    = null
    variantId     = null

    if (abortController) {
      abortController.abort()
    }
    abortController = new AbortController()

    // Race the API call against the 500 ms timeout
    const timeoutId = setTimeout(() => {
      abortController.abort()
      useFallback()
    }, FALLBACK_TIMEOUT_MS)

    try {
      const ctx = toValue(contextRef) || {}
      const response = await axios.get(`${API_URL}/api/atlas/copy`, {
        params: {
          surface,
          context: btoa(JSON.stringify(ctx))
        },
        signal: abortController.signal
      })
      clearTimeout(timeoutId)

      const data  = response.data
      variantId   = data.variant_id
      copy.value  = {
        text:     data.text,
        cta:      data.cta || null,
        fallback: data.fallback ?? false
      }
    } catch (err) {
      clearTimeout(timeoutId)
      if (err.name !== 'CanceledError' && err.name !== 'AbortError') {
        // Non-timeout error — use fallback immediately
        useFallback()
      }
      // Timeout already set fallback via setTimeout
    } finally {
      loading.value = false
    }
  }

  function useFallback() {
    copy.value    = FALLBACK_COPY[surface] || { text: '', fallback: true }
    variantId     = null
    loading.value = false
  }

  /**
   * Report a user interaction event back to the Atlas reward stream.
   * @param {'click'|'action'|'conversion'} kind
   * @param {object} extra - Optional payload (e.g. { dwell_ms: 2000 })
   */
  function reportEvent(kind, extra = {}) {
    if (!variantId) return  // Fallback was served — no tracing

    axios.post(`${API_URL}/api/atlas/copy/${variantId}/event`, {
      kind,
      dwell_ms: extra.dwell_ms || null
    }).catch(() => {
      // Fire-and-forget — never surface telemetry errors to the user
    })
  }

  // Re-load whenever the context changes
  watch(
    () => toValue(contextRef),
    load,
    { immediate: true, deep: true }
  )

  return { copy, loading, reportEvent }
}

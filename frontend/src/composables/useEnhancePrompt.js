/**
 * useEnhancePrompt — composable for the Atlas "Enhance Prompt" affordance.
 *
 * Wraps POST /api/atlas/enhance-prompt with:
 *   - 8s timeout (matches server budget)
 *   - last-write-wins abort on re-invocation
 *   - consistent {ok, data, error} return shape
 *
 * Usage:
 *   const { enhance, loading, error, lastResult } = useEnhancePrompt()
 *   await enhance({ prompt: 'foo', surface: 'atlas_chat' })
 */

import { ref } from 'vue'
import axios from 'axios'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000'

export function useEnhancePrompt() {
  const loading    = ref(false)
  const error      = ref(null)
  const lastResult = ref(null)
  let abortController = null

  async function enhance({
    prompt,
    surface  = 'generic',
    mode     = 'balanced',
    audience = 'user',
    tone     = 'neutral',
  }) {
    if (!prompt || !prompt.trim()) {
      return { ok: false, error: { code: 'empty_prompt', message: 'Prompt is empty' } }
    }

    if (abortController) abortController.abort()
    abortController = new AbortController()

    loading.value = true
    error.value   = null

    try {
      const resp = await axios.post(
        `${API_URL}/api/atlas/enhance-prompt`,
        { prompt, surface, mode, audience, tone },
        { timeout: 9000, signal: abortController.signal },
      )
      lastResult.value = resp.data
      return { ok: true, data: resp.data }
    } catch (err) {
      if (err.name === 'CanceledError' || err.name === 'AbortError') {
        return { ok: false, error: { code: 'aborted' } }
      }
      const normalized = {
        code:    err.response?.data?.error || 'request_failed',
        message: err.response?.data?.message || err.message || 'Failed to enhance prompt',
        status:  err.response?.status || null,
      }
      error.value = normalized
      return { ok: false, error: normalized }
    } finally {
      loading.value = false
    }
  }

  return { enhance, loading, error, lastResult }
}

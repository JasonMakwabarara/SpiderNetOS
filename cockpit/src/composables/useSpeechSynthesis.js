/**
 * useSpeechSynthesis — the browser `speechSynthesis` fallback for Atlas's
 * voice (plan D6-D), used when `POST /api/atlas/speak` fails and the user
 * allows the browser voice.
 *
 * - Voices arrive asynchronously in Chrome; we reload on `voiceschanged`.
 * - Prefers a Sub-Saharan English voice (en-ZA, en-NG, en-KE), then any
 *   English voice, then whatever the browser has.
 * - Speaks sentence by sentence (Chrome cuts long utterances off around 15s)
 *   using the same cleaning as the TTS path (utils/textForSpeech).
 * - `cancel()` stops everything, including queued sentences.
 */
import { getCurrentInstance, onBeforeUnmount, ref } from 'vue'
import { speechSentences } from '../utils/textForSpeech.js'

export const PREFERRED_LANGS = ['en-ZA', 'en-NG', 'en-KE']

const normLang = (lang) => String(lang || '').replace(/_/g, '-').toLowerCase()

/** Best voice for Atlas from a SpeechSynthesisVoice list. */
export function pickVoice(voices, preferred = PREFERRED_LANGS) {
  const list = Array.isArray(voices) ? voices : []
  if (!list.length) return null
  for (const lang of preferred) {
    const want = normLang(lang)
    const hit = list.find((v) => normLang(v?.lang) === want) || list.find((v) => normLang(v?.lang).startsWith(`${want}-`))
    if (hit) return hit
  }
  return list.find((v) => normLang(v?.lang).startsWith('en')) || list[0] || null
}

export function useSpeechSynthesis() {
  const synth = typeof window !== 'undefined' ? window.speechSynthesis : undefined
  const supported = !!synth && typeof window.SpeechSynthesisUtterance === 'function'
  const voices = ref([])
  const speaking = ref(false)
  let generation = 0

  function loadVoices() {
    if (!supported) return []
    try {
      voices.value = synth.getVoices?.() || []
    } catch {
      voices.value = []
    }
    return voices.value
  }

  if (supported) {
    loadVoices()
    try { synth.addEventListener?.('voiceschanged', loadVoices) } catch { /* old Safari */ }
    if (getCurrentInstance()) {
      onBeforeUnmount(() => {
        try { synth.removeEventListener?.('voiceschanged', loadVoices) } catch { /* noop */ }
      })
    }
  }

  function cancel() {
    if (!supported) return
    generation += 1
    speaking.value = false
    try { synth.cancel() } catch { /* noop */ }
  }

  /**
   * Speak `text`. Returns synchronously; `onEnd` fires after the last
   * sentence, `onError` on a real failure (not on our own cancel()).
   */
  function speak(text, { voice, rate = 1, pitch = 1, preferred, onEnd, onError } = {}) {
    if (!supported) return { success: false, error: 'This browser cannot speak.' }
    const chunks = speechSentences(text)
    if (!chunks.length) return { success: false, error: 'Nothing to say.' }
    cancel()
    const token = generation
    const chosen = voice || pickVoice(voices.value.length ? voices.value : loadVoices(), preferred)
    speaking.value = true
    chunks.forEach((chunk, i) => {
      const u = new window.SpeechSynthesisUtterance(chunk)
      if (chosen) {
        u.voice = chosen
        u.lang = chosen.lang
      }
      u.rate = rate
      u.pitch = pitch
      if (i === chunks.length - 1) {
        u.onend = () => {
          if (token !== generation) return
          speaking.value = false
          onEnd?.()
        }
      }
      u.onerror = (event) => {
        if (token !== generation) return
        if (event?.error === 'interrupted' || event?.error === 'canceled') return
        generation += 1
        speaking.value = false
        try { synth.cancel() } catch { /* noop */ }
        onError?.(event)
      }
      synth.speak(u)
    })
    return { success: true, chunks: chunks.length, voice: chosen }
  }

  return { supported, voices, speaking, speak, cancel, loadVoices, pickVoice }
}

export default useSpeechSynthesis

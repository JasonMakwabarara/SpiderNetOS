/**
 * Atlas voice Pinia store (plan D6-D) — the persona catalogue, the user's
 * voice preferences and playback of Atlas's replies.
 *
 * Endpoints (PR 5 contract, Stream E):
 *   GET  /api/voice/personas  → { data: [{ slug, display_name, provider, accent, gender, style, tags,
 *                                           preview_url, recommended_for, cost_per_1k_chars, is_default }] }
 *   GET  /api/me/voice        → { data: { persona_slug, speak_enabled, browser_fallback } }
 *   PUT  /api/me/voice        { persona_slug, speak_enabled, browser_fallback } → same
 *   POST /api/atlas/speak     { text | message_id, persona_slug? } → audio/mpeg
 *
 * Audio is fetched with native `fetch` (axios would buffer it as JSON) and
 * the bearer token from services/api.js, then played through the one shared
 * <audio> element (useAudioPlayer). If the request or playback fails and
 * the user allows it, the browser's speechSynthesis reads the reply instead.
 *
 * Autoplay policy: `toggleSpeak()` and `preview()` call `unlockAudio()`
 * synchronously, so they must be invoked straight from a click handler.
 * When a later programmatic play is still blocked, `autoplayBlocked` flips
 * on and the UI offers "Tap to hear".
 *
 * Preferences hydrate from `authStore.user.preferences.voice` and are merged
 * back into it after a save, so a reload keeps them even before the API
 * answers. The catalogue falls back to the shipped fixture like PR 1 stores.
 */
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api, { readAccessToken } from '../services/api.js'
import { useAuthStore } from './auth.js'
import { useAudioPlayer } from '../composables/useAudioPlayer.js'
import { useSpeechSynthesis } from '../composables/useSpeechSynthesis.js'
import { textForSpeech } from '../utils/textForSpeech.js'
import { describeError, fallbackNotice, clone } from '../utils/apiFallback.js'
import personasFixture from '../../tests/fixtures/voice_personas.json'

export const PREVIEW_TEXT =
  "Hello, I'm Atlas. I read your whole business before I answer — and I will always ask one more question."

export const PROVIDER_LABELS = {
  elevenlabs: 'ElevenLabs',
  fishaudio: 'Fish Audio',
  intron: 'Intron Sahara',
  piper: 'Piper (self-hosted)',
  azure: 'Azure Speech',
}

const DEFAULT_PREFS = { persona_slug: null, speak_enabled: false, browser_fallback: true }

function apiUrl(path) {
  if (/^https?:\/\//i.test(path)) return path
  const base = (import.meta.env?.VITE_API_URL || '').replace(/\/$/, '')
  return `${base}${path.startsWith('/') ? '' : '/'}${path}`
}

function authHeaders(extra = {}) {
  const headers = { ...extra }
  const token = readAccessToken()
  if (token) headers.Authorization = `Bearer ${token}`
  try {
    const imp = JSON.parse(localStorage.getItem('impersonating') || 'null')
    if (imp?.tenant_id) headers['X-Impersonated-Tenant'] = imp.tenant_id
  } catch { /* noop */ }
  return headers
}

/** Fetch an audio body as a Blob; throws an axios-shaped error on HTTP failure. */
async function fetchAudio(path, { method = 'GET', body } = {}) {
  if (typeof fetch !== 'function') throw new Error('This browser cannot fetch audio.')
  const init = {
    method,
    headers: authHeaders({ Accept: 'audio/mpeg', ...(body ? { 'Content-Type': 'application/json' } : {}) }),
  }
  if (body) init.body = JSON.stringify(body)
  const res = await fetch(apiUrl(path), init)
  if (!res.ok) {
    const err = new Error(`Voice request failed with status code ${res.status}`)
    err.response = { status: res.status, data: {} }
    throw err
  }
  const blob = await res.blob()
  if (!blob || !blob.size) throw new Error('The voice service returned no audio.')
  return blob
}

export const useVoiceStore = defineStore('voice', () => {
  // ── State ──────────────────────────────────────────────────────────
  const voices = ref([])
  const selectedVoiceId = ref(DEFAULT_PREFS.persona_slug)
  const speakEnabled = ref(DEFAULT_PREFS.speak_enabled)
  const browserFallback = ref(DEFAULT_PREFS.browser_fallback)
  const autoplayBlocked = ref(false)
  /** message id currently audible (TTS clip or browser voice) */
  const playingMessageId = ref(null)
  /** message id whose audio is being fetched */
  const pendingMessageId = ref(null)
  const previewingSlug = ref(null)
  const lastAutoSpokenId = ref(null)
  /** 'tts' | 'browser' | null — how the current audio is being produced */
  const via = ref(null)

  const loading = ref(false)
  const saving = ref(false)
  const error = ref(null)
  const saveError = ref(null)
  const speakError = ref(null)
  const fallback = ref(false)

  const player = useAudioPlayer()
  let synthApi = null
  const synth = () => (synthApi ||= useSpeechSynthesis())
  let speakToken = 0

  // ── Getters ────────────────────────────────────────────────────────
  const defaultVoice = computed(() => voices.value.find((v) => v.is_default) || voices.value[0] || null)
  const selectedVoice = computed(() => voices.value.find((v) => v.slug === selectedVoiceId.value) || null)

  /** Personas grouped by provider, in catalogue order. */
  const voicesByProvider = computed(() => {
    const groups = new Map()
    for (const v of voices.value) {
      const key = v.provider || 'other'
      if (!groups.has(key)) groups.set(key, { provider: key, label: PROVIDER_LABELS[key] || key, voices: [] })
      groups.get(key).voices.push(v)
    }
    return [...groups.values()]
  })

  const preferences = computed(() => ({
    persona_slug: selectedVoiceId.value,
    speak_enabled: speakEnabled.value,
    browser_fallback: browserFallback.value,
  }))

  const isBusy = computed(() => playingMessageId.value != null || pendingMessageId.value != null || previewingSlug.value != null)

  function stateFor(messageId) {
    if (messageId == null) return 'idle'
    if (pendingMessageId.value === messageId) return 'loading'
    if (playingMessageId.value === messageId) return 'playing'
    return 'idle'
  }

  // ── Preferences ────────────────────────────────────────────────────
  function applyPreferences(p = {}) {
    if (!p || typeof p !== 'object') return
    if (p.persona_slug !== undefined) selectedVoiceId.value = p.persona_slug || null
    if (p.speak_enabled !== undefined && p.speak_enabled !== null) speakEnabled.value = !!p.speak_enabled
    if (p.browser_fallback !== undefined && p.browser_fallback !== null) browserFallback.value = !!p.browser_fallback
  }

  /** Read preferences from the signed-in user (no network). */
  function hydrate() {
    const auth = useAuthStore()
    const voice = auth.user?.preferences?.voice
    if (voice && typeof voice === 'object') applyPreferences(voice)
  }

  function mergeIntoAuth(prefs = preferences.value) {
    const auth = useAuthStore()
    if (!auth.user) return
    const current = auth.user.preferences || {}
    auth.user = { ...auth.user, preferences: { ...current, voice: { ...(current.voice || {}), ...prefs } } }
    try {
      const u = JSON.stringify(auth.user)
      localStorage.setItem('user', u)
      localStorage.setItem('sn_user', u)
    } catch { /* storage full / private mode */ }
  }

  function ensureSelection() {
    if (!selectedVoiceId.value && defaultVoice.value) selectedVoiceId.value = defaultVoice.value.slug
  }

  async function fetchVoices() {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get('/api/voice/personas')
      voices.value = Array.isArray(data?.data) ? data.data : []
      fallback.value = false
      ensureSelection()
      return { success: true }
    } catch (err) {
      error.value = fallbackNotice(err, 'the voice catalogue')
      fallback.value = true
      voices.value = clone(personasFixture.data)
      ensureSelection()
      return { success: false, error: error.value }
    } finally {
      loading.value = false
    }
  }

  async function fetchPreferences() {
    try {
      const { data } = await api.get('/api/me/voice')
      applyPreferences(data?.data || data || {})
      mergeIntoAuth()
      return { success: true }
    } catch (err) {
      hydrate()
      return { success: false, error: describeError(err, 'Could not load your voice settings') }
    }
  }

  /**
   * Save preferences (optionally applying `patch` first). The local choice
   * is kept even when the PUT fails, so voice keeps working on this device;
   * `saveError` says the server didn't take it yet.
   */
  async function savePreferences(patch = {}) {
    applyPreferences(patch)
    saving.value = true
    saveError.value = null
    try {
      const { data } = await api.put('/api/me/voice', { ...preferences.value })
      const server = data?.data
      if (server && typeof server === 'object') applyPreferences(server)
      mergeIntoAuth()
      return { success: true }
    } catch (err) {
      mergeIntoAuth()
      saveError.value = describeError(err, 'Could not save your voice settings')
      return { success: false, error: saveError.value }
    } finally {
      saving.value = false
    }
  }

  function selectVoice(slug) {
    selectedVoiceId.value = slug || null
  }

  // ── Playback ───────────────────────────────────────────────────────
  /** Call from a click handler: blesses the shared audio element. */
  async function unlockAudio() {
    const res = await player.unlock()
    if (res.success) autoplayBlocked.value = false
    else if (res.blocked) autoplayBlocked.value = true
    return res
  }

  /**
   * Flip "Atlas speaks replies". Unlocks audio first — synchronously, inside
   * the caller's gesture — then persists the preference.
   */
  async function toggleSpeak(force) {
    const next = typeof force === 'boolean' ? force : !speakEnabled.value
    const unlocking = next ? unlockAudio() : null
    if (!next) stop()
    const saved = await savePreferences({ speak_enabled: next })
    if (unlocking) await unlocking
    return { success: saved.success, enabled: next, error: saved.error }
  }

  function stop() {
    speakToken += 1
    player.stop()
    synthApi?.cancel()
    playingMessageId.value = null
    pendingMessageId.value = null
    previewingSlug.value = null
    via.value = null
  }

  function browserSpeak(text, id, { onDone } = {}) {
    const s = synth()
    const res = s.speak(text, {
      onEnd: () => {
        if (playingMessageId.value === id) playingMessageId.value = null
        if (via.value === 'browser') via.value = null
        onDone?.()
      },
      onError: (event) => {
        if (playingMessageId.value === id) playingMessageId.value = null
        if (event?.error === 'not-allowed') autoplayBlocked.value = true
        else speakError.value = 'The browser voice stopped unexpectedly.'
        onDone?.()
      },
    })
    if (res.success) {
      playingMessageId.value = id
      via.value = 'browser'
    }
    return res
  }

  /**
   * Speak an Atlas message: server TTS first, browser voice as fallback.
   * Pass `{ gesture: true }` from a click handler so the audio element is
   * unlocked before the (async) fetch returns.
   */
  async function speak(messageId, text, { gesture = false } = {}) {
    const clean = textForSpeech(text)
    if (!clean) return { success: false, error: 'Nothing to say.' }
    stop()
    const unlocking = gesture ? unlockAudio() : null
    const token = ++speakToken
    speakError.value = null
    pendingMessageId.value = messageId
    try {
      const body = { text: clean }
      if (selectedVoiceId.value) body.persona_slug = selectedVoiceId.value
      const blob = await fetchAudio('/api/atlas/speak', { method: 'POST', body })
      if (unlocking) await unlocking
      if (token !== speakToken) return { success: false, cancelled: true }
      pendingMessageId.value = null
      playingMessageId.value = messageId
      via.value = 'tts'
      const played = await player.playBlob(blob, messageId)
      if (token !== speakToken) return { success: false, cancelled: true }
      if (played.success) {
        autoplayBlocked.value = false
        return { success: true, via: 'tts' }
      }
      playingMessageId.value = null
      via.value = null
      if (played.blocked) {
        autoplayBlocked.value = true
        return { success: false, blocked: true, error: played.error }
      }
      throw new Error(played.error || 'Playback failed')
    } catch (err) {
      if (token !== speakToken) return { success: false, cancelled: true }
      pendingMessageId.value = null
      if (browserFallback.value) {
        const res = browserSpeak(clean, messageId)
        if (res.success) return { success: true, via: 'browser' }
        speakError.value = res.error
        return { success: false, error: res.error }
      }
      speakError.value = describeError(err, 'Atlas could not speak')
      return { success: false, error: speakError.value }
    }
  }

  /** Speak `message` if auto-speak is on, the tab is visible and it's new. */
  async function autoSpeak(message) {
    if (!speakEnabled.value || !message || message.role !== 'assistant' || message.isError) return { skipped: true }
    if (typeof document !== 'undefined' && document.visibilityState === 'hidden') return { skipped: true, reason: 'hidden' }
    if (message.id != null && lastAutoSpokenId.value === message.id) return { skipped: true, reason: 'spoken' }
    lastAutoSpokenId.value = message.id
    return speak(message.id, message.content)
  }

  /** Preview a persona. Call from a click handler. */
  async function preview(slug) {
    const persona = voices.value.find((v) => v.slug === slug)
    if (!persona) return { success: false, error: 'Unknown voice.' }
    stop()
    const unlocking = unlockAudio()
    const token = ++speakToken
    const clipId = `preview:${slug}`
    speakError.value = null
    previewingSlug.value = slug
    try {
      const blob = persona.preview_url
        ? await fetchAudio(persona.preview_url)
        : await fetchAudio('/api/atlas/speak', { method: 'POST', body: { text: PREVIEW_TEXT, persona_slug: slug } })
      await unlocking
      if (token !== speakToken) return { success: false, cancelled: true }
      via.value = 'tts'
      const played = await player.playBlob(blob, clipId)
      if (token !== speakToken) return { success: false, cancelled: true }
      if (played.success) return { success: true, via: 'tts' }
      if (played.blocked) {
        previewingSlug.value = null
        via.value = null
        autoplayBlocked.value = true
        return { success: false, blocked: true, error: played.error }
      }
      throw new Error(played.error || 'Playback failed')
    } catch (err) {
      if (token !== speakToken) return { success: false, cancelled: true }
      if (browserFallback.value) {
        const res = synth().speak(PREVIEW_TEXT, {
          onEnd: () => { if (previewingSlug.value === slug) previewingSlug.value = null },
          onError: () => { if (previewingSlug.value === slug) previewingSlug.value = null },
        })
        if (res.success) {
          via.value = 'browser'
          return { success: true, via: 'browser' }
        }
      }
      previewingSlug.value = null
      via.value = null
      speakError.value = describeError(err, 'That preview is not available yet')
      return { success: false, error: speakError.value }
    }
  }

  player.on('ended', ({ id }) => {
    if (id != null && playingMessageId.value === id) playingMessageId.value = null
    if (typeof id === 'string' && id.startsWith('preview:') && previewingSlug.value === id.slice(8)) previewingSlug.value = null
    via.value = null
  })
  player.on('error', ({ id, error: message }) => {
    if (playingMessageId.value === id) playingMessageId.value = null
    if (typeof id === 'string' && id.startsWith('preview:')) previewingSlug.value = null
    speakError.value = message || 'The audio could not be played.'
    via.value = null
  })

  hydrate()

  return {
    // state
    voices, selectedVoiceId, speakEnabled, browserFallback, autoplayBlocked,
    playingMessageId, pendingMessageId, previewingSlug, lastAutoSpokenId, via,
    loading, saving, error, saveError, speakError, fallback,
    // getters
    defaultVoice, selectedVoice, voicesByProvider, preferences, isBusy,
    // actions
    stateFor, hydrate, applyPreferences, ensureSelection, fetchVoices, fetchPreferences, savePreferences, selectVoice,
    unlockAudio, toggleSpeak, speak, autoSpeak, preview, stop,
  }
})

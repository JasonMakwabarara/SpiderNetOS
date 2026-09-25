/**
 * useAudioPlayer — one persistent <audio> element for Atlas's voice
 * (plan D6-D). Shared across every caller so only one clip ever plays.
 *
 *   const player = useAudioPlayer()
 *   await player.unlock()                 // inside a click handler (autoplay policy)
 *   await player.playBlob(blob, 'msg_1')  // → { success } | { success:false, blocked, error }
 *   player.stop()
 *   const off = player.on('ended', ({ id }) => …)
 *
 * Why one element: iOS Safari and Chrome only allow programmatic play()
 * on an element that has already played inside a user gesture. `unlock()`
 * plays a silent clip on that same element from the gesture, after which
 * later `playBlob()` calls (from a fetch, outside the gesture) are allowed.
 * Blobs are played through object URLs (the iOS-safe path) and revoked
 * when the clip ends or is replaced.
 */
import { ref } from 'vue'

// 44-byte PCM WAV with no samples: plays instantly, makes no sound.
export const SILENT_WAV = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQAAAAA='

let audio = null
let objectUrl = null
let unlocked = false
const playing = ref(false)
const currentId = ref(null)
const lastError = ref(null)
const listeners = new Map()

function emit(event, payload) {
  for (const cb of listeners.get(event) || []) {
    try { cb(payload) } catch { /* a listener must never break playback */ }
  }
}

function on(event, cb) {
  if (typeof cb !== 'function') return () => {}
  if (!listeners.has(event)) listeners.set(event, new Set())
  listeners.get(event).add(cb)
  return () => off(event, cb)
}

function off(event, cb) {
  listeners.get(event)?.delete(cb)
}

function isSupported() {
  return typeof window !== 'undefined' && typeof window.Audio === 'function'
}

function ensureAudio() {
  if (audio) return audio
  if (!isSupported()) return null
  audio = new window.Audio()
  try { audio.preload = 'auto' } catch { /* noop */ }
  audio.addEventListener?.('ended', handleEnded)
  audio.addEventListener?.('error', handleError)
  return audio
}

function releaseUrl() {
  if (!objectUrl) return
  try { URL.revokeObjectURL(objectUrl) } catch { /* noop */ }
  objectUrl = null
}

function handleEnded() {
  const id = currentId.value
  playing.value = false
  currentId.value = null
  releaseUrl()
  emit('ended', { id })
}

function handleError() {
  // The silent unlock clip and cleared sources also raise `error`; only
  // report failures of a clip we were actually playing.
  if (!playing.value && currentId.value == null) return
  const id = currentId.value
  playing.value = false
  currentId.value = null
  lastError.value = 'The audio could not be played.'
  releaseUrl()
  emit('error', { id, error: lastError.value })
}

async function playSrc(src, id) {
  const el = ensureAudio()
  if (!el) {
    lastError.value = 'Audio playback is not supported in this browser.'
    return { success: false, error: lastError.value }
  }
  lastError.value = null
  el.muted = false
  el.src = src
  currentId.value = id ?? null
  playing.value = true
  try {
    await el.play()
    emit('play', { id: currentId.value })
    return { success: true }
  } catch (err) {
    playing.value = false
    currentId.value = null
    const blocked = err?.name === 'NotAllowedError'
    lastError.value = blocked ? 'The browser blocked autoplay — tap to hear.' : err?.message || 'The audio could not be played.'
    if (!blocked) releaseUrl()
    return { success: false, blocked, error: lastError.value }
  }
}

/** Stop whatever is playing (no-op when idle). */
function stop() {
  const id = currentId.value
  if (audio) {
    try { audio.pause() } catch { /* noop */ }
    try { audio.currentTime = 0 } catch { /* noop */ }
  }
  const wasPlaying = playing.value
  playing.value = false
  currentId.value = null
  releaseUrl()
  if (wasPlaying || id != null) emit('stop', { id })
}

async function playBlob(blob, id) {
  stop()
  if (!blob) return { success: false, error: 'No audio to play.' }
  if (typeof URL === 'undefined' || typeof URL.createObjectURL !== 'function') {
    lastError.value = 'Audio playback is not supported in this browser.'
    return { success: false, error: lastError.value }
  }
  objectUrl = URL.createObjectURL(blob)
  return playSrc(objectUrl, id)
}

async function playUrl(url, id) {
  stop()
  if (!url) return { success: false, error: 'No audio to play.' }
  return playSrc(url, id)
}

/**
 * Bless the shared element from a user gesture. Safe to call repeatedly;
 * never interrupts a clip that is already playing.
 */
async function unlock() {
  if (unlocked) return { success: true }
  const el = ensureAudio()
  if (!el) return { success: false, error: 'Audio playback is not supported in this browser.' }
  if (playing.value) return { success: true }
  el.muted = true
  el.src = SILENT_WAV
  try {
    await el.play()
    try { el.pause() } catch { /* noop */ }
    unlocked = true
    return { success: true }
  } catch (err) {
    return { success: false, blocked: err?.name === 'NotAllowedError', error: err?.message || 'Audio is blocked.' }
  } finally {
    el.muted = false
  }
}

export function useAudioPlayer() {
  return {
    playing,
    currentId,
    lastError,
    get unlocked() { return unlocked },
    isSupported,
    playBlob,
    playUrl,
    stop,
    unlock,
    on,
    off,
  }
}

/** Test-only: forget the shared element and listeners. */
export function resetAudioPlayer() {
  stop()
  if (audio) {
    audio.removeEventListener?.('ended', handleEnded)
    audio.removeEventListener?.('error', handleError)
  }
  audio = null
  unlocked = false
  lastError.value = null
  listeners.clear()
}

export default useAudioPlayer

// Browser-audio fakes for the voice tests: a controllable <audio> element,
// object URLs, fetch returning audio/mpeg, and speechSynthesis.
import { vi } from 'vitest'

export class FakeAudio {
  static instances = []
  /** (el) => Promise — override per test to reject with NotAllowedError etc. */
  static playImpl = () => Promise.resolve()

  constructor() {
    this.src = ''
    this.muted = false
    this.paused = true
    this.currentTime = 0
    this.preload = ''
    this.listeners = {}
    this.plays = []
    FakeAudio.instances.push(this)
  }

  addEventListener(type, cb) {
    ;(this.listeners[type] ||= new Set()).add(cb)
  }

  removeEventListener(type, cb) {
    this.listeners[type]?.delete(cb)
  }

  dispatch(type) {
    for (const cb of this.listeners[type] || []) cb({ type })
  }

  play() {
    this.plays.push({ src: this.src, muted: this.muted })
    this.paused = false
    return FakeAudio.playImpl(this)
  }

  pause() {
    this.paused = true
  }
}

export function notAllowed() {
  const err = new Error('play() failed because the user didn’t interact with the document first.')
  err.name = 'NotAllowedError'
  return err
}

const saved = {}

export function installAudio() {
  FakeAudio.instances = []
  FakeAudio.playImpl = () => Promise.resolve()
  saved.Audio = window.Audio
  saved.createObjectURL = URL.createObjectURL
  saved.revokeObjectURL = URL.revokeObjectURL
  window.Audio = FakeAudio
  globalThis.Audio = FakeAudio
  let n = 0
  URL.createObjectURL = vi.fn(() => `blob:fake-${++n}`)
  URL.revokeObjectURL = vi.fn()
  return FakeAudio
}

export function restoreAudio() {
  window.Audio = saved.Audio
  globalThis.Audio = saved.Audio
  URL.createObjectURL = saved.createObjectURL
  URL.revokeObjectURL = saved.revokeObjectURL
}

export function audioResponse(bytes = 'ID3fake') {
  return { ok: true, status: 200, blob: async () => new Blob([bytes], { type: 'audio/mpeg' }) }
}

export function installFetch(impl) {
  saved.fetch = globalThis.fetch
  const fn = vi.fn(impl || (async () => audioResponse()))
  globalThis.fetch = fn
  window.fetch = fn
  return fn
}

export function restoreFetch() {
  globalThis.fetch = saved.fetch
  window.fetch = saved.fetch
}

export function installSpeech(voices = []) {
  saved.speechSynthesis = Object.getOwnPropertyDescriptor(window, 'speechSynthesis')
  saved.Utterance = window.SpeechSynthesisUtterance
  const listeners = {}
  const synth = {
    spoken: [],
    speak: vi.fn((u) => synth.spoken.push(u)),
    cancel: vi.fn(),
    getVoices: vi.fn(() => voices),
    addEventListener: vi.fn((type, cb) => { listeners[type] = cb }),
    removeEventListener: vi.fn(),
    fire: (type) => listeners[type]?.(),
  }
  Object.defineProperty(window, 'speechSynthesis', { value: synth, configurable: true, writable: true })
  window.SpeechSynthesisUtterance = class {
    constructor(text) {
      this.text = text
      this.voice = null
      this.lang = ''
    }
  }
  return synth
}

export function removeSpeech() {
  Object.defineProperty(window, 'speechSynthesis', { value: undefined, configurable: true, writable: true })
  window.SpeechSynthesisUtterance = undefined
}

export function restoreSpeech() {
  if (saved.speechSynthesis) Object.defineProperty(window, 'speechSynthesis', saved.speechSynthesis)
  else Object.defineProperty(window, 'speechSynthesis', { value: undefined, configurable: true, writable: true })
  window.SpeechSynthesisUtterance = saved.Utterance
}

export function setVisibility(state) {
  Object.defineProperty(document, 'visibilityState', { value: state, configurable: true })
}

export function resetVisibility() {
  delete document.visibilityState
}

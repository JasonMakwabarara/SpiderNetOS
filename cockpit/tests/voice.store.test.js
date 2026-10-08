// Atlas voice store — Vitest unit tests.
// Hydration from the signed-in user's preferences, the persona catalogue
// (with fixture fallback), saving preferences back into the auth store,
// speaking a reply through native `fetch` + the one shared <audio>, the
// browser speechSynthesis fallback, autoplay blocking and stop().
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('../src/services/api.js', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
  readAccessToken: vi.fn(),
}))

import api, { readAccessToken } from '../src/services/api.js'
import { useVoiceStore } from '../src/stores/voice.js'
import { useAuthStore } from '../src/stores/auth.js'
import { resetAudioPlayer } from '../src/composables/useAudioPlayer.js'
import personasFixture from './fixtures/voice_personas.json'
import { httpError } from './helpers/harness.js'
import {
  FakeAudio, installAudio, restoreAudio,
  installFetch, restoreFetch, installSpeech, restoreSpeech,
  audioResponse, notAllowed,
} from './helpers/audio.js'

const API_BASE = 'https://api.test'
const TOKEN = 'tok_atlas_voice'

const PERSONAS = {
  data: [
    { slug: 'el-zim-man', display_name: 'Zimbabwean man', provider: 'elevenlabs', accent: 'Zimbabwean English', gender: 'male', style: null, tags: ['warm'], preview_url: '/api/voice/personas/el-zim-man/preview', recommended_for: ['atlas'], cost_per_1k_chars: null, is_default: true },
    { slug: 'el-ke-man', display_name: 'Kenyan man', provider: 'elevenlabs', accent: 'Kenyan English', gender: 'male', style: null, tags: [], preview_url: null, recommended_for: [], cost_per_1k_chars: null, is_default: false },
    { slug: 'fish-zim-man', display_name: 'Zimbabwe narrator', provider: 'fishaudio', accent: 'Zimbabwean English', gender: 'male', style: null, tags: [], preview_url: null, recommended_for: ['atlas'], cost_per_1k_chars: 0, is_default: false },
  ],
}

const FIXTURE_DEFAULT = personasFixture.data.find((v) => v.is_default).slug

let fetchMock
let synth

/** Seed the signed-in user before any store reads localStorage. */
function seedUser(preferences) {
  localStorage.setItem('user', JSON.stringify({ id: 42, email: 'jason@apexsynchronia.com', preferences }))
}

/** Fresh pinia + voice store (so `hydrate()` sees the seeded user). */
function makeStore() {
  setActivePinia(createPinia())
  return useVoiceStore()
}

beforeEach(() => {
  vi.resetAllMocks()
  localStorage.clear()
  installAudio()
  resetAudioPlayer()
  fetchMock = installFetch()
  synth = installSpeech([{ lang: 'en-US', name: 'Samantha' }, { lang: 'en-ZA', name: 'Leah' }])
  readAccessToken.mockReturnValue(TOKEN)
  vi.stubEnv('VITE_API_URL', API_BASE)
  setActivePinia(createPinia())
})

afterEach(() => {
  resetAudioPlayer()
  restoreAudio()
  restoreFetch()
  restoreSpeech()
  vi.unstubAllEnvs()
  localStorage.clear()
})

describe('voice store · preferences', () => {
  it('hydrates from authStore.user.preferences.voice', () => {
    seedUser({ theme: 'dark', voice: { persona_slug: 'fish-zim-man', speak_enabled: true, browser_fallback: false } })
    const store = makeStore()
    expect(store.selectedVoiceId).toBe('fish-zim-man')
    expect(store.speakEnabled).toBe(true)
    expect(store.browserFallback).toBe(false)
    expect(store.preferences).toEqual({ persona_slug: 'fish-zim-man', speak_enabled: true, browser_fallback: false })
  })

  it('keeps the defaults when the user has no voice preferences', () => {
    seedUser({ theme: 'dark' })
    const store = makeStore()
    expect(store.preferences).toEqual({ persona_slug: null, speak_enabled: false, browser_fallback: true })

    // A later hydrate() picks up preferences that arrive with the profile.
    const auth = useAuthStore()
    auth.user = { ...auth.user, preferences: { voice: { persona_slug: 'el-ke-man', speak_enabled: true } } }
    store.hydrate()
    expect(store.selectedVoiceId).toBe('el-ke-man')
    expect(store.speakEnabled).toBe(true)
    expect(store.browserFallback).toBe(true)
  })

  it('savePreferences PUTs /api/me/voice and merges the answer into the auth store', async () => {
    seedUser({ theme: 'dark' })
    const store = makeStore()
    api.put.mockResolvedValueOnce({ data: { data: { persona_slug: 'fish-zim-man', speak_enabled: true, browser_fallback: true } } })

    const res = await store.savePreferences({ persona_slug: 'fish-zim-man', speak_enabled: true })
    expect(res.success).toBe(true)
    expect(api.put).toHaveBeenCalledWith('/api/me/voice', {
      persona_slug: 'fish-zim-man',
      speak_enabled: true,
      browser_fallback: true,
    })
    expect(store.saveError).toBeNull()
    expect(store.saving).toBe(false)

    const auth = useAuthStore()
    expect(auth.user.preferences.voice).toEqual({ persona_slug: 'fish-zim-man', speak_enabled: true, browser_fallback: true })
    // Unrelated preferences survive the merge, and the bridge keys are written.
    expect(auth.user.preferences.theme).toBe('dark')
    expect(JSON.parse(localStorage.getItem('user')).preferences.voice.speak_enabled).toBe(true)
    expect(JSON.parse(localStorage.getItem('sn_user')).preferences.voice.persona_slug).toBe('fish-zim-man')
  })

  it('keeps the local choice and reports saveError when the PUT fails', async () => {
    seedUser({})
    const store = makeStore()
    api.put.mockRejectedValueOnce(httpError(500, { message: 'Voice service is down' }))

    const res = await store.savePreferences({ speak_enabled: true })
    expect(res.success).toBe(false)
    expect(store.speakEnabled).toBe(true)
    expect(store.saveError).toBe('Voice service is down (HTTP 500)')
    expect(useAuthStore().user.preferences.voice.speak_enabled).toBe(true)
  })
})

describe('voice store · catalogue', () => {
  it('fetchVoices unwraps {data:[…]}, groups by provider and selects the default', async () => {
    api.get.mockResolvedValueOnce({ data: PERSONAS })
    const store = makeStore()

    const res = await store.fetchVoices()
    expect(res.success).toBe(true)
    expect(api.get).toHaveBeenCalledWith('/api/voice/personas')
    expect(store.voices).toHaveLength(3)
    expect(store.fallback).toBe(false)
    expect(store.error).toBeNull()
    expect(store.loading).toBe(false)
    expect(store.selectedVoiceId).toBe('el-zim-man')
    expect(store.selectedVoice.display_name).toBe('Zimbabwean man')
    expect(store.voicesByProvider.map((g) => g.provider)).toEqual(['elevenlabs', 'fishaudio'])
    expect(store.voicesByProvider.map((g) => g.label)).toEqual(['ElevenLabs', 'Fish Audio'])
    expect(store.voicesByProvider[0].voices).toHaveLength(2)
  })

  it('falls back to the shipped persona fixture when the catalogue fails', async () => {
    api.get.mockRejectedValueOnce(httpError(503))
    const store = makeStore()

    const res = await store.fetchVoices()
    expect(res.success).toBe(false)
    expect(store.fallback).toBe(true)
    expect(store.error).toContain('the voice catalogue')
    expect(store.error).toContain('HTTP 503')
    expect(store.error).toContain('sample data')
    expect(store.voices).toHaveLength(personasFixture.data.length)
    expect(store.selectedVoiceId).toBe(FIXTURE_DEFAULT)

    // The fixture is cloned — mutating the store never leaks into it.
    store.voices[0].display_name = 'changed'
    expect(personasFixture.data[0].display_name).not.toBe('changed')
  })
})

describe('voice store · speaking', () => {
  it('speak() fetches the clip with the bearer header from the API base URL and plays it', async () => {
    seedUser({ voice: { persona_slug: 'fish-zim-man' } })
    const store = makeStore()

    const res = await store.speak('msg_1', '**Hello** there.')
    expect(res).toEqual({ success: true, via: 'tts' })

    expect(fetchMock).toHaveBeenCalledTimes(1)
    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe(`${API_BASE}/api/atlas/speak`)
    expect(init.method).toBe('POST')
    expect(init.headers.Authorization).toBe(`Bearer ${TOKEN}`)
    expect(init.headers.Accept).toBe('audio/mpeg')
    expect(init.headers['Content-Type']).toBe('application/json')
    // Markdown is stripped before it is read aloud, and the persona rides along.
    expect(JSON.parse(init.body)).toEqual({ text: 'Hello there.', persona_slug: 'fish-zim-man' })

    expect(store.playingMessageId).toBe('msg_1')
    expect(store.pendingMessageId).toBeNull()
    expect(store.via).toBe('tts')
    expect(store.stateFor('msg_1')).toBe('playing')
    expect(store.stateFor('msg_2')).toBe('idle')
    expect(store.isBusy).toBe(true)
    expect(FakeAudio.instances).toHaveLength(1)
    expect(FakeAudio.instances[0].plays).toHaveLength(1)
    expect(synth.speak).not.toHaveBeenCalled()
  })

  it('refuses a message with nothing sayable in it', async () => {
    const store = makeStore()
    expect(await store.speak('msg_x', '```const a = 1```')).toEqual({ success: false, error: 'Nothing to say.' })
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('falls back to the browser voice when the TTS request fails', async () => {
    fetchMock.mockResolvedValueOnce({ ok: false, status: 502, blob: async () => new Blob() })
    const store = makeStore()
    store.browserFallback = true

    const res = await store.speak('msg_2', 'Hello there.')
    expect(res).toEqual({ success: true, via: 'browser' })
    expect(synth.speak).toHaveBeenCalledTimes(1)
    expect(synth.spoken.map((u) => u.text)).toEqual(['Hello there.'])
    expect(synth.spoken[0].voice.name).toBe('Leah') // prefers en-ZA
    expect(store.playingMessageId).toBe('msg_2')
    expect(store.pendingMessageId).toBeNull()
    expect(store.via).toBe('browser')
    // Nothing ever reached the <audio> element.
    expect(FakeAudio.instances).toHaveLength(0)
  })

  it('reports an error instead when the browser fallback is off', async () => {
    fetchMock.mockResolvedValueOnce({ ok: false, status: 503, blob: async () => new Blob() })
    const store = makeStore()
    store.browserFallback = false

    const res = await store.speak('msg_3', 'Hello there.')
    expect(res.success).toBe(false)
    expect(store.speakError).toContain('HTTP 503')
    expect(store.playingMessageId).toBeNull()
    expect(store.pendingMessageId).toBeNull()
    expect(synth.speak).not.toHaveBeenCalled()
  })

  it('sets autoplayBlocked when the browser refuses to play the clip', async () => {
    FakeAudio.playImpl = () => Promise.reject(notAllowed())
    const store = makeStore()

    const res = await store.speak('msg_4', 'Hello there.')
    expect(res.success).toBe(false)
    expect(res.blocked).toBe(true)
    expect(store.autoplayBlocked).toBe(true)
    expect(store.playingMessageId).toBeNull()
    expect(store.via).toBeNull()

    // A blessed gesture clears the flag again.
    FakeAudio.playImpl = () => Promise.resolve()
    await store.unlockAudio()
    expect(store.autoplayBlocked).toBe(false)
  })

  it('stop() clears playback, the pending fetch and the browser voice', async () => {
    const store = makeStore()
    await store.speak('msg_5', 'Hello there.')
    expect(store.playingMessageId).toBe('msg_5')

    store.stop()
    expect(store.playingMessageId).toBeNull()
    expect(store.pendingMessageId).toBeNull()
    expect(store.previewingSlug).toBeNull()
    expect(store.via).toBeNull()
    expect(store.isBusy).toBe(false)
    expect(FakeAudio.instances[0].paused).toBe(true)
    expect(FakeAudio.instances[0].currentTime).toBe(0)

    // Once the browser voice has been used, stop() cancels it too.
    fetchMock.mockResolvedValueOnce({ ok: false, status: 502, blob: async () => new Blob() })
    await store.speak('msg_6', 'Hello there.')
    expect(store.via).toBe('browser')
    synth.cancel.mockClear()
    store.stop()
    expect(synth.cancel).toHaveBeenCalled()
    expect(store.playingMessageId).toBeNull()

    // A clip whose fetch is still in flight when stop() lands never plays.
    let release
    fetchMock.mockImplementationOnce(() => new Promise((resolve) => { release = () => resolve(audioResponse()) }))
    const pending = store.speak('msg_7', 'Hello there.')
    expect(store.pendingMessageId).toBe('msg_7')
    store.stop()
    release()
    expect(await pending).toEqual({ success: false, cancelled: true })
    expect(store.playingMessageId).toBeNull()
  })

  it('autoSpeak only reads new assistant replies while the tab is visible', async () => {
    const store = makeStore()
    expect(await store.autoSpeak({ id: 'a1', role: 'assistant', content: 'Hi.' })).toEqual({ skipped: true })

    store.speakEnabled = true
    expect(await store.autoSpeak({ id: 'u1', role: 'user', content: 'Hi.' })).toEqual({ skipped: true })
    expect(await store.autoSpeak({ id: 'e1', role: 'assistant', content: 'Boom.', isError: true })).toEqual({ skipped: true })
    expect(fetchMock).not.toHaveBeenCalled()

    const res = await store.autoSpeak({ id: 'a2', role: 'assistant', content: 'Hello there.' })
    expect(res).toEqual({ success: true, via: 'tts' })
    expect(store.lastAutoSpokenId).toBe('a2')
    // The same reply is never read twice.
    expect(await store.autoSpeak({ id: 'a2', role: 'assistant', content: 'Hello there.' })).toEqual({ skipped: true, reason: 'spoken' })
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })
})

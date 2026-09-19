// VoiceSettings view (/settings/voice) — Vitest + @vue/test-utils.
// Persona cards grouped by provider from the catalogue, the Preview button
// going through the voice store, choosing a persona + Save PUTting
// /api/me/voice, and the fallback notice when no voices come back.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'

vi.mock('../src/services/api.js', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
  readAccessToken: vi.fn(),
}))

import api, { readAccessToken } from '../src/services/api.js'
import VoiceSettings from '../src/views/settings/VoiceSettings.vue'
import { useVoiceStore } from '../src/stores/voice.js'
import { resetAudioPlayer } from '../src/composables/useAudioPlayer.js'
import personasFixture from './fixtures/voice_personas.json'
import { mountView, settle, httpError } from './helpers/harness.js'
import {
  installAudio, restoreAudio, installFetch, restoreFetch,
  installSpeech, restoreSpeech,
} from './helpers/audio.js'

const PERSONAS = personasFixture.data
const PROVIDERS = [...new Set(PERSONAS.map((p) => p.provider))]
const DEFAULT_PERSONA = PERSONAS.find((p) => p.is_default)
const OTHER_PERSONA = PERSONAS.find((p) => !p.is_default && p.provider !== DEFAULT_PERSONA.provider)

const PREFS = { persona_slug: null, speak_enabled: false, browser_fallback: true }

let wrapper
let voice

function mockReads({ personas = { data: PERSONAS }, personasFail = false } = {}) {
  api.get.mockImplementation((url) => {
    if (url === '/api/voice/personas') return personasFail ? Promise.reject(httpError(503)) : Promise.resolve({ data: personas })
    if (url === '/api/me/voice') return Promise.resolve({ data: { data: PREFS } })
    return Promise.reject(httpError(404))
  })
}

async function mountSettings(seed) {
  const mounted = await mountView(VoiceSettings, {
    path: '/settings/voice',
    attachTo: document.body,
    setup: () => {
      voice = useVoiceStore()
      seed?.(voice)
    },
  })
  wrapper = mounted.wrapper
  await settle(3)
  return mounted
}

const find = (testid) => wrapper.find(`[data-testid="${testid}"]`)
const findAll = (prefix) => wrapper.findAll(`[data-testid^="${prefix}"]`)

beforeEach(() => {
  vi.resetAllMocks()
  localStorage.clear()
  installAudio()
  resetAudioPlayer()
  installFetch()
  installSpeech([{ lang: 'en-ZA', name: 'Leah' }])
  readAccessToken.mockReturnValue('tok_atlas_voice')
  mockReads()
  api.put.mockResolvedValue({ data: { data: {} } })
})

afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  voice = null
  resetAudioPlayer()
  restoreAudio()
  restoreFetch()
  restoreSpeech()
  document.body.innerHTML = ''
  localStorage.clear()
})

describe('VoiceSettings view', () => {
  it('loads the catalogue and renders a card per persona, grouped by provider', async () => {
    await mountSettings()
    expect(api.get).toHaveBeenCalledWith('/api/voice/personas')
    expect(api.get).toHaveBeenCalledWith('/api/me/voice')

    expect(find('voice-settings-page').exists()).toBe(true)
    expect(findAll('voice-provider-')).toHaveLength(PROVIDERS.length)
    for (const provider of PROVIDERS) {
      const group = find(`voice-provider-${provider}`)
      expect(group.exists()).toBe(true)
      expect(group.text()).toContain(String(PERSONAS.filter((p) => p.provider === provider).length))
    }
    expect(findAll('voice-persona-')).toHaveLength(PERSONAS.length)

    // The catalogue default is selected and badged; recommendations are marked.
    expect(find(`voice-default-${DEFAULT_PERSONA.slug}`).exists()).toBe(true)
    expect(find(`voice-persona-${DEFAULT_PERSONA.slug}`).attributes('data-selected')).toBe('true')
    expect(find('voice-selected-name').text()).toBe(DEFAULT_PERSONA.display_name)
    expect(findAll('voice-recommended-')).toHaveLength(PERSONAS.filter((p) => (p.recommended_for || []).includes('atlas')).length)
    expect(find('voice-fallback-notice').exists()).toBe(false)
    expect(find('voice-empty').exists()).toBe(false)
  })

  it('Preview plays the persona through the voice store', async () => {
    await mountSettings()
    const preview = vi.spyOn(voice, 'preview').mockResolvedValue({ success: true, via: 'tts' })

    const button = find(`voice-preview-${OTHER_PERSONA.slug}`)
    expect(button.text()).toContain('Preview')
    await button.trigger('click')
    expect(preview).toHaveBeenCalledWith(OTHER_PERSONA.slug)
    await settle()

    // While that clip runs the same button stops it instead of restarting.
    voice.previewingSlug = OTHER_PERSONA.slug
    await settle()
    expect(find(`voice-preview-${OTHER_PERSONA.slug}`).text()).toContain('Stop')
    const stop = vi.spyOn(voice, 'stop')
    await find(`voice-preview-${OTHER_PERSONA.slug}`).trigger('click')
    expect(stop).toHaveBeenCalled()
    expect(preview).toHaveBeenCalledTimes(1)
  })

  it('choosing a persona and pressing Save persists the preferences', async () => {
    await mountSettings()
    const save = vi.spyOn(voice, 'savePreferences')
    api.put.mockResolvedValueOnce({
      data: { data: { persona_slug: OTHER_PERSONA.slug, speak_enabled: true, browser_fallback: true } },
    })

    await find(`voice-choose-${OTHER_PERSONA.slug}`).trigger('click')
    await find('voice-speak-toggle').trigger('click')
    await settle()
    expect(voice.selectedVoiceId).toBe(OTHER_PERSONA.slug)
    expect(find('voice-speak-toggle').attributes('aria-checked')).toBe('true')
    expect(find(`voice-persona-${OTHER_PERSONA.slug}`).attributes('data-selected')).toBe('true')
    expect(find('voice-save-status').text()).toBe('Unsaved changes.')

    await find('voice-save').trigger('click')
    await settle(2)

    expect(save).toHaveBeenCalled()
    expect(api.put).toHaveBeenCalledWith('/api/me/voice', {
      persona_slug: OTHER_PERSONA.slug,
      speak_enabled: true,
      browser_fallback: true,
    })
    expect(find('voice-save-status').text()).toBe('Saved.')
    expect(find('voice-selected-name').text()).toBe(OTHER_PERSONA.display_name)
  })

  it('shows the fallback notice when the catalogue comes back empty', async () => {
    mockReads({ personas: { data: [] } })
    await mountSettings()

    expect(find('voice-fallback-notice').exists()).toBe(true)
    expect(find('voice-fallback-notice').text()).toContain("browser's voice")
    expect(find('voice-empty').exists()).toBe(true)
    expect(findAll('voice-persona-')).toHaveLength(0)
    expect(findAll('voice-provider-')).toHaveLength(0)
    expect(find('voice-selected-name').text()).toBe('none yet')
  })

  it('falls back to the shipped personas with the API error when the catalogue fails', async () => {
    mockReads({ personasFail: true })
    await mountSettings()

    expect(find('voice-fallback-notice').text()).toContain('HTTP 503')
    expect(find('voice-fallback-notice').text()).toContain('sample data')
    expect(findAll('voice-persona-')).toHaveLength(PERSONAS.length)
  })
})

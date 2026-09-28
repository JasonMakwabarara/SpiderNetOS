// AtlasChatPanel · voice — Vitest + @vue/test-utils.
// The header SpeakToggle reflects and flips the store, a play button rides
// on assistant (non-error) replies only, clicking it speaks that message,
// a newly arrived reply auto-speaks only while speaking is enabled, the
// "Tap to hear" state appears when autoplay is blocked, there is exactly
// one polite live region, and playback stops when the tab hides/unmounts.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'

vi.mock('../src/services/api.js', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
  readAccessToken: vi.fn(),
}))

import api, { readAccessToken } from '../src/services/api.js'
import AtlasChatPanel from '../src/components/AtlasChatPanel.vue'
import { useVoiceStore } from '../src/stores/voice.js'
import { resetAudioPlayer } from '../src/composables/useAudioPlayer.js'
import { mountView, settle } from './helpers/harness.js'
import {
  FakeAudio, installAudio, restoreAudio, installFetch, restoreFetch,
  installSpeech, restoreSpeech, setVisibility, resetVisibility,
} from './helpers/audio.js'

const ts = '2026-09-19T08:00:00Z'
const userMsg = { id: 'm1', role: 'user', content: 'What should I automate first?', timestamp: ts }
const atlasMsg = { id: 'm2', role: 'assistant', content: 'Start with inbox triage.', timestamp: ts }
const errorMsg = { id: 'm3', role: 'assistant', content: 'Atlas is unavailable.', timestamp: ts, isError: true }

let fetchMock
let wrapper
let voice

/** Mount the panel with a fresh pinia; `seed(store)` runs before mount. */
async function mountPanel({ messages = [userMsg], seed } = {}) {
  const mounted = await mountView(AtlasChatPanel, {
    path: '/atlas',
    props: { messages },
    attachTo: document.body,
    setup: () => {
      voice = useVoiceStore()
      seed?.(voice)
    },
  })
  wrapper = mounted.wrapper
  await settle(2)
  return mounted
}

const find = (testid) => wrapper.find(`[data-testid="${testid}"]`)

beforeEach(() => {
  vi.resetAllMocks()
  localStorage.clear()
  installAudio()
  resetAudioPlayer()
  fetchMock = installFetch()
  installSpeech([{ lang: 'en-ZA', name: 'Leah' }])
  readAccessToken.mockReturnValue('tok_atlas_voice')
  setVisibility('visible')
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
  resetVisibility()
  document.body.innerHTML = ''
  localStorage.clear()
})

describe('AtlasChatPanel · speak toggle', () => {
  it('reflects the store state and flips it from the click', async () => {
    await mountPanel()
    const toggle = find('atlas-speak-toggle')
    expect(toggle.exists()).toBe(true)
    expect(toggle.attributes('aria-pressed')).toBe('false')
    expect(toggle.text()).toContain('Speak')

    const spy = vi.spyOn(voice, 'toggleSpeak')
    await toggle.trigger('click')
    expect(spy).toHaveBeenCalledWith(true)
    await settle()

    // The store is the source of truth for the pressed state.
    expect(find('atlas-speak-toggle').attributes('aria-pressed')).toBe('true')
    expect(find('atlas-speak-toggle').text()).toContain('Speaking')

    await find('atlas-speak-toggle').trigger('click')
    expect(spy).toHaveBeenLastCalledWith(false)
    await settle()
    expect(find('atlas-speak-toggle').attributes('aria-pressed')).toBe('false')
  })

  it('shows "Tap to hear" and one polite live region when autoplay is blocked', async () => {
    await mountPanel({
      messages: [userMsg, atlasMsg],
      seed: (v) => { v.speakEnabled = true },
    })
    expect(find('atlas-speak-blocked').exists()).toBe(false)

    voice.autoplayBlocked = true
    voice.lastAutoSpokenId = 'm2'
    await settle()

    expect(find('atlas-speak-blocked').text()).toBe('Tap to hear')
    expect(find('atlas-msg-play-m2').text()).toContain('Tap to hear')

    const live = wrapper.findAll('[aria-live]')
    expect(live).toHaveLength(1)
    expect(live[0].attributes('aria-live')).toBe('polite')
    expect(live[0].attributes('data-testid')).toBe('atlas-voice-live')
    expect(live[0].text()).toContain('blocked audio')
  })
})

describe('AtlasChatPanel · per-message play', () => {
  it('renders a play button on assistant replies only, never on errors or user turns', async () => {
    await mountPanel({ messages: [userMsg, atlasMsg, errorMsg] })
    expect(find('atlas-msg-play-m2').exists()).toBe(true)
    expect(find('atlas-msg-play-m1').exists()).toBe(false)
    expect(find('atlas-msg-play-m3').exists()).toBe(false)
    expect(wrapper.findAll('[data-testid^="atlas-msg-play-"]')).toHaveLength(1)
  })

  it('speaks that message from the click and reflects its playing state', async () => {
    await mountPanel({ messages: [userMsg, atlasMsg] })
    const spy = vi.spyOn(voice, 'speak')

    await find('atlas-msg-play-m2').trigger('click')
    expect(spy).toHaveBeenCalledWith('m2', 'Start with inbox triage.', { gesture: true })
    await settle(3)

    expect(voice.playingMessageId).toBe('m2')
    const btn = find('atlas-msg-play-m2')
    expect(btn.attributes('data-state')).toBe('playing')
    expect(btn.text()).toContain('Stop')

    // Pressing it again stops.
    await btn.trigger('click')
    await settle()
    expect(voice.playingMessageId).toBeNull()
    expect(find('atlas-msg-play-m2').attributes('data-state')).toBe('idle')
    expect(FakeAudio.instances[0].paused).toBe(true)
  })
})

describe('AtlasChatPanel · auto-speak', () => {
  it('reads a newly arrived assistant reply when speaking is enabled', async () => {
    await mountPanel({ messages: [userMsg], seed: (v) => { v.speakEnabled = true } })
    expect(fetchMock).not.toHaveBeenCalled()

    await wrapper.setProps({ messages: [userMsg, atlasMsg] })
    await settle(3)

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(fetchMock.mock.calls[0][0]).toContain('/api/atlas/speak')
    expect(JSON.parse(fetchMock.mock.calls[0][1].body).text).toBe('Start with inbox triage.')
    expect(voice.lastAutoSpokenId).toBe('m2')
    expect(voice.playingMessageId).toBe('m2')
  })

  it('stays silent when speaking is disabled, and never re-reads history', async () => {
    await mountPanel({ messages: [userMsg] })
    await wrapper.setProps({ messages: [userMsg, atlasMsg] })
    await settle(3)

    expect(fetchMock).not.toHaveBeenCalled()
    expect(voice.lastAutoSpokenId).toBeNull()
    expect(voice.playingMessageId).toBeNull()

    // Replies already on screen at mount are not read out when it is enabled.
    wrapper.unmount()
    wrapper = null
    await mountPanel({ messages: [userMsg, atlasMsg], seed: (v) => { v.speakEnabled = true } })
    await settle(2)
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('stops playback when the tab is hidden and when the panel goes away', async () => {
    await mountPanel({ messages: [userMsg], seed: (v) => { v.speakEnabled = true } })
    await wrapper.setProps({ messages: [userMsg, atlasMsg] })
    await settle(3)
    expect(voice.playingMessageId).toBe('m2')

    setVisibility('hidden')
    document.dispatchEvent(new Event('visibilitychange'))
    await settle()
    expect(voice.playingMessageId).toBeNull()
    expect(FakeAudio.instances[0].paused).toBe(true)

    setVisibility('visible')
    const stopped = vi.spyOn(voice, 'stop')
    wrapper.unmount()
    wrapper = null
    expect(stopped).toHaveBeenCalled()

    // The visibility listener is torn down with the panel.
    setVisibility('hidden')
    stopped.mockClear()
    document.dispatchEvent(new Event('visibilitychange'))
    expect(stopped).not.toHaveBeenCalled()
  })
})

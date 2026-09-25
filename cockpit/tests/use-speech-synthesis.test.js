// useSpeechSynthesis composable — Vitest unit tests.
// Sub-Saharan voice preference, voiceschanged reloading, sentence-chunked
// speech with markdown stripped, onEnd after the last sentence, cancel()
// silencing queued callbacks, error filtering, and unsupported browsers.
import { describe, it, expect, afterEach, vi } from 'vitest'
import { useSpeechSynthesis, pickVoice, PREFERRED_LANGS } from '../src/composables/useSpeechSynthesis.js'
import { installSpeech, removeSpeech, restoreSpeech } from './helpers/audio.js'

const v = (lang, name = lang) => ({ lang, name })

afterEach(() => {
  restoreSpeech()
})

describe('pickVoice', () => {
  it('prefers en-ZA, then en-NG, then en-KE, then any English, then anything', () => {
    expect(PREFERRED_LANGS).toEqual(['en-ZA', 'en-NG', 'en-KE'])
    expect(pickVoice([v('en-US'), v('en-KE'), v('en-NG'), v('en-ZA')]).lang).toBe('en-ZA')
    expect(pickVoice([v('en-US'), v('en_KE'), v('en-NG')]).lang).toBe('en-NG')
    expect(pickVoice([v('fr-FR'), v('en_KE')]).lang).toBe('en_KE')
    expect(pickVoice([v('fr-FR'), v('en-GB')]).lang).toBe('en-GB')
    expect(pickVoice([v('fr-FR'), v('de-DE')]).lang).toBe('fr-FR')
    expect(pickVoice([])).toBeNull()
    expect(pickVoice(null)).toBeNull()
    expect(pickVoice([v('en-GB'), v('en-US')], ['en-US']).lang).toBe('en-US')
  })
})

describe('useSpeechSynthesis', () => {
  it('loads voices now and again on voiceschanged', () => {
    const voices = []
    const synth = installSpeech(voices)
    const s = useSpeechSynthesis()
    expect(s.supported).toBe(true)
    expect(s.voices.value).toEqual([])
    expect(synth.addEventListener).toHaveBeenCalledWith('voiceschanged', expect.any(Function))
    voices.push(v('en-NG', 'Ezinne'))
    synth.fire('voiceschanged')
    expect(s.voices.value.map((x) => x.name)).toEqual(['Ezinne'])
  })

  it('speaks sentence by sentence with markdown stripped and the preferred voice', () => {
    const synth = installSpeech([v('en-US', 'Samantha'), v('en-ZA', 'Leah')])
    const s = useSpeechSynthesis()
    const res = s.speak('**Value:** We saved 3 hours. Next, draft the [follow-up](https://x.test)! Ready?')
    expect(res.success).toBe(true)
    expect(res.chunks).toBe(3)
    expect(res.voice.name).toBe('Leah')
    expect(synth.cancel).toHaveBeenCalledTimes(1) // clears anything already queued
    expect(synth.speak).toHaveBeenCalledTimes(3)
    expect(synth.spoken.map((u) => u.text)).toEqual(['We saved 3 hours.', 'Next, draft the follow-up!', 'Ready?'])
    expect(synth.spoken.every((u) => u.voice.name === 'Leah' && u.lang === 'en-ZA')).toBe(true)
    expect(s.speaking.value).toBe(true)
  })

  it('fires onEnd after the last sentence only', () => {
    const synth = installSpeech([v('en-ZA')])
    const s = useSpeechSynthesis()
    const onEnd = vi.fn()
    s.speak('One. Two.', { onEnd })
    expect(synth.spoken[0].onend).toBeUndefined()
    synth.spoken[1].onend()
    expect(onEnd).toHaveBeenCalledTimes(1)
    expect(s.speaking.value).toBe(false)
  })

  it('cancel() stops speech and silences callbacks from the cancelled run', () => {
    const synth = installSpeech([v('en-ZA')])
    const s = useSpeechSynthesis()
    const onEnd = vi.fn()
    const onError = vi.fn()
    s.speak('First. Second.', { onEnd, onError })
    const [, last] = synth.spoken
    s.cancel()
    expect(synth.cancel).toHaveBeenCalledTimes(2)
    expect(s.speaking.value).toBe(false)
    last.onend()
    last.onerror({ error: 'synthesis-failed' })
    expect(onEnd).not.toHaveBeenCalled()
    expect(onError).not.toHaveBeenCalled()
  })

  it('ignores interrupted / canceled errors but reports real ones', () => {
    const synth = installSpeech([v('en-ZA')])
    const s = useSpeechSynthesis()
    const onError = vi.fn()
    s.speak('Hello there.', { onError })
    synth.spoken[0].onerror({ error: 'interrupted' })
    synth.spoken[0].onerror({ error: 'canceled' })
    expect(onError).not.toHaveBeenCalled()
    synth.spoken[0].onerror({ error: 'not-allowed' })
    expect(onError).toHaveBeenCalledWith({ error: 'not-allowed' })
    expect(s.speaking.value).toBe(false)
  })

  it('refuses empty text and unsupported browsers', () => {
    installSpeech([])
    expect(useSpeechSynthesis().speak('```code only```').success).toBe(false)

    removeSpeech()
    const s = useSpeechSynthesis()
    expect(s.supported).toBe(false)
    expect(s.speak('Hello.')).toEqual({ success: false, error: 'This browser cannot speak.' })
    expect(() => s.cancel()).not.toThrow()
    expect(s.loadVoices()).toEqual([])
  })
})

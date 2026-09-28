// useAudioPlayer composable — Vitest unit tests.
// One persistent <audio> shared by every caller, blob → object URL path,
// play / ended / stop lifecycle and events, autoplay blocking, the silent
// unlock clip, and graceful failure without Audio support.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { useAudioPlayer, resetAudioPlayer, SILENT_WAV } from '../src/composables/useAudioPlayer.js'
import { installAudio, restoreAudio, notAllowed, FakeAudio } from './helpers/audio.js'

const blob = () => new Blob(['ID3'], { type: 'audio/mpeg' })

beforeEach(() => {
  installAudio()
  resetAudioPlayer()
})

afterEach(() => {
  resetAudioPlayer()
  restoreAudio()
})

describe('useAudioPlayer', () => {
  it('plays a blob through an object URL on one persistent element', async () => {
    const a = useAudioPlayer()
    const b = useAudioPlayer()
    const onPlay = vi.fn()
    a.on('play', onPlay)

    const res = await a.playBlob(blob(), 'msg_1')
    expect(res).toEqual({ success: true })
    expect(URL.createObjectURL).toHaveBeenCalledTimes(1)
    expect(FakeAudio.instances).toHaveLength(1)
    const el = FakeAudio.instances[0]
    expect(el.src).toBe('blob:fake-1')
    expect(el.plays).toHaveLength(1)
    // Shared state across callers.
    expect(b.playing.value).toBe(true)
    expect(b.currentId.value).toBe('msg_1')
    expect(onPlay).toHaveBeenCalledWith({ id: 'msg_1' })

    await b.playBlob(blob(), 'msg_2')
    expect(FakeAudio.instances).toHaveLength(1)
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:fake-1')
    expect(el.src).toBe('blob:fake-2')
    expect(a.currentId.value).toBe('msg_2')
  })

  it('clears state, revokes the URL and emits `ended` when the clip finishes', async () => {
    const player = useAudioPlayer()
    const ended = vi.fn()
    const off = player.on('ended', ended)
    await player.playBlob(blob(), 'msg_9')
    FakeAudio.instances[0].dispatch('ended')
    expect(player.playing.value).toBe(false)
    expect(player.currentId.value).toBeNull()
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:fake-1')
    expect(ended).toHaveBeenCalledWith({ id: 'msg_9' })

    off()
    await player.playBlob(blob(), 'msg_10')
    FakeAudio.instances[0].dispatch('ended')
    expect(ended).toHaveBeenCalledTimes(1)
  })

  it('stop() pauses, rewinds and emits `stop` only when something was playing', async () => {
    const player = useAudioPlayer()
    const onStop = vi.fn()
    player.on('stop', onStop)
    player.stop()
    expect(onStop).not.toHaveBeenCalled()

    await player.playBlob(blob(), 'msg_3')
    const el = FakeAudio.instances[0]
    el.currentTime = 12
    player.stop()
    expect(el.paused).toBe(true)
    expect(el.currentTime).toBe(0)
    expect(player.playing.value).toBe(false)
    expect(onStop).toHaveBeenCalledWith({ id: 'msg_3' })
  })

  it('reports autoplay blocking instead of throwing', async () => {
    FakeAudio.playImpl = () => Promise.reject(notAllowed())
    const player = useAudioPlayer()
    const res = await player.playBlob(blob(), 'msg_4')
    expect(res.success).toBe(false)
    expect(res.blocked).toBe(true)
    expect(res.error).toContain('tap to hear')
    expect(player.playing.value).toBe(false)
    expect(player.currentId.value).toBeNull()

    FakeAudio.playImpl = () => Promise.reject(new Error('decode failed'))
    const broken = await player.playUrl('/clip.mp3', 'msg_5')
    expect(broken).toEqual({ success: false, blocked: false, error: 'decode failed' })
  })

  it('emits `error` for a failing clip but ignores errors while idle', async () => {
    const player = useAudioPlayer()
    const onError = vi.fn()
    player.on('error', onError)
    await player.playBlob(blob(), 'msg_6')
    const el = FakeAudio.instances[0]
    el.dispatch('error')
    expect(onError).toHaveBeenCalledWith({ id: 'msg_6', error: 'The audio could not be played.' })
    el.dispatch('error')
    expect(onError).toHaveBeenCalledTimes(1)
  })

  it('unlock() plays a muted silent clip once, from the gesture', async () => {
    const player = useAudioPlayer()
    expect(player.unlocked).toBe(false)
    const res = await player.unlock()
    expect(res).toEqual({ success: true })
    const el = FakeAudio.instances[0]
    expect(el.plays).toEqual([{ src: SILENT_WAV, muted: true }])
    expect(el.muted).toBe(false)
    expect(player.unlocked).toBe(true)
    await player.unlock()
    expect(el.plays).toHaveLength(1)
  })

  it('unlock() reports a blocked gesture', async () => {
    FakeAudio.playImpl = () => Promise.reject(notAllowed())
    const res = await useAudioPlayer().unlock()
    expect(res.success).toBe(false)
    expect(res.blocked).toBe(true)
  })

  it('fails gracefully without Audio or object URLs', async () => {
    window.Audio = undefined
    const player = useAudioPlayer()
    expect(player.isSupported()).toBe(false)
    expect((await player.playUrl('/x.mp3', 'm')).success).toBe(false)
    expect((await player.unlock()).success).toBe(false)
    expect((await player.playBlob(null, 'm')).error).toBe('No audio to play.')

    installAudio()
    URL.createObjectURL = undefined
    const res = await useAudioPlayer().playBlob(blob(), 'm')
    expect(res.success).toBe(false)
    expect(res.error).toContain('not supported')
  })
})

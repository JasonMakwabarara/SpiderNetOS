// Atlas store — the launch turn (plan D7 §5) and the "one step further"
// thread (plan D8): `{mode, thread_id}` on the way out, `metadata.launch` +
// `metadata.brain` on the way back, and the brain store kept in step.
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('../src/services/api.js', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import api from '../src/services/api.js'
import { useAtlasStore } from '../src/stores/atlas.js'
import { useBrainStore } from '../src/stores/brain.js'
import launchFixture from './fixtures/launch.json'
import { httpError } from './helpers/harness.js'

const LAUNCH = launchFixture.data
const READINESS = launchFixture.readiness

function chatReply(overrides = {}) {
  return {
    data: {
      contract_version: '1',
      session_id: 'sess-1',
      interaction_id: 'int-1',
      message: {
        id: 'msg-1',
        role: 'atlas',
        contract: { action_summary: LAUNCH.next_question.prompt, value: null, future_state: null },
        timestamp: '2026-09-18T11:00:00Z',
        metadata: {
          intent: 'launch',
          mode: 'launch',
          agent_used: 'launch-guide',
          status: 'modelling',
          thread_id: 'thread-77',
          launch: LAUNCH,
          brain: READINESS,
          ...overrides,
        },
      },
      ast: { type: 'launch' },
    },
  }
}

describe('atlas store — launch mode', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
  })

  it('sends the mode with the message and leaves it off an ordinary chat turn', async () => {
    api.post.mockResolvedValue(chatReply())
    const store = useAtlasStore()

    await store.sendMessage('About 10,000', { mode: 'launch' })
    expect(api.post).toHaveBeenCalledWith('/api/atlas/chat', {
      message: 'About 10,000',
      session_id: null,
      mode: 'launch',
    })

    api.post.mockClear()
    api.post.mockResolvedValue({ data: { message: { id: 'm', metadata: {} } } })
    const plain = useAtlasStore()
    plain.$patch({ threadId: null, launch: null })
    await plain.sendMessage('what is my pipeline?')
    expect(api.post.mock.calls[0][1]).not.toHaveProperty('mode')
  })

  it('remembers the thread id and sends it back on the next turn', async () => {
    api.post.mockResolvedValue(chatReply())
    const store = useAtlasStore()

    await store.sendMessage('About 10,000', { mode: 'launch' })
    expect(store.threadId).toBe('thread-77')

    await store.sendMessage('2,000 for kit', { mode: 'launch' })
    expect(api.post.mock.calls[1][1]).toMatchObject({ mode: 'launch', thread_id: 'thread-77' })
  })

  it('an explicit thread_id wins over the remembered one', async () => {
    api.post.mockResolvedValue(chatReply())
    const store = useAtlasStore()
    await store.sendMessage('first', { mode: 'launch' })

    await store.sendMessage('second', { mode: 'launch', thread_id: 'thread-explicit' })
    expect(api.post.mock.calls[1][1].thread_id).toBe('thread-explicit')
  })

  it('captures metadata.launch and metadata.brain off the reply', async () => {
    api.post.mockResolvedValue(chatReply())
    const store = useAtlasStore()

    await store.sendMessage('About 10,000', { mode: 'launch' })

    expect(store.launch.status).toBe('modelling')
    expect(store.launch.stage).toBe('finance')
    expect(store.launch.next_question.id).toBe('starting_cash')
    expect(store.launch.now_filling).toBe('finance/assumptions.yaml')
    expect(store.brainReadiness.pct).toBe(55)

    const last = store.messages[store.messages.length - 1]
    expect(last.metadata.launch.progress_pct).toBe(62)
    expect(last.metadata.brain.files).toHaveLength(6)
  })

  it('hands the readiness straight to the brain store', async () => {
    api.post.mockResolvedValue(chatReply())
    const brain = useBrainStore()
    const store = useAtlasStore()

    expect(brain.readiness.pct).toBe(0)
    await store.sendMessage('About 10,000', { mode: 'launch' })

    expect(brain.readiness.pct).toBe(55)
    expect(brain.readiness.files.map((f) => f.path)).toContain('finance/assumptions.yaml')
    // The fixture is copied, never aliased into the store.
    brain.readiness.files[0].status = 'changed'
    expect(launchFixture.readiness.files[0].status).toBe('filled')
  })

  it('leaves launch state alone when a reply carries no launch metadata', async () => {
    api.post.mockResolvedValueOnce(chatReply())
    const store = useAtlasStore()
    await store.sendMessage('About 10,000', { mode: 'launch' })

    api.post.mockResolvedValueOnce({
      data: { message: { id: 'm2', metadata: { mode: 'act', status: 'ok' } } },
    })
    await store.sendMessage('something else')

    expect(store.launch.status).toBe('modelling')
    expect(store.brainReadiness.pct).toBe(55)
  })

  it('clearSession forgets the thread, the launch and the readiness', async () => {
    api.post.mockResolvedValue(chatReply())
    const store = useAtlasStore()
    await store.sendMessage('About 10,000', { mode: 'launch' })

    store.clearSession()

    expect(store.threadId).toBeNull()
    expect(store.launch).toBeNull()
    expect(store.brainReadiness).toBeNull()
  })

  it('a failed turn still surfaces the error and does not invent launch state', async () => {
    api.post.mockRejectedValueOnce(httpError(500, { message: 'Atlas is down' }))
    const store = useAtlasStore()

    const result = await store.sendMessage('About 10,000', { mode: 'launch' })

    expect(result.success).toBe(false)
    expect(store.error).toContain('Atlas is down')
    expect(store.launch).toBeNull()
  })
})

describe('brain store — applyReadiness', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
  })

  it('accepts both the bare payload and the {data} envelope', () => {
    const store = useBrainStore()

    store.applyReadiness(READINESS)
    expect(store.readiness.pct).toBe(55)

    store.applyReadiness({ data: { pct: 80, files: [{ path: 'offer/offer.md', status: 'filled' }] } })
    expect(store.readiness.pct).toBe(80)
    expect(store.readiness.files).toHaveLength(1)
  })

  it('ignores anything that is not readiness-shaped', () => {
    const store = useBrainStore()
    store.applyReadiness(READINESS)

    store.applyReadiness(null)
    store.applyReadiness({ pct: 99 })
    store.applyReadiness('nope')

    expect(store.readiness.pct).toBe(55)
  })

  it('mirrors each file status into the tree it already holds', async () => {
    api.get.mockResolvedValueOnce({
      data: { data: { folders: [{ key: 'offer', title: 'Offer', files: [{ path: 'offer/offer.md', status: 'missing', version: 0 }] }] } },
    })
    const store = useBrainStore()
    await store.fetchTree()

    store.applyReadiness({ pct: 50, files: [{ path: 'offer/offer.md', status: 'filled', version: 4 }] })

    expect(store.tree.folders[0].files[0]).toMatchObject({ status: 'filled', version: 4 })
  })
})

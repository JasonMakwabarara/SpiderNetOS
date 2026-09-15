// Systemization store — Vitest unit tests.
// Same endpoints SystemsMap.vue used inline; asserts envelope
// unwrapping, payload shapes, refresh-after-mutation and the
// { success, error } return contract.
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('../src/services/api.js', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}))

import api from '../src/services/api.js'
import { useSystemizationStore } from '../src/stores/systemization.js'

const mapPayload = {
  data: {
    data: {
      systems: [
        { id: 'sys_1', function: 'Sales', name: 'Sales system', goal: 'Close deals', processes: [
          { id: 'p_1', name: 'Follow up leads', owner_type: 'founder', has_published_sop: true },
          { id: 'p_2', name: 'Send invoices', owner_type: 'agent', flow_id: 'f_1', last_run_status: 'passed' },
        ] },
      ],
      founder_load: { total_processes: 2, founder_owned: 1, delegated: 0, automated: 1, founder_owned_pct: 50 },
    },
  },
}
const snowballPayload = { data: { data: { queue: [{ id: 'p_1', name: 'Follow up leads' }], next_action: 'Hand off p_1' } } }

function mockReads() {
  api.get.mockImplementation((url) => {
    if (url === '/api/systemization/map') return Promise.resolve(mapPayload)
    if (url === '/api/systemization/snowball') return Promise.resolve(snowballPayload)
    return Promise.reject(new Error(`unexpected GET ${url}`))
  })
}

describe('systemization store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
  })

  it('fetchMap unwraps systems + founder_load and exposes flat processes', async () => {
    mockReads()
    const store = useSystemizationStore()
    const res = await store.fetchMap()
    expect(res.success).toBe(true)
    expect(api.get).toHaveBeenCalledWith('/api/systemization/map')
    expect(store.systems).toHaveLength(1)
    expect(store.founderLoad.founder_owned_pct).toBe(50)
    expect(store.processes.map((p) => p.id)).toEqual(['p_1', 'p_2'])
    expect(store.processById.p_2.flow_id).toBe('f_1')
    expect(store.loading).toBe(false)
  })

  it('fetchSnowball stores the queue', async () => {
    mockReads()
    const store = useSystemizationStore()
    await store.fetchSnowball()
    expect(store.snowball.next_action).toBe('Hand off p_1')
    expect(store.snowball.queue).toHaveLength(1)
  })

  it('fetchMap reports the server message on failure', async () => {
    api.get.mockRejectedValueOnce({ response: { data: { message: 'nope' } } })
    const store = useSystemizationStore()
    const res = await store.fetchMap()
    expect(res).toEqual({ success: false, error: 'nope' })
    expect(store.error).toBe('nope')
    expect(store.systems).toEqual([])
  })

  it('bootstrap posts then refreshes both reads', async () => {
    mockReads()
    api.post.mockResolvedValueOnce({ data: { data: {} } })
    const store = useSystemizationStore()
    const res = await store.bootstrap()
    expect(res.success).toBe(true)
    expect(api.post).toHaveBeenCalledWith('/api/systemization/bootstrap')
    expect(api.get).toHaveBeenCalledWith('/api/systemization/map')
    expect(api.get).toHaveBeenCalledWith('/api/systemization/snowball')
    expect(store.systems).toHaveLength(1)
  })

  it('addProcess posts the trimmed name to the system and refuses blanks', async () => {
    mockReads()
    api.post.mockResolvedValueOnce({ data: { data: { id: 'p_9' } } })
    const store = useSystemizationStore()
    expect((await store.addProcess('sys_1', '   ')).success).toBe(false)
    expect(api.post).not.toHaveBeenCalled()

    const res = await store.addProcess('sys_1', '  Chase overdue  ')
    expect(res.success).toBe(true)
    expect(res.process.id).toBe('p_9')
    expect(api.post).toHaveBeenCalledWith('/api/systemization/systems/sys_1/processes', { name: 'Chase overdue' })
  })

  it('automate posts the schedule, tracks busyProcess and refreshes', async () => {
    mockReads()
    let resolvePost
    api.post.mockReturnValueOnce(new Promise((r) => { resolvePost = r }))
    const store = useSystemizationStore()
    const pending = store.automate('p_1')
    expect(store.busyProcess).toBe('p_1')
    resolvePost({ data: {} })
    const res = await pending
    expect(res.success).toBe(true)
    expect(store.busyProcess).toBeNull()
    expect(api.post).toHaveBeenCalledWith('/api/systemization/processes/p_1/automate', { schedule: 'daily_morning' })
    expect(api.get).toHaveBeenCalledTimes(2)
  })

  it('runNow posts to /run and surfaces failures', async () => {
    api.post.mockRejectedValueOnce({ response: { data: { message: 'flow disabled' } } })
    const store = useSystemizationStore()
    const res = await store.runNow('p_2')
    expect(api.post).toHaveBeenCalledWith('/api/systemization/processes/p_2/run')
    expect(res).toEqual({ success: false, error: 'flow disabled' })
    expect(store.busyProcess).toBeNull()
    expect(api.get).not.toHaveBeenCalled()
  })

  it('resolveEscalation posts the answer and refuses an empty one', async () => {
    mockReads()
    api.post.mockResolvedValueOnce({ data: {} })
    const store = useSystemizationStore()
    expect((await store.resolveEscalation('p_1', '')).success).toBe(false)
    expect(api.post).not.toHaveBeenCalled()

    const res = await store.resolveEscalation('p_1', ' Call the supplier first. ')
    expect(res.success).toBe(true)
    expect(api.post).toHaveBeenCalledWith('/api/systemization/processes/p_1/resolve-escalation', { answer: 'Call the supplier first.' })
  })

  it('falls back to a friendly message when the error has no body', async () => {
    api.post.mockRejectedValueOnce(new Error('network'))
    const store = useSystemizationStore()
    const res = await store.bootstrap()
    expect(res.success).toBe(false)
    expect(res.error).toBe('Bootstrap failed. Please retry.')
  })
})

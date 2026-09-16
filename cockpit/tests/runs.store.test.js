// Runs store — Vitest unit tests.
// List + detail envelopes with fixture fallback, answers/cancel/retry,
// artifact PATCH, workspace fetch and the realtime run handler.
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('../src/services/api.js', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import api from '../src/services/api.js'
import { useRunsStore } from '../src/stores/runs.js'
import runFixture from './fixtures/agent_run.json'
import { httpError } from './helpers/harness.js'

function mockReads() {
  api.get.mockImplementation((url) => {
    if (url === '/api/agent-runs') return Promise.resolve({ data: runFixture.list })
    if (url === '/api/agent-runs/run_blocked_01') return Promise.resolve({ data: runFixture.blocked })
    if (url === '/api/agent-runs/run_0915_cold') return Promise.resolve({ data: runFixture.succeeded })
    if (url === '/api/artifacts/art_seq_q4_s1a') return Promise.resolve({ data: runFixture.artifact })
    if (url === '/api/agent-workspaces/growth') return Promise.resolve({ data: runFixture.workspace })
    return Promise.reject(httpError(404))
  })
}

describe('runs store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
  })

  it('fetchRuns passes cleaned filters and derives counts', async () => {
    mockReads()
    const store = useRunsStore()
    await store.fetchRuns({ status: 'blocked', skill: '' })
    expect(api.get).toHaveBeenCalledWith('/api/agent-runs', { params: { status: 'blocked' } })
    expect(store.runs).toHaveLength(4)
    expect(store.filtered.map((r) => r.id)).toEqual(['run_blocked_01'])
    store.filters = { status: '', skill: '' }
    expect(store.activeCount).toBe(2)
    expect(store.blockedCount).toBe(1)
    expect(store.totalCost).toBeCloseTo(0.57)
    expect(store.skillsSeen.map((s) => s.slug)).toContain('cold-email-drafting')
  })

  it('falls back to the fixture list with an inline error', async () => {
    api.get.mockRejectedValue(httpError(502))
    const store = useRunsStore()
    await store.fetchRuns()
    expect(store.fallback).toBe(true)
    expect(store.error).toContain('HTTP 502')
    expect(store.runs.length).toBeGreaterThan(0)
  })

  it('fetchRun caches the detail and mirrors the summary into the list', async () => {
    mockReads()
    const store = useRunsStore()
    const res = await store.fetchRun('run_blocked_01')
    expect(res.success).toBe(true)
    expect(store.details.run_blocked_01.questions).toHaveLength(2)
    expect(store.runs.find((r) => r.id === 'run_blocked_01')).toMatchObject({ status: 'blocked' })
  })

  it('fetchRun falls back to the matching fixture variant', async () => {
    api.get.mockRejectedValue(httpError(404))
    const store = useRunsStore()
    await store.fetchRun('run_0915_cold')
    expect(store.detailError).toContain('run_0915_cold')
    expect(store.details.run_0915_cold.status).toBe('succeeded')
    await store.fetchRun('run_blocked_01')
    expect(store.details.run_blocked_01.status).toBe('blocked')
    await store.fetchRun('run_unknown')
    expect(store.details.run_unknown.id).toBe('run_unknown')
  })

  it('answer posts {answers} and moves the run off blocked', async () => {
    mockReads()
    const store = useRunsStore()
    await store.fetchRun('run_blocked_01')
    api.post.mockResolvedValueOnce({ data: { data: { id: 'run_blocked_01', status: 'queued', questions: [] } } })
    const answers = [{ path: 'offer/offer.md', section: 'differentiators', text: 'We ship in six weeks.' }]
    const res = await store.answer('run_blocked_01', answers)
    expect(res.success).toBe(true)
    expect(api.post).toHaveBeenCalledWith('/api/agent-runs/run_blocked_01/answers', { answers })
    expect(store.details.run_blocked_01.status).toBe('queued')
    expect(store.runs.find((r) => r.id === 'run_blocked_01').status).toBe('queued')
  })

  it('cancel and retry post and patch status', async () => {
    mockReads()
    const store = useRunsStore()
    await store.fetchRun('run_blocked_01')
    api.post.mockResolvedValueOnce({ data: {} })
    expect((await store.cancel('run_blocked_01')).success).toBe(true)
    expect(api.post).toHaveBeenCalledWith('/api/agent-runs/run_blocked_01/cancel')
    expect(store.details.run_blocked_01.status).toBe('cancelled')

    api.post.mockResolvedValueOnce({ data: { data: { id: 'run_retry_1', status: 'queued', skill_slug: 'cold-email-drafting' } } })
    const res = await store.retry('run_blocked_01')
    expect(res.run_id).toBe('run_retry_1')
    expect(store.runs[0].id).toBe('run_retry_1')

    api.post.mockRejectedValueOnce(httpError(500, { message: 'no' }))
    expect((await store.retry('run_blocked_01')).success).toBe(false)
  })

  it('fetchArtifact + patchArtifact use /api/artifacts/{id}', async () => {
    mockReads()
    const store = useRunsStore()
    await store.fetchArtifact('art_seq_q4_s1a')
    expect(store.artifacts.art_seq_q4_s1a.content.subject).toContain('pipeline')

    api.patch.mockResolvedValueOnce({ data: { data: { id: 'art_seq_q4_s1a', version: 2 } } })
    const content = { subject: 'New subject', body: 'New body' }
    const res = await store.patchArtifact('art_seq_q4_s1a', content)
    expect(res.success).toBe(true)
    expect(api.patch).toHaveBeenCalledWith('/api/artifacts/art_seq_q4_s1a', { content })
    expect(store.artifacts.art_seq_q4_s1a).toMatchObject({ version: 2, content })
  })

  it('fetchWorkspace unwraps and falls back per slug', async () => {
    mockReads()
    const store = useRunsStore()
    await store.fetchWorkspace('growth')
    expect(store.workspaces.growth.budget_daily_usd).toBe(12)
    await store.fetchWorkspace('richard')
    expect(store.error).toContain('richard')
    expect(store.workspaces.richard.agent.name).toBe('Richard')
  })

  it('handleRunUpdated patches list, detail and workspace recent runs', async () => {
    mockReads()
    const store = useRunsStore()
    await store.fetchRuns()
    await store.fetchRun('run_blocked_01')
    await store.fetchWorkspace('growth')

    store.handleRunUpdated({ id: 'run_blocked_01', status: 'running', cost_usd: 0.05 })
    expect(store.runs.find((r) => r.id === 'run_blocked_01')).toMatchObject({ status: 'running', cost_usd: 0.05 })
    expect(store.details.run_blocked_01.status).toBe('running')
    expect(store.workspaces.growth.recent_runs.find((r) => r.id === 'run_blocked_01').status).toBe('running')

    store.handleRunUpdated({ run_id: 'run_new', skill_slug: 'deep-research', status: 'queued' })
    expect(store.runs[0]).toMatchObject({ id: 'run_new', status: 'queued' })

    store.handleToolInvoked({ run_id: 'run_blocked_01', step: { seq: 3, type: 'tool', name: 'Write drafts', status: 'running' } })
    expect(store.details.run_blocked_01.steps).toHaveLength(3)
  })
})

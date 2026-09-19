// Board of advisors store — Vitest unit tests.
// The seats envelope, convening (a real spend, never auto-retried), the
// verdict shape, and the "off" state being distinct from a failure.
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('../src/services/api.js', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import api from '../src/services/api.js'
import { useBoardStore, STANCES, MIN_QUESTION } from '../src/stores/board.js'
import boardFixture from './fixtures/board.json'
import { httpError } from './helpers/harness.js'

describe('board store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
  })

  it('fetchSeats unwraps the seats, the chair and the disclaimer', async () => {
    api.get.mockResolvedValueOnce({ data: boardFixture.seats })
    const store = useBoardStore()

    const res = await store.fetchSeats()

    expect(res.success).toBe(true)
    expect(api.get).toHaveBeenCalledWith('/api/board/seats')
    expect(store.seats.map((s) => s.slug)).toEqual([
      'offer-architect', 'producer', 'leverage-philosopher', 'compounder', 'greenlight',
    ])
    expect(store.chair.slug).toBe('chairman')
    expect(store.disclaimer).toBe('Not legal, financial or professional advice.')
    expect(store.privateRoster).toBe(false)
    expect(store.hasBoard).toBe(true)
  })

  it('every seat says whose frameworks it reasons with, and never claims to be them', async () => {
    api.get.mockResolvedValueOnce({ data: boardFixture.seats })
    const store = useBoardStore()
    await store.fetchSeats()

    for (const seat of store.seats) {
      expect(seat.likeness_mode).not.toBe('cloned')
      expect(seat.inspired_by).toMatch(/published/)
      // The display name is the archetype, not the person.
      expect(seat.display_name).toMatch(/^The /)
    }
  })

  it('a 403 means the board is off, not that the request failed', async () => {
    api.get.mockRejectedValueOnce(httpError(403, { message: 'The board is not switched on for this workspace.' }))
    const store = useBoardStore()

    await store.fetchSeats()

    expect(store.disabled).toBe(true)
    expect(store.fallback).toBe(false)
    expect(store.seats).toEqual([])
    expect(store.error).toBe('The board is not switched on for this workspace.')
  })

  it('any other failure falls back to the fixture seats with a notice', async () => {
    api.get.mockRejectedValueOnce(httpError(500, { message: 'boom' }))
    const store = useBoardStore()

    await store.fetchSeats()

    expect(store.fallback).toBe(true)
    expect(store.disabled).toBe(false)
    expect(store.seats).toHaveLength(5)
    expect(store.error).toContain('Showing sample data')
  })

  it('convene posts the question and puts the new session at the top of the list', async () => {
    const created = {
      data: {
        data: {
          id: 'bs_new', slug: 'should-we-hire', question: 'Should we hire a second bookkeeper?',
          status: 'complete', cost_usd: 0.04,
          verdict: {
            consensus: 'Three say not yet.',
            table_rows: [{ seat: 'greenlight', display_name: 'The Greenlight', stance: 'not_yet', confidence: 0.8, one_number: 'months of runway' }],
            minority_report: 'The cash does not cover the mistake.',
            minority_seat: 'greenlight',
            recommended_action: 'Revisit after two more months of numbers.',
            next_check_date: '2026-11-01',
            kill_criteria: ['runway under four months'],
          },
        },
      },
    }
    api.post.mockResolvedValueOnce(created)
    const store = useBoardStore()

    const res = await store.convene('Should we hire a second bookkeeper?')

    expect(res.success).toBe(true)
    expect(api.post).toHaveBeenCalledWith('/api/board/sessions', { question: 'Should we hire a second bookkeeper?' })
    expect(store.sessions[0].id).toBe('bs_new')
    expect(store.verdict.minority_seat).toBe('greenlight')
    expect(store.tableRows).toHaveLength(1)
    expect(store.convening).toBe(false)
  })

  it('a chosen subset of seats is passed through; an empty one is not', async () => {
    api.post.mockResolvedValue({ data: { data: { id: 'x', question: 'q', verdict: null } } })
    const store = useBoardStore()

    await store.convene('Should we raise the price?', ['greenlight'])
    expect(api.post).toHaveBeenLastCalledWith('/api/board/sessions', { question: 'Should we raise the price?', seats: ['greenlight'] })

    await store.convene('Should we raise the price?', [])
    expect(api.post).toHaveBeenLastCalledWith('/api/board/sessions', { question: 'Should we raise the price?' })
  })

  it('a refused convene surfaces the reason and never retries by itself', async () => {
    api.post.mockRejectedValueOnce(httpError(502, { message: 'The board could not sit' }))
    const store = useBoardStore()

    const res = await store.convene('Should we raise the price of the monthly plan?')

    expect(res.success).toBe(false)
    // Five seats over two rounds is a real spend; a silent retry would double it.
    expect(api.post).toHaveBeenCalledTimes(1)
    expect(store.error).toContain('The board could not sit')
    expect(store.session).toBeNull()
  })

  it('exposes only round one takes as the pre-argument record', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: {
          id: 'bs_1', question: 'q', verdict: null,
          takes: [
            { seat: 'producer', round: 1, verdict: { stance: 'yes' } },
            { seat: 'producer', round: 2, verdict: { stance: 'no' } },
            { seat: 'greenlight', round: 1, verdict: { stance: 'not_yet' } },
          ],
        },
      },
    })
    const store = useBoardStore()

    await store.fetchSession('bs_1')

    expect(store.firstRound.map((t) => t.seat)).toEqual(['producer', 'greenlight'])
  })

  it('every stance renders, including one the backend has not heard of', () => {
    const store = useBoardStore()
    for (const key of ['yes', 'no', 'not_yet', 'depends']) {
      expect(store.stanceOf(key).label).toBeTruthy()
    }
    expect(store.stanceOf('maybe')).toEqual(STANCES.depends)
    expect(MIN_QUESTION).toBeGreaterThan(0)
  })
})

import { beforeEach, describe, expect, it } from 'vitest'
import {
  APPEARANCE_KEY,
  normalizeAppearance,
  readAppearance,
  reduceAppearance,
} from '../src/appearance.js'

const harbourDark = {
  theme: 'midnight-harbour',
  mode: 'dark',
  modes: { 'midnight-harbour': 'dark', 'copper-ledger': 'dark' },
}

describe('appearance fallback', () => {
  beforeEach(() => localStorage.clear())

  it('missing storage is Midnight Harbour dark', () => {
    expect(readAppearance()).toEqual(harbourDark)
  })

  it('corrupt storage is Midnight Harbour dark', () => {
    localStorage.setItem(APPEARANCE_KEY, '{not json')
    localStorage.setItem('spidernet.appearance.extra', 'ignored')
    expect(readAppearance()).toEqual(harbourDark)
    expect(normalizeAppearance('solarpunk')).toEqual(harbourDark)
    expect(normalizeAppearance(['solarpunk'])).toEqual(harbourDark)
    expect(normalizeAppearance({ theme: 'phosphor', mode: 'light' })).toEqual(harbourDark)
  })

  it('rejects a dark mode stored for Solarpunk', () => {
    expect(normalizeAppearance({ theme: 'solarpunk', mode: 'dark' })).toMatchObject({
      theme: 'solarpunk',
      mode: 'sunlit',
    })
  })
})

describe('remembered mode', () => {
  it('Harbour dark → Solarpunk → Harbour restores the Harbour mode', () => {
    let state = normalizeAppearance(null)
    state = reduceAppearance(state, { mode: 'light' })
    expect(state).toMatchObject({ theme: 'midnight-harbour', mode: 'light' })
    state = reduceAppearance(state, { theme: 'solarpunk' })
    expect(state.mode).toBe('sunlit')
    expect(state.modes['midnight-harbour']).toBe('light')
    state = reduceAppearance(state, { theme: 'midnight-harbour' })
    expect(state).toMatchObject({ theme: 'midnight-harbour', mode: 'light' })
  })

  it('Ledger light → Solarpunk → Ledger restores Ledger light', () => {
    let state = reduceAppearance(normalizeAppearance(null), { theme: 'copper-ledger' })
    state = reduceAppearance(state, { mode: 'light' })
    expect(state).toMatchObject({ theme: 'copper-ledger', mode: 'light' })
    state = reduceAppearance(state, { theme: 'solarpunk' })
    expect(state.mode).toBe('sunlit')
    state = reduceAppearance(state, { mode: 'dark' })
    expect(state.mode).toBe('sunlit')
    state = reduceAppearance(state, { theme: 'copper-ledger' })
    expect(state).toMatchObject({ theme: 'copper-ledger', mode: 'light' })
  })
})

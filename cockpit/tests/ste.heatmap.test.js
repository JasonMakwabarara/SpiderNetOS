/**
 * STE component smoke tests (plan §12.9).
 *
 * Lightweight import + shape assertions that don't require @vue/test-utils.
 * Full DOM-level tests will land once we add @vue/test-utils to devDeps.
 */

import { describe, it, expect } from 'vitest'
import MatrixHeatmap    from '../src/components/ste/MatrixHeatmap.vue'
import WinningTagsList  from '../src/components/ste/WinningTagsList.vue'
import DropoffBar       from '../src/components/ste/DropoffBar.vue'
import SimulationPanel  from '../src/components/ste/SimulationPanel.vue'

describe('STE components are importable', () => {
  it('MatrixHeatmap SFC is a Vue component object', () => {
    expect(MatrixHeatmap).toBeTruthy()
    expect(typeof MatrixHeatmap).toBe('object')
  })

  it('WinningTagsList SFC is a Vue component object', () => {
    expect(WinningTagsList).toBeTruthy()
    expect(typeof WinningTagsList).toBe('object')
  })

  it('DropoffBar SFC is a Vue component object', () => {
    expect(DropoffBar).toBeTruthy()
  })

  it('SimulationPanel SFC is a Vue component object', () => {
    expect(SimulationPanel).toBeTruthy()
  })
})

describe('MatrixHeatmap contract', () => {
  it('declares chain + matrix props', () => {
    const keys = Object.keys(MatrixHeatmap.__props || MatrixHeatmap.props || {})
    expect(keys).toContain('chain')
    expect(keys).toContain('matrix')
  })
})

describe('WinningTagsList contract', () => {
  it('declares tags array prop', () => {
    const keys = Object.keys(WinningTagsList.__props || WinningTagsList.props || {})
    expect(keys).toContain('tags')
  })
})

describe('DropoffBar contract', () => {
  it('declares dropoffs object prop', () => {
    const keys = Object.keys(DropoffBar.__props || DropoffBar.props || {})
    expect(keys).toContain('dropoffs')
  })
})

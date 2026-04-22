/**
 * EnhancePromptButton — smoke tests.
 *
 * Lightweight (no @vue/test-utils dependency): asserts that the SFC
 * declares the expected props and that useEnhancePrompt exposes the
 * documented API shape.
 */

import { describe, it, expect, vi } from 'vitest'
import EnhancePromptButton from '../src/components/prompt/EnhancePromptButton.vue'

vi.mock('axios', () => ({
  default: {
    post: vi.fn().mockResolvedValue({
      data: {
        original: 'raw',
        enhanced: 'enhanced output',
        mode:     'deterministic',
        surface:  'generic',
        notes:    [],
        latency_ms: 5,
      },
    }),
  },
}))

describe('EnhancePromptButton', () => {
  it('is a Vue SFC', () => {
    expect(EnhancePromptButton).toBeTruthy()
    expect(typeof EnhancePromptButton).toBe('object')
  })

  it('declares modelValue/surface/mode/applyMode/variant props', () => {
    const keys = Object.keys(EnhancePromptButton.__props || EnhancePromptButton.props || {})
    for (const p of ['modelValue', 'surface', 'mode', 'applyMode', 'variant', 'disabled', 'audience', 'label']) {
      expect(keys).toContain(p)
    }
  })

  it('emits update:modelValue / enhanced / error', () => {
    const emits = EnhancePromptButton.__emits || EnhancePromptButton.emits || []
    for (const e of ['update:modelValue', 'enhanced', 'error']) {
      expect(emits).toContain(e)
    }
  })
})

describe('useEnhancePrompt composable', () => {
  it('returns the documented shape', async () => {
    const { useEnhancePrompt } = await import('../src/composables/useEnhancePrompt.js')
    const h = useEnhancePrompt()
    expect(typeof h.enhance).toBe('function')
    expect(h.loading).toBeTruthy()
    expect('error'      in h).toBe(true)
    expect('lastResult' in h).toBe(true)
  })

  it('short-circuits on empty prompt', async () => {
    const { useEnhancePrompt } = await import('../src/composables/useEnhancePrompt.js')
    const { enhance } = useEnhancePrompt()
    const result = await enhance({ prompt: '   ' })
    expect(result.ok).toBe(false)
    expect(result.error.code).toBe('empty_prompt')
  })

  it('calls axios.post on a non-empty prompt and returns ok', async () => {
    const { useEnhancePrompt } = await import('../src/composables/useEnhancePrompt.js')
    const { enhance } = useEnhancePrompt()
    const result = await enhance({ prompt: 'hello', surface: 'atlas_chat' })
    expect(result.ok).toBe(true)
    expect(result.data.enhanced).toBe('enhanced output')
  })
})

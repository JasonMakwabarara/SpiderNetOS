// usePanZoom composable — Vitest unit tests.
// fit(), clamped zoom (0.5–2.5), viewBox maths, panning by drag / panBy,
// wheel zoom, node clicks not starting a drag, container aspect via a
// (guarded) ResizeObserver, and reduced-motion awareness.
import { describe, it, expect, afterEach, vi } from 'vitest'
import { nextTick } from 'vue'
import { usePanZoom, clampZoom, prefersReducedMotion, MIN_ZOOM, MAX_ZOOM } from '../src/composables/usePanZoom.js'

const parse = (vb) => vb.split(' ').map(Number)
const BOUNDS = { minX: -500, minY: -300, maxX: 500, maxY: 300, width: 1000, height: 600, cx: 0, cy: 0 }

const originalRO = globalThis.ResizeObserver
const originalMatchMedia = window.matchMedia

afterEach(() => {
  globalThis.ResizeObserver = originalRO
  window.matchMedia = originalMatchMedia
})

describe('usePanZoom', () => {
  it('fit(bounds) frames the bounds exactly at zoom 1', () => {
    const pz = usePanZoom()
    pz.fit(BOUNDS)
    expect(pz.zoom.value).toBe(1)
    expect(pz.center.value).toEqual({ x: 0, y: 0 })
    expect(parse(pz.viewBox.value)).toEqual([-500, -300, 1000, 600])
  })

  it('derives the centre from min/max when cx/cy are missing', () => {
    const pz = usePanZoom()
    pz.fit({ minX: 100, minY: 50, maxX: 300, maxY: 250 })
    expect(pz.center.value).toEqual({ x: 200, y: 150 })
    expect(parse(pz.viewBox.value)).toEqual([100, 50, 200, 200])
  })

  it('zooms in and out around the centre, clamped to 0.5–2.5', () => {
    const pz = usePanZoom()
    pz.fit(BOUNDS)
    pz.zoomIn()
    expect(pz.zoom.value).toBeCloseTo(1.25)
    expect(parse(pz.viewBox.value)).toEqual([-400, -240, 800, 480])
    for (let i = 0; i < 20; i++) pz.zoomIn()
    expect(pz.zoom.value).toBe(MAX_ZOOM)
    for (let i = 0; i < 40; i++) pz.zoomOut()
    expect(pz.zoom.value).toBe(MIN_ZOOM)
    expect(parse(pz.viewBox.value)).toEqual([-1000, -600, 2000, 1200])
    expect(pz.setZoom(9)).toBe(2.5)
    expect(clampZoom('nope')).toBe(1)
    expect(clampZoom(0.1)).toBe(0.5)
  })

  it('pans by a screen delta scaled to world units', () => {
    const pz = usePanZoom()
    pz.fit(BOUNDS)
    pz.setZoom(2) // no container size → 1 screen px = 1/zoom world units
    pz.panBy(100, -40)
    expect(pz.center.value).toEqual({ x: -50, y: 20 })
    pz.centerOn(12, 34)
    expect(pz.center.value).toEqual({ x: 12, y: 34 })
  })

  it('drags from the background but not from a map node', () => {
    const pz = usePanZoom()
    pz.fit(BOUNDS)
    const background = { closest: () => null }
    const node = { closest: (sel) => (sel === '[data-map-node]' ? {} : null) }

    pz.onPointerDown({ button: 0, clientX: 10, clientY: 10, target: node })
    expect(pz.dragging.value).toBe(false)

    pz.onPointerDown({ button: 0, clientX: 10, clientY: 10, target: background, pointerId: 1, currentTarget: { setPointerCapture: vi.fn() } })
    expect(pz.dragging.value).toBe(true)
    expect(pz.transition.value).toBe('none')
    pz.onPointerMove({ clientX: 30, clientY: 0 })
    expect(pz.center.value).toEqual({ x: -20, y: 10 })
    pz.onPointerUp({ currentTarget: { releasePointerCapture: vi.fn() } })
    expect(pz.dragging.value).toBe(false)
    pz.onPointerMove({ clientX: 500, clientY: 500 })
    expect(pz.center.value).toEqual({ x: -20, y: 10 })

    pz.onPointerDown({ button: 2, clientX: 0, clientY: 0, target: background })
    expect(pz.dragging.value).toBe(false)
  })

  it('wheel zooms in on scroll up, out on scroll down, and prevents page scroll', () => {
    const pz = usePanZoom()
    pz.fit(BOUNDS)
    const up = { deltaY: -100, preventDefault: vi.fn() }
    pz.onWheel(up)
    expect(up.preventDefault).toHaveBeenCalled()
    expect(pz.zoom.value).toBeCloseTo(1.1)
    pz.onWheel({ deltaY: 100, preventDefault: vi.fn() })
    pz.onWheel({ deltaY: 100, preventDefault: vi.fn() })
    expect(pz.zoom.value).toBeLessThan(1)
    pz.onWheel({ deltaY: 0, preventDefault: vi.fn() })
  })

  it('matches the container aspect ratio from a ResizeObserver, and survives without one', async () => {
    let callback
    const observe = vi.fn()
    const disconnect = vi.fn()
    globalThis.ResizeObserver = class { constructor(cb) { callback = cb } observe(el) { observe(el) } disconnect() { disconnect() } }

    const pz = usePanZoom()
    pz.fit(BOUNDS) // 1000 × 600
    const el = { getBoundingClientRect: () => ({ width: 0, height: 0 }) }
    pz.target.value = el
    await nextTick()
    await nextTick()
    expect(observe).toHaveBeenCalledWith(el)
    callback([{ contentRect: { width: 500, height: 500 } }])
    expect(pz.size.value).toEqual({ width: 500, height: 500 })
    // Square container: the 1000 × 600 fit grows vertically, never crops.
    expect(parse(pz.viewBox.value)).toEqual([-500, -500, 1000, 1000])
    expect(pz.scale.value).toBe(2)
    pz.dispose()
    expect(disconnect).toHaveBeenCalled()

    delete globalThis.ResizeObserver
    const bare = usePanZoom()
    bare.fit(BOUNDS)
    expect(() => bare.observe({ getBoundingClientRect: () => ({ width: 300, height: 100 }) })).not.toThrow()
    expect(bare.size.value).toEqual({ width: 300, height: 100 })
  })

  it('is reduced-motion aware', () => {
    window.matchMedia = vi.fn(() => ({ matches: true, addEventListener: vi.fn(), removeEventListener: vi.fn() }))
    expect(prefersReducedMotion()).toBe(true)
    const pz = usePanZoom()
    expect(pz.reducedMotion.value).toBe(true)
    expect(pz.transition.value).toBe('none')

    window.matchMedia = vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }))
    const motion = usePanZoom()
    expect(motion.transition.value).toContain('ms')

    window.matchMedia = undefined
    expect(prefersReducedMotion()).toBe(false)
    expect(() => usePanZoom()).not.toThrow()
  })
})

/**
 * usePanZoom — viewBox-based pan & zoom for an <svg> (plan D6-C).
 *
 * The SVG keeps its world co-ordinates; only the viewBox changes, so nodes
 * stay crisp and hit-testing stays in world space. Zoom is clamped to
 * [minZoom, maxZoom] (0.5–2.5 by default) relative to the last `fit()`.
 *
 *   const pz = usePanZoom()
 *   <svg ref="pz.target" :viewBox="pz.viewBox.value" @wheel="pz.onWheel"
 *        @pointerdown="pz.onPointerDown" … >
 *   pz.fit(layout.bounds)
 *
 * Environment guards: ResizeObserver and matchMedia are optional (happy-dom
 * and older browsers) — without them the aspect ratio simply stays at the
 * last fitted bounds and motion is assumed allowed.
 */
import { computed, getCurrentInstance, onBeforeUnmount, ref, watch } from 'vue'

export const MIN_ZOOM = 0.5
export const MAX_ZOOM = 2.5
export const ZOOM_STEP = 1.25

const round2 = (n) => Math.round(n * 100) / 100

export function clampZoom(z, min = MIN_ZOOM, max = MAX_ZOOM) {
  const n = Number(z)
  if (!Number.isFinite(n)) return 1
  return Math.min(max, Math.max(min, n))
}

export function prefersReducedMotion() {
  try {
    return typeof window !== 'undefined'
      && typeof window.matchMedia === 'function'
      && !!window.matchMedia('(prefers-reduced-motion: reduce)')?.matches
  } catch {
    return false
  }
}

export function usePanZoom(options = {}) {
  const minZoom = options.minZoom ?? MIN_ZOOM
  const maxZoom = options.maxZoom ?? MAX_ZOOM
  const step = options.step ?? ZOOM_STEP

  /** The element whose size drives the viewBox aspect (usually the <svg>). */
  const target = ref(null)

  // The fitted world rectangle — zoom 1 shows exactly this.
  const base = ref({ width: options.width ?? 1000, height: options.height ?? 1000 })
  const center = ref({ x: 0, y: 0 })
  const zoom = ref(1)
  const size = ref({ width: 0, height: 0 })
  const dragging = ref(false)
  const reducedMotion = ref(prefersReducedMotion())

  // Stretch the fitted rectangle to the container's aspect ratio so fit()
  // never distorts and never crops.
  const frame = computed(() => {
    let { width, height } = base.value
    const cw = size.value.width
    const ch = size.value.height
    if (cw > 0 && ch > 0) {
      const aspect = cw / ch
      if (width / height > aspect) height = width / aspect
      else width = height * aspect
    }
    return { width, height }
  })

  const viewBox = computed(() => {
    const w = frame.value.width / zoom.value
    const h = frame.value.height / zoom.value
    return [center.value.x - w / 2, center.value.y - h / 2, w, h].map(round2).join(' ')
  })

  /** World units per screen pixel at the current zoom. */
  const scale = computed(() => {
    const cw = size.value.width
    return cw > 0 ? frame.value.width / zoom.value / cw : 1 / zoom.value
  })

  /** CSS transition for the viewBox-driven layer; empty when motion is reduced. */
  const transition = computed(() => (reducedMotion.value || dragging.value ? 'none' : 'all 160ms ease'))

  function setZoom(z) {
    zoom.value = clampZoom(z, minZoom, maxZoom)
    return zoom.value
  }

  function zoomIn() { return setZoom(zoom.value * step) }
  function zoomOut() { return setZoom(zoom.value / step) }

  function fit(bounds) {
    if (!bounds) return
    const width = Math.max(1, Number(bounds.width) || (Number(bounds.maxX) - Number(bounds.minX)) || 1)
    const height = Math.max(1, Number(bounds.height) || (Number(bounds.maxY) - Number(bounds.minY)) || 1)
    const cx = Number.isFinite(bounds.cx) ? bounds.cx : (Number(bounds.minX) || 0) + width / 2
    const cy = Number.isFinite(bounds.cy) ? bounds.cy : (Number(bounds.minY) || 0) + height / 2
    base.value = { width, height }
    center.value = { x: cx, y: cy }
    zoom.value = 1
  }

  /** Pan by a screen-pixel delta (drag / arrow keys). */
  function panBy(dx, dy) {
    center.value = {
      x: round2(center.value.x - dx * scale.value),
      y: round2(center.value.y - dy * scale.value),
    }
  }

  /** Centre the view on a world point without changing zoom. */
  function centerOn(x, y) {
    center.value = { x: Number(x) || 0, y: Number(y) || 0 }
  }

  // ── Pointer + wheel handlers ─────────────────────────────────────────
  let last = null
  let pointerId = null

  function onPointerDown(event) {
    if (event.button != null && event.button !== 0) return
    // Clicks on nodes must still select; only drag from the background.
    if (event.target?.closest?.('[data-map-node]')) return
    last = { x: event.clientX, y: event.clientY }
    pointerId = event.pointerId ?? null
    dragging.value = true
    try { if (pointerId != null) event.currentTarget?.setPointerCapture?.(pointerId) } catch { /* noop */ }
  }

  function onPointerMove(event) {
    if (!dragging.value || !last) return
    panBy(event.clientX - last.x, event.clientY - last.y)
    last = { x: event.clientX, y: event.clientY }
  }

  function onPointerUp(event) {
    if (!dragging.value) return
    dragging.value = false
    last = null
    try { if (pointerId != null) event?.currentTarget?.releasePointerCapture?.(pointerId) } catch { /* noop */ }
    pointerId = null
  }

  function onWheel(event) {
    event.preventDefault?.()
    const dy = Number(event.deltaY) || 0
    if (!dy) return
    setZoom(dy < 0 ? zoom.value * 1.1 : zoom.value / 1.1)
  }

  // ── Environment observers (guarded) ─────────────────────────────────
  let observer = null
  let motionQuery = null
  const onMotionChange = (e) => { reducedMotion.value = !!e.matches }

  function measure(el) {
    const rect = el?.getBoundingClientRect?.()
    if (rect && rect.width > 0 && rect.height > 0) size.value = { width: rect.width, height: rect.height }
  }

  function observe(el) {
    disconnect()
    if (!el) return
    measure(el)
    if (typeof ResizeObserver === 'function') {
      try {
        observer = new ResizeObserver((entries) => {
          const box = entries?.[0]?.contentRect
          if (box && box.width > 0 && box.height > 0) size.value = { width: box.width, height: box.height }
        })
        observer.observe(el)
      } catch {
        observer = null
      }
    }
  }

  function disconnect() {
    try { observer?.disconnect?.() } catch { /* noop */ }
    observer = null
  }

  watch(target, (el) => observe(el), { flush: 'post' })

  try {
    if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
      motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)')
      motionQuery?.addEventListener?.('change', onMotionChange)
    }
  } catch {
    motionQuery = null
  }

  function dispose() {
    disconnect()
    try { motionQuery?.removeEventListener?.('change', onMotionChange) } catch { /* noop */ }
    motionQuery = null
  }

  if (getCurrentInstance()) onBeforeUnmount(dispose)

  return {
    target, zoom, center, size, dragging, reducedMotion,
    viewBox, scale, transition,
    minZoom, maxZoom,
    setZoom, zoomIn, zoomOut, fit, panBy, centerOn,
    onPointerDown, onPointerMove, onPointerUp, onWheel,
    observe, dispose,
  }
}

export default usePanZoom

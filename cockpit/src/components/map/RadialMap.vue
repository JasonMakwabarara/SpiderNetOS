<template>
  <div ref="wrapRef" class="relative w-full h-full" data-testid="radial-map-wrap">
    <p :id="instructionsId" class="sr-only" data-testid="radial-map-instructions">
      Business map. The core is your business; pillars sit around it and each pillar's nodes sit on the outer ring.
      Use the left and right arrow keys to move between siblings, up to move to the parent, down to move to the first child.
      Press Enter to open a node's details and Escape to close them. Plus and minus zoom, zero fits the whole map.
    </p>

    <svg
      ref="svgRef"
      class="block w-full h-full select-none"
      role="application"
      tabindex="0"
      :aria-label="ariaLabel"
      :aria-describedby="instructionsId"
      :aria-activedescendant="activeDomId"
      :viewBox="viewBox"
      preserveAspectRatio="xMidYMid meet"
      :style="{ touchAction: 'none', cursor: dragging ? 'grabbing' : 'grab', outline: 'none' }"
      :data-zoom="zoom"
      data-testid="radial-map"
      @keydown="onKeydown"
      @focus="onSvgFocus"
      @wheel="onWheel"
      @pointerdown="onPointerDown"
      @pointermove="onPointerMove"
      @pointerup="onPointerUp"
      @pointercancel="onPointerUp"
      @pointerleave="onPointerUp"
    >
      <!-- Edges: spokes + ribs always, builds_on only for hovered / selected -->
      <g data-testid="map-edges" aria-hidden="true">
        <line
          v-for="e in structuralEdges"
          :key="`${e.kind}-${e.from}-${e.to}`"
          :x1="pos[e.from].x"
          :y1="pos[e.from].y"
          :x2="pos[e.to].x"
          :y2="pos[e.to].y"
          :data-testid="`map-edge-${e.kind}-${e.to}`"
          :style="{
            stroke: 'var(--border)',
            strokeWidth: e.kind === 'spoke' ? 1.4 : 1,
            opacity: isDimmed(e.to) ? 0.25 : 0.9,
          }"
        />
        <path
          v-for="e in ribbonEdges"
          :key="`b-${e.from}-${e.to}`"
          :d="ribbonPath(e)"
          fill="none"
          stroke-dasharray="5 4"
          :data-testid="`map-edge-builds-${e.from}-${e.to}`"
          :style="{ stroke: 'var(--text-secondary)', strokeWidth: 1.4, opacity: 0.85 }"
        />
      </g>

      <MapPillarNode
        v-for="p in pillarNodes"
        :id="domId(p.id)"
        :key="p.id"
        :node="p"
        :focused="focusedId === p.id"
        :dimmed="visibleCountFor(p.key) === 0"
        :visible-count="visibleCountFor(p.key)"
        @select="onSelect"
        @focus="onNodeFocus"
        @hover="onHover"
      />

      <MapNode
        v-for="n in leafNodes"
        :id="domId(n.id)"
        :key="n.id"
        :node="n"
        :selected="selectedId === n.id"
        :focused="focusedId === n.id"
        :dimmed="isDimmed(n.id)"
        :owner-name="ownerName"
        :pillar-label="pillarLabel(n.pillarKey)"
        @select="onSelect"
        @focus="onNodeFocus"
        @hover="onHover"
      />

      <MapCoreNode
        v-if="coreNode"
        :id="domId(coreNode.id)"
        :node="coreNode"
        :focused="focusedId === 'core'"
        @select="onSelect"
        @focus="onNodeFocus"
      />
    </svg>
  </div>
</template>

<script setup>
/**
 * RadialMap — the SVG business map (plan D6-C). Deterministic radial
 * layout, viewBox pan/zoom, and keyboard navigation over a roving tabindex:
 *
 *   ← / →  previous / next sibling (wraps; skips filtered-out nodes)
 *   ↑      parent            ↓  first child
 *   Enter  open the focused node (select)   Esc  close
 *   + / −  zoom              0  fit
 *
 * The parent owns what "selected" means (the store + ?node=); this
 * component emits `select(id)` / `close` and exposes zoomIn / zoomOut / fit.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { radialLayout } from '../../utils/radialLayout.js'
import { usePanZoom } from '../../composables/usePanZoom.js'
import MapCoreNode from './MapCoreNode.vue'
import MapPillarNode from './MapPillarNode.vue'
import MapNode from './MapNode.vue'

const props = defineProps({
  core:       { type: Object, default: () => ({}) },
  pillars:    { type: Array, default: () => [] },
  /** ids that pass the filter/search; null = everything visible */
  visibleIds: { type: [Array, Set], default: null },
  selectedId: { type: String, default: null },
  label:      { type: String, default: '' },
})

const emit = defineEmits(['select', 'close', 'focus-change'])

const wrapRef = ref(null)
const svgRef = ref(null)
const instructionsId = `radial-map-help-${Math.random().toString(36).slice(2, 9)}`
const domPrefix = `rm-${Math.random().toString(36).slice(2, 7)}`

const {
  target, zoom, viewBox, dragging,
  zoomIn, zoomOut, fit, setZoom, centerOn,
  onPointerDown, onPointerMove, onPointerUp, onWheel,
} = usePanZoom()

// ── Layout ─────────────────────────────────────────────────────────────
const layout = computed(() => radialLayout({ core: props.core, pillars: props.pillars }))
const byId = computed(() => Object.fromEntries(layout.value.nodes.map((n) => [n.id, n])))
const pos = byId
const coreNode = computed(() => byId.value.core || null)
const pillarNodes = computed(() => layout.value.nodes.filter((n) => n.kind === 'pillar'))
const leafNodes = computed(() => layout.value.nodes.filter((n) => n.kind === 'node'))
const childrenOf = computed(() => {
  const map = {}
  for (const n of layout.value.nodes) {
    if (!n.parent) continue
    if (!map[n.parent]) map[n.parent] = []
    map[n.parent].push(n.id)
  }
  return map
})

const visibleSet = computed(() => {
  if (props.visibleIds == null) return null
  return props.visibleIds instanceof Set ? props.visibleIds : new Set(props.visibleIds.map(String))
})

function isDimmed(id) {
  const node = byId.value[id]
  if (!node || node.kind !== 'node' || !visibleSet.value) return false
  return !visibleSet.value.has(String(id))
}

function visibleCountFor(key) {
  const kids = childrenOf.value[`pillar:${key}`] || []
  return kids.filter((id) => !isDimmed(id)).length
}

function pillarLabel(key) {
  return byId.value[`pillar:${key}`]?.fullLabel || ''
}

const ownerName = computed(() => props.core?.owner?.name || '')

const ariaLabel = computed(() => props.label || `Business map for ${props.core?.name || 'your business'}`)

// ── Edges ──────────────────────────────────────────────────────────────
const hoveredId = ref(null)
const structuralEdges = computed(() =>
  layout.value.edges.filter((e) => e.kind !== 'builds_on' && pos.value[e.from] && pos.value[e.to]),
)
const ribbonEdges = computed(() => {
  const active = new Set([hoveredId.value, props.selectedId].filter(Boolean))
  if (!active.size) return []
  return layout.value.edges.filter((e) => e.kind === 'builds_on' && (active.has(e.from) || active.has(e.to)))
})

/** A quadratic curve bowed toward the core so ribbons don't cut through the ring. */
function ribbonPath(e) {
  const a = pos.value[e.from]
  const b = pos.value[e.to]
  if (!a || !b) return ''
  const cx = Math.round(((a.x + b.x) / 2) * 55) / 100
  const cy = Math.round(((a.y + b.y) / 2) * 55) / 100
  return `M ${a.x} ${a.y} Q ${cx} ${cy} ${b.x} ${b.y}`
}

// ── Focus + selection ──────────────────────────────────────────────────
const focusedId = ref('core')
const activeDomId = computed(() => (byId.value[focusedId.value] ? domId(focusedId.value) : undefined))

function domId(id) {
  return `${domPrefix}-${String(id).replace(/[^a-zA-Z0-9_-]/g, '_')}`
}

function cssEscape(value) {
  return String(value).replace(/["\\]/g, '\\$&')
}

function setFocus(id, { moveDom = true } = {}) {
  if (!byId.value[id]) return
  focusedId.value = id
  emit('focus-change', id)
  if (!moveDom) return
  nextTick(() => {
    const el = svgRef.value?.querySelector?.(`[data-node-id="${cssEscape(id)}"]`)
    try { el?.focus?.({ preventScroll: true }) } catch { /* SVG focus unsupported */ }
  })
}

function onSelect(node) {
  setFocus(node.id, { moveDom: false })
  if (node.kind === 'node') emit('select', node.id)
}

function onNodeFocus(node) {
  focusedId.value = node.id
}

function onHover(node) {
  hoveredId.value = node?.kind === 'node' ? node.id : null
}

function onSvgFocus(event) {
  // Tabbing onto the map lands on the application; resume from the selected
  // node when there is one so arrows continue from where the user was.
  if (event.target !== svgRef.value) return
  if (props.selectedId && byId.value[props.selectedId]) focusedId.value = props.selectedId
}

function siblingsOf(id) {
  const node = byId.value[id]
  if (!node?.parent) return [id]
  const list = childrenOf.value[node.parent] || []
  if (node.kind !== 'node') return list
  const visible = list.filter((sid) => !isDimmed(sid))
  return visible.includes(id) ? visible : list
}

function move(delta) {
  const list = siblingsOf(focusedId.value)
  if (list.length <= 1) return
  const i = list.indexOf(focusedId.value)
  setFocus(list[(i + delta + list.length) % list.length])
}

function firstChild(id) {
  const kids = childrenOf.value[id] || []
  return kids.find((k) => !isDimmed(k)) || null
}

function onKeydown(event) {
  const id = focusedId.value
  const node = byId.value[id]
  switch (event.key) {
    case 'ArrowRight':
      event.preventDefault()
      move(1)
      break
    case 'ArrowLeft':
      event.preventDefault()
      move(-1)
      break
    case 'ArrowUp':
      event.preventDefault()
      if (node?.parent) setFocus(node.parent)
      break
    case 'ArrowDown': {
      event.preventDefault()
      const child = firstChild(id)
      if (child) setFocus(child)
      break
    }
    case 'Enter':
    case ' ': {
      event.preventDefault()
      if (node?.kind === 'node') {
        emit('select', id)
      } else {
        const child = firstChild(id)
        if (child) setFocus(child)
      }
      break
    }
    case 'Escape':
      event.preventDefault()
      emit('close')
      break
    case 'Home':
      event.preventDefault()
      setFocus('core')
      break
    case '+':
    case '=':
      event.preventDefault()
      zoomIn()
      break
    case '-':
    case '_':
      event.preventDefault()
      zoomOut()
      break
    case '0':
      event.preventDefault()
      fitAll()
      break
    default:
      break
  }
}

function fitAll() {
  fit(layout.value.bounds)
}

// Fit whenever the geometry changes (first data, new pillars, new nodes).
const boundsKey = computed(() => {
  const b = layout.value.bounds
  return `${b.minX}|${b.minY}|${b.maxX}|${b.maxY}`
})
watch(boundsKey, () => fitAll(), { immediate: true })

// Keep keyboard focus on the selection, and bring a selected node into view
// if the user had zoomed in somewhere else.
watch(
  () => [props.selectedId, byId.value],
  ([id]) => {
    if (!id || !byId.value[id]) return
    focusedId.value = id
    if (zoom.value > 1) centerOn(byId.value[id].x, byId.value[id].y)
  },
  { immediate: true },
)

// If the focused node disappears (data refresh), fall back to the core.
watch(byId, (map) => {
  if (!map[focusedId.value]) focusedId.value = 'core'
})

onMounted(() => {
  target.value = wrapRef.value
})

defineExpose({ zoomIn, zoomOut, fit: fitAll, setZoom, zoom, focusedId, setFocus })
</script>

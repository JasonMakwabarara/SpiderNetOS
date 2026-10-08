/**
 * radialLayout — the deterministic hub-and-spoke geometry behind the
 * business map (plan D6-C). Pure maths, no DOM: happy-dom reports zero for
 * every measurement, so labels are truncated by character count and node
 * boxes are fixed sizes rather than measured text.
 *
 *   radialLayout({ core, pillars }, { r1, r2, nodeW, nodeH })
 *     → { nodes: [{ id, kind, x, y, angle, parent, … }],
 *         edges: [{ from, to, kind: 'spoke'|'rib'|'builds_on' }],
 *         bounds: { minX, minY, maxX, maxY, width, height, cx, cy },
 *         rings: { r1, r2 } }
 *
 * Ring 0 = the core (business name + the three brains).
 * Ring 1 = pillars, at equal angles in `order`, first one at `startAngle`.
 * Ring 2 = each pillar's nodes, fanned symmetrically inside that pillar's
 *          own sector. Node boxes lie along their spoke (a radial tree), so
 *          their tangential footprint is `nodeH`, not `nodeW` — that is what
 *          lets ~40 nodes share one ring without overlapping.
 *
 * `r1` / `r2` are minimums: the rings grow just enough that neighbouring
 * pillars and neighbouring nodes never overlap (`rings` reports the radii
 * actually used). Co-ordinates are rounded to 2dp, so the same input always
 * produces identical output.
 */

export const LAYOUT_DEFAULTS = {
  r1: 250,          // pillar ring radius (minimum)
  r2: 420,          // node ring radius (minimum)
  nodeW: 164,
  nodeH: 34,
  pillarW: 140,
  pillarH: 38,
  coreR: 80,
  startAngle: -90,  // first pillar straight up
  sectorFill: 0.86, // share of a pillar's sector its nodes may occupy
  gap: 8,           // px between neighbouring boxes
  padding: 48,
}

const round2 = (n) => Math.round(n * 100) / 100
const toRad = (deg) => (deg * Math.PI) / 180

/** Normalise degrees into [-180, 180). */
export function normalizeAngle(deg) {
  const a = (((Number(deg) % 360) + 540) % 360) - 180
  return Object.is(a, -0) ? 0 : a
}

/**
 * Rotation for a box lying along its spoke, flipped on the left half so
 * text never reads upside down.
 */
export function readableRotation(angle) {
  const a = normalizeAngle(angle)
  return round2(a > 90 || a < -90 ? normalizeAngle(a + 180) : a)
}

/** Characters that fit in `width` px at the map's 11px label size. */
export function labelCapacity(width, { charWidth = 6.4, min = 6, gutter = 30 } = {}) {
  const usable = Math.max(0, Number(width) || 0) - gutter
  return Math.max(min, Math.floor(usable / charWidth))
}

/** Character-count truncation — never measures the DOM. */
export function truncateLabel(label, maxChars) {
  const text = String(label ?? '')
  const max = Math.max(1, Math.floor(Number(maxChars) || 0))
  if (text.length <= max) return text
  if (max <= 1) return '…'
  return `${text.slice(0, max - 1).trimEnd()}…`
}

/** Pillars sorted by `order`, stable on ties / missing orders. */
export function sortPillars(pillars) {
  return (Array.isArray(pillars) ? pillars : [])
    .map((pillar, index) => ({ pillar, index }))
    .sort((a, b) => {
      const oa = Number.isFinite(a.pillar?.order) ? a.pillar.order : a.index
      const ob = Number.isFinite(b.pillar?.order) ? b.pillar.order : b.index
      return oa - ob || a.index - b.index
    })
    .map((entry) => entry.pillar)
}

/** `count` angles centred on `centre`, `step` degrees apart. */
function fanAngles(centre, count, step) {
  if (count <= 0) return []
  const start = centre - (step * (count - 1)) / 2
  return Array.from({ length: count }, (_, i) => start + step * i)
}

/** Radii that keep every ring overlap-free for this data. */
export function resolveRings(pillars, o) {
  const n = pillars.length
  const sectorRad = n ? (2 * Math.PI) / n : 2 * Math.PI

  // Ring 1: neighbouring horizontal pillar boxes need a chord ≥ pillarW + gap.
  let r1 = Math.max(o.r1, o.coreR + o.pillarW / 2 + o.gap * 2)
  if (n >= 2) r1 = Math.max(r1, (o.pillarW + o.gap * 2) / (2 * Math.sin(sectorRad / 2)))

  // Ring 2: clear the pillar ring radially, then give every node nodeH + gap
  // of arc inside its pillar's sector.
  const maxNodes = Math.max(0, ...pillars.map((p) => (Array.isArray(p?.nodes) ? p.nodes.length : 0)))
  let r2 = Math.max(o.r2, r1 + o.pillarW / 2 + o.nodeW / 2 + o.gap * 3)
  if (maxNodes > 1) r2 = Math.max(r2, (maxNodes * (o.nodeH + o.gap)) / (sectorRad * o.sectorFill))

  return { r1: round2(r1), r2: round2(r2) }
}

export function radialLayout(input = {}, options = {}) {
  const o = { ...LAYOUT_DEFAULTS, ...(options || {}) }
  const core = input?.core || {}
  const pillars = sortPillars(input?.pillars)
  const rings = resolveRings(pillars, o)

  const nodes = []
  const edges = []
  const placed = new Set()

  const coreName = core.name || 'Your business'
  nodes.push({
    id: 'core',
    kind: 'core',
    x: 0,
    y: 0,
    angle: 0,
    rotation: 0,
    parent: null,
    r: o.coreR,
    label: truncateLabel(coreName, labelCapacity(o.coreR * 2, { gutter: 24 })),
    fullLabel: coreName,
    data: core,
  })
  placed.add('core')

  const sector = pillars.length ? 360 / pillars.length : 360
  const pillarChars = labelCapacity(o.pillarW)
  const nodeChars = labelCapacity(o.nodeW, { gutter: 52, charWidth: 5.9 })
  // Arc each node needs, in degrees, on ring 2.
  const nodeStep = ((o.nodeH + o.gap) / rings.r2) * (180 / Math.PI)

  pillars.forEach((pillar, i) => {
    const key = pillar?.key || `pillar-${i}`
    const pillarId = `pillar:${key}`
    const angle = o.startAngle + i * sector
    const rad = toRad(angle)
    const children = Array.isArray(pillar?.nodes) ? pillar.nodes : []

    nodes.push({
      id: pillarId,
      kind: 'pillar',
      key,
      x: round2(Math.cos(rad) * rings.r1),
      y: round2(Math.sin(rad) * rings.r1),
      angle: round2(normalizeAngle(angle)),
      rotation: 0,
      parent: 'core',
      w: o.pillarW,
      h: o.pillarH,
      label: truncateLabel(pillar?.label || key, pillarChars),
      fullLabel: pillar?.label || key,
      status: pillar?.status || null,
      order: Number.isFinite(pillar?.order) ? pillar.order : i,
      nodeCount: children.length,
      data: pillar,
    })
    placed.add(pillarId)
    edges.push({ from: 'core', to: pillarId, kind: 'spoke' })

    // Spread nodes evenly across the usable sector, but never tighter than
    // one node's arc (resolveRings guarantees that still fits) and never so
    // loose that a small pillar sprawls across its whole sector.
    const span = sector * o.sectorFill
    const step = children.length > 1
      ? Math.max(nodeStep, Math.min(span / (children.length - 1), nodeStep * 2.2))
      : 0
    const angles = fanAngles(angle, children.length, step)

    children.forEach((node, j) => {
      const id = node?.id != null ? String(node.id) : `${pillarId}:${j}`
      const nodeAngle = angles[j]
      const nrad = toRad(nodeAngle)
      nodes.push({
        id,
        kind: 'node',
        x: round2(Math.cos(nrad) * rings.r2),
        y: round2(Math.sin(nrad) * rings.r2),
        angle: round2(normalizeAngle(nodeAngle)),
        rotation: readableRotation(nodeAngle),
        parent: pillarId,
        w: o.nodeW,
        h: o.nodeH,
        label: truncateLabel(node?.label || id, nodeChars),
        fullLabel: node?.label || id,
        status: node?.status || 'missing',
        ownerType: node?.owner_type || null,
        skillCount: Array.isArray(node?.skills) ? node.skills.length : 0,
        processCount: Number(node?.process_count) || 0,
        pillarKey: key,
        data: node,
      })
      placed.add(id)
      edges.push({ from: pillarId, to: id, kind: 'rib' })
    })
  })

  // builds_on ribbons last, so the renderer can treat them as one group.
  const pairs = new Set()
  for (const pillar of pillars) {
    for (const node of pillar?.nodes || []) {
      if (node?.id == null) continue
      const from = String(node.id)
      for (const raw of node?.builds_on || []) {
        const to = String(raw)
        const pair = `${from}→${to}`
        if (to === from || !placed.has(to) || pairs.has(pair)) continue
        pairs.add(pair)
        edges.push({ from, to, kind: 'builds_on' })
      }
    }
  }

  return { nodes, edges, bounds: boundsOf(nodes, o.padding), rings }
}

/** Axis-aligned bounding box of the laid-out nodes (rotation-aware) + padding. */
export function boundsOf(nodes, padding = LAYOUT_DEFAULTS.padding) {
  let minX = 0
  let minY = 0
  let maxX = 0
  let maxY = 0
  for (const n of nodes || []) {
    let halfX
    let halfY
    if (n.kind === 'core') {
      halfX = n.r || 0
      halfY = n.r || 0
    } else {
      const rad = toRad(n.rotation || 0)
      const w = n.w || 0
      const h = n.h || 0
      halfX = (Math.abs(Math.cos(rad)) * w + Math.abs(Math.sin(rad)) * h) / 2
      halfY = (Math.abs(Math.sin(rad)) * w + Math.abs(Math.cos(rad)) * h) / 2
    }
    minX = Math.min(minX, n.x - halfX)
    maxX = Math.max(maxX, n.x + halfX)
    minY = Math.min(minY, n.y - halfY)
    maxY = Math.max(maxY, n.y + halfY)
  }
  minX -= padding
  minY -= padding
  maxX += padding
  maxY += padding
  return {
    minX: round2(minX),
    minY: round2(minY),
    maxX: round2(maxX),
    maxY: round2(maxY),
    width: round2(maxX - minX),
    height: round2(maxY - minY),
    cx: round2((minX + maxX) / 2),
    cy: round2((minY + maxY) / 2),
  }
}

export default radialLayout

// radialLayout util — Vitest unit tests.
// Determinism, pillar order + equal angles, ring-2 nodes inside their
// pillar's sector, spoke / rib / builds_on edges, char-count truncation,
// minimum radii, readable rotation, bounds, and no overlapping boxes on the
// shipped 9-pillar / 40-node fixture.
import { describe, it, expect } from 'vitest'
import {
  radialLayout, truncateLabel, labelCapacity, normalizeAngle, readableRotation, sortPillars, LAYOUT_DEFAULTS,
} from '../src/utils/radialLayout.js'
import mapFixture from './fixtures/map.json'

const input = mapFixture.data
const dist = (n) => Math.hypot(n.x, n.y)
const angleDiff = (a, b) => Math.abs(normalizeAngle(a - b))

// Separating-axis overlap test for two rotated rectangles.
function corners(n) {
  const a = (n.rotation * Math.PI) / 180
  const c = Math.cos(a)
  const s = Math.sin(a)
  const hw = n.w / 2
  const hh = n.h / 2
  return [[-hw, -hh], [hw, -hh], [hw, hh], [-hw, hh]].map(([x, y]) => [n.x + x * c - y * s, n.y + x * s + y * c])
}
function overlaps(p, q) {
  const axes = (pts) => pts.map(([x1, y1], i) => {
    const [x2, y2] = pts[(i + 1) % pts.length]
    return [-(y2 - y1), x2 - x1]
  })
  for (const [ax, ay] of [...axes(p), ...axes(q)]) {
    const proj = (pts) => pts.map(([x, y]) => x * ax + y * ay)
    const a = proj(p)
    const b = proj(q)
    if (Math.max(...a) <= Math.min(...b) + 1e-6 || Math.max(...b) <= Math.min(...a) + 1e-6) return false
  }
  return true
}

describe('radialLayout', () => {
  it('is deterministic — identical input gives identical output', () => {
    expect(radialLayout(input)).toEqual(radialLayout(JSON.parse(JSON.stringify(input))))
  })

  it('puts the core at the origin and lays out every pillar and node from the fixture', () => {
    const { nodes } = radialLayout(input)
    const core = nodes.find((n) => n.kind === 'core')
    expect(core).toMatchObject({ id: 'core', x: 0, y: 0, parent: null, fullLabel: 'Apex Synchronia' })
    expect(nodes.filter((n) => n.kind === 'pillar')).toHaveLength(9)
    expect(nodes.filter((n) => n.kind === 'node')).toHaveLength(40)
  })

  it('orders pillars by `order` (not array order) at equal angles on ring 1, first one straight up', () => {
    // The fixture deliberately lists "deals" before "sales".
    expect(input.pillars[0].key).toBe('deals')
    const { nodes, rings } = radialLayout(input)
    const pillars = nodes.filter((n) => n.kind === 'pillar')
    expect(pillars.map((p) => p.key)).toEqual(['sales', 'deals', 'marketing', 'operations', 'intelligence', 'customer', 'back_office', 'people', 'founder'])
    expect(pillars[0].angle).toBe(-90)
    expect(pillars[0].x).toBeCloseTo(0, 1)
    expect(pillars[0].y).toBeCloseTo(-rings.r1, 1)
    pillars.forEach((p, i) => {
      expect(dist(p)).toBeCloseTo(rings.r1, 0)
      if (i > 0) expect(angleDiff(p.angle, pillars[i - 1].angle)).toBeCloseTo(40, 5)
      expect(p.parent).toBe('core')
    })
  })

  it('places each node on ring 2 inside its own pillar sector', () => {
    const { nodes, rings } = radialLayout(input)
    const pillars = Object.fromEntries(nodes.filter((n) => n.kind === 'pillar').map((p) => [p.id, p]))
    const leaves = nodes.filter((n) => n.kind === 'node')
    for (const n of leaves) {
      expect(dist(n)).toBeCloseTo(rings.r2, 0)
      const pillar = pillars[n.parent]
      expect(pillar).toBeTruthy()
      expect(angleDiff(n.angle, pillar.angle)).toBeLessThan(20) // half of a 40° sector
    }
    // Nodes keep their input order within a pillar (clockwise).
    const sales = leaves.filter((n) => n.parent === 'pillar:sales')
    expect(sales.map((n) => n.id)).toEqual(input.pillars.find((p) => p.key === 'sales').nodes.map((n) => n.id))
    for (let i = 1; i < sales.length; i++) expect(sales[i].angle).toBeGreaterThan(sales[i - 1].angle)
  })

  it('builds spoke, rib and builds_on edges — dropping unknown, self and duplicate ribbons', () => {
    const data = JSON.parse(JSON.stringify(input))
    const node = data.pillars.find((p) => p.key === 'sales').nodes[0]
    node.builds_on = [...node.builds_on, 'no.such_node', node.id, node.builds_on[0]]
    const { edges } = radialLayout(data)
    expect(edges.filter((e) => e.kind === 'spoke')).toHaveLength(9)
    expect(edges.filter((e) => e.kind === 'rib')).toHaveLength(40)
    const ribbons = edges.filter((e) => e.kind === 'builds_on')
    const fromNode = ribbons.filter((e) => e.from === node.id).map((e) => e.to)
    expect(fromNode).toEqual(['sales.icp', 'marketing.brand_voice', 'sales.prospect_research'])
    const ids = new Set(radialLayout(data).nodes.map((n) => n.id))
    expect(ribbons.every((e) => ids.has(e.from) && ids.has(e.to) && e.from !== e.to)).toBe(true)
    expect(edges.find((e) => e.kind === 'spoke')).toEqual({ from: 'core', to: 'pillar:sales', kind: 'spoke' })
  })

  it('truncates labels by character count and keeps the full label', () => {
    const { nodes } = radialLayout(input)
    const long = nodes.find((n) => n.id === 'sales.partner_recruitment')
    expect(long.fullLabel).toBe('Partner & affiliate recruitment')
    expect(long.label.endsWith('…')).toBe(true)
    expect(long.label.length).toBeLessThanOrEqual(labelCapacity(LAYOUT_DEFAULTS.nodeW, { gutter: 52, charWidth: 5.9 }))
    expect(nodes.find((n) => n.id === 'sales.reply_handling').label).toBe('Reply handling')

    expect(truncateLabel('Hello world', 20)).toBe('Hello world')
    expect(truncateLabel('Hello world', 6)).toBe('Hello…')
    expect(truncateLabel('abc', 1)).toBe('…')
    expect(truncateLabel(null, 5)).toBe('')
    expect(labelCapacity(0)).toBe(6)
  })

  it('treats r1 / r2 as minimums and grows ring 2 when a pillar is crowded', () => {
    const roomy = radialLayout(input, { r1: 400, r2: 900 })
    expect(roomy.rings).toEqual({ r1: 400, r2: 900 })
    const crowded = radialLayout({ core: {}, pillars: [{ key: 'a', order: 1, nodes: Array.from({ length: 20 }, (_, i) => ({ id: `a${i}`, label: `A ${i}` })) }, { key: 'b', order: 2, nodes: [] }] })
    expect(crowded.rings.r2).toBeGreaterThan(LAYOUT_DEFAULTS.r2)
  })

  it('rotates node boxes along their spoke but never upside down', () => {
    const { nodes } = radialLayout(input)
    for (const n of nodes.filter((x) => x.kind === 'node')) {
      expect(n.rotation).toBeGreaterThanOrEqual(-90)
      expect(n.rotation).toBeLessThanOrEqual(90)
    }
    expect(readableRotation(180)).toBe(0)
    expect(readableRotation(-135)).toBe(45)
    expect(readableRotation(30)).toBe(30)
    expect(normalizeAngle(270)).toBe(-90)
  })

  it('never overlaps two boxes on the shipped fixture', () => {
    const { nodes } = radialLayout(input)
    const boxes = nodes.filter((n) => n.kind !== 'core').map((n) => corners(n))
    let collisions = 0
    for (let i = 0; i < boxes.length; i++) {
      for (let j = i + 1; j < boxes.length; j++) if (overlaps(boxes[i], boxes[j])) collisions++
    }
    expect(collisions).toBe(0)
  })

  it('reports bounds that contain every node', () => {
    const { nodes, bounds } = radialLayout(input)
    for (const n of nodes) {
      expect(n.x).toBeGreaterThan(bounds.minX)
      expect(n.x).toBeLessThan(bounds.maxX)
      expect(n.y).toBeGreaterThan(bounds.minY)
      expect(n.y).toBeLessThan(bounds.maxY)
    }
    expect(bounds.width).toBeCloseTo(bounds.maxX - bounds.minX, 1)
  })

  it('handles empty input and missing orders', () => {
    const empty = radialLayout()
    expect(empty.nodes).toHaveLength(1)
    expect(empty.edges).toEqual([])
    expect(Number.isFinite(empty.bounds.width)).toBe(true)
    // Missing orders fall back to array index; ties keep array order.
    expect(sortPillars([{ key: 'b', order: 2 }, { key: 'a', order: 1 }, { key: 'c' }]).map((p) => p.key)).toEqual(['a', 'b', 'c'])
    expect(sortPillars(null)).toEqual([])
  })
})

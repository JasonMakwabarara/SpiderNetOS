import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const css = readFileSync(resolve(import.meta.dirname, '../src/style.css'), 'utf8')

function parseBlock(source) {
  const vars = {}
  for (const match of source.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)) vars[match[1]] = match[2].trim()
  return vars
}

function block(selector) {
  const start = css.indexOf(selector)
  if (start < 0) throw new Error(`missing ${selector}`)
  const open = css.indexOf('{', start)
  let depth = 0
  for (let i = open; i < css.length; i++) {
    if (css[i] === '{') depth++
    else if (css[i] === '}') {
      depth--
      if (depth === 0) return parseBlock(css.slice(open + 1, i))
    }
  }
  throw new Error(`unclosed ${selector}`)
}

const root = block(':root {')
const themes = {
  'harbour-dark': root,
  'harbour-light': { ...root, ...block('html[data-theme="midnight-harbour"][data-mode="light"]') },
  'ledger-dark': { ...root, ...block('html[data-theme="copper-ledger"][data-mode="dark"]') },
  'ledger-light': { ...root, ...block('html[data-theme="copper-ledger"][data-mode="light"]') },
  'solarpunk-sunlit': { ...root, ...block('html[data-theme="solarpunk"][data-mode="sunlit"]') },
}

function channels(hex) {
  const h = hex.replace('#', '')
  const n = parseInt(h.length === 3 ? h.split('').map((c) => c + c).join('') : h.slice(0, 6), 16)
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255]
}

function lin(c) {
  const s = c / 255
  return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
}

function luminance(hex) {
  const [r, g, b] = channels(hex)
  return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b)
}

function contrast(a, b) {
  const L1 = luminance(a)
  const L2 = luminance(b)
  const [hi, lo] = L1 > L2 ? [L1, L2] : [L2, L1]
  return (hi + 0.05) / (lo + 0.05)
}

function distance(a, b) {
  const [ar, ag, ab] = channels(a)
  const [br, bg, bb] = channels(b)
  return Math.hypot(ar - br, ag - bg, ab - bb)
}

function paint(color, onto) {
  const match = color.match(/rgba\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*\)/)
  if (!match) return color
  const alpha = Number(match[4])
  const fg = [Number(match[1]), Number(match[2]), Number(match[3])]
  const bg = channels(onto)
  const mixed = fg.map((channel, i) => Math.round(channel * alpha + bg[i] * (1 - alpha)))
  return `#${mixed.map((n) => n.toString(16).padStart(2, '0')).join('')}`
}

describe('theme contrast and status semantics', () => {
  it.each(Object.entries(themes))('%s keeps text, status, and accent distinct', (name, tokens) => {
    const canvas = tokens['--color-bg-canvas']
    const surface = tokens['--color-bg-surface']
    const pairs = [
      ['primary on canvas', tokens['--color-text-primary'], canvas],
      ['primary on surface', tokens['--color-text-primary'], surface],
      ['secondary on canvas', tokens['--color-text-secondary'], canvas],
      ['muted on canvas', tokens['--color-text-muted'], canvas],
      ['on accent', tokens['--color-on-accent'], tokens['--color-accent-fill']],
      ['warning on surface', tokens['--color-status-warning'], surface],
      ['danger on surface', tokens['--color-status-danger'], surface],
      ['success on surface', tokens['--color-status-success'], surface],
      ['waiting on surface', tokens['--color-accent-secondary'], surface],
    ]
    for (const [label, fg, bg] of pairs) {
      expect(contrast(fg, bg), `${name} ${label}`).toBeGreaterThanOrEqual(4.5)
    }

    const navBg = paint(tokens['--color-nav-active'], tokens['--color-bg-shell'])
    if (navBg.startsWith('#') && navBg !== 'transparent') {
      expect(contrast(tokens['--color-nav-active-text'], navBg), `${name} nav`).toBeGreaterThanOrEqual(4.5)
    }

    expect(tokens['--color-accent-fill'].toLowerCase(), `${name} gold/fill is not warning`).not.toBe(tokens['--color-status-warning'].toLowerCase())
    expect(distance(tokens['--color-status-danger'], tokens['--color-accent-fill']), `${name} danger vs accent`).toBeGreaterThan(80)
    expect(distance(tokens['--color-status-danger'], tokens['--color-accent-secondary']), `${name} danger vs waiting`).toBeGreaterThan(48)
    expect(distance(tokens['--color-status-warning'], tokens['--color-status-danger']), `${name} warning vs danger`).toBeGreaterThan(48)
  })

  it('keeps a visible focus ring and a reduced-motion path', () => {
    expect(css).toMatch(/outline:\s*2px solid var\(--color-focus-ring\)/)
    expect(css).toMatch(/prefers-reduced-motion:\s*reduce/)
    expect(css).toMatch(/\.sn-btn:disabled/)
  })
})

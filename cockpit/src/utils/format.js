/**
 * Small display helpers shared by the skills / brain / runs / today
 * surfaces. Kept dependency-free and side-effect-free so they are trivial
 * to unit test and safe to import from any component.
 */

export function timeAgo(ts) {
  if (!ts) return ''
  const s = Math.max(1, Math.floor((Date.now() - new Date(ts).getTime()) / 1000))
  if (s < 60) return `${s}s ago`
  if (s < 3600) return `${Math.floor(s / 60)}m ago`
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`
  return `${Math.floor(s / 86400)}d ago`
}

export function fmtDate(ts) {
  return ts ? new Date(ts).toLocaleString() : '—'
}

export function fmtMoney(amount, currency = 'USD') {
  const n = Number(amount) || 0
  try {
    return n.toLocaleString('en-US', { style: 'currency', currency })
  } catch {
    return `$${n.toFixed(2)}`
  }
}

export function fmtDuration(ms) {
  const n = Number(ms) || 0
  if (n < 1000) return `${n}ms`
  if (n < 60000) return `${(n / 1000).toFixed(1)}s`
  return `${Math.floor(n / 60000)}m ${Math.round((n % 60000) / 1000)}s`
}

export function durationBetween(start, end) {
  if (!start) return null
  return (end ? new Date(end) : new Date()).getTime() - new Date(start).getTime()
}

export function titleCase(slug) {
  return String(slug || '').replace(/[-_]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

/** Dotted-path lookup: getPath({ a: { b: 1 } }, 'a.b') → 1 */
export function getPath(obj, path) {
  return String(path || '')
    .split('.')
    .filter(Boolean)
    .reduce((acc, key) => (acc == null ? undefined : acc[key]), obj)
}

/** Approval `context` arrives as an object or a JSON string from the raw row. */
export function parseContext(ctx) {
  if (typeof ctx === 'string') {
    try { return JSON.parse(ctx) } catch { return {} }
  }
  return ctx || {}
}

// ── Pipeline stages ──────────────────────────────────────────────────
export const STAGES = {
  human_led:  { label: 'Human-led',  color: 'var(--stage-human)' },
  assisted:   { label: 'Assisted',   color: 'var(--stage-assisted)' },
  autonomous: { label: 'Autonomous', color: 'var(--stage-autonomous)' },
}
export function stageMeta(stage) {
  return STAGES[stage] || { label: stage ? titleCase(stage) : '—', color: 'var(--status-idle)' }
}

// ── Run statuses ─────────────────────────────────────────────────────
const RUN_STATUS_PILL = {
  queued:           'sn-pill',
  running:          'sn-pill-accent',
  blocked:          'sn-pill-danger',
  waiting_approval: 'sn-pill-warn',
  succeeded:        'sn-pill-success',
  failed:           'sn-pill-danger',
  cancelled:        'sn-pill',
}
export function runStatusPill(status) {
  return RUN_STATUS_PILL[status] || 'sn-pill'
}
export function runStatusDot(status) {
  if (status === 'succeeded') return 'background: var(--success); box-shadow: 0 0 6px rgba(34,211,155,0.6);'
  if (status === 'running' || status === 'queued') return 'background: var(--accent); box-shadow: 0 0 6px rgba(0,229,200,0.6);'
  if (status === 'blocked' || status === 'waiting_approval') return 'background: var(--warn); box-shadow: 0 0 6px rgba(245,165,36,0.6);'
  if (status === 'failed') return 'background: var(--danger); box-shadow: 0 0 6px rgba(255,90,122,0.6);'
  return 'background: var(--text-muted);'
}

// ── The six core characters ──────────────────────────────────────────
export const CORE_AGENTS = {
  atlas:    { name: 'Atlas',    role: 'The executive brain',   glyph: 'A' },
  hannah:   { name: 'Hannah',   role: 'The guide and teacher', glyph: 'H' },
  forge:    { name: 'Forge',    role: 'The builder',           glyph: 'F' },
  sentinel: { name: 'Sentinel', role: 'The watcher',           glyph: 'S' },
  prism:    { name: 'Prism',    role: 'The analyst',           glyph: 'P' },
  nexus:    { name: 'Nexus',    role: 'The executor',          glyph: 'N' },
}
export function coreAgent(slug) {
  return CORE_AGENTS[slug] || { name: titleCase(slug) || 'Agent', role: '', glyph: String(slug || '?')[0].toUpperCase() }
}

export function riskPill(risk) {
  if (risk === 'high') return 'sn-pill sn-pill-danger'
  if (risk === 'medium') return 'sn-pill sn-pill-warn'
  return 'sn-pill sn-pill-success'
}

export function brainStatusDot(status) {
  if (status === 'filled') return 'sn-dot-live'
  if (status === 'partial') return 'sn-dot-assisted'
  if (status === 'missing') return 'sn-dot-missing'
  return 'sn-dot-idle'
}

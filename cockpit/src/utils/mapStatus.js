/**
 * Business-map status + owner vocabulary, shared by the map nodes, the
 * legend and the node drawer. Colour comes from the `--status-*` tokens
 * only, so the map never invents its own palette.
 */

export const MAP_STATUS = {
  live:     { label: 'Live',      hint: 'Runs on its own',          color: 'var(--status-live)' },
  assisted: { label: 'Assisted',  hint: 'AI drafts, you approve',   color: 'var(--status-assisted)' },
  human:    { label: 'Human-led', hint: 'Waiting on a person',      color: 'var(--status-human)' },
  missing:  { label: 'Missing',   hint: 'Not set up yet',           color: 'var(--status-missing)' },
}

export const MAP_STATUS_ORDER = ['live', 'assisted', 'human', 'missing']

export function mapStatusMeta(status) {
  return MAP_STATUS[status] || { label: 'Unknown', hint: '', color: 'var(--status-idle)' }
}

const OWNER_LABEL = { founder: 'you', team: 'team', agent: 'agent' }

export function ownerLabel(ownerType) {
  return OWNER_LABEL[ownerType] || 'unassigned'
}

/** One glyph for the node chip: the founder's initial, T(eam), A(gent). */
export function ownerInitial(ownerType, ownerName = '') {
  if (ownerType === 'founder') return (String(ownerName || '').trim()[0] || 'Y').toUpperCase()
  if (ownerType === 'team') return 'T'
  if (ownerType === 'agent') return 'A'
  return '·'
}

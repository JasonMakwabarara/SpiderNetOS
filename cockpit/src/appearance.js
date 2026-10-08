/** Appearance state. Imported first from main.js so the document is themed before Vue mounts. */

export const APPEARANCE_KEY = 'spidernet.appearance'

export const THEMES = ['midnight-harbour', 'copper-ledger', 'solarpunk']

export const ALLOWED_MODES = {
  'midnight-harbour': ['light', 'dark'],
  'copper-ledger': ['light', 'dark'],
  'solarpunk': ['sunlit'],
}

export const THEME_LABELS = {
  'midnight-harbour': 'Midnight Harbour',
  'copper-ledger': 'Copper Ledger',
  'solarpunk': 'Solarpunk',
}

const DEFAULT_THEME = 'midnight-harbour'
const DEFAULT_MODE = 'dark'

function emptyModes() {
  return { 'midnight-harbour': 'dark', 'copper-ledger': 'dark' }
}

export function normalizeAppearance(raw) {
  const record = raw && typeof raw === 'object' && !Array.isArray(raw) ? raw : null
  const themeValid = THEMES.includes(record?.theme)
  const theme = themeValid ? record.theme : DEFAULT_THEME
  const modes = emptyModes()
  for (const key of Object.keys(modes)) {
    const candidate = record?.modes?.[key]
    if (ALLOWED_MODES[key].includes(candidate)) modes[key] = candidate
  }
  let mode
  if (theme === 'solarpunk') mode = 'sunlit'
  else if (themeValid && ALLOWED_MODES[theme].includes(record?.mode)) mode = record.mode
  else mode = modes[theme]
  if (theme !== 'solarpunk') modes[theme] = mode
  return { theme, mode, modes }
}

/** Pure theme/mode transition. Does not touch routes, copy, or permissions. */
export function reduceAppearance(state, patch = {}) {
  const next = normalizeAppearance(state)
  if (patch.theme && THEMES.includes(patch.theme) && patch.theme !== next.theme) {
    next.theme = patch.theme
    next.mode = patch.theme === 'solarpunk' ? 'sunlit' : next.modes[patch.theme]
  }
  if (patch.mode && ALLOWED_MODES[next.theme].includes(patch.mode)) {
    next.mode = patch.mode
    if (next.theme !== 'solarpunk') next.modes[next.theme] = patch.mode
  }
  return normalizeAppearance(next)
}

export function readAppearance() {
  try {
    return normalizeAppearance(JSON.parse(localStorage.getItem(APPEARANCE_KEY) || 'null'))
  } catch {
    return normalizeAppearance(null)
  }
}

export function applyAppearance(state) {
  const root = document.documentElement
  root.dataset.theme = state.theme
  root.dataset.mode = state.mode
  root.style.colorScheme = state.mode === 'dark' ? 'dark' : 'light'
  root.classList.toggle('dark', state.mode === 'dark')
}

export function persistAppearance(state) {
  localStorage.setItem(APPEARANCE_KEY, JSON.stringify({
    theme: state.theme,
    mode: state.mode,
    modes: state.modes,
  }))
}

export function bootAppearance() {
  const state = readAppearance()
  applyAppearance(state)
  return state
}

bootAppearance()

import { computed, reactive } from 'vue'
import {
  ALLOWED_MODES,
  THEME_LABELS,
  THEMES,
  applyAppearance,
  persistAppearance,
  readAppearance,
  reduceAppearance,
} from '../appearance.js'

const state = reactive(readAppearance())

function commit() {
  applyAppearance(state)
  persistAppearance(state)
}

export function useAppearance() {
  const availableModes = computed(() => ALLOWED_MODES[state.theme])

  function applyNext(patch) {
    const next = reduceAppearance(state, patch)
    if (next.theme === state.theme && next.mode === state.mode) return
    state.theme = next.theme
    state.mode = next.mode
    state.modes = next.modes
    commit()
  }

  function setTheme(theme) {
    applyNext({ theme })
  }

  function setMode(mode) {
    applyNext({ mode })
  }

  return {
    theme: computed(() => state.theme),
    mode: computed(() => state.mode),
    availableModes,
    themes: THEMES,
    labels: THEME_LABELS,
    setTheme,
    setMode,
  }
}

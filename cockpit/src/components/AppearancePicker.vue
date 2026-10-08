<template>
  <div class="sn-appearance" :data-variant="variant">
    <p class="sn-appearance-label">Appearance</p>

    <div
      v-if="variant === 'settings'"
      class="sn-theme-grid"
      role="radiogroup"
      aria-label="Theme"
    >
      <button
        v-for="id in themes"
        :key="id"
        type="button"
        role="radio"
        class="sn-theme-card"
        :class="{ active: theme === id }"
        :aria-checked="theme === id"
        :data-preview="id"
        :data-testid="`theme-${id}`"
        @click="setTheme(id)"
      >
        <span class="preview-shell" aria-hidden="true">
          <span class="preview-rail"></span>
          <span class="preview-body">
            <span class="preview-type">Aa</span>
            <span class="preview-active">Active</span>
          </span>
        </span>
        <span>{{ labels[id] }}</span>
      </button>
    </div>

    <div v-else class="sn-appearance-themes" role="radiogroup" aria-label="Theme">
      <button
        v-for="id in themes"
        :key="id"
        type="button"
        role="radio"
        class="sn-appearance-theme"
        :class="{ active: theme === id }"
        :aria-checked="theme === id"
        :data-testid="`theme-${id}`"
        @click="setTheme(id)"
      >
        <span class="sn-appearance-swatch" :data-preview="id" aria-hidden="true"></span>
        <span>{{ labels[id] }}</span>
      </button>
    </div>

    <div
      v-if="availableModes.length > 1"
      class="sn-appearance-modes"
      role="radiogroup"
      aria-label="Mode"
    >
      <button
        v-for="m in availableModes"
        :key="m"
        type="button"
        role="radio"
        class="sn-appearance-mode"
        :class="{ active: mode === m }"
        :aria-checked="mode === m"
        :data-testid="`mode-${m}`"
        @click="setMode(m)"
      >{{ m === 'dark' ? 'Dark' : 'Light' }}</button>
    </div>
  </div>
</template>

<script setup>
import { useAppearance } from '../composables/useAppearance.js'

defineProps({
  variant: { type: String, default: 'menu' },
})

const { theme, mode, availableModes, themes, labels, setTheme, setMode } = useAppearance()
</script>

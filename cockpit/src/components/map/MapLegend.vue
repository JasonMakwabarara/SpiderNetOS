<template>
  <div
    class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[11px]"
    style="color: var(--text-secondary);"
    role="list"
    aria-label="Map legend"
    data-testid="map-legend"
  >
    <span
      v-for="key in MAP_STATUS_ORDER"
      :key="key"
      role="listitem"
      class="inline-flex items-center gap-1.5"
      :title="MAP_STATUS[key].hint"
      :data-testid="`map-legend-${key}`"
    >
      <span class="inline-block size-2 rounded-full" :style="{ background: MAP_STATUS[key].color }" aria-hidden="true" />
      {{ MAP_STATUS[key].label }}
      <span v-if="counts && counts[key] != null" class="mono" style="color: var(--text-muted);">{{ counts[key] }}</span>
    </span>
    <span role="listitem" class="inline-flex items-center gap-1.5" data-testid="map-legend-builds-on">
      <svg width="22" height="6" aria-hidden="true">
        <line x1="1" y1="3" x2="21" y2="3" stroke-dasharray="4 3" :style="{ stroke: 'var(--text-muted)', strokeWidth: 1.5 }" />
      </svg>
      Builds on (hover or select)
    </span>
  </div>
</template>

<script setup>
/** MapLegend — what the status dots and the dashed ribbons mean. */
import { MAP_STATUS, MAP_STATUS_ORDER } from '../../utils/mapStatus.js'

defineProps({
  counts: { type: Object, default: null },
})
</script>

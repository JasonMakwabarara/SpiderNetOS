<template>
  <g
    class="sn-map-pillar"
    data-map-node
    :data-node-id="node.id"
    :data-testid="`map-pillar-${node.key}`"
    :data-status="node.status || 'none'"
    :data-focused="focused ? 'true' : 'false'"
    role="button"
    :tabindex="focused ? 0 : -1"
    :aria-label="ariaLabel"
    :transform="`translate(${node.x} ${node.y})`"
    :style="{ opacity: dimmed ? 0.45 : 1, cursor: 'pointer' }"
    @click="emit('select', node)"
    @focus="emit('focus', node)"
    @mouseenter="emit('hover', node)"
    @mouseleave="emit('hover', null)"
  >
    <title>{{ node.fullLabel }}</title>
    <rect
      :x="-w / 2"
      :y="-h / 2"
      :width="w"
      :height="h"
      rx="19"
      :style="{
        fill: 'var(--bg-elevated)',
        stroke: focused ? meta.color : 'var(--border-active, var(--border))',
        strokeWidth: focused ? 1.8 : 1,
      }"
    />
    <rect
      :x="-w / 2 + 12"
      y="-2"
      width="4"
      height="4"
      rx="2"
      :style="{ fill: meta.color }"
      :data-testid="`map-pillar-dot-${node.key}`"
    />
    <text
      :x="-w / 2 + 24"
      y="4"
      font-size="12"
      :style="{ fill: 'var(--text-primary)', fontWeight: 600, letterSpacing: '0.01em' }"
    >{{ node.label }}</text>
    <text
      :x="w / 2 - 14"
      y="4"
      font-size="10"
      text-anchor="end"
      :style="{ fill: 'var(--text-muted)' }"
      :data-testid="`map-pillar-count-${node.key}`"
    >{{ visibleCount }}/{{ node.nodeCount }}</text>
  </g>
</template>

<script setup>
/**
 * MapPillarNode — a ring-1 pillar (Sales, Deals, Marketing…). Shows the
 * pillar's rolled-up status and how many of its nodes pass the current
 * filter. Selecting a pillar only moves keyboard focus; nodes open drawers.
 */
import { computed } from 'vue'
import { mapStatusMeta } from '../../utils/mapStatus.js'

const props = defineProps({
  node:         { type: Object, required: true },
  focused:      { type: Boolean, default: false },
  dimmed:       { type: Boolean, default: false },
  visibleCount: { type: Number, default: 0 },
})

const emit = defineEmits(['select', 'focus', 'hover'])

const w = computed(() => props.node.w || 140)
const h = computed(() => props.node.h || 38)
const meta = computed(() => mapStatusMeta(props.node.status))

const ariaLabel = computed(() => {
  const n = props.node
  const status = n.status ? `, mostly ${meta.value.label.toLowerCase()}` : ''
  return `${n.fullLabel} pillar${status}. ${n.nodeCount} node${n.nodeCount === 1 ? '' : 's'}, ${props.visibleCount} shown.`
})
</script>

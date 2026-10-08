<template>
  <g
    class="sn-map-node"
    data-map-node
    :data-node-id="node.id"
    :data-testid="`map-node-${node.id}`"
    :data-status="node.status"
    :data-selected="selected ? 'true' : 'false'"
    :data-focused="focused ? 'true' : 'false'"
    :data-dimmed="dimmed ? 'true' : 'false'"
    role="button"
    :tabindex="focused ? 0 : -1"
    :aria-label="ariaLabel"
    :transform="`translate(${node.x} ${node.y}) rotate(${node.rotation || 0})`"
    :style="{ opacity: dimmed ? 0.28 : 1, cursor: 'pointer' }"
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
      rx="10"
      :style="{
        fill: 'var(--bg-card)',
        stroke: selected || focused ? meta.color : 'var(--border)',
        strokeWidth: selected ? 2.2 : focused ? 1.6 : 1,
      }"
    />
    <circle
      :cx="-w / 2 + 13"
      cy="0"
      r="4.5"
      :style="{ fill: meta.color }"
      :data-testid="`map-node-dot-${node.id}`"
    />
    <text
      :x="-w / 2 + 24"
      y="3.8"
      font-size="11"
      :style="{ fill: 'var(--text-primary)', fontWeight: selected ? 600 : 500 }"
      :data-testid="`map-node-label-${node.id}`"
    >{{ node.label }}</text>
    <text
      :x="w / 2 - 25"
      y="3.6"
      font-size="10"
      text-anchor="end"
      :style="{ fill: 'var(--text-muted)' }"
      :data-testid="`map-node-skills-${node.id}`"
    >{{ node.skillCount }}</text>
    <circle :cx="w / 2 - 13" cy="0" r="8" :style="{ fill: 'var(--bg-elevated)', stroke: 'var(--border)' }" />
    <text
      :x="w / 2 - 13"
      y="3.4"
      font-size="9"
      text-anchor="middle"
      :style="{ fill: 'var(--text-secondary)', fontWeight: 600 }"
      :data-testid="`map-node-owner-${node.id}`"
    >{{ initial }}</text>
  </g>
</template>

<script setup>
/**
 * MapNode — one ring-2 node on the business map: status dot (colour by
 * status only), truncated label, skill count and an owner initial. The box
 * is laid along its spoke (see radialLayout). Keyboard focus is roving:
 * only the focused node is in the tab order; RadialMap moves it.
 */
import { computed } from 'vue'
import { mapStatusMeta, ownerInitial, ownerLabel } from '../../utils/mapStatus.js'

const props = defineProps({
  node:      { type: Object, required: true },
  selected:  { type: Boolean, default: false },
  focused:   { type: Boolean, default: false },
  dimmed:    { type: Boolean, default: false },
  ownerName: { type: String, default: '' },
  pillarLabel: { type: String, default: '' },
})

const emit = defineEmits(['select', 'focus', 'hover'])

const w = computed(() => props.node.w || 164)
const h = computed(() => props.node.h || 34)
const meta = computed(() => mapStatusMeta(props.node.status))
const initial = computed(() => ownerInitial(props.node.ownerType, props.ownerName))

const ariaLabel = computed(() => {
  const n = props.node
  const skills = `${n.skillCount} skill${n.skillCount === 1 ? '' : 's'}`
  const parts = [
    n.fullLabel,
    `${meta.value.label} — ${meta.value.hint}`,
    skills,
    `owner: ${ownerLabel(n.ownerType)}`,
  ]
  if (props.pillarLabel) parts.push(`in ${props.pillarLabel}`)
  if (props.selected) parts.push('open')
  return `${parts.join('. ')}.`
})
</script>

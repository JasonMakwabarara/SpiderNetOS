<template>
  <g
    class="sn-map-core"
    data-map-node
    :data-node-id="node.id"
    data-testid="map-core"
    :data-focused="focused ? 'true' : 'false'"
    role="button"
    :tabindex="focused ? 0 : -1"
    :aria-label="ariaLabel"
    :transform="`translate(${node.x} ${node.y})`"
    style="cursor: pointer;"
    @click="emit('select', node)"
    @focus="emit('focus', node)"
  >
    <title>{{ node.fullLabel }} — the three brains</title>

    <!-- Brain arcs: track + value, one third of the ring each -->
    <g v-for="arc in arcs" :key="arc.key" :data-testid="`map-core-arc-${arc.key}`" :data-value="arc.fill">
      <path :d="arc.track" fill="none" stroke-linecap="round" :style="{ stroke: 'var(--border)', strokeWidth: 6 }" />
      <path
        v-if="arc.value"
        :d="arc.value"
        fill="none"
        stroke-linecap="round"
        :style="{ stroke: arc.color, strokeWidth: 6 }"
      />
    </g>

    <circle
      cx="0"
      cy="0"
      :r="r"
      :style="{
        fill: 'var(--bg-card)',
        stroke: focused ? 'var(--status-live)' : 'var(--border-active, var(--border))',
        strokeWidth: focused ? 2 : 1,
      }"
    />
    <text
      x="0"
      y="-18"
      font-size="13"
      text-anchor="middle"
      :style="{ fill: 'var(--text-primary)', fontWeight: 700 }"
      data-testid="map-core-name"
    >{{ node.label }}</text>
    <text
      v-if="ownerName"
      x="0"
      y="-4"
      font-size="9"
      text-anchor="middle"
      :style="{ fill: 'var(--text-muted)' }"
      data-testid="map-core-owner"
    >{{ ownerName }}</text>
    <text
      v-for="(arc, i) in arcs"
      :key="`t-${arc.key}`"
      x="0"
      :y="14 + i * 13"
      font-size="9.5"
      text-anchor="middle"
      :style="{ fill: 'var(--text-secondary)' }"
      :data-testid="`map-core-${arc.key}`"
    >{{ arc.text }}</text>
  </g>
</template>

<script setup>
/**
 * MapCoreNode — the business at the centre of the map, ringed by the three
 * brains (plan D0): Knowledge (brain files filled), Operating (agent
 * workspaces active, out of the six core characters) and Learning
 * (outcomes recorded in the last 30 days, against one a day).
 */
import { computed } from 'vue'

const props = defineProps({
  node:    { type: Object, required: true },
  focused: { type: Boolean, default: false },
})

const emit = defineEmits(['select', 'focus'])

// Targets the arcs fill towards (the contract only carries counts).
const OPERATING_TARGET = 6   // the six core characters
const LEARNING_TARGET = 30   // one recorded outcome a day

const r = computed(() => props.node.r || 80)
const brains = computed(() => props.node.data?.three_brains || {})
const ownerName = computed(() => props.node.data?.owner?.name || '')

const clamp01 = (n) => Math.max(0, Math.min(1, Number(n) || 0))
const round2 = (n) => Math.round(n * 100) / 100

function polar(radius, deg) {
  const rad = (deg * Math.PI) / 180
  return { x: round2(Math.cos(rad) * radius), y: round2(Math.sin(rad) * radius) }
}

function arcPath(radius, start, end) {
  const s = polar(radius, start)
  const e = polar(radius, end)
  const large = end - start > 180 ? 1 : 0
  return `M ${s.x} ${s.y} A ${radius} ${radius} 0 ${large} 1 ${e.x} ${e.y}`
}

const arcs = computed(() => {
  const k = brains.value.knowledge || {}
  const o = brains.value.operating || {}
  const l = brains.value.learning || {}
  const ringR = r.value + 11
  const defs = [
    {
      key: 'knowledge',
      centre: -90,
      fill: clamp01((Number(k.pct) || 0) / 100),
      text: `Knowledge ${Math.round(Number(k.pct) || 0)}%`,
      color: 'var(--status-live)',
    },
    {
      key: 'operating',
      centre: 30,
      fill: clamp01((Number(o.workspaces_active) || 0) / OPERATING_TARGET),
      text: `Operating ${Number(o.workspaces_active) || 0} active`,
      color: 'var(--status-assisted)',
    },
    {
      key: 'learning',
      centre: 150,
      fill: clamp01((Number(l.outcomes_30d) || 0) / LEARNING_TARGET),
      text: `Learning ${Number(l.outcomes_30d) || 0} outcomes`,
      color: 'var(--status-human)',
    },
  ]
  const span = 104
  return defs.map((d) => {
    const start = d.centre - span / 2
    const end = d.centre + span / 2
    return {
      ...d,
      fill: round2(d.fill),
      track: arcPath(ringR, start, end),
      value: d.fill > 0 ? arcPath(ringR, start, start + span * d.fill) : '',
    }
  })
})

const ariaLabel = computed(() => {
  const k = brains.value.knowledge || {}
  const o = brains.value.operating || {}
  const l = brains.value.learning || {}
  return [
    `${props.node.fullLabel}, the core of your business`,
    `Knowledge brain ${Math.round(Number(k.pct) || 0)}% filled (${k.files_filled ?? 0} of ${k.files_total ?? 0} files)`,
    `Operating brain ${o.workspaces_active ?? 0} workspaces active, ${o.runs_today ?? 0} runs today`,
    `Learning brain ${l.outcomes_30d ?? 0} outcomes in 30 days, ${l.experiments_open ?? 0} experiments open`,
  ].join('. ') + '.'
})
</script>

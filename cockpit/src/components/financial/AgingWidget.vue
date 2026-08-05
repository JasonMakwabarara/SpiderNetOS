<template>
  <section class="sn-card p-4" data-testid="aging-widget">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-[10px] uppercase tracking-widest font-semibold" style="color: var(--text-muted);">
        AP aging
      </h3>
      <span v-if="asOf" class="text-[10px] mono" style="color: var(--text-muted);">as of {{ asOf }}</span>
    </div>

    <svg
      :viewBox="`0 0 ${geom.w} ${geom.h}`"
      class="w-full"
      role="img"
      aria-label="Accounts payable aging buckets"
      data-testid="aging-svg"
    >
      <g v-for="(b, i) in buckets" :key="b.key" :data-testid="`aging-bucket-${b.key}`">
        <!-- Bucket label -->
        <text
          :x="geom.padX"
          :y="rowY(i) + geom.barH - 6"
          font-size="10"
          font-family="JetBrains Mono"
          fill="var(--text-muted)"
        >{{ b.label }}</text>
        <!-- Bar track -->
        <rect
          :x="geom.labelW"
          :y="rowY(i)"
          :width="geom.barMax"
          :height="geom.barH"
          rx="3"
          fill="rgba(255,255,255,0.04)"
        />
        <!-- Bar -->
        <rect
          :x="geom.labelW"
          :y="rowY(i)"
          :width="barWidth(b)"
          :height="geom.barH"
          rx="3"
          :fill="b.fill"
          :data-testid="`aging-bar-${b.key}`"
        />
        <!-- Amount -->
        <text
          :x="geom.w - geom.padX"
          :y="rowY(i) + geom.barH - 6"
          text-anchor="end"
          font-size="10"
          font-family="JetBrains Mono"
          class="mono"
          fill="var(--text-secondary)"
          :data-testid="`aging-amount-${b.key}`"
        >{{ formatAmount(b.amount) }}</text>
      </g>
    </svg>

    <div class="mt-2 pt-2 border-t flex items-center justify-between text-xs" style="border-color: var(--border);">
      <span style="color: var(--text-muted);">Total outstanding</span>
      <span class="mono font-semibold" style="color: var(--text-primary);" data-testid="aging-total">
        {{ formatAmount(total) }}
      </span>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue'

/**
 * AgingWidget — horizontal SVG bars for the AP aging report.
 *
 * Hand-rolled SVG per the Usage.vue geometry conventions: fixed viewBox,
 * padX/labelW offsets, JetBrains Mono captions. Five fixed buckets:
 * current / 1-30 / 31-60 / 61-90 / 90+. Bar widths are proportional to
 * the largest bucket amount.
 *
 * `aging` accepts either shape:
 *   { buckets: { current: {amount,count}, "1_30": {...}, ... }, total_outstanding, as_of }
 *   { buckets: [ { key|bucket, amount, count }, ... ], ... }
 */
const props = defineProps({
  aging: { type: Object, default: null },
})

const ORDER = [
  { key: 'current', label: 'Current', fill: 'var(--success)' },
  { key: '1_30',    label: '1–30',    fill: 'var(--accent)' },
  { key: '31_60',   label: '31–60',   fill: 'var(--amber)' },
  { key: '61_90',   label: '61–90',   fill: 'var(--accent-warm)' },
  { key: '90_plus', label: '90+',     fill: 'var(--danger)' },
]

// Geometry (viewBox space)
const geom = {
  w: 320,
  padX: 4,
  labelW: 52,
  amountW: 76,
  rowH: 26,
  barH: 16,
  get barMax() { return this.w - this.labelW - this.amountW },
  get h() { return ORDER.length * this.rowH },
}

function rowY(i) {
  return i * geom.rowH + (geom.rowH - geom.barH) / 2
}

function bucketAmount(raw) {
  if (raw == null) return 0
  if (typeof raw === 'object') return Number(raw.amount || 0)
  return Number(raw) || 0
}

const buckets = computed(() => {
  const src = props.aging?.buckets ?? props.aging ?? {}
  const byKey = {}
  if (Array.isArray(src)) {
    for (const b of src) byKey[b.key ?? b.bucket] = b
  } else {
    Object.assign(byKey, src)
  }
  return ORDER.map((o) => ({
    ...o,
    amount: bucketAmount(byKey[o.key]),
    count: typeof byKey[o.key] === 'object' ? Number(byKey[o.key]?.count || 0) : 0,
  }))
})

const maxAmount = computed(() =>
  Math.max(...buckets.value.map((b) => b.amount), 0)
)

const total = computed(() => {
  const declared = props.aging?.total_outstanding ?? props.aging?.total
  if (declared != null) return Number(declared)
  return buckets.value.reduce((sum, b) => sum + b.amount, 0)
})

const asOf = computed(() => props.aging?.as_of || '')

function barWidth(b) {
  if (!maxAmount.value) return 0
  return Math.round((b.amount / maxAmount.value) * geom.barMax * 100) / 100
}

function formatAmount(v) {
  return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })
}
</script>

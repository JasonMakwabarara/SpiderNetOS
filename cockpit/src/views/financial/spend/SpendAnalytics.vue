<template>
  <div class="px-8 py-6 max-w-[1500px] mx-auto space-y-5">
    <!-- Header + controls -->
    <header class="flex items-start justify-between gap-4 flex-wrap">
      <div>
        <h1 class="text-[26px] font-heading font-semibold tracking-tight" style="color: var(--text-primary);">Spend analytics</h1>
        <p class="text-sm mt-1" style="color: var(--text-muted);">
          Where the money goes — trend, breakdown, monthly intensity, and budget burn across expenses and bills.
        </p>
      </div>
      <div class="flex items-center gap-3 shrink-0 flex-wrap">
        <!-- group_by pills -->
        <div class="flex rounded-md overflow-hidden border" style="border-color: var(--border);" data-testid="spend-group-toggle">
          <button
            v-for="g in GROUPS"
            :key="g.value"
            class="px-3 py-1.5 text-xs font-medium transition-colors"
            :style="groupBy === g.value
              ? 'background: var(--accent-weak); color: var(--accent);'
              : 'background: transparent; color: var(--text-muted);'"
            :data-testid="`spend-group-${g.value}`"
            @click="setGroupBy(g.value)"
          >{{ g.label }}</button>
        </div>
        <!-- period select -->
        <select v-model="period" class="w-auto text-xs" data-testid="spend-period-select" @change="load">
          <option value="30d">Last 30 days</option>
          <option value="90d">Last 90 days</option>
          <option value="12m">Last 12 months</option>
        </select>
      </div>
    </header>

    <!-- Spend over time -->
    <section class="sn-card p-5" data-testid="spend-sparkline">
      <div class="flex items-end justify-between mb-3">
        <div>
          <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Spend over time</div>
          <h3 class="font-heading font-semibold text-[15px] mt-0.5" style="color: var(--text-primary);">
            <span class="mono">${{ formatAmount(totalSpend) }}</span>
            <span class="text-xs mono" style="color: var(--text-muted);"> total · {{ periodLabel }}</span>
          </h3>
        </div>
        <span class="flex items-center gap-1.5 text-[11px]" style="color: var(--text-muted);">
          <span class="w-2 h-0.5" style="background: var(--accent);"></span> spend
        </span>
      </div>

      <svg
        v-if="series.length"
        :viewBox="`0 0 ${spark.w} ${spark.h}`"
        class="w-full"
        :style="`height: ${spark.h}px;`"
        role="img"
        aria-label="Spend over time"
        data-testid="spend-spark-svg"
      >
        <!-- Grid -->
        <g v-for="(y, i) in spark.gridY" :key="`gy-${i}`">
          <line :x1="spark.padX" :x2="spark.w - spark.padX" :y1="y" :y2="y"
                :stroke="i === spark.gridY.length - 1 ? 'rgba(255,255,255,0.10)' : 'rgba(255,255,255,0.04)'"
                stroke-width="1" />
        </g>
        <!-- Area fill -->
        <path :d="spark.areaPath" fill="url(#spendGrad)" />
        <!-- Line -->
        <path :d="spark.linePath" fill="none" stroke="var(--accent)" stroke-width="1.6" data-testid="spend-spark-line" />
        <!-- Last point -->
        <circle :cx="spark.lastX" :cy="spark.lastY" r="3" fill="var(--accent)" />
        <circle :cx="spark.lastX" :cy="spark.lastY" r="6" fill="rgba(0,229,200,0.20)" />
        <!-- Hover crosshair -->
        <g v-if="hoverIdx >= 0">
          <line :x1="hoverX" :x2="hoverX" :y1="spark.padY" :y2="spark.h - spark.padY"
                stroke="rgba(0,229,200,0.5)" stroke-width="1" stroke-dasharray="2 2"/>
          <circle :cx="hoverX" :cy="hoverY" r="3" fill="var(--accent)" />
        </g>
        <!-- X labels -->
        <text :x="spark.padX" :y="spark.h - 4" text-anchor="start" fill="var(--text-muted)" font-size="10" font-family="JetBrains Mono">{{ series[0]?.date }}</text>
        <text :x="spark.w / 2" :y="spark.h - 4" text-anchor="middle" fill="var(--text-muted)" font-size="10" font-family="JetBrains Mono">{{ series[Math.floor(series.length / 2)]?.date }}</text>
        <text :x="spark.w - spark.padX" :y="spark.h - 4" text-anchor="end" fill="var(--text-muted)" font-size="10" font-family="JetBrains Mono">{{ series[series.length - 1]?.date }}</text>
        <!-- Hit area -->
        <rect :x="spark.padX" :y="spark.padY" :width="spark.w - 2 * spark.padX" :height="spark.h - 2 * spark.padY"
              fill="transparent" pointer-events="all"
              @mousemove="onSparkHover" @mouseleave="hoverIdx = -1"/>
        <defs>
          <linearGradient id="spendGrad" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="rgba(0,229,200,0.28)"/>
            <stop offset="100%" stop-color="rgba(0,229,200,0)"/>
          </linearGradient>
        </defs>
      </svg>
      <div v-else class="py-12 text-center text-sm" style="color: var(--text-muted);" data-testid="spend-spark-empty">
        No spend recorded in this period yet.
      </div>

      <div v-if="hoverIdx >= 0" class="mt-2 text-xs mono" style="color: var(--text-secondary);" data-testid="spend-spark-hover">
        <span style="color: var(--accent);">{{ series[hoverIdx]?.date }}</span>
        · ${{ formatAmount(series[hoverIdx]?.amount) }}
      </div>
    </section>

    <!-- Breakdown + heatmap -->
    <section class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
      <!-- Per-group horizontal bars -->
      <div class="sn-card p-5" data-testid="spend-groups">
        <div class="flex items-end justify-between mb-3">
          <div>
            <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Breakdown</div>
            <h3 class="font-heading font-semibold text-[15px] mt-0.5" style="color: var(--text-primary);">
              By {{ groupBy }}
            </h3>
          </div>
          <span class="sn-pill">{{ groups.length }} {{ groups.length === 1 ? 'group' : 'groups' }}</span>
        </div>

        <svg
          v-if="groups.length"
          :viewBox="`0 0 ${barGeom.w} ${barGeom.h}`"
          class="w-full"
          role="img"
          :aria-label="`Spend by ${groupBy}`"
          data-testid="spend-groups-svg"
        >
          <g v-for="(g, i) in groups" :key="g.key || g.label" :data-testid="`spend-group-row-${i}`">
            <!-- Label -->
            <text
              :x="barGeom.padX"
              :y="rowY(i) + barGeom.barH - 5"
              font-size="10"
              font-family="JetBrains Mono"
              fill="var(--text-muted)"
            >{{ truncate(g.label, 16) }}</text>
            <!-- Track -->
            <rect
              :x="barGeom.labelW"
              :y="rowY(i)"
              :width="barGeom.barMax"
              :height="barGeom.barH"
              rx="3"
              fill="rgba(255,255,255,0.04)"
            />
            <!-- Bar -->
            <rect
              :x="barGeom.labelW"
              :y="rowY(i)"
              :width="groupBarWidth(g)"
              :height="barGeom.barH"
              rx="3"
              fill="var(--accent)"
              :fill-opacity="(0.45 + 0.55 * (maxGroupAmount ? g.amount / maxGroupAmount : 0)).toFixed(2)"
              :data-testid="`spend-group-bar-${i}`"
            />
            <!-- Amount -->
            <text
              :x="barGeom.w - barGeom.padX"
              :y="rowY(i) + barGeom.barH - 5"
              text-anchor="end"
              font-size="10"
              font-family="JetBrains Mono"
              class="mono"
              fill="var(--text-secondary)"
              :data-testid="`spend-group-amount-${i}`"
            >{{ formatAmount(g.amount) }}</text>
          </g>
        </svg>
        <div v-else class="py-10 text-center text-sm" style="color: var(--text-muted);" data-testid="spend-groups-empty">
          Nothing to break down yet.
        </div>
      </div>

      <!-- Month heatmap -->
      <div class="sn-card p-5" data-testid="spend-heatmap">
        <div class="flex items-end justify-between mb-3">
          <div>
            <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Intensity</div>
            <h3 class="font-heading font-semibold text-[15px] mt-0.5" style="color: var(--text-primary);">Spend by month</h3>
          </div>
          <div class="flex items-center gap-2 text-[10px] mono" style="color: var(--text-muted);">
            low
            <span v-for="b in HEAT_LEGEND" :key="b" class="w-3 h-3 rounded-sm" :style="`background: ${heatColor(b)};`"></span>
            high
          </div>
        </div>

        <div
          v-if="months.length"
          class="grid gap-1.5"
          :style="`grid-template-columns: repeat(${Math.min(months.length, 6)}, minmax(0, 1fr));`"
        >
          <div
            v-for="(m, i) in months"
            :key="m.month"
            class="rounded-md p-2 cursor-default"
            :style="`background: ${heatColor(maxMonthAmount ? m.amount / maxMonthAmount : 0)};`"
            :title="`${m.month} · $${formatAmount(m.amount)}`"
            :data-testid="`spend-heat-${i}`"
          >
            <div class="text-[10px] mono" style="color: var(--text-muted);">{{ monthLabel(m.month) }}</div>
            <div class="text-xs mono mt-0.5" style="color: var(--text-primary);">{{ formatShort(m.amount) }}</div>
          </div>
        </div>
        <div v-else class="py-10 text-center text-sm" style="color: var(--text-muted);" data-testid="spend-heatmap-empty">
          No monthly data yet.
        </div>

        <p class="mt-3 text-xs" style="color: var(--text-muted);">
          Each cell = one month. Colour scales linearly across the window.
        </p>
      </div>
    </section>

    <!-- Budget vs actual -->
    <section class="sn-card p-5" data-testid="spend-budgets">
      <div class="flex items-end justify-between mb-2">
        <div>
          <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Burn</div>
          <h3 class="font-heading font-semibold text-[15px] mt-0.5" style="color: var(--text-primary);">Budget vs actual</h3>
        </div>
        <span v-if="overBudgetCount" class="sn-pill sn-pill-danger" data-testid="spend-over-budget-count">
          {{ overBudgetCount }} over
        </span>
      </div>

      <div v-if="budgets.length" class="divide-y" style="border-color: var(--divider);">
        <BudgetBar
          v-for="b in budgets"
          :key="b.label"
          :label="b.label"
          :budget="b.budget"
          :actual="b.actual"
        />
      </div>
      <div v-else class="py-8 text-center text-sm" style="color: var(--text-muted);" data-testid="spend-budgets-empty">
        No budgets configured for this period.
      </div>
    </section>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAccountingStore } from '../../../stores/accounting.js'
import BudgetBar from '../../../components/financial/BudgetBar.vue'

const accountingStore = useAccountingStore()

const GROUPS = [
  { label: 'Category', value: 'category' },
  { label: 'Vendor', value: 'vendor' },
]
const groupBy = ref('category')
const period = ref('90d')
const hoverIdx = ref(-1)

const periodLabel = computed(() => ({
  '30d': 'last 30 days',
  '90d': 'last 90 days',
  '12m': 'last 12 months',
}[period.value] || period.value))

// ── Summary slices (all defensive — amounts are decimal strings) ───
const series = computed(() =>
  (accountingStore.spendSummary?.series || []).map((p) => ({
    date: p.date,
    amount: Number(p.amount ?? p.total ?? 0),
  }))
)

const groups = computed(() =>
  (accountingStore.spendSummary?.groups || [])
    .map((g) => ({
      key: g.key ?? g.id,
      label: g.label ?? g.name ?? g.key ?? '—',
      amount: Number(g.amount ?? g.total ?? 0),
    }))
    .sort((a, b) => b.amount - a.amount)
    .slice(0, 10)
)

const months = computed(() =>
  (accountingStore.spendSummary?.months || []).map((m) => ({
    month: m.month,
    amount: Number(m.amount ?? m.total ?? 0),
  }))
)

const budgets = computed(() =>
  (accountingStore.spendSummary?.budgets || []).map((b) => ({
    label: b.label ?? b.name ?? '—',
    budget: Number(b.budget ?? 0),
    actual: Number(b.actual ?? b.spent ?? 0),
  }))
)

const totalSpend = computed(() => {
  const declared = accountingStore.spendSummary?.total
  if (declared != null) return Number(declared)
  return series.value.reduce((s, p) => s + p.amount, 0)
})

const overBudgetCount = computed(() =>
  budgets.value.filter((b) => b.budget > 0 && b.actual > b.budget).length
)

// ── Sparkline geometry (Usage.vue conventions) ─────────────────────
const spark = computed(() => {
  const w = 1100, h = 220
  const padX = 36, padY = 18
  if (!series.value.length) {
    return { w, h, padX, padY, points: [], linePath: '', areaPath: '', lastX: 0, lastY: 0, gridY: [], min: 0, max: 0 }
  }
  const amounts = series.value.map((p) => p.amount)
  const min = 0
  const max = Math.max(...amounts) * 1.1 || 1
  const stepX = (w - padX * 2) / Math.max(1, series.value.length - 1)
  const points = series.value.map((p, i) => {
    const x = padX + i * stepX
    const y = padY + (h - padY * 2) * (1 - (p.amount - min) / (max - min))
    return { x, y, amount: p.amount }
  })
  const linePath = points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x.toFixed(2)},${p.y.toFixed(2)}`).join(' ')
  const areaPath = `${linePath} L${points[points.length - 1].x.toFixed(2)},${h - padY} L${points[0].x.toFixed(2)},${h - padY} Z`
  const last = points[points.length - 1]
  const gridY = [0, 0.25, 0.5, 0.75, 1].map((t) => padY + (h - padY * 2) * t)
  return { w, h, padX, padY, points, linePath, areaPath, lastX: last.x, lastY: last.y, gridY, min, max }
})

const hoverX = computed(() => spark.value.points[hoverIdx.value]?.x || 0)
const hoverY = computed(() => spark.value.points[hoverIdx.value]?.y || 0)

function onSparkHover(e) {
  const svg = e.currentTarget.ownerSVGElement
  const rect = svg.getBoundingClientRect()
  const ratio = spark.value.w / rect.width
  const xInSvg = (e.clientX - rect.left) * ratio
  const stepX = (spark.value.w - spark.value.padX * 2) / Math.max(1, series.value.length - 1)
  const idx = Math.round((xInSvg - spark.value.padX) / stepX)
  hoverIdx.value = Math.max(0, Math.min(series.value.length - 1, idx))
}

// ── Per-group bar geometry (AgingWidget conventions) ───────────────
const barGeom = computed(() => {
  const w = 520
  const padX = 4
  const labelW = 128
  const amountW = 88
  const rowH = 26
  const barH = 16
  return {
    w, padX, labelW, amountW, rowH, barH,
    barMax: w - labelW - amountW,
    h: Math.max(1, groups.value.length) * rowH,
  }
})

function rowY(i) {
  return i * barGeom.value.rowH + (barGeom.value.rowH - barGeom.value.barH) / 2
}

const maxGroupAmount = computed(() =>
  Math.max(...groups.value.map((g) => g.amount), 0)
)

function groupBarWidth(g) {
  if (!maxGroupAmount.value) return 0
  return Math.round((g.amount / maxGroupAmount.value) * barGeom.value.barMax * 100) / 100
}

// ── Heatmap ────────────────────────────────────────────────────────
const HEAT_LEGEND = [0.05, 0.25, 0.5, 0.75, 1.0]

const maxMonthAmount = computed(() =>
  Math.max(...months.value.map((m) => m.amount), 0)
)

function heatColor(norm) {
  if (norm == null) return 'transparent'
  if (norm <= 0.04) return 'rgba(255,255,255,0.05)'
  const a = 0.12 + norm * 0.5
  return `rgba(0,229,200,${a.toFixed(2)})`
}

function monthLabel(ym) {
  if (!ym) return '—'
  const [y, m] = String(ym).split('-')
  const names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
  const name = names[Number(m) - 1]
  return name ? `${name} ${String(y).slice(2)}` : ym
}

// ── Helpers ────────────────────────────────────────────────────────
function formatAmount(v) {
  return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })
}

function formatShort(v) {
  const n = Number(v || 0)
  if (n >= 1e6) return `$${(n / 1e6).toFixed(1)}M`
  if (n >= 1e3) return `$${(n / 1e3).toFixed(1)}k`
  return `$${n.toFixed(0)}`
}

function truncate(s, n) {
  const str = String(s || '')
  return str.length > n ? `${str.slice(0, n - 1)}…` : str
}

// ── Data load ──────────────────────────────────────────────────────
function setGroupBy(value) {
  if (groupBy.value === value) return
  groupBy.value = value
  load()
}

function load() {
  hoverIdx.value = -1
  accountingStore.fetchSpendSummary({ group_by: groupBy.value, period: period.value })
}

onMounted(load)
</script>

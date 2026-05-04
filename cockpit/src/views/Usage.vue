<template>
  <div class="px-8 py-6 max-w-[1500px] mx-auto space-y-6">
    <header class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-[26px] font-heading font-semibold tracking-tight" style="color: var(--text-primary);">Usage &amp; Cost</h1>
        <p class="text-sm mt-1" style="color: var(--text-muted);">
          30-day trend, daily intensity heatmap, anomalies and live budget caps.
        </p>
      </div>
      <button class="sn-btn" data-testid="usage-refresh" @click="loadAll">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8 8 0 004.582 9M20 20v-5h-.581m0 0a8 8 0 01-15.357-2"/>
        </svg>
        Refresh
      </button>
    </header>

    <!-- Budget cards -->
    <section class="grid grid-cols-1 md:grid-cols-3 gap-3" data-testid="usage-stats">
      <div class="sn-card p-4">
        <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Today</div>
        <div class="mt-1 flex items-baseline gap-2">
          <span class="text-2xl font-heading font-semibold mono" :style="todayColor">${{ today.toFixed(2) }}</span>
          <span class="text-xs mono" style="color: var(--text-muted);">/ ${{ dailyCap.toFixed(0) }}</span>
        </div>
        <div class="mt-2 h-1.5 rounded-full overflow-hidden" style="background: var(--bg-elevated);">
          <div class="h-full rounded-full transition-all"
               :style="`width:${dailyPct}%; background:${dailyPct > 80 ? 'var(--warn)' : 'var(--accent)'};`"></div>
        </div>
        <div class="mt-2 text-xs" style="color: var(--text-muted);">{{ dailyPct }}% of daily cap</div>
      </div>

      <div class="sn-card p-4">
        <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Month to date</div>
        <div class="mt-1 flex items-baseline gap-2">
          <span class="text-2xl font-heading font-semibold mono" style="color: var(--text-primary);">${{ monthly.toFixed(0) }}</span>
          <span class="text-xs mono" style="color: var(--text-muted);">/ ${{ monthlyCap.toFixed(0) }}</span>
        </div>
        <div class="mt-2 h-1.5 rounded-full overflow-hidden" style="background: var(--bg-elevated);">
          <div class="h-full rounded-full transition-all"
               :style="`width:${monthlyPct}%; background:${monthlyPct > 80 ? 'var(--warn)' : 'var(--accent)'};`"></div>
        </div>
        <div class="mt-2 text-xs" style="color: var(--text-muted);">Projected: ${{ projection.toFixed(0) }}</div>
      </div>

      <div class="sn-card p-4">
        <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Throughput</div>
        <div class="mt-1 flex items-baseline gap-2">
          <span class="text-2xl font-heading font-semibold mono" style="color: var(--text-primary);">{{ formatNum(tokensToday) }}</span>
          <span class="text-xs mono" style="color: var(--text-muted);">tokens · today</span>
        </div>
        <div class="mt-2 text-xs" style="color: var(--text-muted);">{{ formatNum(requestsToday) }} requests</div>
      </div>
    </section>

    <!-- Sparkline -->
    <section class="sn-card p-5" data-testid="usage-sparkline">
      <div class="flex items-end justify-between mb-3">
        <div>
          <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">30-day cost trend</div>
          <h3 class="font-heading font-semibold text-[15px] mt-0.5" style="color: var(--text-primary);">
            ${{ totalSeries.toFixed(0) }} <span class="text-xs mono" style="color: var(--text-muted);">last 30d</span>
          </h3>
        </div>
        <div class="flex items-center gap-3 text-[11px]" style="color: var(--text-muted);">
          <span class="flex items-center gap-1.5">
            <span class="w-2 h-0.5" style="background: var(--accent);"></span> cost
          </span>
          <span class="flex items-center gap-1.5">
            <span class="w-2 h-0.5" style="background: var(--amber); opacity:0.6;"></span> projection
          </span>
        </div>
      </div>

      <!-- SVG sparkline -->
      <svg
        v-if="series.length"
        :viewBox="`0 0 ${spark.w} ${spark.h}`"
        class="w-full"
        :style="`height: ${spark.h}px;`"
        role="img"
        aria-label="30-day cost sparkline"
      >
        <!-- Grid -->
        <g v-for="(y, i) in spark.gridY" :key="`gy-${i}`">
          <line :x1="spark.padX" :x2="spark.w - spark.padX" :y1="y" :y2="y"
                :stroke="i === spark.gridY.length - 1 ? 'rgba(255,255,255,0.10)' : 'rgba(255,255,255,0.04)'"
                stroke-width="1" />
        </g>
        <!-- Area fill -->
        <path :d="spark.areaPath" fill="url(#sparkGrad)" />
        <!-- Line -->
        <path :d="spark.linePath" fill="none" stroke="var(--accent)" stroke-width="1.6" />
        <!-- Last point -->
        <circle :cx="spark.lastX" :cy="spark.lastY" r="3" fill="var(--accent)" />
        <circle :cx="spark.lastX" :cy="spark.lastY" r="6" fill="rgba(0,229,200,0.20)" />
        <!-- Hover -->
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
          <linearGradient id="sparkGrad" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="rgba(0,229,200,0.28)"/>
            <stop offset="100%" stop-color="rgba(0,229,200,0)"/>
          </linearGradient>
        </defs>
      </svg>
      <div v-else class="py-12 text-center text-sm" style="color: var(--text-muted);">No usage data yet.</div>

      <div v-if="hoverIdx >= 0" class="mt-2 text-xs mono" style="color: var(--text-secondary);">
        <span style="color: var(--accent);">{{ series[hoverIdx]?.date }}</span>
        · cost ${{ (series[hoverIdx]?.cost || 0).toFixed(2) }}
        · {{ formatNum(series[hoverIdx]?.requests) }} req
        · {{ formatNum(series[hoverIdx]?.tokens) }} tok
      </div>
    </section>

    <!-- Heatmap + Anomalies side-by-side -->
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <!-- Heatmap -->
      <div class="sn-card p-5 lg:col-span-2" data-testid="usage-heatmap">
        <div class="flex items-end justify-between mb-3">
          <div>
            <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Daily intensity</div>
            <h3 class="font-heading font-semibold text-[15px] mt-0.5" style="color: var(--text-primary);">
              Cost by day-of-week × week
            </h3>
          </div>
          <div class="flex items-center gap-2 text-[10px] mono" style="color: var(--text-muted);">
            low
            <span v-for="b in heatLegend" :key="b" class="w-3 h-3 rounded-sm"
                  :style="`background: ${heatColor(b)};`"></span>
            high
          </div>
        </div>

        <div class="flex">
          <div class="flex flex-col gap-1 pr-2 text-[10px] mono" style="color: var(--text-muted);">
            <span v-for="d in DOWS" :key="d" class="h-4 flex items-center">{{ d }}</span>
          </div>
          <div class="flex-1 grid gap-1" :style="`grid-template-columns: repeat(${weeksCount}, minmax(0, 1fr));`">
            <div
              v-for="cell in heatCells" :key="cell.key"
              class="h-4 rounded-sm cursor-default"
              :style="`background: ${heatColor(cell.norm)};`"
              :title="cell.title"
              :data-testid="`heat-${cell.key}`"
            ></div>
          </div>
        </div>

        <p class="mt-3 text-xs" style="color: var(--text-muted);">
          Each cell = one day. Colour scales linearly across the 30-day window.
        </p>
      </div>

      <!-- Anomalies -->
      <div class="sn-card p-5 flex flex-col" data-testid="usage-anomalies">
        <div class="flex items-end justify-between mb-3">
          <div>
            <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Recent</div>
            <h3 class="font-heading font-semibold text-[15px] mt-0.5" style="color: var(--text-primary);">Anomalies</h3>
          </div>
          <span class="sn-pill" :class="anomalies.length ? 'sn-pill-warn' : 'sn-pill-success'">
            {{ anomalies.length }} {{ anomalies.length === 1 ? 'event' : 'events' }}
          </span>
        </div>
        <ul v-if="anomalies.length" class="flex-1 overflow-y-auto divide-y space-y-0" style="border-color: var(--divider);">
          <li v-for="a in anomalies" :key="a.id" class="py-2.5 flex items-start gap-2.5">
            <span class="mt-1 w-1.5 h-1.5 rounded-full shrink-0"
                  :style="`background: ${a.severity === 'warn' ? 'var(--warn)' : 'var(--accent)'};`"></span>
            <div class="min-w-0 flex-1">
              <div class="text-sm" style="color: var(--text-primary);">{{ a.msg }}</div>
              <div class="text-[11px] mt-0.5" style="color: var(--text-muted);">{{ timeAgo(a.date) }} · {{ a.severity }}</div>
            </div>
          </li>
        </ul>
        <div v-else class="flex-1 grid place-items-center text-sm py-10" style="color: var(--text-muted);">
          No anomalies detected.
        </div>
      </div>
    </section>

    <!-- Budget settings -->
    <section class="sn-card p-5" data-testid="usage-budget-settings">
      <div class="flex items-end justify-between mb-4">
        <div>
          <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Budget settings</div>
          <h3 class="font-heading font-semibold text-[15px] mt-0.5" style="color: var(--text-primary);">Caps &amp; alerts</h3>
        </div>
        <span class="sn-pill" :class="updateMessage?.type === 'success' ? 'sn-pill-success' : updateMessage?.type === 'error' ? 'sn-pill-danger' : ''">
          <span v-if="updateMessage">{{ updateMessage.text }}</span>
          <span v-else>Saved automatically</span>
        </span>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-2xl">
        <div>
          <label class="block mb-1">Daily cap (USD)</label>
          <input v-model.number="form.dailyLimit" type="number" min="0" step="1" data-testid="budget-daily" />
        </div>
        <div>
          <label class="block mb-1">Monthly cap (USD)</label>
          <input v-model.number="form.monthlyLimit" type="number" min="0" step="10" data-testid="budget-monthly" />
        </div>
        <div>
          <label class="block mb-1">Alert threshold (%)</label>
          <input v-model.number="form.alertThreshold" type="number" min="0" max="100" step="5" data-testid="budget-threshold" />
        </div>
        <div>
          <label class="block mb-1">Action at limit</label>
          <select v-model="form.actionAtLimit" data-testid="budget-action">
            <option value="degrade">Degrade — fall back to cheaper models</option>
            <option value="block">Block — pause execution</option>
            <option value="warn">Warn only</option>
          </select>
        </div>
      </div>

      <div class="mt-5 flex justify-end">
        <button class="sn-btn-primary" :disabled="isUpdating" data-testid="budget-save" @click="save">
          {{ isUpdating ? 'Saving…' : 'Save changes' }}
        </button>
      </div>
    </section>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useUsageStore } from '../stores/usage.js'
import api from '../services/api.js'

const usageStore = useUsageStore()

const series = ref([])
const anomalies = ref([])
const isUpdating = ref(false)
const updateMessage = ref(null)
const hoverIdx = ref(-1)

const form = reactive({
  dailyLimit: 95,
  monthlyLimit: 1800,
  alertThreshold: 80,
  actionAtLimit: 'degrade',
})

const today = computed(() => usageStore.currentSpend?.daily ?? 0)
const monthly = computed(() => usageStore.currentSpend?.monthly ?? 0)
const dailyCap = computed(() => usageStore.budget?.daily_limit ?? 95)
const monthlyCap = computed(() => usageStore.budget?.monthly_limit ?? 1800)
const dailyPct = computed(() => Math.min(100, Math.round((today.value / dailyCap.value) * 100)))
const monthlyPct = computed(() => Math.min(100, Math.round((monthly.value / monthlyCap.value) * 100)))
const projection = computed(() => monthly.value * (30 / Math.max(1, new Date().getDate())))
const tokensToday = computed(() => usageStore.currentSpend?.tokens_today ?? 0)
const requestsToday = computed(() => usageStore.currentSpend?.requests_today ?? 0)
const totalSeries = computed(() => series.value.reduce((s, p) => s + (p.cost || 0), 0))

const todayColor = computed(() => dailyPct.value > 90
  ? 'color: var(--danger);'
  : dailyPct.value > 75
    ? 'color: var(--warn);'
    : 'color: var(--text-primary);')

// ── Sparkline geometry ─────────────────────────────────────────────
const spark = computed(() => {
  const w = 1100, h = 220
  const padX = 36, padY = 18
  if (!series.value.length) {
    return { w, h, padX, padY, points: [], linePath: '', areaPath: '', lastX: 0, lastY: 0, gridY: [], min: 0, max: 0 }
  }
  const costs = series.value.map((p) => p.cost || 0)
  const min = 0
  const max = Math.max(...costs) * 1.1 || 1
  const stepX = (w - padX * 2) / Math.max(1, series.value.length - 1)
  const points = series.value.map((p, i) => {
    const x = padX + i * stepX
    const y = padY + (h - padY * 2) * (1 - (p.cost - min) / (max - min))
    return { x, y, cost: p.cost }
  })
  const linePath = points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x.toFixed(2)},${p.y.toFixed(2)}`).join(' ')
  const areaPath = `${linePath} L${points[points.length - 1].x.toFixed(2)},${h - padY} L${points[0].x.toFixed(2)},${h - padY} Z`
  const last = points[points.length - 1]
  // 4 grid lines
  const gridY = [0, 0.25, 0.5, 0.75, 1].map((t) => padY + (h - padY * 2) * t)
  return { w, h, padX, padY, points, linePath, areaPath, lastX: last.x, lastY: last.y, gridY, min, max }
})

const hoverX = computed(() => spark.value.points[hoverIdx.value]?.x || 0)
const hoverY = computed(() => spark.value.points[hoverIdx.value]?.y || 0)

function onSparkHover(e) {
  const svg = e.currentTarget.ownerSVGElement
  const rect = svg.getBoundingClientRect()
  const ratio = (spark.value.w) / rect.width
  const xInSvg = (e.clientX - rect.left) * ratio
  const stepX = (spark.value.w - spark.value.padX * 2) / Math.max(1, series.value.length - 1)
  const idx = Math.round((xInSvg - spark.value.padX) / stepX)
  hoverIdx.value = Math.max(0, Math.min(series.value.length - 1, idx))
}

// ── Heatmap ────────────────────────────────────────────────────────
const DOWS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

const heatCells = computed(() => {
  if (!series.value.length) return []
  const max = Math.max(...series.value.map((p) => p.cost || 0)) || 1
  // Place each day in a (dow, weekIdx) cell, ordered by date asc.
  const startDate = new Date(series.value[0].date + 'T00:00:00Z')
  const startDow = startDate.getUTCDay()
  return series.value.map((p, i) => {
    const slot = startDow + i
    const dow = slot % 7
    const week = Math.floor(slot / 7)
    return {
      key: `${week}-${dow}`,
      week, dow,
      norm: (p.cost || 0) / max,
      title: `${p.date} · $${(p.cost || 0).toFixed(2)} · ${formatNum(p.requests)} req`,
    }
  })
})

const weeksCount = computed(() => {
  const cells = heatCells.value
  if (!cells.length) return 6
  return Math.max(...cells.map((c) => c.week)) + 1
})

const heatLegend = computed(() => [0.05, 0.25, 0.5, 0.75, 1.0])

function heatColor(norm) {
  if (norm == null) return 'transparent'
  if (norm <= 0.04) return 'rgba(255,255,255,0.05)'
  // Cyan ramp from accent at 0.15 alpha → 1.0 alpha
  const a = 0.15 + norm * 0.85
  return `rgba(0,229,200,${a.toFixed(2)})`
}

// ── Helpers ────────────────────────────────────────────────────────
function timeAgo(ts) {
  if (!ts) return ''
  const s = Math.max(1, Math.floor((Date.now() - new Date(ts).getTime()) / 1000))
  if (s < 60) return `${s}s ago`
  if (s < 3600) return `${Math.floor(s / 60)}m ago`
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`
  return `${Math.floor(s / 86400)}d ago`
}

function formatNum(n) {
  if (n == null) return '0'
  if (n >= 1e6) return (n / 1e6).toFixed(2) + 'M'
  if (n >= 1e3) return (n / 1e3).toFixed(1) + 'k'
  return Number(n).toLocaleString()
}

// ── Data load ──────────────────────────────────────────────────────
async function loadAll() {
  await Promise.all([
    usageStore.fetchBudget(),
    usageStore.fetchCurrentSpend(),
    loadSeries(),
    loadAnomalies(),
  ])
  if (usageStore.budget) {
    form.dailyLimit = usageStore.budget.daily_limit
    form.monthlyLimit = usageStore.budget.monthly_limit
    form.alertThreshold = (usageStore.budget.alert_threshold || 0.8) * 100
    form.actionAtLimit = usageStore.budget.action_at_limit || 'degrade'
  }
}

async function loadSeries() {
  try {
    const { data } = await api.get('/api/usage/series')
    series.value = data?.data || []
  } catch (err) {
    series.value = []
  }
}

async function loadAnomalies() {
  try {
    const { data } = await api.get('/api/usage/anomalies')
    anomalies.value = data?.data || []
  } catch (err) {
    anomalies.value = []
  }
}

async function save() {
  isUpdating.value = true
  updateMessage.value = null
  const result = await usageStore.updateBudget({
    daily_limit: form.dailyLimit,
    monthly_limit: form.monthlyLimit,
    alert_threshold: form.alertThreshold / 100,
    action_at_limit: form.actionAtLimit,
  })
  updateMessage.value = result.success
    ? { type: 'success', text: 'Saved' }
    : { type: 'error', text: result.error || 'Save failed' }
  isUpdating.value = false
  setTimeout(() => { updateMessage.value = null }, 4500)
}

onMounted(loadAll)
</script>

<template>
  <div class="px-8 py-6 max-w-[1500px] mx-auto space-y-6">
    <!-- Header -->
    <header class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-[26px] font-heading font-semibold tracking-tight" style="color: var(--text-primary);">Copy Lab</h1>
        <p class="text-sm mt-1" style="color: var(--text-muted);">
          Toggle Atlas-generated copy per surface, and watch arm uplift across active bandit experiments.
        </p>
      </div>
      <button class="sn-btn" data-testid="copy-refresh" @click="loadAll">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8 8 0 004.582 9M20 20v-5h-.581m0 0a8 8 0 01-15.357-2"/>
        </svg>
        Refresh
      </button>
    </header>

    <!-- Surfaces -->
    <section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3" data-testid="copy-surfaces">
      <article
        v-for="s in surfaces" :key="s.key"
        class="sn-card p-4"
      >
        <div class="flex items-center justify-between mb-1.5">
          <h2 class="font-heading font-semibold text-sm" style="color: var(--text-primary);">{{ s.label }}</h2>
          <span class="sn-pill text-[10px]" :class="state[s.key] === 'fallback' ? 'sn-pill-warn' : 'sn-pill-success'">
            {{ state[s.key] === 'fallback' ? 'Fallback' : 'Live' }}
          </span>
        </div>
        <p class="text-xs mb-3" style="color: var(--text-muted);">{{ s.description }}</p>
        <div class="flex items-center gap-2">
          <button
            class="sn-btn flex-1 justify-center text-xs"
            :style="state[s.key] !== 'fallback'
              ? 'background: var(--accent-weak); color: var(--accent); border-color: rgba(0,229,200,0.30);'
              : ''"
            :data-testid="`copy-${s.key}-on`"
            @click="setSurface(s.key, 'on')"
          >Atlas variant</button>
          <button
            class="sn-btn flex-1 justify-center text-xs"
            :style="state[s.key] === 'fallback'
              ? 'background: var(--bg-elevated); color: var(--text-primary); border-color: var(--border-active);'
              : ''"
            :data-testid="`copy-${s.key}-fallback`"
            @click="setSurface(s.key, 'fallback')"
          >Fallback</button>
        </div>
      </article>
    </section>

    <!-- Experiments + uplift histogram -->
    <section class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-5">
      <article class="sn-card p-5" data-testid="copy-experiments">
        <header class="flex items-end justify-between mb-4">
          <div>
            <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Active experiment</div>
            <h3 class="font-heading font-semibold text-[15px] mt-0.5" style="color: var(--text-primary);">
              Headline copy · arm uplift vs. control
            </h3>
          </div>
          <div class="flex items-center gap-3 text-[11px]" style="color: var(--text-muted);">
            <span class="flex items-center gap-1.5">
              <span class="w-2.5 h-2.5 rounded-sm" style="background: var(--accent);"></span> winning
            </span>
            <span class="flex items-center gap-1.5">
              <span class="w-2.5 h-2.5 rounded-sm" style="background: rgba(150,161,178,0.5);"></span> control
            </span>
          </div>
        </header>

        <!-- Histogram (SVG) -->
        <svg
          v-if="arms.length"
          :viewBox="`0 0 ${chart.w} ${chart.h}`"
          class="w-full"
          :style="`height: ${chart.h}px;`"
          role="img"
          aria-label="Arm uplift histogram"
        >
          <!-- 0% baseline -->
          <line :x1="chart.padX" :x2="chart.w - chart.padX" :y1="chart.zeroY" :y2="chart.zeroY"
                stroke="rgba(255,255,255,0.16)" stroke-width="1" stroke-dasharray="3 3" />
          <!-- Bars -->
          <g v-for="(a, i) in arms" :key="a.id">
            <rect
              :x="chart.padX + i * chart.bw + 8"
              :y="a.upliftPct >= 0 ? chart.zeroY - barLen(a.upliftPct) : chart.zeroY"
              :width="chart.bw - 16"
              :height="barLen(a.upliftPct)"
              :fill="barFill(a)"
              :data-testid="`copy-arm-bar-${a.id}`"
              rx="3"
            />
            <!-- Value label -->
            <text
              :x="chart.padX + i * chart.bw + chart.bw / 2"
              :y="a.upliftPct >= 0 ? chart.zeroY - barLen(a.upliftPct) - 6 : chart.zeroY + barLen(a.upliftPct) + 14"
              text-anchor="middle"
              :fill="a.upliftPct >= 0 ? 'var(--accent)' : 'var(--danger)'"
              font-size="11"
              font-family="JetBrains Mono"
            >{{ a.upliftPct >= 0 ? '+' : '' }}{{ a.upliftPct.toFixed(1) }}%</text>
            <!-- Arm label -->
            <text
              :x="chart.padX + i * chart.bw + chart.bw / 2"
              :y="chart.h - 18"
              text-anchor="middle"
              fill="var(--text-secondary)"
              font-size="11"
              font-family="JetBrains Mono"
            >{{ a.arm }}</text>
            <!-- Impressions -->
            <text
              :x="chart.padX + i * chart.bw + chart.bw / 2"
              :y="chart.h - 4"
              text-anchor="middle"
              fill="var(--text-muted)"
              font-size="9"
              font-family="JetBrains Mono"
            >{{ formatNum(a.impressions) }} imp</text>
          </g>
          <!-- Y-axis -->
          <text :x="chart.padX - 6" :y="chart.zeroY + 3" text-anchor="end" fill="var(--text-muted)" font-size="9" font-family="JetBrains Mono">0%</text>
          <text :x="chart.padX - 6" :y="chart.padY + 3" text-anchor="end" fill="var(--text-muted)" font-size="9" font-family="JetBrains Mono">+{{ axisMaxLabel }}%</text>
        </svg>
        <div v-else class="py-12 text-center text-sm" style="color: var(--text-muted);">
          No experiments running.
        </div>

        <!-- Arm rows -->
        <ul class="mt-4 divide-y" style="border-color: var(--divider);">
          <li v-for="a in arms" :key="`row-${a.id}`" class="py-2 flex items-center gap-3">
            <span class="w-2 h-2 rounded-sm shrink-0" :style="`background: ${barFill(a)};`"></span>
            <code class="mono text-[11px] w-20 shrink-0" style="color: var(--text-muted);">{{ a.arm }}</code>
            <span class="flex-1 truncate text-sm" style="color: var(--text-primary);">{{ a.text }}</span>
            <span class="mono text-xs w-16 text-right" style="color: var(--text-secondary);">
              {{ (a.ctr * 100).toFixed(1) }}%
            </span>
            <span class="sn-pill text-[10px]"
                  :class="a.winning ? 'sn-pill-success' : a.upliftPct < 0 ? 'sn-pill-danger' : ''">
              {{ a.winning ? 'winning' : a.upliftPct >= 0 ? '+' + a.upliftPct.toFixed(1) + '%' : a.upliftPct.toFixed(1) + '%' }}
            </span>
          </li>
        </ul>
      </article>

      <!-- Methodology -->
      <aside class="sn-card p-5 text-sm space-y-3" style="color: var(--text-secondary);">
        <div>
          <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Method</div>
          <h3 class="font-heading font-semibold text-[15px] mt-0.5" style="color: var(--text-primary);">Bandit · Thompson</h3>
        </div>
        <p>
          Uplift is calculated against the <code class="mono" style="color: var(--accent);">control</code>
          arm's CTR. Cells turn cyan above zero, red below, and the winning
          arm is the highest CTR with statistical significance.
        </p>
        <div class="grid grid-cols-2 gap-2 mt-2">
          <div class="sn-card p-3">
            <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Total impressions</div>
            <div class="mono text-base mt-0.5" style="color: var(--text-primary);">{{ formatNum(totalImpressions) }}</div>
          </div>
          <div class="sn-card p-3">
            <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Best uplift</div>
            <div class="mono text-base mt-0.5" style="color: var(--accent);">+{{ bestUplift.toFixed(1) }}%</div>
          </div>
        </div>
      </aside>
    </section>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../../services/api.js'

const surfaces = [
  { key: 'empty_state',   label: 'Empty state',   description: 'Shown when a user first lands on a page with no data.' },
  { key: 'banner',        label: 'Banner',        description: 'Short nudges at the top of dashboards.' },
  { key: 'modal',         label: 'Modal',         description: 'Decision moments that need explicit acceptance.' },
  { key: 'tooltip',       label: 'Tooltip',       description: 'Micro-context on labels and column headers.' },
  { key: 'success_state', label: 'Success state', description: 'Reinforces progress after a meaningful outcome.' },
  { key: 'error_state',   label: 'Error state',   description: 'Preserves trust when something goes wrong.' },
]

const state = ref({})
const experiments = ref([])

const arms = computed(() => {
  if (!experiments.value.length) return []
  const control = experiments.value.find((a) => a.arm === 'control') || experiments.value[0]
  const baseCtr = control?.ctr || 0.0001
  return experiments.value.map((a) => ({
    ...a,
    upliftPct: ((a.ctr - baseCtr) / baseCtr) * 100,
  }))
})

const totalImpressions = computed(() => arms.value.reduce((s, a) => s + (a.impressions || 0), 0))
const bestUplift = computed(() => arms.value.reduce((m, a) => Math.max(m, a.upliftPct), 0))

// ── Chart geometry ─────────────────────────────────────────────────
const chart = computed(() => {
  const w = 760, h = 240
  const padX = 32, padY = 24
  const n = arms.value.length || 1
  const bw = (w - padX * 2) / n
  const max = Math.max(20, ...arms.value.map((a) => Math.abs(a.upliftPct)))
  const usable = h - padY * 2 - 28 // reserve bottom area for labels
  return { w, h, padX, padY, bw, max, usable, zeroY: padY + usable * 0.55 }
})

const axisMaxLabel = computed(() => {
  return Math.ceil(chart.value.max / 5) * 5
})

function barLen(v) {
  const c = chart.value
  if (c.max === 0) return 0
  // cap at usable*0.55 above and usable*0.45 below
  const maxUp   = c.usable * 0.55
  const maxDown = c.usable * 0.45
  if (v >= 0) return Math.min(maxUp,   (v / axisMaxLabel.value) * maxUp)
  return       Math.min(maxDown, (Math.abs(v) / axisMaxLabel.value) * maxDown)
}

function barFill(a) {
  if (a.upliftPct < 0) return 'var(--danger)'
  if (a.winning)        return 'var(--accent)'
  return 'rgba(150,161,178,0.55)'
}

function formatNum(n) {
  if (!n) return '0'
  if (n >= 1e6) return (n / 1e6).toFixed(2) + 'M'
  if (n >= 1e3) return (n / 1e3).toFixed(1) + 'k'
  return Number(n).toLocaleString()
}

async function loadState() {
  try {
    const { data } = await api.get('/api/admin/copy/state')
    state.value = data?.state || {}
  } catch {
    for (const s of surfaces) state.value[s.key] = 'on'
  }
}

async function loadExperiments() {
  try {
    const { data } = await api.get('/api/admin/copy/experiments')
    experiments.value = data?.data || []
  } catch {
    experiments.value = []
  }
}

async function setSurface(key, value) {
  state.value = { ...state.value, [key]: value }
  try { await api.put('/api/admin/copy/state', { surface: key, value }) } catch { /* optimistic */ }
}

async function loadAll() {
  await Promise.all([loadState(), loadExperiments()])
}

onMounted(loadAll)
</script>

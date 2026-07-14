<template>
  <div class="sn-card p-5 space-y-4" data-testid="ste-simulation-panel">
    <header class="flex items-center justify-between gap-3">
      <div>
        <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">State Engine</div>
        <h3 class="font-heading font-semibold text-[15px] mt-0.5" style="color: var(--text-primary);">Monte Carlo simulation</h3>
      </div>
      <span v-if="status" class="sn-pill text-[10px]"
            :class="status === 'streaming' ? 'sn-pill-accent' : status === 'error' ? 'sn-pill-danger' : 'sn-pill-success'">
        {{ status }}
      </span>
    </header>

    <!-- Inputs -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
      <label class="flex flex-col gap-1">
        <span class="text-[11px]" style="color: var(--text-muted);">Chain</span>
        <select v-model="chain" :disabled="streaming" data-testid="ste-chain">
          <option value="session_lifecycle">session_lifecycle</option>
          <option value="tenant_lifecycle">tenant_lifecycle</option>
        </select>
      </label>
      <label class="flex flex-col gap-1">
        <span class="text-[11px]" style="color: var(--text-muted);">Start state</span>
        <input v-model="startState" type="text" class="mono" :disabled="streaming" data-testid="ste-start-state" />
      </label>
      <label class="flex flex-col gap-1">
        <span class="text-[11px]" style="color: var(--text-muted);">Steps</span>
        <input v-model.number="steps" type="number" min="1" max="50" :disabled="streaming" data-testid="ste-steps" />
      </label>
      <label class="flex flex-col gap-1">
        <span class="text-[11px]" style="color: var(--text-muted);">Runs</span>
        <input v-model.number="runs" type="number" min="10" max="5000" step="100" :disabled="streaming" data-testid="ste-runs" />
      </label>
    </div>

    <!-- Run + status -->
    <div class="flex items-center justify-between gap-3">
      <button
        class="sn-btn-primary"
        :disabled="streaming"
        data-testid="ste-run"
        @click="run"
      >
        <svg v-if="!streaming" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 3l14 9-14 9V3z"/>
        </svg>
        <svg v-else class="animate-spin w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="9" stroke-dasharray="42 22"/>
        </svg>
        {{ streaming ? 'Streaming…' : 'Run simulation' }}
      </button>

      <div class="flex items-center gap-3 text-xs" style="color: var(--text-muted);">
        <span v-if="frameInfo" data-testid="ste-frame-info">frame {{ frameInfo.frame }}/{{ frameInfo.total }}</span>
        <span v-if="durationMs !== null">{{ durationMs }} ms</span>
        <span v-if="error" style="color: var(--danger);" data-testid="ste-error">{{ error }}</span>
      </div>
    </div>

    <!-- Progress bar -->
    <div v-if="streaming || progress > 0" class="h-1 rounded-full overflow-hidden" style="background: var(--bg-elevated);">
      <div class="h-full transition-all duration-150" :style="`width:${(progress * 100).toFixed(0)}%; background: var(--accent);`"></div>
    </div>

    <!-- KPIs (animate as frames arrive) -->
    <div v-if="result" class="grid grid-cols-3 gap-3" data-testid="ste-kpis">
      <div class="sn-card p-3">
        <p class="text-[10px] uppercase tracking-widest font-semibold" style="color: var(--text-muted);">Activation</p>
        <p class="text-xl font-heading font-semibold mono" style="color: var(--success);">
          {{ (result.activation_probability * 100).toFixed(1) }}%
        </p>
        <p v-if="result.confidence_95" class="text-[11px] mono" style="color: var(--text-muted);">
          95% CI [{{ (result.confidence_95[0] * 100).toFixed(1) }}%, {{ (result.confidence_95[1] * 100).toFixed(1) }}%]
        </p>
      </div>
      <div class="sn-card p-3">
        <p class="text-[10px] uppercase tracking-widest font-semibold" style="color: var(--text-muted);">Churn</p>
        <p class="text-xl font-heading font-semibold mono" style="color: var(--danger);">
          {{ (result.churn_probability * 100).toFixed(1) }}%
        </p>
      </div>
      <div class="sn-card p-3">
        <p class="text-[10px] uppercase tracking-widest font-semibold" style="color: var(--text-muted);">Expected value</p>
        <p class="text-xl font-heading font-semibold mono" style="color: var(--accent);">
          {{ result.expected_value >= 0 ? '+' : '' }}{{ (result.expected_value * 100).toFixed(1) }}%
        </p>
      </div>
    </div>

    <!-- Live distribution bars -->
    <div v-if="distEntries.length" class="space-y-1.5" data-testid="ste-distribution">
      <div class="text-[10px] uppercase tracking-widest font-semibold" style="color: var(--text-muted);">End-state distribution</div>
      <div v-for="[state, p] in distEntries" :key="state" class="flex items-center gap-3 text-sm">
        <span class="mono w-24 shrink-0" style="color: var(--text-secondary);">{{ state }}</span>
        <div class="flex-1 h-2 rounded-full overflow-hidden" style="background: var(--bg-elevated);">
          <div class="h-full transition-all duration-150"
               :style="`width:${(p * 100).toFixed(1)}%; background: ${stateColor(state)};`"></div>
        </div>
        <span class="mono text-xs w-14 text-right" style="color: var(--text-primary);">{{ (p * 100).toFixed(1) }}%</span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'

const API_URL = (import.meta.env.VITE_API_URL || '').replace(/\/$/, '')

const chain      = ref('session_lifecycle')
const startState = ref('visitor')
const steps      = ref(10)
const runs       = ref(1000)

const streaming  = ref(false)
const status     = ref('')
const error      = ref('')
const progress   = ref(0)
const frameInfo  = ref(null)
const durationMs = ref(null)
const result     = ref(null)
const distribution = ref({})
let abortCtrl = null

const distEntries = computed(() =>
  Object.entries(distribution.value || {}).sort((a, b) => b[1] - a[1])
)

function stateColor(state) {
  const cool = ['active', 'expansion', 'champion', 'paying']
  const warn = ['trial', 'visitor']
  const danger = ['churned', 'churn_risk']
  if (cool.includes(state))   return 'var(--accent)'
  if (warn.includes(state))   return 'var(--amber)'
  if (danger.includes(state)) return 'var(--danger)'
  return 'rgba(150,161,178,0.7)'
}

async function run() {
  if (streaming.value) return
  reset()
  streaming.value = true
  status.value = 'streaming'
  const started = performance.now()
  abortCtrl = new AbortController()

  const url = `${API_URL}/api/ste/simulate`

  try {
    const resp = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream' },
      body: JSON.stringify({
        chain: chain.value, start_state: startState.value,
        steps: Number(steps.value), runs: Number(runs.value),
      }),
      signal: abortCtrl.signal,
    })

    if (!resp.ok || !resp.body) {
      throw new Error(`HTTP ${resp.status}`)
    }

    const reader = resp.body.getReader()
    const decoder = new TextDecoder('utf-8')
    let buffer = ''
    while (true) {
      const { done, value } = await reader.read()
      if (done) break
      buffer += decoder.decode(value, { stream: true })

      // SSE frames are delimited by a blank line
      let idx
      while ((idx = buffer.indexOf('\n\n')) >= 0) {
        const raw = buffer.slice(0, idx); buffer = buffer.slice(idx + 2)
        const dataLine = raw.split('\n').find((l) => l.startsWith('data: '))
        if (!dataLine) continue
        try {
          const evt = JSON.parse(dataLine.slice(6))
          handleEvent(evt)
        } catch { /* malformed frame */ }
      }
    }
    status.value = 'done'
  } catch (err) {
    if (err.name === 'AbortError') {
      status.value = 'cancelled'
    } else {
      error.value = err.message || 'Simulation failed'
      status.value = 'error'
    }
  } finally {
    streaming.value = false
    durationMs.value = Math.round(performance.now() - started)
    abortCtrl = null
  }
}

function handleEvent(evt) {
  if (evt.kind === 'meta') {
    distribution.value = Object.fromEntries(evt.states.map((s) => [s, 0]))
    return
  }
  if (evt.kind === 'frame') {
    frameInfo.value = { frame: evt.frame, total: evt.total }
    progress.value = evt.progress
    distribution.value = evt.distribution
    result.value = {
      activation_probability: evt.activation_probability,
      churn_probability:      evt.churn_probability,
      expected_value:         evt.expected_value,
      confidence_95:          null,
    }
    return
  }
  if (evt.kind === 'done') {
    progress.value = 1
    distribution.value = evt.end_state_distribution
    result.value = {
      activation_probability: evt.activation_probability,
      churn_probability:      evt.churn_probability,
      expected_value:         evt.expected_value,
      confidence_95:          evt.confidence_95,
    }
  }
}

function reset() {
  status.value = ''
  error.value = ''
  progress.value = 0
  frameInfo.value = null
  durationMs.value = null
  result.value = null
  distribution.value = {}
}
</script>

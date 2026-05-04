<template>
  <div class="p-6 space-y-6">
    <header class="flex items-start justify-between">
      <div>
        <p class="text-xs uppercase tracking-wider text-red-600">Platform · STE</p>
        <h1 class="text-2xl font-bold">State Transition Engine</h1>
        <p class="text-sm text-gray-500 max-w-2xl">
          Read-only Markov projections over <code>event_log</code>. Matrices are
          damped (0.85 learned + 0.15 uniform) and regenerated synchronously
          with each new event via <code>StateTransitionProjection</code>.
        </p>
      </div>
      <div class="flex items-center gap-2">
        <label class="text-sm" for="chain-select">Chain:</label>
        <select id="chain-select" v-model="chain" @change="loadAll"
                class="px-2 py-1.5 border rounded text-sm">
          <option value="session_lifecycle">session_lifecycle</option>
          <option value="tenant_lifecycle">tenant_lifecycle</option>
        </select>
      </div>
    </header>

    <!-- Headline row -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
      <div class="bg-white p-3 rounded-lg border">
        <p class="text-xs text-gray-500">States tracked</p>
        <p class="text-xl font-bold">{{ stateCount }}</p>
      </div>
      <div class="bg-white p-3 rounded-lg border">
        <p class="text-xs text-gray-500">Transitions</p>
        <p class="text-xl font-bold">{{ transitionCount }}</p>
      </div>
      <div class="bg-white p-3 rounded-lg border">
        <p class="text-xs text-gray-500">Projector lag</p>
        <p class="text-xl font-bold" :class="lagClass">{{ lagSeconds }}s</p>
      </div>
      <div class="bg-white p-3 rounded-lg border">
        <p class="text-xs text-gray-500">Unmapped event types</p>
        <p class="text-xl font-bold" :class="unmappedClass">{{ unmappedCount }}</p>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <!-- Matrix heatmap -->
      <section class="lg:col-span-2 bg-white rounded-lg shadow-sm border p-4">
        <div class="flex items-center justify-between mb-3">
          <h2 class="font-semibold text-sm">Transition matrix P(to | from)</h2>
          <button class="text-xs text-gray-500" @click="loadMatrix">Refresh</button>
        </div>
        <MatrixHeatmap :chain="chain" :matrix="matrix" />
      </section>

      <!-- Drop-offs -->
      <section class="bg-white rounded-lg shadow-sm border p-4">
        <h2 class="font-semibold text-sm mb-3">Drop-off probabilities</h2>
        <DropoffBar :dropoffs="dropoffs" />
      </section>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <!-- Winning tags -->
      <section class="lg:col-span-2 bg-white rounded-lg shadow-sm border p-4">
        <div class="flex items-center justify-between mb-3">
          <h2 class="font-semibold text-sm">Winning tag combinations</h2>
          <label class="text-xs text-gray-500">
            <span class="mr-1">Metric:</span>
            <select v-model="metric" @change="loadWinningTags"
                    class="px-1.5 py-0.5 border rounded text-xs">
              <option value="activation">activation</option>
              <option value="expansion">expansion</option>
            </select>
          </label>
        </div>
        <WinningTagsList :tags="winningTags" />
      </section>

      <!-- Simulation -->
      <section>
        <SimulationPanel />
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import axios from 'axios'
import MatrixHeatmap    from '../../components/ste/MatrixHeatmap.vue'
import DropoffBar       from '../../components/ste/DropoffBar.vue'
import WinningTagsList  from '../../components/ste/WinningTagsList.vue'
import SimulationPanel  from '../../components/ste/SimulationPanel.vue'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000'

const chain         = ref('session_lifecycle')
const metric        = ref('activation')
const matrix        = ref({})
const dropoffs      = ref({})
const winningTags   = ref([])
const lagSeconds    = ref(0)
const unmappedCount = ref(0)

const stateCount = computed(() => {
  const s = new Set()
  for (const from in matrix.value) {
    s.add(from)
    for (const to in matrix.value[from]) s.add(to)
  }
  return s.size
})

const transitionCount = computed(() => {
  let n = 0
  for (const from in matrix.value) {
    n += Object.keys(matrix.value[from]).length
  }
  return n
})

const lagClass = computed(() => {
  if (lagSeconds.value >= 300) return 'text-red-600'
  if (lagSeconds.value >= 60)  return 'text-amber-600'
  return 'text-green-600'
})

const unmappedClass = computed(() =>
  unmappedCount.value > 0 ? 'text-amber-600' : 'text-green-600'
)

async function loadMatrix() {
  try {
    const { data } = await axios.get(`${API_URL}/api/ste/matrix`, { params: { chain: chain.value } })
    matrix.value = data?.matrix || {}
  } catch {
    matrix.value = {}
  }
}

async function loadDropoffs() {
  try {
    const { data } = await axios.get(`${API_URL}/api/ste/dropoffs`, { params: { chain: chain.value } })
    dropoffs.value = data?.dropoffs || {}
  } catch {
    dropoffs.value = {}
  }
}

async function loadWinningTags() {
  try {
    const { data } = await axios.get(`${API_URL}/api/ste/winning-tags`, {
      params: { chain: chain.value, metric: metric.value, limit: 10 },
    })
    winningTags.value = data?.winning_tags || []
  } catch {
    winningTags.value = []
  }
}

async function loadLag() {
  try {
    const { data } = await axios.get(`${API_URL}/api/ste/lag`)
    lagSeconds.value = data?.lag_seconds ?? 0
  } catch {
    lagSeconds.value = 0
  }
}

async function loadUnmapped() {
  try {
    const { data } = await axios.get(`${API_URL}/api/ste/unmapped`)
    unmappedCount.value = (data?.unmapped_events || []).length
  } catch {
    unmappedCount.value = 0
  }
}

async function loadAll() {
  await Promise.all([loadMatrix(), loadDropoffs(), loadWinningTags(), loadLag(), loadUnmapped()])
}

onMounted(loadAll)
</script>

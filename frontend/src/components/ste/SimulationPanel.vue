<template>
  <div class="bg-white rounded-lg shadow-sm border p-4 space-y-3">
    <h3 class="font-semibold text-sm">Monte Carlo simulation</h3>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
      <label class="flex flex-col">
        <span class="text-xs text-gray-500 mb-1">Chain</span>
        <select v-model="chain" class="px-2 py-1 border rounded text-sm">
          <option value="session_lifecycle">session_lifecycle</option>
          <option value="tenant_lifecycle">tenant_lifecycle</option>
        </select>
      </label>

      <label class="flex flex-col">
        <span class="text-xs text-gray-500 mb-1">Start state</span>
        <input v-model="startState" type="text"
               class="px-2 py-1 border rounded text-sm font-mono" />
      </label>

      <label class="flex flex-col">
        <span class="text-xs text-gray-500 mb-1">Steps</span>
        <input v-model.number="steps" type="number" min="1" max="50"
               class="px-2 py-1 border rounded text-sm" />
      </label>

      <label class="flex flex-col">
        <span class="text-xs text-gray-500 mb-1">Runs</span>
        <input v-model.number="runs" type="number" min="10" max="5000" step="100"
               class="px-2 py-1 border rounded text-sm" />
      </label>
    </div>

    <div class="flex items-center gap-2">
      <button
        @click="run"
        :disabled="loading || !startState"
        class="px-3 py-1.5 rounded bg-indigo-600 text-white text-sm font-medium disabled:opacity-50"
      >
        {{ loading ? 'Simulating…' : 'Run simulation' }}
      </button>
      <span v-if="durationMs !== null" class="text-xs text-gray-500">{{ durationMs }} ms</span>
      <span v-if="error" class="text-xs text-red-600">{{ error }}</span>
    </div>

    <div v-if="result" class="mt-2 grid grid-cols-3 gap-3">
      <div class="p-3 rounded border bg-green-50">
        <p class="text-xs text-gray-600">Activation</p>
        <p class="text-xl font-bold text-green-700">
          {{ (result.activation_probability * 100).toFixed(1) }}%
        </p>
        <p class="text-xs text-gray-500">
          95% CI [{{ (result.confidence_95[0] * 100).toFixed(1) }}%, {{ (result.confidence_95[1] * 100).toFixed(1) }}%]
        </p>
      </div>
      <div class="p-3 rounded border bg-red-50">
        <p class="text-xs text-gray-600">Churn</p>
        <p class="text-xl font-bold text-red-700">
          {{ (result.churn_probability * 100).toFixed(1) }}%
        </p>
      </div>
      <div class="p-3 rounded border bg-indigo-50">
        <p class="text-xs text-gray-600">Expected value</p>
        <p class="text-xl font-bold text-indigo-700">
          {{ result.expected_value >= 0 ? '+' : '' }}{{ (result.expected_value * 100).toFixed(1) }}%
        </p>
      </div>
    </div>

    <div v-if="result?.end_state_distribution" class="mt-2">
      <p class="text-xs text-gray-500 mb-1">End-state distribution</p>
      <ul class="text-xs font-mono space-y-0.5">
        <li v-for="[state, p] in endStateEntries" :key="state" class="flex justify-between">
          <span>{{ state }}</span>
          <span>{{ (p * 100).toFixed(1) }}%</span>
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import axios from 'axios'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000'

const chain       = ref('session_lifecycle')
const startState  = ref('visitor')
const steps       = ref(10)
const runs        = ref(1000)

const loading     = ref(false)
const error       = ref('')
const durationMs  = ref(null)
const result      = ref(null)

const endStateEntries = computed(() =>
  Object.entries(result.value?.end_state_distribution || {}).sort((a, b) => b[1] - a[1])
)

async function run() {
  error.value      = ''
  result.value     = null
  durationMs.value = null
  loading.value    = true

  const started = performance.now()
  try {
    const resp = await axios.post(`${API_URL}/api/ste/simulate`, {
      chain:       chain.value,
      start_state: startState.value,
      steps:       steps.value,
      runs:        runs.value,
    })
    result.value = resp.data
    durationMs.value = Math.round(performance.now() - started)
  } catch (err) {
    error.value = err.response?.data?.error || err.message || 'Simulation failed'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="p-6 space-y-6">
    <header class="flex items-start justify-between">
      <div>
        <p class="text-xs uppercase tracking-wider text-red-600">Platform workspace</p>
        <h1 class="text-2xl font-bold">SpiderNet OS — platform console</h1>
        <p class="text-sm text-gray-500">Operate tenants, rollouts, flags, and the Atlas RL loop.</p>
      </div>
      <RoleBadge :role="authStore.role" />
    </header>

    <div class="grid grid-cols-1 md:grid-cols-6 gap-3 text-sm">
      <div class="bg-white p-4 rounded-lg shadow-sm border">
        <p class="text-xs text-gray-500">Tenants</p>
        <p class="text-2xl font-bold">{{ stats.tenants }}</p>
      </div>
      <div class="bg-white p-4 rounded-lg shadow-sm border">
        <p class="text-xs text-gray-500">Users</p>
        <p class="text-2xl font-bold">{{ stats.users }}</p>
      </div>
      <div class="bg-white p-4 rounded-lg shadow-sm border">
        <p class="text-xs text-gray-500">Rev MTD</p>
        <p class="text-2xl font-bold">${{ stats.revMtd.toFixed(0) }}</p>
      </div>
      <div class="bg-white p-4 rounded-lg shadow-sm border">
        <p class="text-xs text-gray-500">Incidents</p>
        <p class="text-2xl font-bold">{{ stats.incidents }}</p>
      </div>
      <div class="bg-white p-4 rounded-lg shadow-sm border">
        <p class="text-xs text-gray-500">TS (7d)</p>
        <p class="text-2xl font-bold">
          {{ stats.ts7d.toFixed(2) }}
          <span class="text-green-600 text-sm" v-if="stats.ts7d >= 0.85">✓</span>
        </p>
      </div>
      <div class="bg-white p-4 rounded-lg shadow-sm border">
        <p class="text-xs text-gray-500">Gate</p>
        <p class="text-xl font-bold"
           :class="stats.gate === 'GREEN' ? 'text-green-600'
                   : stats.gate === 'AMBER' ? 'text-amber-600' : 'text-red-600'">
          {{ stats.gate }}
        </p>
      </div>
    </div>

    <section class="bg-white rounded-lg shadow-sm border">
      <div class="flex items-center justify-between p-4 border-b">
        <h2 class="font-semibold text-sm">Rollouts in flight</h2>
      </div>
      <ul class="divide-y">
        <li class="p-4 flex items-center justify-between">
          <div>
            <p class="font-medium">usage-aggregates-v2</p>
            <p class="text-xs text-gray-500">SHADOW · {{ shadowProgress }}h / 48h · diffs last 24h: <span class="font-mono">{{ stats.shadowOpenDiffs }}</span></p>
          </div>
          <RouterLink
            to="/platform/rollouts/usage-v2"
            class="px-3 py-1.5 rounded bg-red-600 text-white text-sm font-medium"
          >Open console</RouterLink>
        </li>
      </ul>
    </section>

    <!-- STE tile (plan §12) -->
    <section class="bg-white rounded-lg shadow-sm border p-4 flex items-center justify-between">
      <div>
        <h2 class="font-semibold text-sm">State Transition Engine</h2>
        <p class="text-xs text-gray-500">
          Markov matrices, drop-off probabilities, and Monte Carlo simulation
          over <code>event_log</code>. Read-only in Phase 1.
        </p>
      </div>
      <RouterLink
        to="/platform/ste"
        class="px-3 py-1.5 rounded bg-indigo-600 text-white text-sm font-medium"
      >Open STE</RouterLink>
    </section>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { useAuthStore } from '../../stores/auth.js'
import RoleBadge from '../../components/security/RoleBadge.vue'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000'

const authStore = useAuthStore()

const stats = ref({
  tenants:         0,
  users:           0,
  revMtd:          0,
  incidents:       0,
  ts7d:            0,
  gate:            '—',
  shadowStartedAt: null,
  shadowOpenDiffs: 0,
})

const shadowProgress = computed(() => {
  if (!stats.value.shadowStartedAt) return 0
  const started = new Date(stats.value.shadowStartedAt).getTime()
  const now     = Date.now()
  return Math.max(0, Math.min(48, Math.round((now - started) / 3_600_000)))
})

async function load() {
  try {
    const { data } = await axios.get(`${API_URL}/api/platform/overview`)
    stats.value = { ...stats.value, ...data }
  } catch {
    /* keep defaults */
  }

  try {
    const { data } = await axios.get(`${API_URL}/api/usage/shadow/gate`)
    const gate = data?.shadow_gate
    if (gate) {
      stats.value.gate            = gate.ready_for_cutover ? 'GREEN' : 'AMBER'
      stats.value.shadowOpenDiffs = gate.open_diffs_last_24h ?? 0
    }
  } catch {
    /* keep defaults */
  }
}

onMounted(load)
</script>

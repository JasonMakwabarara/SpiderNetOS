<template>
  <div class="p-6 space-y-4">
    <header class="flex items-start justify-between">
      <div>
        <p class="text-xs uppercase tracking-wider text-red-600">Rollout console</p>
        <h1 class="text-2xl font-bold">Usage Aggregates v2</h1>
        <p class="text-sm text-gray-500">
          Shadow → hard cutover. The CUTOVER action is only enabled once every gate is green.
        </p>
      </div>
      <span
        class="px-3 py-1 rounded-full text-sm font-semibold"
        :class="gateColor"
      >
        Gate: {{ gateLabel }}
      </span>
    </header>

    <!-- Stage bar -->
    <div class="flex items-center gap-1 text-xs font-medium">
      <span
        v-for="s in stages"
        :key="s.key"
        class="flex-1 text-center px-3 py-1 rounded"
        :class="currentStage === s.key
          ? 'bg-indigo-600 text-white'
          : isPastStage(s.key)
            ? 'bg-green-100 text-green-700'
            : 'bg-gray-100 text-gray-500'"
      >{{ s.label }}</span>
    </div>

    <StepUpGuard reason="Rollout changes touch every tenant. Verify it is you.">
      <!-- Flag toggles -->
      <div class="bg-white rounded-lg shadow-sm border p-4 space-y-2">
        <h2 class="font-semibold text-sm mb-2">Flag state</h2>
        <div v-for="f in managedFlags" :key="f.name" class="flex items-center justify-between text-sm">
          <code class="font-mono">{{ f.name }}</code>
          <div class="flex items-center gap-2">
            <span
              class="px-2 py-0.5 rounded text-xs"
              :class="f.value === 'on'
                ? 'bg-green-100 text-green-700'
                : 'bg-gray-100 text-gray-600'"
            >{{ f.value }}</span>
            <button
              class="px-2 py-1 rounded border text-xs"
              :disabled="f.locked"
              @click="toggle(f.name, f.value === 'on' ? 'off' : 'on')"
            >Toggle</button>
            <span v-if="f.locked" class="text-xs text-gray-400">🔒 gate held</span>
          </div>
        </div>
      </div>

      <!-- Shadow diffs -->
      <div class="bg-white rounded-lg shadow-sm border">
        <div class="flex items-center justify-between p-4 border-b">
          <h2 class="font-semibold text-sm">Shadow diffs (last 24 h)</h2>
          <button class="text-xs text-gray-500" @click="refreshGate">Refresh</button>
        </div>
        <EmptyState
          v-if="!gate.open_diffs_last_24h"
          title="Zero diffs in the last 24 hours"
          description="The cutover gate is clear. Proceed to the consumer audit."
        />
        <p v-else class="p-4 text-sm text-red-600">
          {{ gate.open_diffs_last_24h }} unresolved diff(s) detected. Investigate before cutover.
        </p>
      </div>

      <!-- Consumer audit checklist -->
      <div class="bg-white rounded-lg shadow-sm border p-4">
        <h2 class="font-semibold text-sm mb-3">Consumer audit</h2>
        <ul class="grid grid-cols-2 gap-2 text-sm">
          <li v-for="c in consumers" :key="c.name" class="flex items-center gap-2">
            <input type="checkbox" v-model="c.ready" :id="`c-${c.name}`" />
            <label :for="`c-${c.name}`">{{ c.name }}</label>
          </li>
        </ul>
      </div>

      <!-- Cutover -->
      <div class="rounded-lg border-2 border-red-300 bg-red-50 p-4 flex items-center justify-between">
        <div>
          <h2 class="font-bold text-red-700">Cutover to v2</h2>
          <p class="text-xs text-red-700 mt-0.5">
            Requires: gate green + consumer audit 100% + fresh MFA.
          </p>
        </div>
        <button
          class="px-4 py-2 rounded bg-red-600 text-white font-semibold disabled:opacity-40 disabled:cursor-not-allowed"
          :disabled="!canCutover"
          @click="cutoverOpen = true"
        >CUTOVER</button>
      </div>
    </StepUpGuard>

    <TypedConfirmDialog
      v-model="cutoverOpen"
      phrase="CUTOVER"
      title="Hard cutover to v2"
      confirmLabel="Execute cutover"
      @confirm="runCutover"
    >
      <p class="text-sm">
        This disables the shadow flag and enables the cutover flag globally.
        <strong>All tenants switch to canonical-only v2 immediately.</strong>
      </p>
    </TypedConfirmDialog>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import StepUpGuard        from '../../components/security/StepUpGuard.vue'
import TypedConfirmDialog from '../../components/feedback/TypedConfirmDialog.vue'
import EmptyState         from '../../components/data/EmptyState.vue'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000'

const stages = [
  { key: 'prep',    label: 'Prep' },
  { key: 'shadow',  label: 'Shadow (48h)' },
  { key: 'cutover', label: 'Cutover' },
  { key: 'watch',   label: 'Watch (72h)' },
]

const managedFlags = reactive([
  { name: 'atlas.usage_aggregates_v2',          value: 'off', locked: false },
  { name: 'atlas.usage_aggregates_v2.shadow',   value: 'off', locked: false },
  { name: 'atlas.usage_aggregates_v2.cutover',  value: 'off', locked: true },
  { name: 'atlas.usage_aggregates_v2.rollback', value: 'off', locked: false },
])

const consumers = reactive([
  { name: 'Cockpit store',             ready: true },
  { name: 'ObservabilityController',   ready: true },
  { name: 'AggregateUsageJob',         ready: true },
  { name: 'Grafana dashboards',        ready: false },
  { name: 'Metabase reports',          ready: false },
  { name: 'Partner integrations',      ready: false },
])

const gate = ref({ open_diffs_last_24h: 0, ready_for_cutover: true })
const cutoverOpen = ref(false)

const currentStage = computed(() => {
  const cutover = managedFlags.find((f) => f.name.endsWith('cutover'))?.value === 'on'
  const shadow  = managedFlags.find((f) => f.name.endsWith('shadow'))?.value === 'on'
  if (cutover) return 'watch'
  if (shadow)  return 'shadow'
  return 'prep'
})

function isPastStage(key) {
  const order = stages.map((s) => s.key)
  return order.indexOf(key) < order.indexOf(currentStage.value)
}

const gateLabel = computed(() => (gate.value.ready_for_cutover ? 'GREEN' : 'AMBER'))
const gateColor = computed(() =>
  gate.value.ready_for_cutover ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700')

const canCutover = computed(() => {
  const allReady = consumers.every((c) => c.ready)
  return gate.value.ready_for_cutover && allReady
})

async function refreshGate() {
  try {
    const { data } = await axios.get(`${API_URL}/api/usage/shadow/gate`)
    gate.value = data?.shadow_gate || gate.value
    // Unlock cutover toggle once gate is green and consumer audit is complete.
    const cutoverFlag = managedFlags.find((f) => f.name.endsWith('cutover'))
    if (cutoverFlag) cutoverFlag.locked = !canCutover.value
  } catch {
    /* keep previous */
  }
}

async function toggle(name, next) {
  try {
    await axios.put(`${API_URL}/api/platform/feature-flags/${encodeURIComponent(name)}`, { value: next })
    const f = managedFlags.find((x) => x.name === name)
    if (f) f.value = next
  } catch {
    /* server rejects if capability / step-up missing */
  }
}

async function runCutover() {
  await toggle('atlas.usage_aggregates_v2.shadow',  'off')
  await toggle('atlas.usage_aggregates_v2.cutover', 'on')
  await refreshGate()
}

async function loadFlags() {
  try {
    const { data } = await axios.get(`${API_URL}/api/platform/feature-flags`)
    for (const flag of managedFlags) {
      const remote = (data?.flags || []).find((r) => r.name === flag.name)
      if (remote) flag.value = String(remote.value)
    }
  } catch {
    /* keep defaults */
  }
}

onMounted(async () => {
  await loadFlags()
  await refreshGate()
})
</script>

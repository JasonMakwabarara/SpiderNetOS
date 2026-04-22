<template>
  <div class="p-6 space-y-6">
    <header>
      <h1 class="text-2xl font-bold">Atlas copy surfaces</h1>
      <p class="text-sm text-gray-500">
        Toggle transformation-scored copy per surface, or fall back to the approved static template.
      </p>
    </header>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <article
        v-for="s in surfaces"
        :key="s.key"
        class="bg-white rounded-lg shadow-sm border p-4"
      >
        <div class="flex items-center justify-between mb-2">
          <h2 class="font-semibold text-sm">{{ s.label }}</h2>
          <span
            class="text-xs px-2 py-0.5 rounded-full"
            :class="state[s.key] === 'fallback'
              ? 'bg-gray-200 text-gray-700'
              : 'bg-green-100 text-green-700'"
          >
            {{ state[s.key] === 'fallback' ? 'Fallback' : 'Live' }}
          </span>
        </div>

        <p class="text-xs text-gray-500 mb-3">{{ s.description }}</p>

        <div class="flex items-center gap-2">
          <button
            class="px-3 py-1.5 rounded text-xs"
            :class="state[s.key] !== 'fallback'
              ? 'bg-indigo-600 text-white'
              : 'border'"
            @click="setSurface(s.key, 'on')"
          >
            Use Atlas variant
          </button>
          <button
            class="px-3 py-1.5 rounded text-xs"
            :class="state[s.key] === 'fallback'
              ? 'bg-gray-700 text-white'
              : 'border'"
            @click="setSurface(s.key, 'fallback')"
          >
            Force fallback
          </button>
        </div>
      </article>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000'

const surfaces = [
  { key: 'empty_state',   label: 'Empty state',   description: 'Shown when a user first lands on a page with no data.' },
  { key: 'banner',        label: 'Banner',        description: 'Short nudges at the top of dashboards.' },
  { key: 'modal',         label: 'Modal',         description: 'Decision moments that need explicit acceptance.' },
  { key: 'tooltip',       label: 'Tooltip',       description: 'Micro-context on labels and column headers.' },
  { key: 'success_state', label: 'Success state', description: 'Reinforces progress after a meaningful outcome.' },
  { key: 'error_state',   label: 'Error state',   description: 'Preserves trust when something goes wrong.' },
]

const state = ref({})

async function load() {
  // Read effective flags. Endpoint assumed available; falls back to "on".
  try {
    const { data } = await axios.get(`${API_URL}/api/admin/copy/state`)
    state.value = data?.state || {}
  } catch {
    for (const s of surfaces) state.value[s.key] = 'on'
  }
}

async function setSurface(key, value) {
  state.value[key] = value
  try {
    await axios.put(`${API_URL}/api/admin/copy/state`, { surface: key, value })
  } catch {
    /* swallow — optimistic UI; a toast would be ideal */
  }
}

onMounted(load)
</script>

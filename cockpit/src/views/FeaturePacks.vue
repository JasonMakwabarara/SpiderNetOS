<template>
  <div class="px-6 py-7 max-w-6xl mx-auto" data-testid="feature-packs-page">
    <div class="mb-7">
      <div class="sn-eyebrow">Build · Vertical packs</div>
      <h1 class="text-2xl font-semibold tracking-tight mt-1" style="color: var(--text-primary);">
        Feature pack registry
      </h1>
      <p class="text-sm mt-1" style="color: var(--text-secondary);">
        Install vertical AIOS bundles — agents, flows, and STE chains — without custom engineering.
      </p>
    </div>

    <div v-if="loading" class="text-sm" style="color: var(--text-muted);">Loading catalogue…</div>

    <div v-else class="grid md:grid-cols-2 gap-4">
      <div v-for="pack in catalogue" :key="pack.pack_id" class="sn-card p-5">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h3 class="font-medium" style="color: var(--text-primary);">{{ pack.display_name }}</h3>
            <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ pack.description }}</p>
            <div class="mono text-xs mt-2" style="color: var(--text-muted);">
              {{ pack.vertical }} · v{{ pack.version }}
            </div>
          </div>
          <span class="sn-pill sn-pill-success" v-if="isInstalled(pack.pack_id)">Installed</span>
          <span class="sn-pill" v-else>Available</span>
        </div>
      </div>
    </div>

    <div v-if="installed.length" class="mt-8">
      <h2 class="font-medium mb-3" style="color: var(--text-primary);">Installed on this tenant</h2>
      <div class="grid md:grid-cols-2 gap-4">
        <div v-for="pack in installed" :key="pack.id" class="sn-card p-4 text-sm">
          <div style="color: var(--text-primary);">{{ pack.display_name }}</div>
          <div class="mono text-xs mt-1" style="color: var(--text-muted);">{{ pack.status }} · {{ pack.pack_id }}</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../services/api.js'

const loading = ref(true)
const catalogue = ref([])
const installed = ref([])

function isInstalled(packId) {
  return installed.value.some((p) => p.pack_id === packId)
}

onMounted(async () => {
  try {
    const [cat, inst] = await Promise.all([
      api.get('/api/feature-packs/catalogue'),
      api.get('/api/feature-packs'),
    ])
    catalogue.value = cat.data.data || []
    installed.value = inst.data.data || []
  } finally {
    loading.value = false
  }
})
</script>

<style scoped>
.sn-eyebrow {
  font-family: 'JetBrains Mono', ui-monospace, monospace;
  font-size: 11px;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: var(--accent);
}
.sn-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 12px;
}
</style>

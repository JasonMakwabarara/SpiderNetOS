<template>
  <div class="px-6 py-7 max-w-6xl mx-auto" data-testid="ent-connectors">
    <div class="flex items-end justify-between flex-wrap gap-3 mb-7">
      <div>
        <div class="sn-eyebrow">Enterprise · Connectors</div>
        <h1 class="text-2xl font-semibold tracking-tight mt-1" style="color: var(--text-primary);">
          Business-system connectors
        </h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">
          Govern data flows to ERP, CRM, BI, IAM, and data lakes — all tenant-bounded.
        </p>
      </div>
      <button class="sn-btn-primary">+ Add connector</button>
    </div>

    <div v-if="loading" class="text-sm" style="color: var(--text-muted);">Loading connectors…</div>

    <div v-else class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
      <div
        v-for="c in items"
        :key="c.id"
        class="sn-card p-5"
        :data-testid="`ent-connector-${c.id}`"
      >
        <div class="flex items-center justify-between">
          <div class="w-10 h-10 rounded-lg flex items-center justify-center"
               style="background: var(--bg-elevated); border: 1px solid var(--border);">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color: var(--accent);">
              <path stroke-linecap="round" d="M7 7V3M17 7V3M5 7h14v4a7 7 0 01-14 0V7z"/>
            </svg>
          </div>
          <span class="sn-pill" :class="c.status === 'connected' ? 'sn-pill-success' : 'sn-pill-warn'">
            {{ c.status }}
          </span>
        </div>
        <h3 class="mt-4 font-medium" style="color: var(--text-primary);">{{ c.name }}</h3>
        <div class="mono text-xs mt-1" style="color: var(--text-muted);">
          {{ c.category }} · {{ c.region }}
        </div>
        <div class="mt-4 pt-4 flex items-center justify-between text-xs"
             style="border-top: 1px solid var(--divider); color: var(--text-secondary);">
          <span>{{ c.last_sync_human || '—' }}</span>
          <button class="sn-link">Manage →</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../services/api.js'

const items = ref([])
const loading = ref(true)

onMounted(async () => {
  try {
    const { data } = await api.get('/api/enterprise/connectors')
    items.value = data.data || []
  } catch (e) {
    items.value = []
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
  opacity: 0.85;
}
.sn-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 16px;
  transition: border-color 0.18s ease;
}
.sn-card:hover { border-color: var(--border-active); }
.sn-link { color: var(--accent); }
.sn-link:hover { color: var(--text-primary); }
.mono { font-family: 'JetBrains Mono', ui-monospace, monospace; }
.sn-btn-primary {
  background: var(--accent-warm);
  color: white;
  padding: 0.55rem 1.1rem;
  border-radius: 999px;
  font-weight: 500;
  font-size: 0.875rem;
  transition: filter 0.15s;
}
.sn-btn-primary:hover { filter: brightness(1.1); }
.sn-pill {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 2px 10px; border-radius: 999px;
  font-family: 'JetBrains Mono', monospace;
  font-size: 10px; text-transform: uppercase; letter-spacing: 0.08em;
  border: 1px solid var(--border);
}
.sn-pill-success { color: var(--success); border-color: rgba(49,214,123,0.4); background: rgba(49,214,123,0.06); }
.sn-pill-warn { color: var(--warn); border-color: rgba(245,184,75,0.4); background: rgba(245,184,75,0.06); }
</style>

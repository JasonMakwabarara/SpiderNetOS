<template>
  <div class="px-6 py-7 max-w-6xl mx-auto" data-testid="outcomes-page">
    <div class="flex items-end justify-between flex-wrap gap-3 mb-7">
      <div>
        <div class="sn-eyebrow">Observe · Outcomes</div>
        <h1 class="text-2xl font-semibold tracking-tight mt-1" style="color: var(--text-primary);">
          Weekly review
        </h1>
        <p class="text-sm mt-1 max-w-2xl" style="color: var(--text-secondary);">
          The operator console for your autonomous business. SpiderNetOS runs thousands of
          decisions a day — agents, flows, governance, approvals — and hands you a 5-minute
          weekly review. This is the cockpit.
        </p>
      </div>
      <button class="sn-btn-primary" :disabled="loading" @click="load">Refresh</button>
    </div>

    <div v-if="loading" class="text-sm" style="color: var(--text-muted);">Loading outcomes…</div>

    <template v-else>
      <div v-if="atlasBriefing?.text" class="sn-card p-5 mb-6 border-l-4" style="border-left-color: var(--accent);">
        <h2 class="font-medium mb-2" style="color: var(--text-primary);">Atlas briefing</h2>
        <p class="text-sm whitespace-pre-wrap" style="color: var(--text-secondary);">{{ atlasBriefing.text }}</p>
        <p v-if="atlasBriefing.estimated_cost_usd != null" class="mono text-xs mt-2" style="color: var(--text-muted);">
          Est. inference cost ${{ Number(atlasBriefing.estimated_cost_usd).toFixed(4) }}
        </p>
      </div>

      <div class="grid md:grid-cols-3 gap-4 mb-6">
        <div class="sn-card p-5">
          <div class="mono text-xs uppercase tracking-wider" style="color: var(--text-muted);">Pending</div>
          <div class="text-3xl font-semibold mt-2" style="color: var(--accent-warm);">{{ summary.pending_count ?? 0 }}</div>
        </div>
        <div class="sn-card p-5">
          <div class="mono text-xs uppercase tracking-wider" style="color: var(--text-muted);">Accepted</div>
          <div class="text-3xl font-semibold mt-2" style="color: var(--accent);">{{ summary.accepted_count ?? 0 }}</div>
        </div>
        <div class="sn-card p-5">
          <div class="mono text-xs uppercase tracking-wider" style="color: var(--text-muted);">Autonomy level</div>
          <div class="text-3xl font-semibold mt-2" style="color: var(--text-primary);">{{ autonomy.autonomy_level ?? 1 }}</div>
        </div>
      </div>

      <div class="sn-card p-5 mb-6">
        <h2 class="font-medium mb-3" style="color: var(--text-primary);">Autonomy controls</h2>
        <div class="flex flex-wrap items-center gap-4">
          <label class="text-sm" style="color: var(--text-secondary);">
            Level
            <select v-model.number="autonomyLevel" class="ml-2 sn-input">
              <option :value="1">1 — Manual</option>
              <option :value="2">2 — Assisted</option>
              <option :value="3">3 — Autonomous</option>
            </select>
          </label>
          <button class="sn-btn-secondary" @click="saveAutonomy">Save</button>
        </div>
      </div>

      <div class="space-y-3">
        <h2 class="font-medium" style="color: var(--text-primary);">Recommendations</h2>
        <div v-if="!recommendations.length" class="sn-card p-8 text-center text-sm" style="color: var(--text-muted);">
          No recommendations yet. Trigger a cognitive cycle from Atlas or wait for the next review window.
        </div>
        <div v-for="rec in recommendations" :key="rec.id" class="sn-card p-5" :data-testid="`rec-${rec.id}`">
          <div class="flex items-start justify-between gap-4">
            <div>
              <h3 class="font-medium" style="color: var(--text-primary);">{{ rec.title }}</h3>
              <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ rec.justification || 'AI-generated business outcome proposal' }}</p>
              <div class="mono text-xs mt-2 flex flex-wrap gap-3" style="color: var(--text-muted);">
                <span>Impact {{ rec.impact_score ?? '—' }}</span>
                <span>Revenue +${{ formatMoney(rec.expected_revenue_gain) }}</span>
                <span class="sn-pill">{{ rec.status }}</span>
              </div>
            </div>
            <div v-if="rec.status === 'pending'" class="flex gap-2 shrink-0">
              <button class="sn-btn-primary text-sm" @click="accept(rec.id)">Accept</button>
              <button class="sn-btn-secondary text-sm" @click="reject(rec.id)">Reject</button>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../services/api.js'

const loading = ref(true)
const recommendations = ref([])
const summary = ref({})
const autonomy = ref({})
const autonomyLevel = ref(1)
const atlasBriefing = ref(null)

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/api/outcomes/weekly-review')
    recommendations.value = data.data?.recommendations || []
    summary.value = data.data?.summary || {}
    autonomy.value = data.data?.autonomy || {}
    atlasBriefing.value = data.data?.atlas_briefing || null
    autonomyLevel.value = autonomy.value.autonomy_level ?? 1
  } finally {
    loading.value = false
  }
}

async function accept(id) {
  await api.patch(`/api/outcomes/recommendations/${id}/accept`)
  await load()
}

async function reject(id) {
  await api.patch(`/api/outcomes/recommendations/${id}/reject`)
  await load()
}

async function saveAutonomy() {
  await api.put('/api/outcomes/autonomy', { autonomy_level: autonomyLevel.value })
  await load()
}

function formatMoney(v) {
  return Number(v || 0).toLocaleString()
}

onMounted(load)
</script>

<style scoped>
.sn-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 12px;
}
.sn-input {
  background: var(--bg-elevated);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 0.35rem 0.5rem;
}
</style>

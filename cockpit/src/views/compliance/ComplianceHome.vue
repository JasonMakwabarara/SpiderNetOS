<template>
  <div class="px-6 py-7 max-w-4xl mx-auto" data-testid="compliance-home">
    <div class="mb-6">
      <div class="sn-eyebrow">Operate · Compliance</div>
      <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">Compliance Radar</h1>
      <p class="text-sm mt-1" style="color: var(--text-secondary);">
        SpiderNetOS learns your business and shows what applies — in plain English.
      </p>
    </div>

    <div class="sn-card p-4 mb-6 flex items-center gap-4">
      <div class="flex-1">
        <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Business profile mapped</p>
        <div class="mt-2 h-2 rounded-full overflow-hidden" style="background: var(--border);">
          <div class="h-full rounded-full transition-all" :style="{ width: `${profilePct}%`, background: 'var(--accent)' }" />
        </div>
      </div>
      <span class="text-lg font-bold mono" style="color: var(--accent);">{{ profilePct }}%</span>
    </div>

    <div v-if="loading" class="text-sm" style="color: var(--text-muted);">Loading…</div>

    <div v-else class="space-y-3">
      <div v-for="ob in obligations" :key="ob.id" class="sn-card p-4">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h3 class="font-medium" style="color: var(--text-primary);">{{ ob.title }}</h3>
            <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ ob.summary }}</p>
          </div>
          <span class="sn-pill text-[10px] shrink-0">{{ ob.severity }}</span>
        </div>
        <RouterLink v-if="ob.action_path" :to="ob.action_path" class="inline-block mt-3 text-sm font-medium" style="color: var(--accent);">
          {{ ob.action }} →
        </RouterLink>
      </div>
    </div>

    <p class="text-xs mt-6" style="color: var(--text-muted);">{{ disclaimer }}</p>

    <RouterLink to="/atlas?seed=discovery" class="inline-block mt-4 sn-btn px-4 py-2 rounded-lg text-sm font-semibold">
      I don't know what I need — ask Atlas
    </RouterLink>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../services/api.js'

const loading = ref(true)
const obligations = ref([])
const profilePct = ref(0)
const disclaimer = ref('Guidance only — not legal advice.')

onMounted(async () => {
  try {
    const { data } = await api.get('/api/compliance/obligations')
    obligations.value = data?.data || []
    profilePct.value = data?.profile_pct ?? 0
    disclaimer.value = data?.disclaimer || disclaimer.value
  } catch {
    obligations.value = [{
      id: 'fallback',
      title: 'Start with Atlas',
      summary: 'Tell Atlas about your business and we will map what applies.',
      severity: 'awareness',
      action: 'Open Atlas',
      action_path: '/atlas?seed=discovery',
    }]
  } finally {
    loading.value = false
  }
})
</script>

<style scoped>
.sn-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; }
.sn-pill { padding: 2px 8px; border-radius: 999px; background: var(--bg-elevated); color: var(--text-muted); }
.sn-btn { background: linear-gradient(135deg, #00E5C8, #087D6E); color: #05070A; }
</style>

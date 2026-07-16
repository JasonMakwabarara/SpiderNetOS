<template>
  <div class="px-6 py-7 max-w-6xl mx-auto" data-testid="communications-page">
    <div class="mb-7">
      <div class="sn-eyebrow">Operate · Communications</div>
      <h1 class="text-2xl font-semibold tracking-tight mt-1" style="color: var(--text-primary);">
        Business communications
      </h1>
      <p class="text-sm mt-1 max-w-2xl" style="color: var(--text-secondary);">
        Voice, messaging integrations, and omnichannel touchpoints — governed through one surface.
      </p>
    </div>

    <div class="grid lg:grid-cols-2 gap-6">
      <section class="sn-card p-5">
        <h2 class="font-medium mb-4" style="color: var(--text-primary);">Voice numbers</h2>
        <div v-if="voiceLoading" class="text-sm" style="color: var(--text-muted);">Loading…</div>
        <ul v-else-if="numbers.length" class="space-y-3">
          <li v-for="n in numbers" :key="n.id || n.phone_number" class="flex justify-between text-sm">
            <span style="color: var(--text-primary);">{{ n.phone_number || n.number }}</span>
            <span class="sn-pill">{{ n.status || 'active' }}</span>
          </li>
        </ul>
        <p v-else class="text-sm" style="color: var(--text-muted);">No voice numbers configured.</p>
      </section>

      <section class="sn-card p-5">
        <h2 class="font-medium mb-4" style="color: var(--text-primary);">Integrations</h2>
        <div v-if="intLoading" class="text-sm" style="color: var(--text-muted);">Loading…</div>
        <ul v-else-if="integrations.length" class="space-y-3">
          <li v-for="i in integrations" :key="i.provider || i.id" class="flex justify-between text-sm">
            <span style="color: var(--text-primary);">{{ i.name || i.provider }}</span>
            <span class="sn-pill" :class="i.connected ? 'sn-pill-success' : ''">{{ i.connected ? 'connected' : 'available' }}</span>
          </li>
        </ul>
        <p v-else class="text-sm" style="color: var(--text-muted);">Connect Slack, calendar, and messaging providers from Settings.</p>
      </section>
    </div>

    <section class="sn-card p-5 mt-6">
      <h2 class="font-medium mb-2" style="color: var(--text-primary);">Sales inbox</h2>
      <p class="text-sm mb-3" style="color: var(--text-secondary);">
        Email and WhatsApp conversations from the Lead-to-Sale Funnel bundle live in their own inbox.
      </p>
      <RouterLink to="/sales/inbox" class="sn-btn-outline px-3 py-1.5 rounded-lg text-sm font-medium inline-block">
        Open sales inbox →
      </RouterLink>
    </section>

    <section class="sn-card p-5 mt-6">
      <h2 class="font-medium mb-2" style="color: var(--text-primary);">Channels roadmap</h2>
      <p class="text-sm" style="color: var(--text-secondary);">
        Hermes multi-channel adapter (15+ platforms) routes through MetaPlanner dispatch with approvals,
        traces, and cost governance — same as voice and webhook flows.
      </p>
    </section>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../services/api.js'

const numbers = ref([])
const integrations = ref([])
const voiceLoading = ref(true)
const intLoading = ref(true)

onMounted(async () => {
  try {
    const { data } = await api.get('/api/voice/numbers')
    numbers.value = data.data || data.numbers || data || []
  } catch { numbers.value = [] } finally { voiceLoading.value = false }

  try {
    const { data } = await api.get('/api/integrations')
    integrations.value = data.data || data.integrations || data || []
  } catch { integrations.value = [] } finally { intLoading.value = false }
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
.sn-btn-outline {
  background: transparent;
  color: var(--text-primary);
  border: 1px solid var(--border);
}
</style>

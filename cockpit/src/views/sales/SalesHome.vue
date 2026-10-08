<template>
  <div class="px-6 py-7 max-w-4xl mx-auto">
    <div class="mb-6">
      <div class="sn-eyebrow">Operate · Sales</div>
      <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">Sales & CRM OS</h1>
      <p class="text-sm mt-1" style="color: var(--text-secondary);">
        Pipeline visibility, lead follow-up, and growth automation — start with one flow.
      </p>
    </div>

    <div class="grid md:grid-cols-3 gap-4 mb-6">
      <div class="sn-card p-4">
        <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Open deals</p>
        <p class="text-2xl font-bold mt-1" style="color: var(--text-primary);">{{ summary.open_deals }}</p>
      </div>
      <div class="sn-card p-4">
        <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Stale leads</p>
        <p class="text-2xl font-bold mt-1" style="color: #FFAA00;">{{ summary.stale_leads }}</p>
      </div>
      <div class="sn-card p-4">
        <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Follow-ups due</p>
        <p class="text-2xl font-bold mt-1" style="color: var(--accent);">{{ summary.followups_due }}</p>
      </div>
    </div>

    <div class="sn-card p-5 space-y-4">
      <h2 class="font-medium" style="color: var(--text-primary);">First win</h2>
      <p class="text-sm" style="color: var(--text-secondary);">
        Run lead capture to score and route new leads automatically.
      </p>
      <button
        type="button"
        class="sn-btn px-4 py-2 rounded-lg text-sm font-semibold disabled:opacity-50"
        :disabled="running"
        @click="runLeadCapture"
      >
        {{ running ? 'Starting…' : 'Run lead capture flow' }}
      </button>
      <p v-if="message" class="text-xs" style="color: var(--accent);">{{ message }}</p>
      <RouterLink to="/operate/first-win" class="text-sm underline block" style="color: var(--accent);">
        Or use the guided first-win wizard →
      </RouterLink>
    </div>

    <div class="sn-card p-5 space-y-3 mt-6">
      <h2 class="font-medium" style="color: var(--text-primary);">Partner outreach</h2>
      <p class="text-sm" style="color: var(--text-secondary);">
        Recruit creators into your affiliate program: import a shortlist, send the invite sequence from your own mailbox, work the DM queue.
      </p>
      <div class="flex flex-wrap items-center gap-3">
        <RouterLink to="/sales/partners" class="sn-btn px-4 py-2 rounded-lg text-sm font-semibold">Open prospects</RouterLink>
        <RouterLink to="/sales/partners/dm-queue" class="text-sm underline" style="color: var(--accent);">DM queue →</RouterLink>
        <RouterLink to="/sales/partners/settings" class="text-sm underline" style="color: var(--text-muted);">Settings</RouterLink>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../services/api.js'

const summary = ref({ open_deals: 0, stale_leads: 0, followups_due: 0 })
const running = ref(false)
const message = ref('')

onMounted(async () => {
  try {
    const { data } = await api.get('/api/agents')
    const agents = data?.data || data?.agents || data || []
    const crmAgents = Array.isArray(agents) ? agents.filter((a) => (a.slug || '').includes('crm') || (a.config?.pack_id === 'sales-crm')) : []
    summary.value.open_deals = crmAgents.length
  } catch { /* defaults */ }
})

async function runLeadCapture() {
  running.value = true
  message.value = ''
  try {
    const { data: flowsRes } = await api.get('/api/flows')
    const flows = flowsRes?.data || flowsRes || []
    const list = Array.isArray(flows) ? flows : (flows.data || [])
    let flow = list.find((f) => /lead/i.test(f.name || f.slug || ''))
    if (!flow) {
      const created = await api.post('/api/flows/quick-create', {
        template: 'followup',
        who: 'sales team',
        when: 'on new lead',
      })
      flow = created.data?.data || created.data
    }
    if (flow?.id) {
      await api.post(`/api/flows/${flow.id}/execute`, {})
      message.value = 'Flow started — check Traces for the result.'
    } else {
      message.value = 'Ask Atlas: "Set up lead capture for my business"'
    }
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not start flow. Try Atlas chat.'
  } finally {
    running.value = false
  }
}
</script>

<style scoped>
.sn-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; }
.sn-btn { background: linear-gradient(135deg, #00E5C8, #087D6E); color: #05070A; }
</style>

<template>
  <div class="px-6 py-7 max-w-2xl mx-auto" data-testid="ops-first-win">
    <div class="mb-6">
      <div class="sn-eyebrow">Operate · First win</div>
      <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">Your first automation in 5 minutes</h1>
      <p class="text-sm mt-1" style="color: var(--text-secondary);">
        Pick one template, answer two questions, run it — then see the result in Traces.
      </p>
    </div>

    <div class="sn-card p-5 space-y-4">
      <label class="block text-sm font-medium" style="color: var(--text-primary);">What do you want to automate?</label>
      <select v-model="template" class="w-full rounded-lg px-3 py-2 text-sm border" style="background: var(--bg-elevated); border-color: var(--border); color: var(--text-primary);">
        <option value="status">Daily status digest</option>
        <option value="followup">Lead or customer follow-up reminder</option>
        <option value="invoice">Invoice payment reminder</option>
      </select>

      <label class="block text-sm font-medium" style="color: var(--text-primary);">Who should this help?</label>
      <input v-model="who" type="text" placeholder="e.g. My sales team, me, finance" class="w-full rounded-lg px-3 py-2 text-sm border" style="background: var(--bg-elevated); border-color: var(--border); color: var(--text-primary);" />

      <label class="block text-sm font-medium" style="color: var(--text-primary);">When should it run?</label>
      <input v-model="when" type="text" placeholder="e.g. Every morning at 8am" class="w-full rounded-lg px-3 py-2 text-sm border" style="background: var(--bg-elevated); border-color: var(--border); color: var(--text-primary);" />

      <button type="button" class="sn-btn w-full py-3 rounded-lg font-semibold disabled:opacity-50" :disabled="running" @click="run">
        {{ running ? 'Creating…' : 'Create and run' }}
      </button>

      <p v-if="error" class="text-sm" style="color: var(--danger);">{{ error }}</p>
      <p v-if="success" class="text-sm" style="color: var(--accent);">{{ success }}</p>
      <RouterLink v-if="tracePath" :to="tracePath" class="text-sm underline block" style="color: var(--accent);">View in Traces →</RouterLink>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import api from '../../services/api.js'

const template = ref('status')
const who = ref('')
const when = ref('Every weekday morning')
const running = ref(false)
const error = ref('')
const success = ref('')
const tracePath = ref('')

const names = {
  status: 'Daily Status Digest',
  followup: 'Follow-up Reminder',
  invoice: 'Invoice Reminder',
}

async function run() {
  running.value = true
  error.value = ''
  success.value = ''
  tracePath.value = ''
  try {
    const name = names[template.value] || 'Quick Start Flow'
    const { data: created } = await api.post('/api/flows', {
      name,
      description: `For: ${who.value || 'team'}. When: ${when.value}`,
      status: 'draft',
    })
    const flow = created?.data || created
    if (!flow?.id) throw new Error('Could not create flow')
    await api.post(`/api/flows/${flow.id}/publish`)
    await api.post(`/api/flows/${flow.id}/execute`, {})
    success.value = `"${name}" is running. Open Traces to see what happened.`
    tracePath.value = '/traces'
  } catch (e) {
    error.value = e.response?.data?.message || e.message || 'Something went wrong.'
  } finally {
    running.value = false
  }
}
</script>

<style scoped>
.sn-eyebrow { font-size: 11px; letter-spacing: 0.18em; text-transform: uppercase; color: var(--accent); }
.sn-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; }
.sn-btn { background: linear-gradient(135deg, #00E5C8, #087D6E); color: #05070A; }
</style>

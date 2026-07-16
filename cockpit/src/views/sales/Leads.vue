<template>
  <div class="px-6 py-7 max-w-7xl mx-auto">
    <div class="mb-6 flex items-start justify-between gap-4">
      <div>
        <div class="sn-eyebrow">Operate · Sales</div>
        <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">Lead Pipeline</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">
          Every lead captured across email, WhatsApp, and your landing pages.
        </p>
      </div>
      <button type="button" class="sn-btn px-4 py-2 rounded-lg text-sm font-semibold" @click="showForm = !showForm">
        {{ showForm ? 'Cancel' : '+ Add lead' }}
      </button>
    </div>

    <div v-if="showForm" class="sn-card p-5 mb-6 grid md:grid-cols-4 gap-3">
      <input v-model="form.name" placeholder="Name" class="sn-input" />
      <input v-model="form.email" placeholder="Email" class="sn-input" />
      <input v-model="form.phone" placeholder="Phone" class="sn-input" />
      <input v-model="form.whatsapp_number" placeholder="WhatsApp number" class="sn-input" />
      <button type="button" class="sn-btn px-4 py-2 rounded-lg text-sm font-semibold md:col-span-1" :disabled="creating" @click="submitForm">
        {{ creating ? 'Adding…' : 'Add' }}
      </button>
      <p v-if="formError" class="text-xs md:col-span-4" style="color: #FF6B6B;">{{ formError }}</p>
    </div>

    <div class="grid md:grid-cols-3 gap-4 mb-6">
      <div class="sn-card p-4">
        <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Open deals</p>
        <p class="text-2xl font-bold mt-1" style="color: var(--text-primary);">{{ store.summary.open_deals }}</p>
      </div>
      <div class="sn-card p-4">
        <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Stale leads (3d+)</p>
        <p class="text-2xl font-bold mt-1" style="color: #FFAA00;">{{ store.summary.stale_leads }}</p>
      </div>
      <div class="sn-card p-4">
        <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Total leads</p>
        <p class="text-2xl font-bold mt-1" style="color: var(--accent);">{{ store.leads.length }}</p>
      </div>
    </div>

    <div v-if="store.loading" class="text-sm" style="color: var(--text-secondary);">Loading…</div>
    <div v-else class="overflow-x-auto">
      <div class="flex gap-4" style="min-width: max-content;">
        <div v-for="stage in STAGES" :key="stage" class="sn-card p-3" style="width: 260px; flex-shrink: 0;">
          <div class="flex items-center justify-between mb-3">
            <p class="text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
              {{ stage.replace('_', ' ') }}
            </p>
            <span class="text-xs px-2 py-0.5 rounded-full" style="background: var(--bg-elevated); color: var(--text-secondary);">
              {{ (store.leadsByStage[stage] || []).length }}
            </span>
          </div>
          <div class="space-y-2">
            <div v-for="lead in store.leadsByStage[stage]" :key="lead.id" class="p-3 rounded-lg" style="background: var(--bg-elevated); border: 1px solid var(--border);">
              <p class="text-sm font-medium" style="color: var(--text-primary);">{{ lead.name || lead.email || lead.phone || 'Unnamed lead' }}</p>
              <p class="text-xs mt-0.5" style="color: var(--text-muted);">{{ lead.email || lead.whatsapp_number || lead.phone }}</p>
              <div class="flex items-center justify-between mt-2">
                <span class="text-xs" style="color: var(--text-secondary);">Score {{ lead.score }}</span>
                <select
                  class="text-xs rounded px-1 py-0.5"
                  style="background: var(--bg-card); color: var(--text-secondary); border: 1px solid var(--border);"
                  :value="lead.stage"
                  @change="onStageChange(lead, $event.target.value)"
                >
                  <option v-for="s in STAGES" :key="s" :value="s">{{ s.replace('_', ' ') }}</option>
                </select>
              </div>
            </div>
            <p v-if="!(store.leadsByStage[stage] || []).length" class="text-xs italic" style="color: var(--text-muted);">No leads</p>
          </div>
        </div>
      </div>
    </div>
    <p v-if="store.error" class="text-xs mt-4" style="color: #FF6B6B;">{{ store.error }}</p>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useSalesStore, STAGES } from '../../stores/sales.js'

const store = useSalesStore()
const showForm = ref(false)
const creating = ref(false)
const formError = ref('')
const form = ref({ name: '', email: '', phone: '', whatsapp_number: '' })

onMounted(async () => {
  await Promise.all([store.fetchLeads(), store.fetchSummary()])
})

async function submitForm() {
  formError.value = ''
  if (!form.value.email && !form.value.phone && !form.value.whatsapp_number) {
    formError.value = 'Add at least an email, phone, or WhatsApp number.'
    return
  }
  creating.value = true
  const result = await store.createLead({ ...form.value })
  creating.value = false
  if (result.success) {
    form.value = { name: '', email: '', phone: '', whatsapp_number: '' }
    showForm.value = false
    await store.fetchSummary()
  } else {
    formError.value = result.error
  }
}

async function onStageChange(lead, newStage) {
  if (newStage === lead.stage) return
  await store.transitionStage(lead.id, newStage)
  await store.fetchSummary()
}
</script>

<style scoped>
.sn-eyebrow { font-size: 11px; letter-spacing: 0.18em; text-transform: uppercase; color: var(--accent); }
.sn-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; }
.sn-btn { background: linear-gradient(135deg, #00E5C8, #087D6E); color: #05070A; }
.sn-input {
  background: var(--bg-elevated);
  border: 1px solid var(--border);
  color: var(--text-primary);
  border-radius: 8px;
  padding: 0.5rem 0.75rem;
  font-size: 0.875rem;
}
</style>

<template>
  <div class="p-6 space-y-6" :style="{ background: 'var(--bg)' }">
    <div>
      <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">Connectors</h1>
      <p class="text-sm mt-1" :style="{ color: 'var(--text-secondary)' }">
        Connect the tools your business already runs on. Your agents can then act in them —
        posting updates, booking appointments, syncing contacts, or calling your own APIs.
      </p>
    </div>

    <div v-if="loading" class="text-sm" :style="{ color: 'var(--text-muted)' }">Loading connectors…</div>

    <div v-for="(group, category) in grouped" :key="category" class="space-y-3">
      <h2 class="text-xs font-semibold uppercase tracking-wider" :style="{ color: 'var(--text-muted)' }">
        {{ categoryLabel(category) }}
      </h2>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <div v-for="c in group" :key="c.provider" class="dct-card p-5 flex flex-col">
          <div class="flex items-start justify-between gap-2">
            <div>
              <h3 class="font-semibold" :style="{ color: 'var(--text-primary)' }">{{ c.name }}</h3>
              <p class="text-xs mt-1" :style="{ color: 'var(--text-secondary)' }">{{ c.description }}</p>
            </div>
            <span class="text-[10px] px-2 py-1 rounded-full whitespace-nowrap"
              :class="statusClass(c.status)">{{ statusLabel(c.status) }}</span>
          </div>

          <p class="text-[11px] mt-3" :style="{ color: 'var(--text-muted)' }">
            Actions: {{ (c.actions || []).join(', ') || '—' }}
          </p>
          <p v-if="c.last_error" class="text-[11px] mt-1 text-red-500">{{ c.last_error }}</p>

          <div class="mt-4 flex gap-2">
            <button v-if="!c.connected" class="dct-btn-primary px-4 py-2 text-sm" @click="openConnect(c)">Connect</button>
            <template v-else>
              <button class="px-3 py-2 rounded-xl text-sm border" :disabled="busy === c.provider"
                :style="{ borderColor: 'var(--border)', color: 'var(--text-secondary)', background: 'var(--surface-low)' }"
                @click="testOne(c)">{{ busy === c.provider ? 'Testing…' : 'Test' }}</button>
              <button class="px-3 py-2 rounded-xl text-sm border"
                :style="{ borderColor: 'var(--border)', color: 'var(--text-muted)', background: 'var(--surface-low)' }"
                @click="disconnect(c)">Disconnect</button>
            </template>
            <a v-if="c.docs_url" :href="c.docs_url" target="_blank" rel="noopener noreferrer"
              class="px-2 py-2 text-xs underline self-center" style="color: var(--accent);">Docs</a>
          </div>
        </div>
      </div>
    </div>

    <!-- Connect dialog -->
    <div v-if="active" class="fixed inset-0 z-50 flex items-center justify-center p-4"
      style="background: rgba(0,0,0,0.6);" @click.self="active = null">
      <div class="dct-card p-6 w-full max-w-md space-y-4">
        <h3 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Connect {{ active.name }}</h3>
        <p class="text-xs" :style="{ color: 'var(--text-muted)' }">
          Credentials are encrypted before storage and never shown again.
        </p>

        <div v-for="f in active.fields" :key="f.key" class="space-y-1">
          <label class="text-sm" :style="{ color: 'var(--text-secondary)' }">
            {{ f.label }}<span v-if="f.required" class="text-red-500"> *</span>
          </label>
          <input v-model="form[f.key]" :type="f.secret ? 'password' : 'text'"
            :autocomplete="f.secret ? 'new-password' : 'off'"
            class="w-full px-3 py-2 rounded border"
            :style="{ borderColor: 'var(--border)', background: 'var(--surface-low)', color: 'var(--text-primary)' }" />
        </div>

        <p v-if="dialogError" class="text-xs text-red-500">{{ dialogError }}</p>

        <div class="flex gap-2 justify-end pt-2">
          <button class="px-4 py-2 text-sm" :style="{ color: 'var(--text-muted)' }" @click="active = null">Cancel</button>
          <button class="dct-btn-primary px-5 py-2 text-sm" :disabled="saving" @click="save">
            {{ saving ? 'Connecting…' : 'Connect' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '../services/api.js'

const catalogue = ref([])
const loading = ref(true)
const busy = ref(null)
const active = ref(null)
const form = reactive({})
const saving = ref(false)
const dialogError = ref('')

const grouped = computed(() => {
  const out = {}
  for (const c of catalogue.value) (out[c.category] ||= []).push(c)
  return out
})

function categoryLabel(c) {
  return { messaging: 'Messaging', automation: 'Automation & custom', calendar: 'Calendar', crm: 'CRM' }[c] || c
}
function statusLabel(s) {
  return { connected: 'Connected', error: 'Needs attention', pending: 'Pending', not_connected: 'Not connected' }[s] || s
}
function statusClass(s) {
  return s === 'connected' ? 'dct-pill-lime' : s === 'error' ? 'dct-pill-pink' : 'dct-pill-cyan'
}

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/api/integrations/catalogue')
    catalogue.value = data.data || []
  } finally {
    loading.value = false
  }
}

function openConnect(c) {
  active.value = c
  dialogError.value = ''
  for (const k of Object.keys(form)) delete form[k]
  for (const f of c.fields || []) form[f.key] = ''
}

async function save() {
  saving.value = true
  dialogError.value = ''
  try {
    const credentials = {}
    for (const [k, v] of Object.entries(form)) if (v) credentials[k] = v
    const { data } = await api.post(`/api/integrations/${active.value.provider}/authorize`, { credentials })
    if (data.verified === false) dialogError.value = data.error || 'Connected, but verification failed.'
    else active.value = null
    await load()
  } catch (e) {
    dialogError.value = e.response?.data?.error || 'Could not connect.'
  } finally {
    saving.value = false
  }
}

async function testOne(c) {
  busy.value = c.provider
  try {
    await api.post(`/api/integrations/${c.provider}/test`)
    await load()
  } finally {
    busy.value = null
  }
}

async function disconnect(c) {
  if (!confirm(`Disconnect ${c.name}? Stored credentials will be deleted.`)) return
  await api.delete(`/api/integrations/${c.provider}`).catch(() => {})
  await load()
}

onMounted(load)
</script>

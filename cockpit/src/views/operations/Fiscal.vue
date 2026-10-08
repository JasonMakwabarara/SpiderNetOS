<template>
  <div class="p-6 space-y-6" :style="{ background: 'var(--bg)' }">
    <router-link to="/operations" class="text-sm" :style="{ color: 'var(--charge-vivid)' }">Operations</router-link>
    <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">Fiscalisation</h1>
    <p class="text-sm" :style="{ color: 'var(--text-secondary)' }">Sandbox submissions only. Live ZIMRA is not called from this screen.</p>
    <form class="dct-card p-4 flex gap-3" @submit.prevent="createDevice">
      <input v-model="device.name" required placeholder="Device name" class="px-3 py-2 rounded border flex-1" :style="field" />
      <input v-model="device.device_identifier" required placeholder="Device id" class="px-3 py-2 rounded border" :style="field" />
      <button class="dct-btn-primary px-4 py-2 text-sm">Register</button>
    </form>
    <form class="dct-card p-4 grid gap-3 md:grid-cols-3" @submit.prevent="fiscalise">
      <input v-model="fiscal.invoice_id" required placeholder="Invoice id" class="px-3 py-2 rounded border" :style="field" />
      <select v-model="fiscal.device_id" required class="px-3 py-2 rounded border" :style="field">
        <option value="">Device</option>
        <option v-for="row in devices" :key="row.id" :value="row.id">{{ row.name }}</option>
      </select>
      <button class="dct-btn-primary px-4 py-2 text-sm">Fiscalise</button>
    </form>
    <p v-if="message" class="text-sm">{{ message }}</p>
    <p v-for="row in submissions" :key="row.id" class="text-sm" :style="{ color: 'var(--text-secondary)' }">
      {{ row.status }} · {{ row.verification_code || 'no code' }} · live {{ row.is_live }}
    </p>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '../../services/api.js'
const field = { background: 'var(--surface-low)', color: 'var(--text-primary)', borderColor: 'var(--border)' }
const devices = ref([])
const submissions = ref([])
const message = ref('')
const device = ref({ name: '', device_identifier: '', status: 'active' })
const fiscal = ref({ invoice_id: '', device_id: '' })
async function load() {
  devices.value = (await api.get('/api/enterprise/fiscal-devices')).data.data || []
  submissions.value = (await api.get('/api/enterprise/fiscal-submissions')).data.data || []
}
async function createDevice() {
  try { await api.post('/api/enterprise/fiscal-devices', device.value); device.value = { name: '', device_identifier: '', status: 'active' }; await load() }
  catch (err) { message.value = err.response?.data?.message || 'Could not register the device.' }
}
async function fiscalise() {
  try {
    await api.post(`/api/enterprise/invoices/${fiscal.value.invoice_id}/fiscalise`, { device_id: fiscal.value.device_id })
    await load()
  } catch (err) { message.value = err.response?.data?.message || 'Could not fiscalise the invoice.' }
}
onMounted(load)
</script>

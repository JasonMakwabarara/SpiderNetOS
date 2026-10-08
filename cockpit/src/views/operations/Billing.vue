<template>
  <div class="p-6 space-y-6" :style="{ background: 'var(--bg)' }">
    <router-link to="/operations" class="text-sm" :style="{ color: 'var(--charge-vivid)' }">Operations</router-link>
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">Credit notes</h1>
      <router-link to="/financial/invoices" class="text-sm" :style="{ color: 'var(--charge-vivid)' }">Invoices</router-link>
    </div>
    <form class="dct-card p-4 grid gap-3 md:grid-cols-3" @submit.prevent="create">
      <input v-model="form.invoice_id" required placeholder="Invoice id" class="px-3 py-2 rounded border" :style="field" />
      <input v-model="form.description" required placeholder="Line description" class="px-3 py-2 rounded border" :style="field" />
      <button class="dct-btn-primary px-4 py-2 text-sm">Draft credit note</button>
    </form>
    <p v-if="message" class="text-sm">{{ message }}</p>
    <div v-for="row in rows" :key="row.id" class="flex justify-between text-sm">
      <span :style="{ color: 'var(--text-primary)' }">{{ row.credit_note_number }} · {{ row.currency }} {{ row.total_amount }} · {{ row.status }}</span>
      <button v-if="row.status === 'draft'" @click="issue(row.id)" :style="{ color: 'var(--charge-vivid)' }">Issue</button>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '../../services/api.js'
const field = { background: 'var(--surface-low)', color: 'var(--text-primary)', borderColor: 'var(--border)' }
const rows = ref([])
const message = ref('')
const form = ref({ invoice_id: '', description: '' })
async function load() { rows.value = (await api.get('/api/enterprise/credit-notes')).data.data || [] }
async function create() {
  try {
    await api.post('/api/enterprise/credit-notes', {
      invoice_id: form.value.invoice_id,
      lines: [{ description: form.value.description, quantity: 1, unit_price: 1 }],
    })
    await load()
  } catch (err) { message.value = err.response?.data?.message || 'Could not create the credit note.' }
}
async function issue(id) {
  try { await api.post(`/api/enterprise/credit-notes/${id}/issue`); await load() }
  catch (err) { message.value = err.response?.data?.message || 'Could not issue the credit note.' }
}
onMounted(load)
</script>

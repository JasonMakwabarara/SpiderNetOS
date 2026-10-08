<template>
  <div class="p-6 space-y-6" :style="{ background: 'var(--bg)' }" data-testid="ops-requisitions">
    <router-link to="/operations" class="text-sm" :style="{ color: 'var(--charge-vivid)' }">Operations</router-link>
    <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">Requisitions</h1>
    <form class="dct-card p-4 grid gap-3" @submit.prevent="create">
      <input v-model="form.title" required placeholder="Title" class="px-3 py-2 rounded border" :style="field" data-testid="requisition-title" />
      <input v-model="form.description" required placeholder="Line description" class="px-3 py-2 rounded border" :style="field" />
      <button class="dct-btn-primary px-4 py-2 text-sm w-fit" data-testid="requisition-create">Create draft</button>
    </form>
    <p v-if="message" class="text-sm">{{ message }}</p>
    <div v-for="row in rows" :key="row.id" class="dct-card p-3 flex justify-between items-center" :style="{ background: 'var(--surface-low)' }">
      <div>
        <p :style="{ color: 'var(--text-primary)' }">{{ row.requisition_number }} · {{ row.title }}</p>
        <p class="text-xs" :style="{ color: 'var(--text-muted)' }">{{ row.status }}</p>
      </div>
      <button v-if="row.status === 'draft'" class="text-sm" :style="{ color: 'var(--charge-vivid)' }" @click="submit(row.id)">Submit</button>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '../../services/api.js'
const field = { background: 'var(--surface-low)', color: 'var(--text-primary)', borderColor: 'var(--border)' }
const rows = ref([])
const message = ref('')
const form = ref({ title: '', description: '' })
async function load() { rows.value = (await api.get('/api/enterprise/requisitions')).data.data || [] }
async function create() {
  try {
    await api.post('/api/enterprise/requisitions', {
      title: form.value.title,
      lines: [{ description: form.value.description, quantity: 1, unit_price: 0 }],
    })
    form.value = { title: '', description: '' }
    await load()
  } catch (err) { message.value = err.response?.data?.message || 'Could not create the requisition.' }
}
async function submit(id) {
  try { await api.post(`/api/enterprise/requisitions/${id}/submit`); await load() }
  catch (err) { message.value = err.response?.data?.message || 'Could not submit.' }
}
onMounted(load)
</script>

<template>
  <div class="p-6 space-y-6" :style="{ background: 'var(--bg)' }">
    <router-link to="/operations" class="text-sm" :style="{ color: 'var(--charge-vivid)' }">Operations</router-link>
    <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">Assets</h1>
    <form class="dct-card p-4 flex gap-3" @submit.prevent="create">
      <input v-model="form.tag" required placeholder="Tag" class="px-3 py-2 rounded border" :style="field" />
      <input v-model="form.name" required placeholder="Name" class="px-3 py-2 rounded border flex-1" :style="field" />
      <button class="dct-btn-primary px-4 py-2 text-sm">Add asset</button>
    </form>
    <p v-if="message" class="text-sm">{{ message }}</p>
    <div v-for="row in rows" :key="row.id" class="text-sm flex justify-between">
      <span :style="{ color: 'var(--text-primary)' }">{{ row.tag }} · {{ row.name }} · {{ row.status }}</span>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '../../services/api.js'
const field = { background: 'var(--surface-low)', color: 'var(--text-primary)', borderColor: 'var(--border)' }
const rows = ref([])
const message = ref('')
const form = ref({ tag: '', name: '' })
async function load() { rows.value = (await api.get('/api/enterprise/assets')).data.data || [] }
async function create() {
  try { await api.post('/api/enterprise/assets', form.value); form.value = { tag: '', name: '' }; await load() }
  catch (err) { message.value = err.response?.data?.message || 'Could not create the asset.' }
}
onMounted(load)
</script>

<template>
  <div class="p-6 space-y-6" :style="{ background: 'var(--bg)' }">
    <router-link to="/operations" class="text-sm" :style="{ color: 'var(--charge-vivid)' }">Operations</router-link>
    <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">Attendance</h1>
    <form class="dct-card p-4 grid gap-3 md:grid-cols-4" @submit.prevent="create">
      <input v-model="form.employee_id" required placeholder="Employee id" class="px-3 py-2 rounded border" :style="field" />
      <select v-model="form.type" class="px-3 py-2 rounded border" :style="field">
        <option value="in">Clock in</option>
        <option value="out">Clock out</option>
      </select>
      <button class="dct-btn-primary px-4 py-2 text-sm">Record</button>
    </form>
    <p v-if="message" class="text-sm">{{ message }}</p>
    <ul class="space-y-2">
      <li v-for="row in rows" :key="row.id" class="text-sm" :style="{ color: 'var(--text-secondary)' }">
        {{ row.type }} · {{ row.employee_id }}
      </li>
    </ul>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '../../services/api.js'
const field = { background: 'var(--surface-low)', color: 'var(--text-primary)', borderColor: 'var(--border)' }
const rows = ref([])
const message = ref('')
const form = ref({ employee_id: '', type: 'in' })
async function load() { rows.value = (await api.get('/api/enterprise/clock-events')).data.data || [] }
async function create() {
  try { await api.post('/api/enterprise/clock-events', form.value); await load() }
  catch (err) { message.value = err.response?.data?.message || 'Could not record the clock event.' }
}
onMounted(load)
</script>

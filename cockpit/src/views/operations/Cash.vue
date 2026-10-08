<template>
  <div class="p-6 space-y-6" :style="{ background: 'var(--bg)' }">
    <router-link to="/operations" class="text-sm" :style="{ color: 'var(--charge-vivid)' }">Operations</router-link>
    <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">Cash</h1>
    <form class="dct-card p-4 flex gap-3" @submit.prevent="createBook">
      <input v-model="book.name" required placeholder="Cashbook name" class="px-3 py-2 rounded border flex-1" :style="field" />
      <input v-model="book.currency" required placeholder="USD" class="px-3 py-2 rounded border w-24" :style="field" />
      <button class="dct-btn-primary px-4 py-2 text-sm">Add cashbook</button>
    </form>
    <form class="dct-card p-4 grid gap-3 md:grid-cols-5" @submit.prevent="createMovement">
      <select v-model="movement.cashbook_id" required class="px-3 py-2 rounded border" :style="field">
        <option value="">Cashbook</option>
        <option v-for="row in books" :key="row.id" :value="row.id">{{ row.name }} ({{ row.currency }})</option>
      </select>
      <select v-model="movement.type" class="px-3 py-2 rounded border" :style="field">
        <option value="receipt">Receipt</option>
        <option value="payment">Payment</option>
      </select>
      <input v-model="movement.amount" required type="number" min="0.01" step="0.01" class="px-3 py-2 rounded border" :style="field" />
      <input v-model="movement.movement_date" required type="date" class="px-3 py-2 rounded border" :style="field" />
      <button class="dct-btn-primary px-4 py-2 text-sm">Record</button>
    </form>
    <p v-if="message" class="text-sm">{{ message }}</p>
    <p v-for="row in movements" :key="row.id" class="text-sm" :style="{ color: 'var(--text-secondary)' }">
      {{ row.type }} {{ row.currency }} {{ row.amount }}
    </p>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '../../services/api.js'
const field = { background: 'var(--surface-low)', color: 'var(--text-primary)', borderColor: 'var(--border)' }
const books = ref([])
const movements = ref([])
const message = ref('')
const book = ref({ name: '', currency: 'USD' })
const movement = ref({ cashbook_id: '', type: 'receipt', amount: '', movement_date: new Date().toISOString().slice(0, 10), currency: '' })
async function load() {
  books.value = (await api.get('/api/enterprise/cashbooks')).data.data || []
  movements.value = (await api.get('/api/enterprise/cash-movements')).data.data || []
}
async function createBook() {
  try { await api.post('/api/enterprise/cashbooks', book.value); book.value = { name: '', currency: 'USD' }; await load() }
  catch (err) { message.value = err.response?.data?.message || 'Could not create the cashbook.' }
}
async function createMovement() {
  const selected = books.value.find((row) => row.id === movement.value.cashbook_id)
  try {
    await api.post('/api/enterprise/cash-movements', { ...movement.value, currency: selected?.currency || 'USD' })
    await load()
  } catch (err) { message.value = err.response?.data?.message || 'Could not record the movement.' }
}
onMounted(load)
</script>

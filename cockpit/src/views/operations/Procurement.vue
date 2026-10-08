<template>
  <div class="p-6 space-y-6" :style="{ background: 'var(--bg)' }">
    <router-link to="/operations" class="text-sm" :style="{ color: 'var(--charge-vivid)' }">Operations</router-link>
    <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">Procurement</h1>
    <form class="dct-card p-4 flex gap-3" @submit.prevent="createVendor">
      <input v-model="vendorName" required placeholder="Supplier name" class="px-3 py-2 rounded border flex-1" :style="field" />
      <button class="dct-btn-primary px-4 py-2 text-sm">Add supplier</button>
    </form>
    <form class="dct-card p-4 grid gap-3 md:grid-cols-4" @submit.prevent="createOrder">
      <select v-model="order.vendor_id" required class="px-3 py-2 rounded border" :style="field">
        <option value="">Supplier</option>
        <option v-for="v in vendors" :key="v.id" :value="v.id">{{ v.name }}</option>
      </select>
      <input v-model="order.currency" required placeholder="USD" class="px-3 py-2 rounded border" :style="field" />
      <input v-model="order.amount" required type="number" min="0" step="0.01" class="px-3 py-2 rounded border" :style="field" />
      <button class="dct-btn-primary px-4 py-2 text-sm">Draft PO</button>
    </form>
    <p v-if="message" class="text-sm">{{ message }}</p>
    <div v-for="row in orders" :key="row.id" class="flex justify-between text-sm">
      <span :style="{ color: 'var(--text-primary)' }">{{ row.po_number }} · {{ row.currency }} {{ row.amount }} · {{ row.status }}</span>
      <button v-if="row.status === 'draft'" @click="issue(row.id)" :style="{ color: 'var(--charge-vivid)' }">Issue</button>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '../../services/api.js'
const field = { background: 'var(--surface-low)', color: 'var(--text-primary)', borderColor: 'var(--border)' }
const vendors = ref([])
const orders = ref([])
const vendorName = ref('')
const message = ref('')
const order = ref({ vendor_id: '', currency: 'USD', amount: 0 })
async function load() {
  vendors.value = (await api.get('/api/enterprise/vendors')).data.data || []
  orders.value = (await api.get('/api/enterprise/purchase-orders')).data.data || []
}
async function createVendor() {
  try { await api.post('/api/enterprise/vendors', { name: vendorName.value }); vendorName.value = ''; await load() }
  catch (err) { message.value = err.response?.data?.message || 'Could not create the supplier.' }
}
async function createOrder() {
  try { await api.post('/api/enterprise/purchase-orders', order.value); await load() }
  catch (err) { message.value = err.response?.data?.message || 'Could not create the purchase order.' }
}
async function issue(id) {
  try { await api.post(`/api/enterprise/purchase-orders/${id}/issue`); await load() }
  catch (err) { message.value = err.response?.data?.message || 'Could not issue the purchase order.' }
}
onMounted(load)
</script>

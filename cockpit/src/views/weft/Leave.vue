<template>
  <div class="p-6 space-y-6" :style="{ background: 'var(--bg)' }" data-testid="weft-leave">
    <WeftSubnav />
    <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">Leave</h1>
    <form class="dct-card p-4 grid gap-3 md:grid-cols-2" @submit.prevent="submit">
      <select v-model="form.employee_id" required class="px-3 py-2 rounded border" :style="field" data-testid="leave-employee">
        <option value="">Employee</option>
        <option v-for="person in people" :key="person.id" :value="person.id">{{ person.name }}</option>
      </select>
      <select v-model="form.leave_type_id" required class="px-3 py-2 rounded border" :style="field" data-testid="leave-type">
        <option value="">Type</option>
        <option v-for="type in types" :key="type.id" :value="type.id">{{ type.name }}</option>
      </select>
      <input v-model="form.starts_on" type="date" required class="px-3 py-2 rounded border" :style="field" data-testid="leave-start" />
      <input v-model="form.ends_on" type="date" required class="px-3 py-2 rounded border" :style="field" data-testid="leave-end" />
      <button class="dct-btn-primary px-4 py-2 text-sm" data-testid="leave-submit">Request leave</button>
    </form>
    <p v-if="message" class="text-sm">{{ message }}</p>
    <article v-for="row in rows" :key="row.id" class="dct-card p-4 text-sm space-y-2" :style="{ color: 'var(--text-primary)' }">
      <p>{{ row.employee?.name }} · {{ row.leave_type?.name || row.leaveType?.name }} · {{ String(row.starts_on).slice(0, 10) }} to {{ String(row.ends_on).slice(0, 10) }} · {{ row.status }}</p>
      <div v-if="row.status === 'pending'" class="flex gap-2">
        <button class="dct-btn-primary px-3 py-1 text-sm" :data-testid="`leave-approve-${row.id}`" @click="resolve(row, true)">Approve</button>
        <button class="px-3 py-1 text-sm" @click="resolve(row, false)">Reject</button>
      </div>
    </article>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import WeftSubnav from '../../components/weft/WeftSubnav.vue'
import api from '../../services/api.js'

const field = { background: 'var(--surface-low)', color: 'var(--text-primary)', borderColor: 'var(--border)' }
const people = ref([])
const types = ref([])
const rows = ref([])
const message = ref('')
const form = ref({ employee_id: '', leave_type_id: '', starts_on: '', ends_on: '' })

async function load() {
  people.value = (await api.get('/api/enterprise/employees', { params: { status: 'active' } })).data.data || []
  types.value = (await api.get('/api/enterprise/leave/types')).data.data || []
  rows.value = (await api.get('/api/enterprise/leave/requests')).data.data || []
}

async function submit() {
  message.value = ''
  try {
    await api.post('/api/enterprise/leave/requests', form.value)
    form.value = { employee_id: '', leave_type_id: '', starts_on: '', ends_on: '' }
    await load()
  } catch (err) {
    message.value = err.response?.data?.message || 'Could not request leave.'
  }
}

async function resolve(row, approved) {
  await api.post(`/api/enterprise/leave/requests/${row.id}/resolve`, { approved })
  await load()
}

onMounted(load)
</script>

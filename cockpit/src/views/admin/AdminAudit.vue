<template>
  <div class="p-6 space-y-4">
    <header class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold">Audit log</h1>
        <p class="text-sm text-gray-500">Every admin action is recorded. Filter, inspect, export.</p>
      </div>
      <button class="px-3 py-2 rounded border text-sm" @click="exportCsv">Export CSV</button>
    </header>

    <FilterBar v-model="filters" :fields="filterFields" @apply="load" @reset="load" />

    <DataTable :columns="columns" :rows="events" :loading="loading" caption="Audit events" rowKeyField="id">
      <template #cell-actor_role="{ row }">
        <RoleBadge :role="row.actor_role" />
      </template>
      <template #cell-event_type="{ value }">
        <code class="text-xs bg-gray-100 px-1 rounded">{{ value }}</code>
      </template>
      <template #actions="{ row }">
        <button class="text-xs text-indigo-600" @click="inspect(row)">Inspect</button>
      </template>
      <template #empty>
        <EmptyState title="No audit events yet" description="Admin actions will appear here as they happen." />
      </template>
    </DataTable>

    <JsonDrawer v-model="drawerOpen" :payload="selected" title="Audit event payload" />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'
import DataTable   from '../../components/data/DataTable.vue'
import FilterBar   from '../../components/data/FilterBar.vue'
import EmptyState  from '../../components/data/EmptyState.vue'
import JsonDrawer  from '../../components/data/JsonDrawer.vue'
import RoleBadge   from '../../components/security/RoleBadge.vue'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000'

const loading     = ref(false)
const events      = ref([])
const drawerOpen  = ref(false)
const selected    = ref(null)

const filters     = ref({})
const filterFields = [
  { key: 'actor',      label: 'Actor',      type: 'text' },
  { key: 'event_type', label: 'Event type', type: 'text' },
  { key: 'from',       label: 'From',       type: 'date' },
  { key: 'to',         label: 'To',         type: 'date' },
]

const columns = [
  { key: 'occurred_at', label: 'When',  sortable: true },
  { key: 'actor_email', label: 'Actor' },
  { key: 'actor_role',  label: 'Role' },
  { key: 'event_type',  label: 'Event' },
  { key: 'target_id',   label: 'Target' },
]

function inspect(row) {
  selected.value = row
  drawerOpen.value = true
}

async function load() {
  loading.value = true
  try {
    const { data } = await axios.get(`${API_URL}/api/admin/audit`, { params: filters.value })
    events.value = data?.events || []
  } catch {
    events.value = []
  } finally {
    loading.value = false
  }
}

function exportCsv() {
  const header = columns.map((c) => c.label).join(',')
  const rows   = events.value.map((e) => columns.map((c) => JSON.stringify(e[c.key] ?? '')).join(','))
  const blob   = new Blob([[header, ...rows].join('\n')], { type: 'text/csv' })
  const a      = document.createElement('a')
  a.href       = URL.createObjectURL(blob)
  a.download   = `audit-${new Date().toISOString().slice(0, 10)}.csv`
  a.click()
}

onMounted(load)
</script>

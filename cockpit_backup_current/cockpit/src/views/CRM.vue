<template>
  <div class="p-6">
    <div class="flex justify-between items-center mb-6">
      <h1 class="text-2xl font-bold"> CRM Records</h1>
      <div class="flex gap-2">
        <button @click="downloadJSON" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600"> Download JSON</button>
        <button @click="downloadCSV" class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600"> Download CSV</button>
      </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
      <div v-if="loading" class="text-center py-8">Loading...</div>
      <div v-else-if="crmRecords.length === 0" class="text-center py-8 text-gray-500">
        No CRM records yet. Update CRM with: <code class="bg-gray-100 px-2 py-1 rounded">update crm [field] to [value]</code>
      </div>
      <div v-else>
        <table class="min-w-full">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-2 text-left">ID</th>
              <th class="px-4 py-2 text-left">Field</th>
              <th class="px-4 py-2 text-left">Value</th>
              <th class="px-4 py-2 text-left">Updated</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="record in crmRecords" :key="record.id" class="border-t">
              <td class="px-4 py-2 text-sm font-mono">{{ record.id.substring(0, 8) }}</td>
              <td class="px-4 py-2 font-medium">{{ record.field }}</td>
              <td class="px-4 py-2">{{ record.value }}</td>
              <td class="px-4 py-2 text-sm text-gray-500">{{ new Date(record.created_at).toLocaleString() }}</td>
            </tr>
          </tbody>
        </table>
        <div class="mt-4 text-sm text-gray-500">Total: {{ crmRecords.length }} records</div>
      </div>
    </div>
  </div>
</template>

<script>
import api from '../services/api.js'

export default {
  data() {
    return { crmRecords: [], loading: false }
  },
  mounted() { this.fetchCRM() },
  methods: {
    async fetchCRM() {
      this.loading = true
      try {
        const { data } = await api.get('/crm')
        this.crmRecords = data
      } catch (error) { console.error('Error:', error) }
      finally { this.loading = false }
    },
    downloadJSON() {
      const json = JSON.stringify(this.crmRecords, null, 2)
      const blob = new Blob([json], { type: 'application/json' })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `crm_records_${new Date().toISOString().slice(0,10)}.json`
      a.click()
      URL.revokeObjectURL(url)
    },
    downloadCSV() {
      if (this.crmRecords.length === 0) return
      const headers = ['ID', 'Field', 'Value', 'Updated At']
      const rows = this.crmRecords.map(r => [
        r.id,
        `"${r.field}"`,
        `"${r.value}"`,
        r.created_at
      ])
      const csv = [headers.join(','), ...rows.map(r => r.join(','))].join('\n')
      const blob = new Blob([csv], { type: 'text/csv' })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `crm_records_${new Date().toISOString().slice(0,10)}.csv`
      a.click()
      URL.revokeObjectURL(url)
    }
  }
}
</script>

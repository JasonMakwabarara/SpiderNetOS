<template>
  <div class="p-6">
    <div class="flex justify-between items-center mb-6">
      <h1 class="text-2xl font-bold"> Flows</h1>
      <div class="flex gap-2">
        <button @click="downloadJSON" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600"> Download JSON</button>
        <button @click="downloadCSV" class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600"> Download CSV</button>
        <button @click="createNewFlow" class="bg-orange-500 text-white px-4 py-2 rounded hover:bg-orange-600">+ New Flow</button>
      </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
      <div v-if="loading" class="text-center py-8">Loading...</div>
      <div v-else-if="flows.length === 0" class="text-center py-8 text-gray-500">
        No flows yet. Create one with: <code class="bg-gray-100 px-2 py-1 rounded">create flow [name]</code>
      </div>
      <div v-else>
        <table class="min-w-full">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-2 text-left">ID</th>
              <th class="px-4 py-2 text-left">Name</th>
              <th class="px-4 py-2 text-left">Description</th>
              <th class="px-4 py-2 text-left">Status</th>
              <th class="px-4 py-2 text-left">Triggers</th>
              <th class="px-4 py-2 text-left">Steps</th>
              <th class="px-4 py-2 text-left">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="flow in flows" :key="flow.id" class="border-t">
              <td class="px-4 py-2 text-sm font-mono">{{ flow.id.substring(0, 8) }}</td>
              <td class="px-4 py-2 font-medium">{{ flow.name }}</td>
              <td class="px-4 py-2 text-sm text-gray-600">{{ flow.description || '-' }}</td>
              <td class="px-4 py-2">
                <span :class="{
                  'text-green-600': flow.status === 'published',
                  'text-yellow-600': flow.status === 'draft',
                  'text-gray-600': flow.status === 'archived'
                }">{{ flow.status }}</span>
              </td>
              <td class="px-4 py-2">{{ (flow.triggers || []).join(', ') }}</td>
              <td class="px-4 py-2">{{ (flow.dag?.steps || []).length }}</td>
              <td class="px-4 py-2">
                <button @click="executeFlow(flow.id)" class="text-green-500 hover:text-green-700 mr-2"> Execute</button>
                <button @click="editFlow(flow.id)" class="text-yellow-500 hover:text-yellow-700 mr-2"> Edit</button>
                <button @click="deleteFlow(flow.id)" class="text-red-500 hover:text-red-700"> Delete</button>
              </td>
            </tr>
          </tbody>
        </table>
        <div class="mt-4 text-sm text-gray-500">Total: {{ flows.length }} flows</div>
      </div>
    </div>
  </div>
</template>

<script>
import api from '../services/api.js'

export default {
  data() {
    return { flows: [], loading: false }
  },
  mounted() { this.fetchFlows() },
  methods: {
    async fetchFlows() {
      this.loading = true
      try {
        const { data } = await api.get('/flows')
        this.flows = data
      } catch (error) { console.error('Error:', error) }
      finally { this.loading = false }
    },
    createNewFlow() {
      this.$router.push('/flows/new')
    },
    async executeFlow(id) {
      try {
        const { data } = await api.post(`/flows/${id}/execute`)
        alert(' Flow executed successfully!\n' + JSON.stringify(data.results, null, 2))
        await this.fetchFlows()
      } catch (error) { alert('Error: ' + error.message) }
    },
    editFlow(id) {
      this.$router.push(`/flows/${id}/edit`)
    },
    async deleteFlow(id) {
      if (!confirm('Delete this flow?')) return
      try {
        await api.delete(`/flows/${id}`)
        await this.fetchFlows()
      } catch (error) { alert('Error: ' + error.message) }
    },
    downloadJSON() {
      const json = JSON.stringify(this.flows, null, 2)
      const blob = new Blob([json], { type: 'application/json' })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `flows_${new Date().toISOString().slice(0,10)}.json`
      a.click()
      URL.revokeObjectURL(url)
    },
    downloadCSV() {
      if (this.flows.length === 0) return
      const headers = ['ID', 'Name', 'Description', 'Status', 'Triggers', 'Steps']
      const rows = this.flows.map(f => [
        f.id,
        `"${f.name}"`,
        `"${f.description || ''}"`,
        f.status,
        `"${(f.triggers || []).join('; ')}"`,
        (f.dag?.steps || []).length
      ])
      const csv = [headers.join(','), ...rows.map(r => r.join(','))].join('\n')
      const blob = new Blob([csv], { type: 'text/csv' })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `flows_${new Date().toISOString().slice(0,10)}.csv`
      a.click()
      URL.revokeObjectURL(url)
    }
  }
}
</script>

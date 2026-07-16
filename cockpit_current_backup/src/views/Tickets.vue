<template>
  <div class="p-6">
    <div class="flex justify-between items-center mb-6">
      <h1 class="text-2xl font-bold"> Tickets</h1>
      <div class="flex gap-2">
        <button @click="downloadJSON" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600"> Download JSON</button>
        <button @click="downloadCSV" class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600"> Download CSV</button>
      </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
      <div v-if="loading" class="text-center py-8">Loading...</div>
      <div v-else-if="tickets.length === 0" class="text-center py-8 text-gray-500">
        No tickets yet. Create one with: <code class="bg-gray-100 px-2 py-1 rounded">create ticket [title] for [description]</code>
      </div>
      <div v-else>
        <table class="min-w-full">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-2 text-left">ID</th>
              <th class="px-4 py-2 text-left">Title</th>
              <th class="px-4 py-2 text-left">Description</th>
              <th class="px-4 py-2 text-left">Status</th>
              <th class="px-4 py-2 text-left">Priority</th>
              <th class="px-4 py-2 text-left">Created</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="ticket in tickets" :key="ticket.id" class="border-t">
              <td class="px-4 py-2 text-sm font-mono">{{ ticket.id.substring(0, 8) }}</td>
              <td class="px-4 py-2 font-medium">{{ ticket.title }}</td>
              <td class="px-4 py-2 text-sm text-gray-600">{{ ticket.description }}</td>
              <td class="px-4 py-2">
                <span :class="{
                  'text-green-600': ticket.status === 'closed',
                  'text-yellow-600': ticket.status === 'open',
                  'text-red-600': ticket.status === 'pending'
                }">{{ ticket.status }}</span>
              </td>
              <td class="px-4 py-2">{{ ticket.priority }}</td>
              <td class="px-4 py-2 text-sm text-gray-500">{{ new Date(ticket.created_at).toLocaleString() }}</td>
            </tr>
          </tbody>
        </table>
        <div class="mt-4 text-sm text-gray-500">Total: {{ tickets.length }} tickets</div>
      </div>
    </div>
  </div>
</template>

<script>
import api from '../services/api.js'

export default {
  data() {
    return { tickets: [], loading: false }
  },
  mounted() { this.fetchTickets() },
  methods: {
    async fetchTickets() {
      this.loading = true
      try {
        const { data } = await api.get('/tickets')
        this.tickets = data
      } catch (error) { console.error('Error:', error) }
      finally { this.loading = false }
    },
    downloadJSON() {
      const json = JSON.stringify(this.tickets, null, 2)
      const blob = new Blob([json], { type: 'application/json' })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `tickets_${new Date().toISOString().slice(0,10)}.json`
      a.click()
      URL.revokeObjectURL(url)
    },
    downloadCSV() {
      if (this.tickets.length === 0) return
      const headers = ['ID', 'Title', 'Description', 'Status', 'Priority', 'Created At']
      const rows = this.tickets.map(t => [
        t.id,
        `"${t.title}"`,
        `"${t.description || ''}"`,
        t.status,
        t.priority,
        t.created_at
      ])
      const csv = [headers.join(','), ...rows.map(r => r.join(','))].join('\n')
      const blob = new Blob([csv], { type: 'text/csv' })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `tickets_${new Date().toISOString().slice(0,10)}.csv`
      a.click()
      URL.revokeObjectURL(url)
    }
  }
}
</script>

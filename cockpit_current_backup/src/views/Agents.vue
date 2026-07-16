<template>
  <div class="p-6">
    <div class="flex justify-between items-center mb-6">
      <h1 class="text-2xl font-bold"> Agents</h1>
      <div class="flex gap-2">
        <button @click="downloadJSON" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600"> Download JSON</button>
        <button @click="downloadCSV" class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600"> Download CSV</button>
        <button @click="openCreateModal" class="bg-orange-500 text-white px-4 py-2 rounded hover:bg-orange-600">+ New Agent</button>
      </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
      <div v-if="loading" class="text-center py-8">Loading...</div>
      <div v-else-if="agents.length === 0" class="text-center py-8 text-gray-500">
        No agents yet. Create one with: <code class="bg-gray-100 px-2 py-1 rounded">create agent [name]</code>
      </div>
      <div v-else>
        <table class="min-w-full">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-2 text-left">ID</th>
              <th class="px-4 py-2 text-left">Name</th>
              <th class="px-4 py-2 text-left">Description</th>
              <th class="px-4 py-2 text-left">Type</th>
              <th class="px-4 py-2 text-left">Status</th>
              <th class="px-4 py-2 text-left">Capabilities</th>
              <th class="px-4 py-2 text-left">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="agent in agents" :key="agent.id" class="border-t">
              <td class="px-4 py-2 text-sm font-mono">{{ agent.id.substring(0, 8) }}</td>
              <td class="px-4 py-2 font-medium">{{ agent.name }}</td>
              <td class="px-4 py-2 text-sm text-gray-600">{{ agent.description || '-' }}</td>
              <td class="px-4 py-2">{{ agent.type || 'custom' }}</td>
              <td class="px-4 py-2">
                <span :class="{
                  'text-green-600': agent.status === 'active',
                  'text-gray-600': agent.status === 'inactive',
                  'text-red-600': agent.status === 'archived'
                }">{{ agent.status }}</span>
              </td>
              <td class="px-4 py-2">
                <span v-for="cap in (agent.capabilities || [])" :key="cap" class="inline-block bg-gray-200 text-xs px-2 py-1 rounded mr-1">
                  {{ cap }}
                </span>
              </td>
              <td class="px-4 py-2">
                <button @click="goToChat(agent.id)" class="text-blue-500 hover:text-blue-700 mr-2"> Chat</button>
                <button @click="editAgent(agent.id)" class="text-yellow-500 hover:text-yellow-700 mr-2"> Edit</button>
                <button @click="deleteAgent(agent.id)" class="text-red-500 hover:text-red-700"> Delete</button>
              </td>
            </tr>
          </tbody>
        </table>
        <div class="mt-4 text-sm text-gray-500">Total: {{ agents.length }} agents</div>
      </div>
    </div>

    <!-- Create Modal -->
    <div v-if="showModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-white rounded-lg p-6 w-full max-w-md">
        <h2 class="text-xl font-bold mb-4">Create New Agent</h2>
        <input v-model="newAgent.name" placeholder="Agent Name" class="w-full p-2 mb-2 border rounded" />
        <input v-model="newAgent.description" placeholder="Description" class="w-full p-2 mb-2 border rounded" />
        <select v-model="newAgent.capabilities" multiple class="w-full p-2 mb-2 border rounded">
          <option value="chat">Chat</option>
          <option value="email">Email</option>
          <option value="slack">Slack</option>
          <option value="webhook">Webhook</option>
        </select>
        <div class="flex gap-2 mt-4">
          <button @click="createAgent" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">Create</button>
          <button @click="showModal = false" class="bg-gray-300 px-4 py-2 rounded hover:bg-gray-400">Cancel</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import api from '../services/api.js'

export default {
  data() {
    return {
      agents: [],
      loading: false,
      showModal: false,
      newAgent: { name: '', description: '', capabilities: [] }
    }
  },
  mounted() { this.fetchAgents() },
  methods: {
    goToChat(id) {
      this.$router.push(`/agents/${id}/chat`)
    },
    goToChat(id) {
      this.$router.push(`/agents/${id}/chat`)
    },
    async fetchAgents() {
      this.loading = true
      try {
        const { data } = await api.get('/agents')
        this.agents = data
      } catch (error) { console.error('Error:', error) }
      finally { this.loading = false }
    },
    async createAgent() {
      if (!this.newAgent.name) return
      try {
        await api.post('/agents', this.newAgent)
        this.showModal = false
        this.newAgent = { name: '', description: '', capabilities: [] }
        await this.fetchAgents()
      } catch (error) { alert('Error: ' + error.message) }
    },
    async deleteAgent(id) {
      if (!confirm('Delete this agent?')) return
      try {
        await api.delete(`/agents/${id}`)
        await this.fetchAgents()
      } catch (error) { alert('Error: ' + error.message) }
    },
    chatAgent(id) {
      this.$router.push(`/agents/${id}/chat`)
    },
    editAgent(id) {
      this.$router.push(`/agents/${id}/edit`)
    },
    openCreateModal() {
      this.showModal = true
      this.newAgent = { name: '', description: '', capabilities: [] }
    },
    downloadJSON() {
      const json = JSON.stringify(this.agents, null, 2)
      const blob = new Blob([json], { type: 'application/json' })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `agents_${new Date().toISOString().slice(0,10)}.json`
      a.click()
      URL.revokeObjectURL(url)
    },
    downloadCSV() {
      if (this.agents.length === 0) return
      const headers = ['ID', 'Name', 'Description', 'Type', 'Status', 'Capabilities']
      const rows = this.agents.map(a => [
        a.id,
        `"${a.name}"`,
        `"${a.description || ''}"`,
        a.type || 'custom',
        a.status,
        `"${(a.capabilities || []).join('; ')}"`
      ])
      const csv = [headers.join(','), ...rows.map(r => r.join(','))].join('\n')
      const blob = new Blob([csv], { type: 'text/csv' })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `agents_${new Date().toISOString().slice(0,10)}.csv`
      a.click()
      URL.revokeObjectURL(url)
    }
  }
}
</script>




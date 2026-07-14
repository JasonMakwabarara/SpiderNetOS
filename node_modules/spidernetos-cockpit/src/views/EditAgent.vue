<template>
  <div class="p-6">
    <div class="flex items-center gap-3 mb-6">
      <button @click="$router.back()" class="text-gray-500 hover:text-gray-700">? Back</button>
      <h1 class="text-2xl font-bold">?? Edit Agent</h1>
    </div>

    <div class="bg-white rounded-lg shadow p-6 max-w-2xl">
      <div v-if="loading" class="text-center py-8">Loading...</div>
      <div v-else-if="!agent" class="text-center py-8 text-red-500">Agent not found</div>
      <div v-else>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium mb-1">Name</label>
            <input v-model="agent.name" class="w-full p-2 border rounded" />
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Description</label>
            <textarea v-model="agent.description" class="w-full p-2 border rounded" rows="3"></textarea>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Status</label>
            <select v-model="agent.status" class="w-full p-2 border rounded">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="archived">Archived</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Capabilities</label>
            <div class="flex gap-2 flex-wrap">
              <label v-for="cap in ['chat', 'email', 'slack', 'webhook']" :key="cap" class="flex items-center gap-1">
                <input type="checkbox" :value="cap" v-model="agent.capabilities" />
                {{ cap }}
              </label>
            </div>
          </div>
          <div class="flex gap-2 mt-4">
            <button @click="saveAgent" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">?? Save</button>
            <button @click="$router.back()" class="bg-gray-300 px-4 py-2 rounded hover:bg-gray-400">Cancel</button>
          </div>
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
      agent: null,
      loading: false,
      agentId: null
    }
  },
  mounted() {
    this.agentId = this.$route.params.id
    if (this.agentId) {
      this.fetchAgent()
    }
  },
  methods: {
    async fetchAgent() {
      this.loading = true
      try {
        const { data } = await api.get(`/agents/${this.agentId}`)
        this.agent = data
      } catch (error) {
        console.error('Error fetching agent:', error)
        alert('Failed to load agent')
      } finally {
        this.loading = false
      }
    },
    async saveAgent() {
      try {
        await api.put(`/agents/${this.agentId}`, this.agent)
        alert('? Agent updated successfully!')
        this.$router.push('/agents')
      } catch (error) {
        alert('? Failed to update agent: ' + error.message)
      }
    }
  }
}
</script>

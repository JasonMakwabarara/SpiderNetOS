<template>
  <div class="p-6">
    <div class="flex items-center gap-3 mb-6">
      <button @click="$router.back()" class="text-gray-500 hover:text-gray-700">? Back</button>
      <h1 class="text-2xl font-bold">?? Edit Flow</h1>
    </div>

    <div class="bg-white rounded-lg shadow p-6 max-w-2xl">
      <div v-if="loading" class="text-center py-8">Loading...</div>
      <div v-else-if="!flow" class="text-center py-8 text-red-500">Flow not found</div>
      <div v-else>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium mb-1">Name</label>
            <input v-model="flow.name" class="w-full p-2 border rounded" />
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Description</label>
            <textarea v-model="flow.description" class="w-full p-2 border rounded" rows="3"></textarea>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Status</label>
            <select v-model="flow.status" class="w-full p-2 border rounded">
              <option value="draft">Draft</option>
              <option value="published">Published</option>
              <option value="archived">Archived</option>
            </select>
          </div>
          <div class="flex gap-2 mt-4">
            <button @click="saveFlow" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">?? Save</button>
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
      flow: null,
      loading: false,
      flowId: null
    }
  },
  mounted() {
    this.flowId = this.$route.params.id
    if (this.flowId) {
      this.fetchFlow()
    }
  },
  methods: {
    async fetchFlow() {
      this.loading = true
      try {
        const { data } = await api.get(`/flows/${this.flowId}`)
        this.flow = data
      } catch (error) {
        console.error('Error fetching flow:', error)
        alert('Failed to load flow')
      } finally {
        this.loading = false
      }
    },
    async saveFlow() {
      try {
        await api.put(`/flows/${this.flowId}`, this.flow)
        alert('? Flow updated successfully!')
        this.$router.push('/flows')
      } catch (error) {
        alert('? Failed to update flow: ' + error.message)
      }
    }
  }
}
</script>

<template>
  <div class="p-6">
    <div class="flex justify-between items-center mb-6">
      <h1 class="text-2xl font-bold">New Flow</h1>
      <div class="flex gap-2">
        <button @click="saveFlow" class="bg-blue-500 text-white px-4 py-2 rounded">Save Draft</button>
        <button @click="publishFlow" class="bg-green-500 text-white px-4 py-2 rounded">Publish</button>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
      <div class="lg:col-span-1">
        <div class="bg-white rounded-lg shadow p-4">
          <div class="mb-4">
            <label class="block text-sm font-medium mb-1">Flow Name</label>
            <input v-model="flowName" type="text" class="w-full p-2 border rounded" placeholder="Enter flow name" />
          </div>
          <div class="mb-4">
            <label class="block text-sm font-medium mb-1">Description</label>
            <textarea v-model="flowDescription" class="w-full p-2 border rounded" rows="3" placeholder="Describe your flow"></textarea>
          </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4 mt-4">
          <h3 class="font-semibold mb-3">Add Node:</h3>
          <div class="space-y-2">
            <button @click="addNode('email')" class="w-full text-left px-3 py-2 border rounded hover:bg-gray-50"> Email</button>
            <button @click="addNode('slack')" class="w-full text-left px-3 py-2 border rounded hover:bg-gray-50"> Slack</button>
            <button @click="addNode('webhook')" class="w-full text-left px-3 py-2 border rounded hover:bg-gray-50"> Webhook</button>
            <button @click="addNode('trigger')" class="w-full text-left px-3 py-2 border rounded hover:bg-gray-50"> Trigger</button>
          </div>
        </div>
      </div>

      <div class="lg:col-span-3">
        <div class="bg-white rounded-lg shadow p-4 min-h-[400px]">
          <div v-if="nodes.length === 0" class="text-center py-12 text-gray-500">
            <p class="text-xl mb-2"> No nodes yet</p>
            <p>Click a node type from the left panel to add it</p>
          </div>
          <div v-for="(node, index) in nodes" :key="index" class="border-b py-3">
            <div class="flex justify-between items-center">
              <div class="flex items-center gap-3">
                <span class="text-2xl">{{ getIcon(node.type) }}</span>
                <div>
                  <span class="font-medium">{{ node.label || node.type }}</span>
                  <span class="text-sm text-gray-400 ml-2">{{ node.type }}</span>
                </div>
              </div>
              <div class="flex gap-2">
                <button @click="editNode(index)" class="text-blue-500 text-sm"> Edit</button>
                <button @click="removeNode(index)" class="text-red-500 text-sm"></button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Edit Modal -->
    <div v-if="showModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-white rounded-lg p-6 w-full max-w-md">
        <h2 class="text-xl font-bold mb-4">Edit Node</h2>
        
        <div v-if="editingNode">
          <div class="mb-3">
            <label class="block text-sm font-medium mb-1">Label</label>
            <input v-model="editingNode.label" class="w-full p-2 border rounded" />
          </div>
          
          <div v-if="editingNode.type === 'email'" class="space-y-3">
            <input v-model="editingNode.config.to" placeholder="Recipient email" class="w-full p-2 border rounded" />
            <input v-model="editingNode.config.subject" placeholder="Subject" class="w-full p-2 border rounded" />
            <textarea v-model="editingNode.config.body" placeholder="Email body" class="w-full p-2 border rounded" rows="3"></textarea>
          </div>
          
          <div v-if="editingNode.type === 'slack'" class="space-y-3">
            <input v-model="editingNode.config.webhook" placeholder="Slack webhook URL" class="w-full p-2 border rounded" />
            <textarea v-model="editingNode.config.message" placeholder="Message" class="w-full p-2 border rounded" rows="3"></textarea>
          </div>
          
          <div v-if="editingNode.type === 'webhook'" class="space-y-3">
            <input v-model="editingNode.config.url" placeholder="Webhook URL" class="w-full p-2 border rounded" />
            <textarea v-model="editingNode.config.data" placeholder='{"key": "value"}' class="w-full p-2 border rounded" rows="3"></textarea>
          </div>
        </div>
        
        <div class="flex gap-2 mt-4">
          <button @click="saveNode" class="bg-blue-500 text-white px-4 py-2 rounded">Save</button>
          <button @click="showModal = false" class="bg-gray-300 px-4 py-2 rounded">Cancel</button>
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
      flowName: '',
      flowDescription: '',
      nodes: [],
      showModal: false,
      editingNode: null,
      editingIndex: null
    }
  },
  methods: {
    getIcon(type) {
      const icons = { email: '', slack: '', webhook: '', trigger: '' }
      return icons[type] || ''
    },
    addNode(type) {
      const configs = {
        email: { to: '', subject: '', body: '' },
        slack: { webhook: '', message: '' },
        webhook: { url: '', data: '{}' },
        trigger: { event: 'manual' }
      }
      this.nodes.push({
        type: type,
        label: type.charAt(0).toUpperCase() + type.slice(1),
        config: configs[type] || {}
      })
    },
    editNode(index) {
      this.editingIndex = index
      this.editingNode = { ...this.nodes[index] }
      this.showModal = true
    },
    saveNode() {
      if (this.editingIndex !== null && this.editingNode) {
        this.nodes[this.editingIndex] = { ...this.editingNode }
      }
      this.showModal = false
    },
    removeNode(index) {
      this.nodes.splice(index, 1)
    },
    async saveFlow() {
      try {
        await api.post('/flows', {
          name: this.flowName || 'Untitled Flow',
          description: this.flowDescription,
          dag: { steps: this.nodes },
          triggers: ['manual'],
          status: 'draft'
        })
        alert(' Flow saved!')
        this.$router.push('/flows')
      } catch (e) {
        alert(' Failed to save: ' + e.message)
      }
    },
    async publishFlow() {
      try {
        await api.post('/flows', {
          name: this.flowName || 'Untitled Flow',
          description: this.flowDescription,
          dag: { steps: this.nodes },
          triggers: ['manual'],
          status: 'published'
        })
        alert(' Flow published!')
        this.$router.push('/flows')
      } catch (e) {
        alert(' Failed to publish: ' + e.message)
      }
    }
  }
}
</script>

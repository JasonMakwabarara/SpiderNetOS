<template>
  <div class="memory p-6 space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Memory Graph</h1>
        <p class="text-sm text-gray-500 mt-1">
          Browse and search agent memory nodes
        </p>
      </div>
      <button
        @click="refreshMemory"
        class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 flex items-center space-x-2"
      >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
        <span>Refresh</span>
      </button>
    </div>

    <!-- Search -->
    <div class="flex items-center space-x-4">
      <div class="flex-1 relative">
        <input
          v-model="searchQuery"
          type="text"
          placeholder="Search memory nodes..."
          class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
        />
        <svg class="w-5 h-5 text-gray-400 absolute left-3 top-1/2 transform -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
      </div>
      <select
        v-model="nodeTypeFilter"
        class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
      >
        <option value="">All Types</option>
        <option value="conversation">Conversation</option>
        <option value="document">Document</option>
        <option value="knowledge">Knowledge</option>
        <option value="execution">Execution</option>
      </select>
    </div>

    <!-- Nodes Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <div
        v-for="node in filteredNodes"
        :key="node.id"
        class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 hover:shadow-md transition-shadow"
      >
        <div class="flex items-start justify-between mb-2">
          <span
            class="px-2 py-1 text-xs font-medium rounded-full"
            :class="typeBadgeClass(node.node_type)"
          >
            {{ node.node_type }}
          </span>
          <span class="text-xs text-gray-400">
            {{ formatDate(node.created_at) }}
          </span>
        </div>
        
        <p class="text-sm text-gray-900 line-clamp-3">{{ node.content }}</p>
        
        <div v-if="node.embedding" class="mt-2 flex items-center space-x-1">
          <span class="text-xs text-gray-400">Vector:</span>
          <span class="text-xs text-indigo-600 font-mono">
            [{{ node.embedding.slice(0, 3).join(', ') }}...]
          </span>
        </div>
        
        <div class="mt-3 pt-3 border-t border-gray-100 flex items-center justify-between">
          <span v-if="node.agent_id" class="text-xs text-gray-500">
            Agent: {{ node.agent_id.slice(0, 8) }}...
          </span>
          <div class="flex space-x-2">
            <button
              @click="viewNode(node)"
              class="text-sm text-indigo-600 hover:text-indigo-700"
            >
              View
            </button>
            <button
              @click="findRelated(node)"
              class="text-sm text-gray-500 hover:text-gray-700"
            >
              Related
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <div v-if="filteredNodes.length === 0" class="text-center py-12 bg-white rounded-lg border border-gray-200">
      <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
      </svg>
      <h3 class="text-lg font-medium text-gray-900">No memory nodes found</h3>
      <p class="text-gray-500 mt-1">Memory will populate as agents process data</p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'

const searchQuery = ref('')
const nodeTypeFilter = ref('')
const isLoading = ref(false)

// Mock data - would come from API
const nodes = ref([
  {
    id: '1',
    node_type: 'conversation',
    content: 'User asked about creating a new workflow for data processing. Atlas suggested using Forge agent.',
    created_at: new Date().toISOString(),
    agent_id: 'atlas-123',
    embedding: [0.1, 0.2, 0.3, 0.4, 0.5]
  },
  {
    id: '2',
    node_type: 'knowledge',
    content: 'System architecture: Laravel backend, Python intelligence worker, PostgreSQL event store.',
    created_at: new Date(Date.now() - 86400000).toISOString(),
    agent_id: 'hannah-456',
    embedding: [0.2, 0.3, 0.4, 0.5, 0.6]
  },
  {
    id: '3',
    node_type: 'execution',
    content: 'Flow "data-pipeline" executed successfully with 5 nodes processed in 1.2s.',
    created_at: new Date(Date.now() - 172800000).toISOString(),
    agent_id: 'nexus-789',
    embedding: [0.3, 0.4, 0.5, 0.6, 0.7]
  }
])

const filteredNodes = computed(() => {
  let result = nodes.value
  
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    result = result.filter(n => n.content.toLowerCase().includes(query))
  }
  
  if (nodeTypeFilter.value) {
    result = result.filter(n => n.node_type === nodeTypeFilter.value)
  }
  
  return result
})

function typeBadgeClass(type) {
  const classes = {
    conversation: 'bg-blue-100 text-blue-800',
    document: 'bg-green-100 text-green-800',
    knowledge: 'bg-purple-100 text-purple-800',
    execution: 'bg-orange-100 text-orange-800'
  }
  return classes[type] || 'bg-gray-100 text-gray-800'
}

function formatDate(date) {
  return new Date(date).toLocaleDateString()
}

function refreshMemory() {
  isLoading.value = true
  setTimeout(() => {
    isLoading.value = false
  }, 500)
}

function viewNode(node) {
  console.log('View node:', node)
}

function findRelated(node) {
  console.log('Find related to:', node)
}

onMounted(() => {
  refreshMemory()
})
</script>

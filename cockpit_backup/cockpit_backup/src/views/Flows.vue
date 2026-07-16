<template>
  <div class="flows p-6 space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Flows</h1>
        <p class="text-sm text-gray-500 mt-1">
          Visual workflow builder
        </p>
      </div>
      <button
        @click="$router.push('/flows/new')"
        class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 flex items-center space-x-2"
      >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        <span>Create Flow</span>
      </button>
    </div>

    <!-- Filters -->
    <div class="flex items-center space-x-4">
      <input
        v-model="searchQuery"
        type="text"
        placeholder="Search flows..."
        class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
      />
      <select
        v-model="statusFilter"
        class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
      >
        <option value="">All Status</option>
        <option value="published">Published</option>
        <option value="draft">Draft</option>
      </select>
    </div>

    <!-- Flows List -->
    <div v-if="flowsStore.isLoading" class="text-center py-12">
      <div class="animate-spin w-8 h-8 border-4 border-indigo-600 border-t-transparent rounded-full mx-auto" />
      <p class="mt-4 text-gray-500">Loading flows...</p>
    </div>

    <div v-else-if="filteredFlows.length === 0" class="text-center py-12 bg-white rounded-lg border border-gray-200">
      <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
      </svg>
      <h3 class="text-lg font-medium text-gray-900">No flows yet</h3>
      <p class="text-gray-500 mt-1">Create your first workflow</p>
    </div>

    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <div
        v-for="flow in filteredFlows"
        :key="flow.id"
        class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 hover:shadow-md transition-shadow"
      >
        <div class="flex items-start justify-between">
          <div>
            <h3 class="font-semibold text-gray-900">{{ flow.name }}</h3>
            <p class="text-sm text-gray-500">@{{ flow.slug }}</p>
          </div>
          <span
            class="px-2 py-1 text-xs font-medium rounded-full"
            :class="flow.status === 'published' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'"
          >
            {{ flow.status }}
          </span>
        </div>

        <p class="mt-3 text-sm text-gray-600 line-clamp-2">
          {{ flow.description || 'No description' }}
        </p>

        <div class="mt-4 flex items-center justify-between text-sm text-gray-500">
          <span>{{ flow.dag?.nodes?.length || 0 }} nodes</span>
          <span>{{ flow.dag?.edges?.length || 0 }} edges</span>
        </div>

        <div class="mt-4 pt-4 border-t border-gray-200 flex items-center justify-between">
          <button
            @click="executeFlow(flow.id)"
            :disabled="flow.status !== 'published' || flowsStore.isExecuting"
            class="px-3 py-1.5 text-sm bg-indigo-600 text-white rounded hover:bg-indigo-700 disabled:opacity-50"
          >
            {{ flowsStore.isExecuting ? 'Running...' : 'Run' }}
          </button>
          <div class="flex space-x-2">
            <button
              @click="$router.push(`/flows/${flow.id}`)"
              class="p-1.5 text-gray-400 hover:text-indigo-600 rounded"
              title="Edit"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
              </svg>
            </button>
            <button
              v-if="flow.status === 'draft'"
              @click="publishFlow(flow.id)"
              class="p-1.5 text-gray-400 hover:text-green-600 rounded"
              title="Publish"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
            </button>
            <button
              @click="deleteFlow(flow.id)"
              class="p-1.5 text-gray-400 hover:text-red-600 rounded"
              title="Delete"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useFlowsStore } from '../stores/flows.js'

const flowsStore = useFlowsStore()

const searchQuery = ref('')
const statusFilter = ref('')

const filteredFlows = computed(() => {
  let flows = flowsStore.flows
  
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    flows = flows.filter(f => 
      f.name.toLowerCase().includes(query) ||
      f.slug.toLowerCase().includes(query)
    )
  }
  
  if (statusFilter.value) {
    flows = flows.filter(f => f.status === statusFilter.value)
  }
  
  return flows
})

onMounted(() => {
  flowsStore.fetchFlows()
})

async function executeFlow(flowId) {
  await flowsStore.executeFlow(flowId)
}

async function publishFlow(flowId) {
  await flowsStore.publishFlow(flowId)
}

async function deleteFlow(flowId) {
  if (!confirm('Are you sure you want to delete this flow?')) return
  await flowsStore.deleteFlow(flowId)
}
</script>

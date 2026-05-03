<template>
  <div class="agents p-6 space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Agents</h1>
        <p class="text-sm text-gray-500 mt-1">
          Manage your AI workforce
        </p>
      </div>
      <button
        @click="showCreateModal = true"
        class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 flex items-center space-x-2"
      >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        <span>Create Agent</span>
      </button>
    </div>

    <!-- Filters -->
    <div class="flex items-center space-x-4">
      <input
        v-model="searchQuery"
        type="text"
        placeholder="Search agents..."
        class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
      />
      <select
        v-model="statusFilter"
        class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
      >
        <option value="">All Status</option>
        <option value="active">Active</option>
        <option value="paused">Paused</option>
        <option value="draft">Draft</option>
      </select>
      <select
        v-model="capabilityFilter"
        class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
      >
        <option value="">All Capabilities</option>
        <option v-for="cap in availableCapabilities" :key="cap" :value="cap">
          {{ cap }}
        </option>
      </select>
    </div>

    <!-- Agents Grid -->
    <div v-if="agentsStore.isLoading" class="text-center py-12">
      <div class="animate-spin w-8 h-8 border-4 border-indigo-600 border-t-transparent rounded-full mx-auto" />
      <p class="mt-4 text-gray-500">Loading agents...</p>
    </div>

    <div v-else-if="filteredAgents.length === 0" class="text-center py-12 bg-white rounded-lg border border-gray-200">
      <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
      </svg>
      <h3 class="text-lg font-medium text-gray-900">No agents found</h3>
      <p class="text-gray-500 mt-1">Create your first agent to get started</p>
    </div>

    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <AgentCard
        v-for="agent in filteredAgents"
        :key="agent.id"
        :agent="agent"
        @toggle="handleToggle"
        @edit="handleEdit"
        @dispatch="handleDispatch"
      />
    </div>

    <!-- Create/Edit Modal -->
    <div v-if="showCreateModal || editingAgent" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-white rounded-lg shadow-xl max-w-lg w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="p-6">
          <h2 class="text-xl font-semibold text-gray-900 mb-4">
            {{ editingAgent ? 'Edit Agent' : 'Create New Agent' }}
          </h2>
          
          <form @submit.prevent="handleSave" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
              <input
                v-model="form.name"
                type="text"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
              />
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Slug</label>
              <input
                v-model="form.slug"
                type="text"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
              />
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
              <textarea
                v-model="form.description"
                rows="3"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
              />
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Capabilities</label>
              <div class="flex flex-wrap gap-2">
                <label
                  v-for="cap in availableCapabilities"
                  :key="cap"
                  class="inline-flex items-center px-3 py-1 rounded-full border cursor-pointer"
                  :class="form.capabilities.includes(cap) ? 'bg-indigo-50 border-indigo-300' : 'bg-gray-50 border-gray-300'"
                >
                  <input
                    v-model="form.capabilities"
                    type="checkbox"
                    :value="cap"
                    class="sr-only"
                  />
                  <span class="text-sm" :class="form.capabilities.includes(cap) ? 'text-indigo-700' : 'text-gray-700'">
                    {{ cap }}
                  </span>
                </label>
              </div>
            </div>
            
            <div class="flex items-center space-x-2">
              <input
                v-model="form.status"
                type="checkbox"
                true-value="active"
                false-value="draft"
                id="active"
                class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500"
              />
              <label for="active" class="text-sm text-gray-700">Active immediately</label>
            </div>
            
            <div class="flex justify-end space-x-3 pt-4">
              <button
                type="button"
                @click="closeModal"
                class="px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-lg"
              >
                Cancel
              </button>
              <button
                type="submit"
                :disabled="isSaving"
                class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50"
              >
                {{ isSaving ? 'Saving...' : (editingAgent ? 'Update' : 'Create') }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Dispatch Modal -->
    <div v-if="dispatchingAgent" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-white rounded-lg shadow-xl max-w-lg w-full mx-4">
        <div class="p-6">
          <h2 class="text-xl font-semibold text-gray-900 mb-4">
            Dispatch {{ dispatchingAgent.name }}
          </h2>
          
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Intent</label>
              <select
                v-model="dispatchForm.intent"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
              >
                <option v-for="cap in dispatchingAgent.capabilities" :key="cap" :value="cap">
                  {{ cap }}
                </option>
              </select>
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Context (JSON)</label>
              <textarea
                v-model="dispatchForm.context"
                rows="4"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 font-mono text-sm"
              />
            </div>
            
            <div class="flex justify-end space-x-3">
              <button
                @click="dispatchingAgent = null"
                class="px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-lg"
              >
                Cancel
              </button>
              <button
                @click="executeDispatch"
                :disabled="isDispatching"
                class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50"
              >
                {{ isDispatching ? 'Dispatching...' : 'Dispatch' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAgentsStore } from '../stores/agents.js'
import AgentCard from '../components/AgentCard.vue'

const agentsStore = useAgentsStore()

const searchQuery = ref('')
const statusFilter = ref('')
const capabilityFilter = ref('')
const showCreateModal = ref(false)
const editingAgent = ref(null)
const dispatchingAgent = ref(null)
const isSaving = ref(false)
const isDispatching = ref(false)

const form = ref({
  name: '',
  slug: '',
  description: '',
  capabilities: [],
  status: 'draft'
})

const dispatchForm = ref({
  intent: '',
  context: '{}'
})

const availableCapabilities = [
  'chat', 'flow_execution', 'content_generation', 'data_analysis',
  'memory_access', 'task_delegation', 'monitoring', 'anomaly_detection',
  'flow_creation', 'dag_building', 'teaching', 'tutoring'
]

const filteredAgents = computed(() => {
  let agents = agentsStore.agents
  
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    agents = agents.filter(a => 
      a.name.toLowerCase().includes(query) ||
      a.slug.toLowerCase().includes(query) ||
      a.description?.toLowerCase().includes(query)
    )
  }
  
  if (statusFilter.value) {
    agents = agents.filter(a => a.status === statusFilter.value)
  }
  
  if (capabilityFilter.value) {
    agents = agents.filter(a => a.capabilities?.includes(capabilityFilter.value))
  }
  
  return agents
})

onMounted(() => {
  agentsStore.fetchAgents()
})

function handleToggle(agentId) {
  agentsStore.toggleAgentStatus(agentId)
}

function handleEdit(agent) {
  editingAgent.value = agent
  form.value = {
    name: agent.name,
    slug: agent.slug,
    description: agent.description || '',
    capabilities: [...(agent.capabilities || [])],
    status: agent.status
  }
}

function handleDispatch(agent) {
  dispatchingAgent.value = agent
  dispatchForm.value.intent = agent.capabilities?.[0] || ''
}

function closeModal() {
  showCreateModal.value = false
  editingAgent.value = null
  form.value = {
    name: '',
    slug: '',
    description: '',
    capabilities: [],
    status: 'draft'
  }
}

async function handleSave() {
  isSaving.value = true
  
  try {
    if (editingAgent.value) {
      await agentsStore.updateAgent(editingAgent.value.id, form.value)
    } else {
      await agentsStore.createAgent(form.value)
    }
    closeModal()
  } finally {
    isSaving.value = false
  }
}

async function executeDispatch() {
  isDispatching.value = true
  
  try {
    const context = JSON.parse(dispatchForm.value.context || '{}')
    await agentsStore.dispatchAgent(
      dispatchingAgent.value.id,
      dispatchForm.value.intent,
      context
    )
    dispatchingAgent.value = null
  } catch (e) {
    alert('Invalid JSON in context')
  } finally {
    isDispatching.value = false
  }
}
</script>

<template>
  <div class="dashboard p-6 space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
        <p class="text-sm text-gray-500 mt-1">
          Welcome back, {{ authStore.user?.name || 'User' }}
        </p>
      </div>
      <div class="flex items-center space-x-4">
        <span 
          v-if="wsStore.isConnected" 
          class="flex items-center space-x-1 text-sm text-green-600"
        >
          <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse" />
          <span>Live</span>
        </span>
        <span v-else class="text-sm text-gray-400">Offline</span>
      </div>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
      <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
        <div class="flex items-center space-x-3">
          <div class="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center">
            <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
          </div>
          <div>
            <p class="text-sm text-gray-500">Active Agents</p>
            <p class="text-xl font-semibold text-gray-900">{{ agentsStore.activeAgents.length }}</p>
          </div>
        </div>
      </div>

      <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
        <div class="flex items-center space-x-3">
          <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
          </div>
          <div>
            <p class="text-sm text-gray-500">Published Flows</p>
            <p class="text-xl font-semibold text-gray-900">{{ flowsStore.publishedFlows.length }}</p>
          </div>
        </div>
      </div>

      <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
        <div class="flex items-center space-x-3">
          <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <div>
            <p class="text-sm text-gray-500">Executions Today</p>
            <p class="text-xl font-semibold text-gray-900">{{ flowsStore.recentExecutions.length }}</p>
          </div>
        </div>
      </div>

      <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
        <div class="flex items-center space-x-3">
          <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
            <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <div>
            <p class="text-sm text-gray-500">Today's Cost</p>
            <p class="text-xl font-semibold" :class="costColorClass">
              ${{ usageStore.currentSpend.daily.toFixed(2) }}
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- Main Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Atlas Chat -->
      <div class="lg:col-span-2">
        <AtlasChat class="h-[500px]" />
      </div>

      <!-- Side Panel -->
      <div class="space-y-4">
        <!-- Usage Card -->
        <UsageCard />

        <!-- Quick Actions -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
          <h3 class="font-semibold text-gray-900 mb-3">Quick Actions</h3>
          <div class="space-y-2">
            <button
              @click="quickCreateFlow"
              class="w-full flex items-center space-x-2 px-4 py-2 text-left text-sm bg-gray-50 hover:bg-gray-100 rounded-lg"
            >
              <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
              </svg>
              <span>Create New Flow</span>
            </button>
            <button
              @click="quickAddAgent"
              class="w-full flex items-center space-x-2 px-4 py-2 text-left text-sm bg-gray-50 hover:bg-gray-100 rounded-lg"
            >
              <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
              </svg>
              <span>Add Agent</span>
            </button>
            <button
              @click="quickViewUsage"
              class="w-full flex items-center space-x-2 px-4 py-2 text-left text-sm bg-gray-50 hover:bg-gray-100 rounded-lg"
            >
              <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
              </svg>
              <span>View Usage Report</span>
            </button>
          </div>
        </div>

        <!-- Recent Activity -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
          <h3 class="font-semibold text-gray-900 mb-3">Recent Executions</h3>
          <div v-if="flowsStore.recentExecutions.length === 0" class="text-center py-4 text-gray-500">
            No recent executions
          </div>
          <div v-else class="space-y-2">
            <div
              v-for="execution in flowsStore.recentExecutions.slice(0, 5)"
              :key="execution.id"
              class="flex items-center justify-between p-2 bg-gray-50 rounded text-sm"
            >
              <div class="flex items-center space-x-2">
                <span 
                  class="w-2 h-2 rounded-full"
                  :class="{
                    'bg-green-500': execution.status === 'completed',
                    'bg-yellow-500': execution.status === 'running',
                    'bg-red-500': execution.status === 'failed'
                  }"
                />
                <span class="truncate max-w-[120px]">{{ execution.flow?.name || 'Unknown Flow' }}</span>
              </div>
              <span class="text-gray-500 text-xs">{{ formatTime(execution.started_at) }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth.js'
import { useAgentsStore } from '../stores/agents.js'
import { useFlowsStore } from '../stores/flows.js'
import { useUsageStore } from '../stores/usage.js'
import AtlasChat from '../components/AtlasChat.vue'
import UsageCard from '../components/UsageCard.vue'

const router = useRouter()
const authStore = useAuthStore()
const agentsStore = useAgentsStore()
const flowsStore = useFlowsStore()
const usageStore = useUsageStore()

// Mock ws store - would be from useWebSocket composable
const wsStore = { isConnected: true }

const costColorClass = computed(() => {
  if (usageStore.isDegraded) return 'text-yellow-600'
  if (usageStore.isNearLimit) return 'text-orange-600'
  return 'text-gray-900'
})

onMounted(() => {
  // Fetch initial data
  agentsStore.fetchAgents()
  flowsStore.fetchFlows()
  usageStore.fetchBudget()
  usageStore.fetchCurrentSpend()
})

function quickCreateFlow() {
  router.push('/flows/new')
}

function quickAddAgent() {
  router.push('/agents/new')
}

function quickViewUsage() {
  router.push('/settings/usage')
}

function formatTime(timestamp) {
  if (!timestamp) return ''
  const date = new Date(timestamp)
  const now = new Date()
  const diff = Math.floor((now - date) / 1000)
  
  if (diff < 60) return 'just now'
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
  return `${Math.floor(diff / 86400)}d ago`
}
</script>

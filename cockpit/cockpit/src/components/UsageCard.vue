<template>
  <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
    <div class="flex items-center justify-between mb-4">
      <h3 class="font-semibold text-gray-900">Usage & Budget</h3>
      <router-link 
        to="/settings/usage" 
        class="text-sm text-indigo-600 hover:text-indigo-700"
      >
        Details
      </router-link>
    </div>

    <!-- Alert Banner -->
    <div 
      v-if="usageStore.isNearLimit" 
      class="mb-4 p-3 rounded-lg"
      :class="usageStore.isDegraded ? 'bg-yellow-50 border border-yellow-200' : 'bg-orange-50 border border-orange-200'"
    >
      <div class="flex items-center space-x-2">
        <svg class="w-5 h-5" :class="usageStore.isDegraded ? 'text-yellow-600' : 'text-orange-600'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
        <p class="text-sm font-medium" :class="usageStore.isDegraded ? 'text-yellow-800' : 'text-orange-800'">
          {{ usageStore.isDegraded ? 'Degraded mode: Using cheaper models' : 'Approaching budget limit' }}
        </p>
      </div>
    </div>

    <!-- Daily Usage -->
    <div class="mb-4">
      <div class="flex items-center justify-between mb-2">
        <span class="text-sm text-gray-600">Daily</span>
        <span class="text-sm font-medium text-gray-900">
          ${{ usageStore.currentSpend.daily.toFixed(2) }} / ${{ usageStore.budget?.daily_limit.toFixed(2) || '10.00' }}
        </span>
      </div>
      <div class="h-2 bg-gray-200 rounded-full overflow-hidden">
        <div 
          class="h-full rounded-full transition-all duration-500"
          :class="dailyProgressColor"
          :style="{ width: `${Math.min(usageStore.dailyPercentUsed, 100)}%` }"
        />
      </div>
      <p class="mt-1 text-xs text-gray-500">
        ${{ usageStore.dailyRemaining.toFixed(2) }} remaining today
      </p>
    </div>

    <!-- Monthly Usage -->
    <div>
      <div class="flex items-center justify-between mb-2">
        <span class="text-sm text-gray-600">Monthly</span>
        <span class="text-sm font-medium text-gray-900">
          ${{ usageStore.currentSpend.monthly.toFixed(2) }} / ${{ usageStore.budget?.monthly_limit.toFixed(2) || '100.00' }}
        </span>
      </div>
      <div class="h-2 bg-gray-200 rounded-full overflow-hidden">
        <div 
          class="h-full rounded-full transition-all duration-500"
          :class="monthlyProgressColor"
          :style="{ width: `${Math.min(usageStore.monthlyPercentUsed, 100)}%` }"
        />
      </div>
      <p class="mt-1 text-xs text-gray-500">
        ${{ usageStore.monthlyRemaining.toFixed(2) }} remaining this month
      </p>
    </div>

    <!-- Quick Stats -->
    <div class="mt-4 pt-4 border-t border-gray-200 grid grid-cols-3 gap-4">
      <div class="text-center">
        <p class="text-lg font-semibold text-gray-900">{{ formatNumber(stats.agents) }}</p>
        <p class="text-xs text-gray-500">Active Agents</p>
      </div>
      <div class="text-center">
        <p class="text-lg font-semibold text-gray-900">{{ formatNumber(stats.flows) }}</p>
        <p class="text-xs text-gray-500">Flows Run</p>
      </div>
      <div class="text-center">
        <p class="text-lg font-semibold text-gray-900">{{ formatNumber(stats.tokens) }}</p>
        <p class="text-xs text-gray-500">Tokens</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useUsageStore } from '../stores/usage.js'

const usageStore = useUsageStore()

// Mock stats - would come from usage store
const stats = computed(() => ({
  agents: 6,
  flows: 12,
  tokens: 45000
}))

const dailyProgressColor = computed(() => {
  const pct = usageStore.dailyPercentUsed
  if (pct >= 90) return 'bg-red-500'
  if (pct >= 70) return 'bg-yellow-500'
  return 'bg-green-500'
})

const monthlyProgressColor = computed(() => {
  const pct = usageStore.monthlyPercentUsed
  if (pct >= 90) return 'bg-red-500'
  if (pct >= 70) return 'bg-yellow-500'
  return 'bg-green-500'
})

function formatNumber(num) {
  if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M'
  if (num >= 1000) return (num / 1000).toFixed(1) + 'K'
  return num.toString()
}
</script>

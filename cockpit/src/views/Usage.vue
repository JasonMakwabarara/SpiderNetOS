<template>
  <div class="usage p-6 space-y-6">
    <!-- Header -->
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Usage & Budget</h1>
      <p class="text-sm text-gray-500 mt-1">
        Monitor costs and manage spending limits
      </p>
    </div>

    <!-- Budget Overview -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <p class="text-sm text-gray-500 mb-1">Daily Limit</p>
        <p class="text-2xl font-bold text-gray-900">${{ usageStore.budget?.daily_limit.toFixed(2) || '10.00' }}</p>
        <div class="mt-2 h-2 bg-gray-200 rounded-full overflow-hidden">
          <div 
            class="h-full rounded-full"
            :class="dailyProgressColor"
            :style="{ width: `${Math.min(usageStore.dailyPercentUsed, 100)}%` }"
          />
        </div>
        <p class="mt-1 text-sm text-gray-500">
          ${{ usageStore.currentSpend.daily.toFixed(2) }} used
        </p>
      </div>

      <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <p class="text-sm text-gray-500 mb-1">Monthly Limit</p>
        <p class="text-2xl font-bold text-gray-900">${{ usageStore.budget?.monthly_limit.toFixed(2) || '100.00' }}</p>
        <div class="mt-2 h-2 bg-gray-200 rounded-full overflow-hidden">
          <div 
            class="h-full rounded-full"
            :class="monthlyProgressColor"
            :style="{ width: `${Math.min(usageStore.monthlyPercentUsed, 100)}%` }"
          />
        </div>
        <p class="mt-1 text-sm text-gray-500">
          ${{ usageStore.currentSpend.monthly.toFixed(2) }} used
        </p>
      </div>

      <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <p class="text-sm text-gray-500 mb-1">Current Plan</p>
        <p class="text-2xl font-bold text-gray-900">{{ usageStore.budget?.plan || 'Standard' }}</p>
        <p class="mt-1 text-sm text-gray-500">
          {{ usageStore.budget?.action_at_limit || 'degrade' }} at limit
        </p>
      </div>
    </div>

    <!-- Budget Settings -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
      <h2 class="text-lg font-semibold text-gray-900 mb-4">Budget Settings</h2>
      
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-2xl">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Daily Limit ($)</label>
          <input
            v-model.number="budgetForm.dailyLimit"
            type="number"
            min="0"
            step="0.01"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Monthly Limit ($)</label>
          <input
            v-model.number="budgetForm.monthlyLimit"
            type="number"
            min="0"
            step="0.01"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Alert Threshold (%)</label>
          <input
            v-model.number="budgetForm.alertThreshold"
            type="number"
            min="0"
            max="100"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Action at Limit</label>
          <select
            v-model="budgetForm.actionAtLimit"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
          >
            <option value="degrade">Degrade (use cheaper models)</option>
            <option value="block">Block (stop execution)</option>
            <option value="warn">Warn only</option>
          </select>
        </div>
      </div>

      <div class="mt-6 flex items-center justify-between">
        <span v-if="updateMessage" class="text-sm" :class="updateMessage.type === 'error' ? 'text-red-600' : 'text-green-600'">
          {{ updateMessage.text }}
        </span>
        <button
          @click="updateBudget"
          :disabled="isUpdating"
          class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 ml-auto"
        >
          {{ isUpdating ? 'Updating...' : 'Update Budget' }}
        </button>
      </div>
    </div>

    <!-- Usage History -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
      <h2 class="text-lg font-semibold text-gray-900 mb-4">Usage History</h2>

      <!-- Atlas copy: empty state banner (wired to useAtlasCopy composable) -->
      <div
        v-if="usageStore.dailyUsage.length === 0 && !usageStore.isLoading"
        class="py-8 text-center"
      >
        <p v-if="emptyCopy.loading" class="text-sm text-gray-400">Loading…</p>
        <template v-else>
          <p class="text-base font-medium text-gray-700 mb-1">
            {{ emptyCopy.copy?.text || 'Your usage insights will appear here once your first request is processed.' }}
          </p>
          <button
            v-if="emptyCopy.copy?.cta"
            class="mt-3 text-sm text-indigo-600 hover:underline"
            @click="emptyCopy.reportEvent('action')"
          >
            {{ emptyCopy.copy.cta.label }}
          </button>
        </template>
      </div>

      <!-- Data table -->
      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                <!-- Atlas tooltip surface -->
                <span :title="tooltipCopy.copy?.text || 'Total API requests on this day'">
                  Requests
                </span>
              </th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tokens</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cost</th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            <tr v-for="day in usageStore.dailyUsage.slice(0, 10)" :key="day.date + (day.resource_type || '')">
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ day.date }}</td>
              <!-- total_calls is the canonical v2 field (was request_count) -->
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ (day.total_calls ?? 0).toLocaleString() }}</td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ (day.total_tokens ?? 0).toLocaleString() }}</td>
              <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">${{ (day.total_cost ?? 0).toFixed(4) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Atlas copy: success state (shown after first successful aggregate lands) -->
    <div v-if="showSuccessState && successCopy.copy" class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">
      {{ successCopy.copy.text }}
      <button class="ml-2 underline text-green-700" @click="showSuccessState = false">Dismiss</button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, reactive, onMounted } from 'vue'
import { useUsageStore } from '../stores/usage.js'
import { useAtlasCopy } from '../composables/useAtlasCopy.js'

const usageStore = useUsageStore()

const isUpdating      = ref(false)
const updateMessage   = ref(null)
const showSuccessState = ref(false)

// Atlas copy surfaces
const emptyCopy   = useAtlasCopy('empty_state',   computed(() => ({ page: 'usage' })))
const tooltipCopy = useAtlasCopy('tooltip',        computed(() => ({ target: 'total_calls' })))
const successCopy = useAtlasCopy('success_state',  computed(() => ({ page: 'usage' })))

const budgetForm = reactive({
  dailyLimit: 10.00,
  monthlyLimit: 100.00,
  alertThreshold: 80,
  actionAtLimit: 'degrade'
})

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

onMounted(async () => {
  await usageStore.fetchBudget()
  await usageStore.fetchDailyUsage()

  // Initialize form from store
  if (usageStore.budget) {
    budgetForm.dailyLimit      = usageStore.budget.daily_limit
    budgetForm.monthlyLimit    = usageStore.budget.monthly_limit
    budgetForm.alertThreshold  = (usageStore.budget.alert_threshold || 0.8) * 100
    budgetForm.actionAtLimit   = usageStore.budget.action_at_limit
  }

  // Show success state if data just arrived (post-fix scenario)
  if (usageStore.dailyUsage.length > 0) {
    showSuccessState.value = true
    successCopy.reportEvent('action')
    setTimeout(() => { showSuccessState.value = false }, 8000)
  }
})

async function updateBudget() {
  isUpdating.value = true
  updateMessage.value = null
  
  const result = await usageStore.updateBudget({
    daily_limit: budgetForm.dailyLimit,
    monthly_limit: budgetForm.monthlyLimit,
    alert_threshold: budgetForm.alertThreshold / 100,
    action_at_limit: budgetForm.actionAtLimit
  })
  
  if (result.success) {
    updateMessage.value = { type: 'success', text: 'Budget updated successfully!' }
  } else {
    updateMessage.value = { type: 'error', text: result.error || 'Failed to update budget' }
  }
  
  isUpdating.value = false
  setTimeout(() => updateMessage.value = null, 5000)
}
</script>

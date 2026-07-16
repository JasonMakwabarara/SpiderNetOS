<template>
  <div class="space-y-4">
    <div class="bg-indigo-50 rounded-lg p-4 mb-4">
      <p class="text-sm text-indigo-800">
        Set a monthly budget for AI operations. You can adjust this anytime in settings.
      </p>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Monthly Budget (USD)</label>
      <div class="relative">
        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">$</span>
        <input
          v-model.number="form.monthly_limit_usd"
          type="number"
          min="10"
          max="100000"
          class="w-full pl-8 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
          placeholder="500"
        />
      </div>
      <p class="text-xs text-gray-500 mt-1">Minimum $10/month. Costs scale with usage.</p>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Currency</label>
      <select
        v-model="form.currency"
        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
      >
        <option v-for="curr in currencies" :key="curr.code" :value="curr.code">
          {{ curr.code }} — {{ curr.name }}
        </option>
      </select>
    </div>

    <div class="pt-4 border-t border-gray-100">
      <p class="text-sm font-medium text-gray-700 mb-2">Estimated usage at this budget:</p>
      <div class="grid grid-cols-3 gap-3 text-center">
        <div class="bg-gray-50 rounded-lg p-3">
          <div class="text-lg font-semibold text-gray-900">~{{ estimatedChats }}</div>
          <div class="text-xs text-gray-500">AI chats</div>
        </div>
        <div class="bg-gray-50 rounded-lg p-3">
          <div class="text-lg font-semibold text-gray-900">~{{ estimatedFlows }}</div>
          <div class="text-xs text-gray-500">Flow runs</div>
        </div>
        <div class="bg-gray-50 rounded-lg p-3">
          <div class="text-lg font-semibold text-gray-900">~{{ estimatedTasks }}</div>
          <div class="text-xs text-gray-500">Task executions</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'

const props = defineProps({
  modelValue: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update:modelValue'])

const currencies = [
  { code: 'USD', name: 'US Dollar' },
  { code: 'EUR', name: 'Euro' },
  { code: 'GBP', name: 'British Pound' },
  { code: 'CAD', name: 'Canadian Dollar' },
  { code: 'AUD', name: 'Australian Dollar' },
  { code: 'JPY', name: 'Japanese Yen' },
]

const form = ref({
  monthly_limit_usd: props.modelValue?.monthly_limit_usd || 500,
  currency: props.modelValue?.currency || 'USD',
})

// Rough estimates based on average costs
const estimatedChats = computed(() => {
  const budget = form.value.monthly_limit_usd || 0
  return Math.floor(budget / 0.02)
})

const estimatedFlows = computed(() => {
  const budget = form.value.monthly_limit_usd || 0
  return Math.floor(budget / 0.06)
})

const estimatedTasks = computed(() => {
  const budget = form.value.monthly_limit_usd || 0
  return Math.floor(budget / 0.03)
})

watch(form, (newVal) => {
  emit('update:modelValue', newVal)
}, { deep: true })

watch(() => props.modelValue, (newVal) => {
  if (newVal) {
    form.value = { ...form.value, ...newVal }
  }
}, { immediate: true })
</script>

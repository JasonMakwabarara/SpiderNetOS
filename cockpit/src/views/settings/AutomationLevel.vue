<template>
  <div class="max-w-2xl mx-auto p-6">
    <h1 class="text-2xl font-bold mb-2">Automation Level</h1>
    <p class="text-gray-600 mb-6">Control how much autonomy Atlas has when executing tasks.</p>

    <div class="space-y-4 mb-8">
      <label
        v-for="option in options"
        :key="option.value"
        class="flex items-start gap-3 p-4 border-2 rounded-lg cursor-pointer transition-all"
        :class="{
          'border-indigo-500 bg-indigo-50': selected === option.value,
          'border-gray-200 hover:border-gray-300': selected !== option.value,
        }"
      >
        <input v-model="selected" type="radio" :value="option.value" class="mt-0.5 w-4 h-4 text-indigo-600" />
        <div class="flex-1">
          <div class="flex items-center gap-2">
            <span class="font-medium text-gray-900">{{ option.label }}</span>
            <span v-if="option.recommended" class="px-2 py-0.5 text-xs bg-indigo-100 text-indigo-700 rounded-full">Recommended</span>
            <span v-if="currentLevel === option.value" class="px-2 py-0.5 text-xs bg-green-100 text-green-700 rounded-full">Current</span>
          </div>
          <p class="text-sm text-gray-600 mt-1">{{ option.description }}</p>
        </div>
      </label>
    </div>

    <div class="flex items-center justify-between pt-4 border-t border-gray-200">
      <p v-if="saved" class="text-sm text-green-600 flex items-center gap-1">
        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
        Saved successfully
      </p>
      <p v-else-if="error" class="text-sm text-red-600">{{ error }}</p>
      <p v-else class="text-sm text-gray-500">Changes take effect immediately</p>

      <button
        class="px-6 py-2 rounded-lg bg-indigo-600 text-white font-medium hover:bg-indigo-700 disabled:opacity-50"
        :disabled="selected === currentLevel || isLoading"
        @click="save"
      >
        {{ isLoading ? 'Saving...' : 'Save Changes' }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useOnboarding } from '../../composables/useOnboarding.js'
import { useAuthStore } from '../../stores/auth.js'

const { updateAutomationLevel, load, automationLevel } = useOnboarding()
const auth = useAuthStore()

const selected = ref('assisted')
const currentLevel = ref('assisted')
const isLoading = ref(false)
const saved = ref(false)
const error = ref(null)

const options = [
  { value: 'manual', label: 'Suggest only', description: 'Atlas proposes actions; you approve every execution. Maximum oversight.', recommended: false },
  { value: 'assisted', label: 'Suggest + confirm', description: 'Reversible actions run automatically. Irreversible actions require approval.', recommended: true },
  { value: 'autonomous', label: 'Act automatically', description: 'Full execution within budget and policies. Only failures page you.', recommended: false },
]

onMounted(async () => {
  await load()
  selected.value = automationLevel.value
  currentLevel.value = automationLevel.value
})

async function save() {
  isLoading.value = true
  saved.value = false
  error.value = null
  try {
    await updateAutomationLevel(selected.value)
    currentLevel.value = selected.value
    saved.value = true
    setTimeout(() => saved.value = false, 3000)
  } catch (e) {
    error.value = e.message || 'Failed to save'
  } finally {
    isLoading.value = false
  }
}
</script>

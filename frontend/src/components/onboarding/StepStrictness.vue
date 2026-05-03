<template>
  <div class="space-y-4">
    <div class="bg-amber-50 rounded-lg p-4 mb-4">
      <p class="text-sm text-amber-800">
        Choose how much autonomy Atlas has. This affects when human approval is required.
        You can change this anytime in Settings.
      </p>
    </div>

    <div class="space-y-3">
      <label
        v-for="option in options"
        :key="option.value"
        class="flex items-start gap-3 p-4 border-2 rounded-lg cursor-pointer transition-all"
        :class="{
          'border-indigo-500 bg-indigo-50': selected === option.value,
          'border-gray-200 hover:border-gray-300': selected !== option.value,
        }"
      >
        <input
          v-model="selected"
          type="radio"
          :value="option.value"
          class="mt-0.5 w-4 h-4 text-indigo-600 focus:ring-indigo-500"
        />
        <div class="flex-1">
          <div class="flex items-center gap-2">
            <span class="font-medium text-gray-900">{{ option.label }}</span>
            <span
              v-if="option.recommended"
              class="px-2 py-0.5 text-xs bg-indigo-100 text-indigo-700 rounded-full"
            >
              Recommended
            </span>
          </div>
          <p class="text-sm text-gray-600 mt-1">{{ option.description }}</p>
          <ul class="text-xs text-gray-500 mt-2 space-y-1">
            <li v-for="example in option.examples" :key="example" class="flex items-center gap-1">
              <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
              </svg>
              {{ example }}
            </li>
          </ul>
        </div>
      </label>
    </div>

    <div v-if="selected" class="pt-4 border-t border-gray-100">
      <p class="text-sm text-gray-700">
        <strong>Current selection:</strong>
        {{ options.find(o => o.value === selected)?.label }}
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
  modelValue: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update:modelValue'])

const options = [
  {
    value: 'manual',
    label: 'Suggest only',
    description: 'Atlas proposes actions but never executes without your explicit approval. Every action becomes an approval request.',
    examples: [
      'Atlas drafts emails but waits for you to send',
      'All tool calls require manual approval',
      'Maximum oversight, slower execution',
    ],
    recommended: false,
  },
  {
    value: 'assisted',
    label: 'Suggest + confirm',
    description: 'Atlas executes reversible actions automatically (like drafting content). Irreversible actions (like sending emails, making purchases) require approval.',
    examples: [
      'Drafts, edits, and analysis run automatically',
      'Sends, purchases, and deletions ask first',
      'Balanced speed with safety',
    ],
    recommended: true,
  },
  {
    value: 'autonomous',
    label: 'Act automatically',
    description: 'Atlas executes all actions within your budget and policy guardrails. Only hard failures or policy violations page you.',
    examples: [
      'Full execution within defined constraints',
      'Real-time responses without delays',
      'Best for mature workflows you trust',
    ],
    recommended: false,
  },
]

const selected = ref(props.modelValue?.automation_level || 'assisted')

// Emit when selection changes
watch(selected, (newVal) => {
  emit('update:modelValue', {
    automation_level: newVal,
  })
})

// Sync from props
watch(() => props.modelValue?.automation_level, (newVal) => {
  if (newVal) {
    selected.value = newVal
  }
}, { immediate: true })
</script>

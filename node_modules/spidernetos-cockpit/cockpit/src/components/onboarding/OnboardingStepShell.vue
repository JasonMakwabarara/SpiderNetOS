<template>
  <div class="space-y-6">
    <!-- Step Content -->
    <div class="min-h-[200px]">
      <slot />
    </div>

    <!-- Navigation -->
    <div class="flex items-center justify-between pt-4 border-t border-gray-100">
      <button
        class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
        :disabled="currentStep === 0"
        @click="$emit('back')"
      >
        Back
      </button>

      <div class="flex items-center gap-3">
        <span v-if="error" class="text-sm text-red-600">{{ error }}</span>
        <button
          class="px-6 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-2"
          :disabled="!isValid || isLoading"
          @click="$emit('next')"
        >
          <svg v-if="isLoading" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
          </svg>
          {{ isLastStep ? 'Finish' : 'Next' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  currentStep: { type: Number, required: true },
  isLastStep: { type: Boolean, default: false },
  isValid: { type: Boolean, default: true },
  isLoading: { type: Boolean, default: false },
  error: { type: String, default: null },
})

defineEmits(['next', 'back'])
</script>

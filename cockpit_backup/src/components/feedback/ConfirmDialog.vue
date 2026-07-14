<template>
  <Teleport to="body">
    <div
      v-if="modelValue"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="titleId"
      @click.self="cancel"
    >
      <div class="w-full max-w-md rounded-lg shadow-xl" style="background: var(--bg-card, #fff);">
        <div class="p-5 border-b" style="border-color: var(--border, #e5e7eb);">
          <h3 :id="titleId" class="text-lg font-semibold" style="color: var(--text-primary, #111);">
            {{ title }}
          </h3>
        </div>

        <div class="p-5 text-sm" style="color: var(--text-secondary, #374151);">
          <slot>{{ message }}</slot>
        </div>

        <div class="px-5 py-3 flex justify-end gap-2 border-t" style="border-color: var(--border, #e5e7eb);">
          <button
            class="px-4 py-1.5 rounded text-sm font-medium"
            style="color: var(--text-primary, #111);"
            @click="cancel"
          >
            {{ cancelLabel }}
          </button>
          <button
            class="px-4 py-1.5 rounded text-sm font-medium text-white"
            :style="{ background: destructive ? '#dc2626' : '#4f46e5' }"
            @click="confirm"
          >
            {{ confirmLabel }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  modelValue:   { type: Boolean, default: false },
  title:        { type: String, default: 'Are you sure?' },
  message:      { type: String, default: '' },
  confirmLabel: { type: String, default: 'Confirm' },
  cancelLabel:  { type: String, default: 'Cancel' },
  destructive:  { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'confirm', 'cancel'])

const titleId = computed(() => `confirm-dialog-${Math.random().toString(36).slice(2, 9)}`)

function confirm() {
  emit('confirm')
  emit('update:modelValue', false)
}
function cancel() {
  emit('cancel')
  emit('update:modelValue', false)
}
</script>

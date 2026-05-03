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
          <h3 :id="titleId" class="text-lg font-semibold text-red-700">
            {{ title }}
          </h3>
        </div>

        <div class="p-5 text-sm" style="color: var(--text-secondary, #374151);">
          <slot />
          <p class="mt-4">
            Type
            <code class="px-1.5 py-0.5 rounded bg-gray-100 font-mono text-red-700 select-all">{{ phrase }}</code>
            to confirm.
          </p>
          <input
            v-model="input"
            type="text"
            autocomplete="off"
            class="mt-3 w-full px-3 py-2 border rounded font-mono"
            :class="[
              matches ? 'border-green-400 focus:ring-green-500' : 'border-gray-300 focus:ring-indigo-500',
              'focus:outline-none focus:ring-2',
            ]"
            :aria-label="`Type ${phrase} to confirm`"
            :aria-invalid="!matches && input.length > 0"
            @keyup.enter="matches && confirm()"
          />
        </div>

        <div class="px-5 py-3 flex justify-end gap-2 border-t" style="border-color: var(--border, #e5e7eb);">
          <button
            class="px-4 py-1.5 rounded text-sm font-medium"
            @click="cancel"
          >
            {{ cancelLabel }}
          </button>
          <button
            class="px-4 py-1.5 rounded text-sm font-semibold text-white bg-red-600 disabled:opacity-40 disabled:cursor-not-allowed"
            :disabled="!matches"
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
import { computed, ref, watch } from 'vue'

const props = defineProps({
  modelValue:   { type: Boolean, default: false },
  phrase:       { type: String, required: true },
  title:        { type: String, default: 'Confirm destructive action' },
  confirmLabel: { type: String, default: 'Confirm' },
  cancelLabel:  { type: String, default: 'Cancel' },
})

const emit = defineEmits(['update:modelValue', 'confirm', 'cancel'])

const input   = ref('')
const titleId = computed(() => `typed-confirm-${Math.random().toString(36).slice(2, 9)}`)
const matches = computed(() => input.value.trim() === props.phrase)

watch(() => props.modelValue, (v) => { if (v) input.value = '' })

function confirm() {
  if (!matches.value) return
  emit('confirm')
  emit('update:modelValue', false)
  input.value = ''
}
function cancel() {
  emit('cancel')
  emit('update:modelValue', false)
  input.value = ''
}
</script>

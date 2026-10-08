<template>
  <Teleport to="body">
    <Transition name="prompt">
      <div
        v-if="modelValue"
        class="fixed inset-0 z-[9998] flex items-center justify-center px-4"
        style="background: rgba(5,7,10,0.65); backdrop-filter: blur(6px);"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        data-testid="prompt-dialog"
        @click.self="cancel"
        @keydown.escape.stop="cancel"
      >
        <div class="w-full max-w-md sn-panel overflow-hidden">
          <header class="px-5 py-4 border-b" style="border-color: var(--border);">
            <h3 :id="titleId" class="font-semibold text-[15px]" style="color: var(--text-primary);">
              {{ title }}
            </h3>
          </header>

          <div class="px-5 py-4 text-sm" style="color: var(--text-secondary);">
            <slot>
              <p v-if="message" class="whitespace-pre-line" data-testid="prompt-dialog-message">{{ message }}</p>
            </slot>
            <label v-if="inputLabel" class="block mt-3 text-xs" :for="inputId">{{ inputLabel }}</label>
            <textarea
              v-if="multiline"
              :id="inputId"
              ref="inputRef"
              v-model="value"
              class="sn-textarea mt-2"
              rows="4"
              :placeholder="placeholder"
              data-testid="prompt-dialog-input"
              @keydown.ctrl.enter.prevent="confirm"
              @keydown.meta.enter.prevent="confirm"
            ></textarea>
            <input
              v-else
              :id="inputId"
              ref="inputRef"
              v-model="value"
              type="text"
              class="mt-2"
              autocomplete="off"
              :placeholder="placeholder"
              data-testid="prompt-dialog-input"
              @keydown.enter.prevent="confirm"
            />
          </div>

          <footer
            class="px-5 py-3 flex justify-end gap-2 border-t"
            style="border-color: var(--border); background: rgba(0,0,0,0.25);"
          >
            <button type="button" class="sn-btn-secondary" data-testid="prompt-dialog-cancel" @click="cancel">
              {{ cancelLabel }}
            </button>
            <button
              type="button"
              class="sn-btn sn-btn-primary"
              :disabled="!canConfirm"
              data-testid="prompt-dialog-confirm"
              @click="confirm"
            >
              {{ confirmLabel }}
            </button>
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
/**
 * PromptDialog — ConfirmDialog with a text answer. Replaces window.prompt
 * (which is blocked in PWAs / iframes and unstyled everywhere). Emits
 * `confirm(value)` with the trimmed answer and `cancel` otherwise.
 */
import { computed, nextTick, ref, watch } from 'vue'

const props = defineProps({
  modelValue:   { type: Boolean, default: false },
  title:        { type: String, default: 'Your answer' },
  message:      { type: String, default: '' },
  inputLabel:   { type: String, default: '' },
  placeholder:  { type: String, default: '' },
  initialValue: { type: String, default: '' },
  confirmLabel: { type: String, default: 'Confirm' },
  cancelLabel:  { type: String, default: 'Cancel' },
  multiline:    { type: Boolean, default: true },
  required:     { type: Boolean, default: true },
})

const emit = defineEmits(['update:modelValue', 'confirm', 'cancel'])

const uid = Math.random().toString(36).slice(2, 9)
const titleId = `prompt-dialog-${uid}`
const inputId = `prompt-dialog-input-${uid}`

const value = ref(props.initialValue)
const inputRef = ref(null)

const canConfirm = computed(() => !props.required || value.value.trim().length > 0)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    value.value = props.initialValue
    nextTick(() => inputRef.value?.focus?.())
  },
  { immediate: true },
)

function confirm() {
  if (!canConfirm.value) return
  emit('confirm', value.value.trim())
  emit('update:modelValue', false)
}

function cancel() {
  emit('cancel')
  emit('update:modelValue', false)
}
</script>

<style scoped>
.prompt-enter-active, .prompt-leave-active { transition: opacity 0.16s ease; }
.prompt-enter-from, .prompt-leave-to { opacity: 0; }
</style>

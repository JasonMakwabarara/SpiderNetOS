<template>
  <Teleport to="body">
    <Transition name="confirm">
      <div
        v-if="modelValue"
        class="fixed inset-0 z-[9998] flex items-center justify-center px-4"
        style="background: rgba(5,7,10,0.65); backdrop-filter: blur(6px);"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        data-testid="typed-confirm-dialog"
        @click.self="cancel"
        @keydown.escape="cancel"
      >
        <div class="w-full max-w-md sn-panel overflow-hidden">
          <header class="px-5 py-4 border-b" style="border-color: var(--border);">
            <div class="flex items-center gap-2">
              <span class="sn-pill sn-pill-danger">{{ riskLabel }}</span>
              <h3 :id="titleId" class="font-heading font-semibold text-[15px]"
                  style="color: var(--text-primary);">
                {{ title }}
              </h3>
            </div>
          </header>

          <div class="px-5 py-4 text-sm" style="color: var(--text-secondary);">
            <slot />
            <p class="mt-4">
              Type
              <code class="px-1.5 py-0.5 rounded mono select-all"
                    style="background: var(--bg-elevated); color: var(--danger); border: 1px solid rgba(255,90,122,0.30);">
                {{ phrase }}
              </code>
              to confirm.
            </p>
            <input
              v-model="input"
              type="text"
              autocomplete="off"
              class="mt-3 mono"
              :style="matches
                ? 'border-color: rgba(34,211,155,0.6); box-shadow: 0 0 0 3px rgba(34,211,155,0.10);'
                : ''"
              :aria-label="`Type ${phrase} to confirm`"
              :aria-invalid="!matches && input.length > 0"
              data-testid="typed-confirm-input"
              @keyup.enter="matches && confirm()"
            />
            <label v-if="reasonRequired" class="block mt-4">Reason
              <span style="color: var(--danger);">*</span>
            </label>
            <textarea
              v-if="reasonRequired"
              v-model="reason"
              rows="3"
              class="mt-1"
              placeholder="Briefly note why this change is needed (will be recorded in audit)…"
              data-testid="typed-confirm-reason"
            />
          </div>

          <footer class="px-5 py-3 flex items-center justify-end gap-2 border-t"
                  style="border-color: var(--border); background: rgba(0,0,0,0.25);">
            <button class="sn-btn" data-testid="typed-confirm-cancel" @click="cancel">{{ cancelLabel }}</button>
            <button
              class="sn-btn"
              :disabled="!canConfirm"
              :style="canConfirm
                ? 'background: rgba(255,90,122,0.16); color: var(--danger); border-color: rgba(255,90,122,0.40);'
                : 'opacity: 0.4; cursor: not-allowed;'"
              data-testid="typed-confirm-submit"
              @click="confirm"
            >{{ confirmLabel }}</button>
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  modelValue:     { type: Boolean, default: false },
  phrase:         { type: String, required: true },
  title:          { type: String, default: 'Confirm destructive action' },
  riskLabel:      { type: String, default: 'High risk' },
  confirmLabel:   { type: String, default: 'Confirm' },
  cancelLabel:    { type: String, default: 'Cancel' },
  reasonRequired: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'confirm', 'cancel'])

const input = ref('')
const reason = ref('')
const titleId = computed(() => `typed-confirm-${Math.random().toString(36).slice(2, 9)}`)
const matches = computed(() => input.value.trim() === props.phrase)
const canConfirm = computed(() =>
  matches.value && (!props.reasonRequired || reason.value.trim().length > 3)
)

watch(() => props.modelValue, (v) => {
  if (v) { input.value = ''; reason.value = '' }
})

function confirm() {
  if (!canConfirm.value) return
  emit('confirm', { reason: reason.value.trim() })
  emit('update:modelValue', false)
}
function cancel() {
  emit('cancel')
  emit('update:modelValue', false)
}
</script>

<style scoped>
.confirm-enter-active, .confirm-leave-active { transition: opacity 0.18s ease; }
.confirm-enter-from, .confirm-leave-to { opacity: 0; }
</style>

<template>
  <Teleport to="body">
    <div
      v-if="modelValue"
      class="fixed inset-0 z-40 flex"
      role="dialog"
      aria-modal="true"
      aria-label="Raw JSON inspector"
      @keydown.esc="close"
    >
      <div class="flex-1 bg-black/40" @click="close" />

      <aside class="w-[480px] max-w-full h-full bg-white shadow-xl flex flex-col">
        <header class="flex items-center justify-between px-4 py-3 border-b">
          <h3 class="text-sm font-semibold">{{ title }}</h3>
          <div class="flex items-center gap-2">
            <button
              class="px-2 py-1 text-xs rounded border"
              :aria-label="copied ? 'Copied' : 'Copy JSON'"
              @click="copy"
            >
              {{ copied ? 'Copied ✓' : 'Copy' }}
            </button>
            <button class="px-2 py-1 text-xs" aria-label="Close drawer" @click="close">✕</button>
          </div>
        </header>

        <pre
          class="flex-1 overflow-auto text-xs font-mono p-4 whitespace-pre-wrap break-words"
          style="background: #0b1021; color: #a5f3fc;"
        >{{ pretty }}</pre>
      </aside>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, ref } from 'vue'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  payload:    { type: [Object, Array, String, Number, Boolean, null], default: null },
  title:      { type: String, default: 'Raw JSON' },
})
const emit   = defineEmits(['update:modelValue'])
const copied = ref(false)

const pretty = computed(() => {
  try {
    return JSON.stringify(props.payload, null, 2)
  } catch {
    return String(props.payload)
  }
})

function close() { emit('update:modelValue', false) }
async function copy() {
  try {
    await navigator.clipboard.writeText(pretty.value)
    copied.value = true
    setTimeout(() => (copied.value = false), 1500)
  } catch {
    /* ignore */
  }
}
</script>

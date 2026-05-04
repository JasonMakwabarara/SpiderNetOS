<template>
  <div class="inline-flex flex-col items-stretch">
    <button
      type="button"
      class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium border transition-colors"
      :class="classes"
      :disabled="loading || disabled"
      :title="title"
      :aria-busy="loading"
      :aria-label="loading ? 'Enhancing prompt…' : 'Enhance prompt'"
      @click="onClick"
    >
      <svg
        v-if="!loading"
        class="w-3.5 h-3.5"
        fill="none"
        stroke="currentColor"
        viewBox="0 0 24 24"
        aria-hidden="true"
      >
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707M12 18a6 6 0 100-12 6 6 0 000 12z"
        />
      </svg>
      <svg
        v-else
        class="w-3.5 h-3.5 animate-spin"
        fill="none"
        stroke="currentColor"
        viewBox="0 0 24 24"
        aria-hidden="true"
      >
        <circle cx="12" cy="12" r="10" stroke-width="3" class="opacity-25" />
        <path
          stroke-linecap="round"
          stroke-width="3"
          d="M12 2a10 10 0 0110 10"
          class="opacity-75"
        />
      </svg>
      <span>{{ loading ? 'Enhancing…' : label }}</span>
    </button>

    <!-- Diff preview (optional; shown when showPreview=true and lastResult exists) -->
    <div
      v-if="showPreview && lastResult"
      class="mt-2 rounded border bg-gray-50 p-2 text-xs"
      role="region"
      aria-label="Enhanced prompt preview"
    >
      <div class="flex items-center justify-between mb-1.5">
        <span class="font-semibold text-gray-600">Enhanced ({{ lastResult.mode || 'preview' }})</span>
        <div class="flex gap-1">
          <button
            type="button"
            class="px-2 py-0.5 rounded bg-indigo-600 text-white"
            @click="apply"
          >Apply</button>
          <button
            type="button"
            class="px-2 py-0.5 rounded border"
            @click="dismiss"
          >Dismiss</button>
        </div>
      </div>
      <pre class="whitespace-pre-wrap text-gray-800 max-h-72 overflow-y-auto">{{ lastResult.enhanced }}</pre>
    </div>

    <!-- Inline error -->
    <p v-if="error" class="mt-1 text-xs text-red-600" role="alert">
      {{ error.message }}
    </p>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useEnhancePrompt } from '../../composables/useEnhancePrompt.js'

const props = defineProps({
  /**
   * Current prompt text to enhance. Bound via v-model so the button
   * can write back the enhanced version directly when applyMode='replace'.
   */
  modelValue: { type: String, default: '' },

  /** Atlas target surface (affects the server-side prompt template). */
  surface:  { type: String, default: 'generic' },
  mode:     { type: String, default: 'balanced' },  // concise | balanced | deep
  audience: { type: String, default: 'user' },

  /** UI label override. */
  label:    { type: String, default: 'Enhance' },

  /** Disable the button (e.g., during user typing). */
  disabled: { type: Boolean, default: false },

  /**
   * Apply mode:
   *   - 'replace' (default) — the enhanced prompt replaces modelValue on success
   *   - 'preview'           — show the diff panel and require user to click Apply
   */
  applyMode: { type: String, default: 'preview' },

  /** Colour variant: 'default' | 'ghost'. */
  variant: { type: String, default: 'default' },
})

const emit = defineEmits(['update:modelValue', 'enhanced', 'error'])

const { enhance, loading, error, lastResult } = useEnhancePrompt()

const showPreview = ref(false)

const classes = computed(() => {
  if (props.variant === 'ghost') {
    return [
      'border-transparent text-gray-500 hover:bg-gray-100',
      'disabled:opacity-40 disabled:cursor-not-allowed',
    ]
  }
  return [
    'border-indigo-300 bg-indigo-50 text-indigo-700 hover:bg-indigo-100',
    'disabled:opacity-40 disabled:cursor-not-allowed',
  ]
})

const title = computed(() =>
  loading.value
    ? 'Enhancing your prompt…'
    : 'Rewrite this prompt with Atlas — clearer goals, constraints, and success criteria.',
)

async function onClick() {
  if (!props.modelValue || !props.modelValue.trim()) {
    emit('error', { code: 'empty_prompt', message: 'Write something first.' })
    return
  }

  const result = await enhance({
    prompt:   props.modelValue,
    surface:  props.surface,
    mode:     props.mode,
    audience: props.audience,
  })

  if (!result.ok) {
    emit('error', result.error)
    return
  }

  emit('enhanced', result.data)

  if (props.applyMode === 'replace') {
    emit('update:modelValue', result.data.enhanced)
  } else {
    showPreview.value = true
  }
}

function apply() {
  if (!lastResult.value?.enhanced) return
  emit('update:modelValue', lastResult.value.enhanced)
  showPreview.value = false
}

function dismiss() {
  showPreview.value = false
}
</script>

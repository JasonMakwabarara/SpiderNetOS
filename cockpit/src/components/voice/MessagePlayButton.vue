<template>
  <button
    type="button"
    class="inline-flex items-center gap-1 px-2 py-0.5 text-[11px] font-medium rounded-full border transition-colors"
    :class="state === 'playing'
      ? 'bg-indigo-50 border-indigo-300 text-indigo-700'
      : blocked
        ? 'bg-amber-50 border-amber-300 text-amber-800 hover:bg-amber-100'
        : 'bg-white border-gray-200 text-gray-600 hover:border-indigo-300 hover:text-indigo-700'"
    :aria-label="ariaLabel"
    :data-state="state"
    :data-testid="`atlas-msg-play-${messageId}`"
    @click="onClick"
  >
    <svg v-if="state === 'playing'" class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
      <rect x="6" y="6" width="12" height="12" rx="1.5" />
    </svg>
    <svg v-else-if="state === 'loading'" class="w-3 h-3 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true">
      <path stroke-linecap="round" d="M12 3a9 9 0 019 9" />
    </svg>
    <svg v-else class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
      <path d="M8 5.5v13l11-6.5-11-6.5z" />
    </svg>
    <span>{{ label }}</span>
  </button>
</template>

<script setup>
/**
 * MessagePlayButton — per-reply ▶ / ■ for Atlas messages. Emits `play` or
 * `stop`; the parent calls the voice store from the click so the audio is
 * unlocked inside the gesture. Shows "Tap to hear" when the browser
 * blocked an automatic read-out.
 */
import { computed } from 'vue'

const props = defineProps({
  messageId: { type: [String, Number], required: true },
  /** 'idle' | 'loading' | 'playing' */
  state:     { type: String, default: 'idle' },
  blocked:   { type: Boolean, default: false },
})

const emit = defineEmits(['play', 'stop'])

const label = computed(() => {
  if (props.state === 'playing') return 'Stop'
  if (props.state === 'loading') return 'Loading…'
  return props.blocked ? 'Tap to hear' : 'Play'
})

const ariaLabel = computed(() => {
  if (props.state === 'playing') return 'Stop reading this reply'
  if (props.state === 'loading') return 'Preparing audio — press to cancel'
  return 'Read this reply aloud'
})

function onClick() {
  if (props.state === 'playing' || props.state === 'loading') emit('stop', props.messageId)
  else emit('play', props.messageId)
}
</script>

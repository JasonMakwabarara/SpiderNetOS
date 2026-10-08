<template>
  <div class="inline-flex items-center gap-2">
    <button
      type="button"
      class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-full border transition-colors"
      :class="enabled
        ? 'bg-indigo-600 border-indigo-600 text-white hover:bg-indigo-700'
        : 'bg-white border-gray-300 text-gray-700 hover:border-indigo-300'"
      :aria-pressed="enabled ? 'true' : 'false'"
      :aria-label="enabled ? 'Atlas reads replies aloud — turn off' : 'Have Atlas read replies aloud'"
      :title="enabled ? 'Atlas reads new replies aloud' : 'Read new replies aloud'"
      :disabled="disabled"
      data-testid="atlas-speak-toggle"
      @click="emit('toggle')"
    >
      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5L6 9H3v6h3l5 4V5z" />
        <path v-if="enabled" stroke-linecap="round" d="M15.5 8.5a5 5 0 010 7M18.5 5.5a9 9 0 010 13" />
        <path v-else stroke-linecap="round" d="M16 9l5 6M21 9l-5 6" />
      </svg>
      <span>{{ enabled ? 'Speaking' : 'Speak' }}</span>
    </button>
    <span
      v-if="enabled && blocked"
      class="text-[11px] text-amber-700"
      data-testid="atlas-speak-blocked"
    >Tap to hear</span>
  </div>
</template>

<script setup>
/**
 * SpeakToggle — "Atlas reads replies aloud" switch for the Atlas chat
 * header. Presentational: the parent calls `voiceStore.toggleSpeak()`
 * straight from the `toggle` event so the audio unlock happens inside the
 * click gesture (autoplay policy).
 */
defineProps({
  enabled:  { type: Boolean, default: false },
  blocked:  { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['toggle'])
</script>

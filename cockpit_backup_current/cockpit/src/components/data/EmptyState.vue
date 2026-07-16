<template>
  <div class="py-10 px-6 text-center" role="status" aria-live="polite">
    <div v-if="icon" class="mx-auto w-12 h-12 mb-3 text-gray-400" v-html="icon" />
    <p class="text-base font-medium" style="color: var(--text-primary, #111);">
      <slot name="title">{{ title }}</slot>
    </p>
    <p class="text-sm mt-1" style="color: var(--text-muted, #6b7280);">
      <slot>{{ description }}</slot>
    </p>
    <div v-if="$slots.actions" class="mt-4 flex justify-center gap-2">
      <slot name="actions" />
    </div>
  </div>
</template>

<script setup>
defineProps({
  title:       { type: String, default: 'No data yet' },
  description: { type: String, default: '' },
  icon:        { type: String, default: '' },
})
</script>

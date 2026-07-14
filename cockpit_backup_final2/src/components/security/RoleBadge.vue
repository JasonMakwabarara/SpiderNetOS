<template>
  <span
    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold"
    :class="classes"
    :aria-label="`Role: ${label}`"
    role="status"
  >
    <svg v-if="icon" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
      <path :d="icon" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" />
    </svg>
    <span>{{ label }}</span>
  </span>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  role: { type: String, required: true },
})

const label = computed(() => ({
  user:        'User',
  admin:       'Admin',
  super_admin: 'Super Admin',
}[props.role] || props.role))

const classes = computed(() => ({
  user:        'bg-indigo-100 text-indigo-800',
  admin:       'bg-amber-100 text-amber-800',
  super_admin: 'bg-red-100 text-red-800 ring-1 ring-red-300',
}[props.role] || 'bg-gray-100 text-gray-700'))

// Shield icon for super_admin; no icon for others
const icon = computed(() =>
  props.role === 'super_admin'
    ? 'M12 15v2m0 4a2 2 0 01-2-2v-1a2 2 0 012-2h0a2 2 0 012 2v1a2 2 0 01-2 2zm6-10V7a6 6 0 00-12 0v4a2 2 0 002 2h8a2 2 0 002-2z'
    : null,
)
</script>

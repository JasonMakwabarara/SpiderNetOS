<template>
  <div>
    <p v-if="!entries.length" class="text-sm text-gray-500 py-4">No drop-off data yet.</p>

    <ul v-else class="space-y-1.5">
      <li v-for="[state, p] in entries" :key="state" class="text-sm">
        <div class="flex items-center justify-between text-xs mb-0.5">
          <span class="font-mono">{{ state }}</span>
          <span class="font-mono" :class="colorFor(p)">{{ (p * 100).toFixed(1) }}%</span>
        </div>
        <div class="h-1.5 rounded-full bg-gray-100 overflow-hidden">
          <div
            class="h-full rounded-full"
            :class="barColorFor(p)"
            :style="{ width: `${Math.min(100, p * 100)}%` }"
          />
        </div>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  dropoffs: { type: Object, default: () => ({}) },
})

const entries = computed(() =>
  Object.entries(props.dropoffs).sort((a, b) => b[1] - a[1])
)

function colorFor(p) {
  if (p >= 0.3) return 'text-red-600'
  if (p >= 0.15) return 'text-amber-600'
  return 'text-green-600'
}

function barColorFor(p) {
  if (p >= 0.3) return 'bg-red-500'
  if (p >= 0.15) return 'bg-amber-500'
  return 'bg-green-500'
}
</script>

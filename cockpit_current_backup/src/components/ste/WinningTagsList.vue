<template>
  <div>
    <p v-if="!tags.length" class="text-sm text-gray-500 py-4">No winning tag combinations yet.</p>

    <ol v-else class="space-y-1.5 text-sm">
      <li
        v-for="(row, i) in tags"
        :key="`${row.from_state}:${row.tag_key}:${row.tag_value}`"
        class="flex items-center justify-between gap-2 px-3 py-2 rounded border"
      >
        <div class="flex items-center gap-2 min-w-0">
          <span class="text-gray-400 w-5 text-right">{{ i + 1 }}.</span>
          <span class="font-mono text-xs">{{ row.from_state }}</span>
          <span class="text-gray-400">·</span>
          <code class="text-xs bg-gray-100 px-1.5 py-0.5 rounded">
            {{ row.tag_key }}={{ row.tag_value }}
          </code>
        </div>

        <div class="flex items-center gap-3">
          <span class="text-xs text-gray-400">{{ row.observations }} obs</span>
          <span :class="liftClass(row.lift)" class="text-xs font-mono">
            {{ row.lift >= 0 ? '+' : '' }}{{ (row.lift * 100).toFixed(1) }}%
          </span>
        </div>
      </li>
    </ol>
  </div>
</template>

<script setup>
defineProps({
  tags: { type: Array, default: () => [] },
})

function liftClass(lift) {
  if (lift >= 0.05)  return 'text-green-700'
  if (lift >= 0)     return 'text-green-500'
  if (lift >= -0.05) return 'text-amber-500'
  return 'text-red-600'
}
</script>

<template>
  <div>
    <p v-if="!states.length" class="text-sm text-gray-500 py-6 text-center">
      No transitions recorded yet for this chain.
    </p>

    <div v-else class="overflow-x-auto">
      <table class="min-w-full text-xs font-mono" role="grid" :aria-label="`Transition matrix for ${chain}`">
        <thead>
          <tr>
            <th class="p-1 text-left font-semibold text-gray-500">from ↓ / to →</th>
            <th v-for="to in states" :key="to"
                class="p-1 font-semibold text-gray-600 text-center">{{ to }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="from in states" :key="from">
            <td class="p-1 font-semibold text-gray-700">{{ from }}</td>
            <td v-for="to in states" :key="to"
                class="p-1 text-center"
                :style="cellStyle(from, to)"
                :title="cellTitle(from, to)"
                :aria-label="cellTitle(from, to)">
              {{ cellText(from, to) }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  chain:  { type: String, required: true },
  matrix: { type: Object, default: () => ({}) },
})

const states = computed(() => {
  const s = new Set()
  for (const from in props.matrix) {
    s.add(from)
    for (const to in props.matrix[from]) s.add(to)
  }
  return [...s].sort()
})

function prob(from, to) {
  return props.matrix?.[from]?.[to] ?? 0
}

function cellText(from, to) {
  const p = prob(from, to)
  return p > 0 ? p.toFixed(2) : '—'
}

function cellTitle(from, to) {
  return `${from} → ${to}: P = ${prob(from, to).toFixed(4)}`
}

function cellStyle(from, to) {
  const p = prob(from, to)
  if (p <= 0) return 'background: #f9fafb; color: #d1d5db'
  const intensity = Math.min(1, p)
  // Indigo ramp — more intense for higher probabilities
  const r = Math.round(99 + (1 - intensity) * 156)
  const g = Math.round(102 + (1 - intensity) * 153)
  const b = Math.round(241 - intensity * 10)
  const fg = intensity > 0.5 ? '#fff' : '#1f2937'
  return `background: rgb(${r},${g},${b}); color: ${fg}`
}
</script>

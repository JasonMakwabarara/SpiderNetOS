<template>
  <div class="w-full">
    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200 text-sm">
        <caption v-if="caption" class="sr-only">{{ caption }}</caption>

        <thead class="bg-gray-50">
          <tr>
            <th
              v-for="col in columns"
              :key="col.key"
              scope="col"
              class="px-4 py-2 text-left font-medium text-gray-500 uppercase tracking-wider"
              :aria-sort="sortedBy === col.key ? sortDir : 'none'"
            >
              <button
                v-if="col.sortable"
                class="inline-flex items-center gap-1 hover:text-gray-700"
                @click="toggleSort(col.key)"
              >
                {{ col.label }}
                <span aria-hidden="true" v-if="sortedBy === col.key">
                  {{ sortDir === 'ascending' ? '▲' : '▼' }}
                </span>
              </button>
              <template v-else>{{ col.label }}</template>
            </th>
            <th v-if="$slots.actions" scope="col" class="px-4 py-2 text-right font-medium text-gray-500">
              Actions
            </th>
          </tr>
        </thead>

        <tbody class="bg-white divide-y divide-gray-100">
          <tr v-if="loading">
            <td :colspan="columns.length + ($slots.actions ? 1 : 0)" class="px-4 py-6 text-center text-gray-400">
              Loading…
            </td>
          </tr>
          <tr v-else-if="!sortedRows.length">
            <td :colspan="columns.length + ($slots.actions ? 1 : 0)" class="px-4 py-6 text-center text-gray-400">
              <slot name="empty">No data</slot>
            </td>
          </tr>
          <tr
            v-else
            v-for="(row, idx) in sortedRows"
            :key="rowKey(row, idx)"
            class="hover:bg-gray-50"
          >
            <td v-for="col in columns" :key="col.key" class="px-4 py-2 whitespace-nowrap">
              <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]">
                {{ format(row, col) }}
              </slot>
            </td>
            <td v-if="$slots.actions" class="px-4 py-2 whitespace-nowrap text-right">
              <slot name="actions" :row="row" />
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'

const props = defineProps({
  columns: { type: Array, required: true }, // [{key,label,sortable?,format?}]
  rows:    { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  caption: { type: String, default: '' },
  rowKeyField: { type: String, default: 'id' },
})

const sortedBy = ref('')
const sortDir  = ref('ascending')

function toggleSort(key) {
  if (sortedBy.value === key) {
    sortDir.value = sortDir.value === 'ascending' ? 'descending' : 'ascending'
  } else {
    sortedBy.value = key
    sortDir.value  = 'ascending'
  }
}

const sortedRows = computed(() => {
  if (!sortedBy.value) return props.rows
  const key = sortedBy.value
  const dir = sortDir.value === 'ascending' ? 1 : -1
  return [...props.rows].sort((a, b) => {
    const av = a[key]
    const bv = b[key]
    if (av == null) return 1
    if (bv == null) return -1
    if (av === bv) return 0
    return (av > bv ? 1 : -1) * dir
  })
})

function rowKey(row, idx) {
  return row[props.rowKeyField] ?? idx
}

function format(row, col) {
  if (typeof col.format === 'function') return col.format(row[col.key], row)
  return row[col.key]
}
</script>

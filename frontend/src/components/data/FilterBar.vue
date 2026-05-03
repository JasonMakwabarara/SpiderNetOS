<template>
  <div class="flex flex-wrap items-end gap-2 p-3 rounded-lg border bg-white">
    <div v-for="f in fields" :key="f.key" class="flex flex-col">
      <label :for="`f-${f.key}`" class="text-xs font-medium text-gray-600">{{ f.label }}</label>
      <input
        v-if="f.type === 'text' || !f.type"
        :id="`f-${f.key}`"
        type="text"
        class="px-2 py-1 border rounded text-sm"
        :value="modelValue?.[f.key] || ''"
        @input="update(f.key, $event.target.value)"
      />
      <select
        v-else-if="f.type === 'select'"
        :id="`f-${f.key}`"
        class="px-2 py-1 border rounded text-sm"
        :value="modelValue?.[f.key] || ''"
        @change="update(f.key, $event.target.value)"
      >
        <option value="">All</option>
        <option v-for="opt in f.options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
      </select>
      <input
        v-else-if="f.type === 'date'"
        :id="`f-${f.key}`"
        type="date"
        class="px-2 py-1 border rounded text-sm"
        :value="modelValue?.[f.key] || ''"
        @input="update(f.key, $event.target.value)"
      />
    </div>

    <div class="flex-1" />

    <button
      class="px-3 py-1.5 rounded bg-indigo-600 text-white text-sm font-medium"
      @click="$emit('apply')"
    >
      Apply
    </button>
    <button
      class="px-3 py-1.5 rounded border text-sm"
      @click="reset"
    >
      Reset
    </button>
  </div>
</template>

<script setup>
const props = defineProps({
  fields:     { type: Array, required: true },  // [{key,label,type,options?}]
  modelValue: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:modelValue', 'apply', 'reset'])

function update(key, value) {
  emit('update:modelValue', { ...props.modelValue, [key]: value })
}
function reset() {
  emit('update:modelValue', {})
  emit('reset')
}
</script>

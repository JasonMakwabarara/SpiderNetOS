<template>
  <div class="space-y-4">
    <div class="bg-gray-50 rounded-lg p-4 mb-4">
      <p class="text-sm text-gray-700">
        Customize your workspace appearance. These settings appear in the Atlas UI and exported reports.
      </p>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Primary Color</label>
      <div class="flex items-center gap-3">
        <input
          v-model="form.primary_color"
          type="color"
          class="w-12 h-10 rounded cursor-pointer border border-gray-300"
        />
        <input
          v-model="form.primary_color"
          type="text"
          pattern="^#[a-fA-F0-9]{6}$"
          class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 font-mono uppercase"
          placeholder="#6366f1"
        />
      </div>
      <p class="text-xs text-gray-500 mt-1">Used for buttons, links, and accents throughout the UI.</p>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Logo URL (optional)</label>
      <input
        v-model="form.logo_url"
        type="url"
        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
        placeholder="https://your-cdn.com/logo.png"
      />
      <p class="text-xs text-gray-500 mt-1">PNG or SVG, recommended size 200×40px.</p>
    </div>

    <div class="pt-4 border-t border-gray-100">
      <p class="text-sm font-medium text-gray-700 mb-3">Preview</p>
      <div
        class="bg-white border border-gray-200 rounded-lg p-4 shadow-sm"
        :style="previewStyles"
      >
        <div class="flex items-center gap-3">
          <div
            v-if="form.logo_url"
            class="h-8 w-8 rounded bg-gray-100 flex items-center justify-center overflow-hidden"
          >
            <img :src="form.logo_url" alt="Logo" class="max-h-full max-w-full object-contain">
          </div>
          <div v-else class="h-8 w-8 rounded flex items-center justify-center" :style="{ backgroundColor: form.primary_color }">
            <span class="text-white font-bold text-xs">SN</span>
          </div>
          <div class="h-8 px-3 rounded flex items-center text-white text-sm font-medium" :style="{ backgroundColor: form.primary_color }">
            Sample Button
          </div>
        </div>
      </div>
    </div>

    <div class="text-xs text-gray-500">
      Skip this step to use SpiderNet defaults.
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'

const props = defineProps({
  modelValue: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update:modelValue'])

const form = ref({
  primary_color: props.modelValue?.primary_color || '#6366f1',
  logo_url: props.modelValue?.logo_url || '',
})

const previewStyles = computed(() => ({
  '--brand-color': form.value.primary_color,
}))

// Validate hex color
const isValidColor = computed(() => {
  return /^#[a-fA-F0-9]{6}$/.test(form.value.primary_color)
})

// Emit on change
watch(form, (newVal) => {
  if (isValidColor.value) {
    emit('update:modelValue', newVal)
  }
}, { deep: true })

// Sync from props
watch(() => props.modelValue, (newVal) => {
  if (newVal) {
    form.value = { ...form.value, ...newVal }
  }
}, { immediate: true })
</script>

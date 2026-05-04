<template>
  <div class="space-y-4">
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Organization Name</label>
      <input
        v-model="form.name"
        type="text"
        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
        placeholder="Acme Inc."
      />
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Industry</label>
      <select
        v-model="form.industry"
        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
      >
        <option value="">Select an industry</option>
        <option v-for="ind in industries" :key="ind" :value="ind">{{ ind }}</option>
      </select>
    </div>

    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Region</label>
        <select
          v-model="form.region"
          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
        >
          <option value="">Select region</option>
          <option v-for="region in regions" :key="region" :value="region">{{ region }}</option>
        </select>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Timezone</label>
        <select
          v-model="form.timezone"
          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
        >
          <option value="">Select timezone</option>
          <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
        </select>
      </div>
    </div>

    <div v-if="startedAt" class="text-xs text-gray-500">
      Started: {{ new Date(startedAt).toLocaleString() }}
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'

const props = defineProps({
  modelValue: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update:modelValue'])

const industries = [
  'Technology', 'Finance', 'Healthcare', 'Real Estate', 'Retail',
  'Manufacturing', 'Education', 'Legal', 'Marketing', 'Other'
]

const regions = [
  'North America', 'Europe', 'Asia Pacific', 'Latin America', 'Middle East', 'Africa'
]

const timezones = [
  'UTC', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
  'Europe/London', 'Europe/Paris', 'Europe/Berlin', 'Asia/Tokyo', 'Asia/Singapore', 'Australia/Sydney'
]

const form = ref({
  name: props.modelValue?.name || '',
  industry: props.modelValue?.industry || '',
  region: props.modelValue?.region || '',
  timezone: props.modelValue?.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone,
  started_at: props.modelValue?.started_at || new Date().toISOString(),
})

const startedAt = computed(() => form.value.started_at)

// Emit updates when form changes
watch(form, (newVal) => {
  emit('update:modelValue', newVal)
}, { deep: true })

// Initialize from props
watch(() => props.modelValue, (newVal) => {
  if (newVal) {
    form.value = { ...form.value, ...newVal }
  }
}, { immediate: true })
</script>

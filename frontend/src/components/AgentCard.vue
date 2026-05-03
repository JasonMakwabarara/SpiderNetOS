<template>
  <div 
    class="agent-card bg-white rounded-lg shadow-sm border border-gray-200 p-4 hover:shadow-md transition-shadow"
    :class="{ 'opacity-60': agent.status !== 'active' }"
  >
    <div class="flex items-start justify-between">
      <div class="flex items-center space-x-3">
        <div 
          class="w-10 h-10 rounded-full flex items-center justify-center text-white font-semibold"
          :class="statusColorClass"
        >
          {{ agentInitials }}
        </div>
        <div>
          <h3 class="font-semibold text-gray-900">{{ agent.name }}</h3>
          <p class="text-sm text-gray-500">@{{ agent.slug }}</p>
        </div>
      </div>
      <span 
        class="px-2 py-1 text-xs font-medium rounded-full"
        :class="statusBadgeClass"
      >
        {{ agent.status }}
      </span>
    </div>

    <p class="mt-3 text-sm text-gray-600 line-clamp-2">
      {{ agent.description || 'No description provided' }}
    </p>

    <div class="mt-3 flex flex-wrap gap-1">
      <span 
        v-for="cap in displayedCapabilities" 
        :key="cap"
        class="px-2 py-0.5 text-xs bg-indigo-50 text-indigo-700 rounded"
      >
        {{ cap }}
      </span>
      <span 
        v-if="extraCapabilities > 0" 
        class="px-2 py-0.5 text-xs text-gray-500"
      >
        +{{ extraCapabilities }} more
      </span>
    </div>

    <div class="mt-4 flex items-center justify-between">
      <div class="flex items-center space-x-2 text-sm text-gray-500">
        <span v-if="agent.execution_count !== undefined">
          {{ formatNumber(agent.execution_count) }} runs
        </span>
      </div>
      <div class="flex space-x-2">
        <button
          @click="$emit('toggle', agent.id)"
          class="p-1.5 text-gray-400 hover:text-gray-600 rounded"
          :title="agent.status === 'active' ? 'Pause' : 'Activate'"
        >
          <svg v-if="agent.status === 'active'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </button>
        <button
          @click="$emit('edit', agent)"
          class="p-1.5 text-gray-400 hover:text-indigo-600 rounded"
          title="Edit"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
          </svg>
        </button>
        <button
          @click="$emit('dispatch', agent)"
          class="px-3 py-1.5 text-sm bg-indigo-600 text-white rounded hover:bg-indigo-700"
        >
          Run
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  agent: {
    type: Object,
    required: true
  }
})

defineEmits(['toggle', 'edit', 'dispatch'])

const agentInitials = computed(() => {
  return props.agent.name
    .split(' ')
    .map(n => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2)
})

const statusColorClass = computed(() => {
  const colors = {
    active: 'bg-green-500',
    paused: 'bg-yellow-500',
    error: 'bg-red-500',
    draft: 'bg-gray-400'
  }
  return colors[props.agent.status] || 'bg-gray-400'
})

const statusBadgeClass = computed(() => {
  const classes = {
    active: 'bg-green-100 text-green-800',
    paused: 'bg-yellow-100 text-yellow-800',
    error: 'bg-red-100 text-red-800',
    draft: 'bg-gray-100 text-gray-800'
  }
  return classes[props.agent.status] || 'bg-gray-100 text-gray-800'
})

const displayedCapabilities = computed(() => {
  return (props.agent.capabilities || []).slice(0, 3)
})

const extraCapabilities = computed(() => {
  return Math.max(0, (props.agent.capabilities || []).length - 3)
})

function formatNumber(num) {
  if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M'
  if (num >= 1000) return (num / 1000).toFixed(1) + 'K'
  return num.toString()
}
</script>

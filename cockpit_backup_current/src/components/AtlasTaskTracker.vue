<template>
  <div class="space-y-3">
    <!-- Overall progress bar -->
    <div>
      <div class="flex items-center justify-between mb-1">
        <span class="text-xs font-medium text-gray-700">Plan Progress</span>
        <span class="text-xs text-gray-500">{{ planProgress }}%</span>
      </div>
      <div class="w-full bg-gray-200 rounded-full h-2">
        <div
          class="h-2 rounded-full transition-all duration-500"
          :class="progressBarClass"
          :style="{ width: planProgress + '%' }"
        />
      </div>
      <div class="flex items-center justify-between mt-1 text-xs text-gray-400">
        <span>{{ completedCount }}/{{ tasks.length }} tasks</span>
        <span v-if="failedCount > 0" class="text-red-500">{{ failedCount }} failed</span>
      </div>
    </div>

    <!-- Task list -->
    <div class="space-y-1">
      <div
        v-for="task in tasks"
        :key="task.id"
        class="rounded-lg border transition-colors cursor-pointer"
        :class="taskBorderClass(task)"
        @click="toggleExpand(task.id)"
      >
        <!-- Task summary row -->
        <div class="flex items-center space-x-3 px-3 py-2">
          <!-- Status icon -->
          <div class="flex-shrink-0">
            <!-- Pending -->
            <div v-if="task.status === 'pending'" class="w-5 h-5 rounded-full border-2 border-gray-300" />
            <!-- Running -->
            <div v-else-if="task.status === 'running'" class="w-5 h-5 rounded-full border-2 border-blue-500 flex items-center justify-center animate-pulse">
              <div class="w-2 h-2 bg-blue-500 rounded-full" />
            </div>
            <!-- Completed -->
            <div v-else-if="task.status === 'completed'" class="w-5 h-5 rounded-full bg-green-500 flex items-center justify-center">
              <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
              </svg>
            </div>
            <!-- Failed -->
            <div v-else-if="task.status === 'failed'" class="w-5 h-5 rounded-full bg-red-500 flex items-center justify-center">
              <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </div>
          </div>

          <!-- Task label -->
          <div class="flex-1 min-w-0">
            <p class="text-sm text-gray-900 truncate">{{ task.label }}</p>
          </div>

          <!-- Agent badge -->
          <span
            v-if="task.agent"
            class="flex-shrink-0 px-2 py-0.5 text-xs font-medium rounded-full bg-gray-100 text-gray-600"
          >
            {{ task.agent }}
          </span>

          <!-- Duration -->
          <span v-if="task.duration" class="flex-shrink-0 text-xs text-gray-400">
            {{ formatDuration(task.duration) }}
          </span>

          <!-- Expand indicator -->
          <svg
            class="w-4 h-4 text-gray-400 transition-transform flex-shrink-0"
            :class="{ 'rotate-180': expandedTasks.has(task.id) }"
            fill="none" stroke="currentColor" viewBox="0 0 24 24"
          >
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
          </svg>
        </div>

        <!-- Expanded details -->
        <div v-if="expandedTasks.has(task.id)" class="px-3 pb-3 pt-1 border-t border-gray-100">
          <div class="space-y-2 text-xs">
            <div v-if="task.description" class="text-gray-600">
              {{ task.description }}
            </div>
            <div class="grid grid-cols-2 gap-2">
              <div>
                <span class="text-gray-400">Status:</span>
                <span
                  class="ml-1 font-medium"
                  :class="{
                    'text-gray-500': task.status === 'pending',
                    'text-blue-600': task.status === 'running',
                    'text-green-600': task.status === 'completed',
                    'text-red-600': task.status === 'failed'
                  }"
                >
                  {{ task.status }}
                </span>
              </div>
              <div v-if="task.agent">
                <span class="text-gray-400">Agent:</span>
                <span class="ml-1 text-gray-700">{{ task.agent }}</span>
              </div>
              <div v-if="task.started_at">
                <span class="text-gray-400">Started:</span>
                <span class="ml-1 text-gray-700">{{ formatTime(task.started_at) }}</span>
              </div>
              <div v-if="task.completed_at">
                <span class="text-gray-400">Finished:</span>
                <span class="ml-1 text-gray-700">{{ formatTime(task.completed_at) }}</span>
              </div>
            </div>
            <div v-if="task.error" class="bg-red-50 text-red-700 rounded p-2">
              {{ task.error }}
            </div>
            <div v-if="task.output" class="bg-gray-50 rounded p-2 font-mono text-gray-700 whitespace-pre-wrap">
              {{ task.output }}
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, reactive } from 'vue'

const props = defineProps({
  tasks: {
    type: Array,
    required: true
  },
  planProgress: {
    type: Number,
    default: 0
  }
})

const expandedTasks = reactive(new Set())

const completedCount = computed(() => props.tasks.filter(t => t.status === 'completed').length)
const failedCount = computed(() => props.tasks.filter(t => t.status === 'failed').length)

const progressBarClass = computed(() => {
  if (failedCount.value > 0) return 'bg-red-500'
  if (props.planProgress >= 100) return 'bg-green-500'
  return 'bg-indigo-600'
})

function taskBorderClass(task) {
  switch (task.status) {
    case 'running': return 'border-blue-200 bg-blue-50'
    case 'completed': return 'border-green-200 bg-white'
    case 'failed': return 'border-red-200 bg-red-50'
    default: return 'border-gray-200 bg-white'
  }
}

function toggleExpand(taskId) {
  if (expandedTasks.has(taskId)) {
    expandedTasks.delete(taskId)
  } else {
    expandedTasks.add(taskId)
  }
}

function formatDuration(ms) {
  if (!ms) return ''
  if (ms < 1000) return `${ms}ms`
  const seconds = Math.floor(ms / 1000)
  if (seconds < 60) return `${seconds}s`
  const minutes = Math.floor(seconds / 60)
  const remainingSeconds = seconds % 60
  return `${minutes}m ${remainingSeconds}s`
}

function formatTime(timestamp) {
  if (!timestamp) return ''
  return new Date(timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}
</script>

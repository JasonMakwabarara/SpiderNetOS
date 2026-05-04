<template>
  <div class="flex flex-col h-full overflow-y-auto">
    <!-- Panel Header -->
    <div class="px-4 py-3 border-b border-gray-200 bg-white">
      <h3 class="font-semibold text-gray-900 text-sm">Context</h3>
    </div>

    <div class="flex-1 overflow-y-auto p-4 space-y-4">
      <!-- Command AST Section -->
      <section class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <button
          @click="showAst = !showAst"
          class="w-full flex items-center justify-between px-4 py-3 text-left hover:bg-gray-50 transition-colors"
        >
          <div class="flex items-center space-x-2">
            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
            </svg>
            <span class="text-sm font-medium text-gray-900">Command AST</span>
          </div>
          <svg
            class="w-4 h-4 text-gray-400 transition-transform"
            :class="{ 'rotate-180': showAst }"
            fill="none" stroke="currentColor" viewBox="0 0 24 24"
          >
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
          </svg>
        </button>
        <div v-if="showAst" class="px-4 pb-4">
          <div v-if="ast" class="bg-gray-50 rounded-lg p-3 overflow-x-auto">
            <pre class="text-xs font-mono text-gray-700 whitespace-pre-wrap">{{ formatJson(ast) }}</pre>
          </div>
          <p v-else class="text-xs text-gray-400 italic">No command parsed yet</p>
        </div>
      </section>

      <!-- Task Tracker Section -->
      <section class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <button
          @click="showTasks = !showTasks"
          class="w-full flex items-center justify-between px-4 py-3 text-left hover:bg-gray-50 transition-colors"
        >
          <div class="flex items-center space-x-2">
            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
            </svg>
            <span class="text-sm font-medium text-gray-900">Task Tracker</span>
            <span
              v-if="tasks.length > 0"
              class="px-1.5 py-0.5 text-xs font-medium rounded-full bg-indigo-100 text-indigo-700"
            >
              {{ tasks.length }}
            </span>
          </div>
          <svg
            class="w-4 h-4 text-gray-400 transition-transform"
            :class="{ 'rotate-180': showTasks }"
            fill="none" stroke="currentColor" viewBox="0 0 24 24"
          >
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
          </svg>
        </button>
        <div v-if="showTasks" class="px-4 pb-4">
          <AtlasTaskTracker
            v-if="tasks.length > 0"
            :tasks="tasks"
            :plan-progress="planProgress"
          />
          <p v-else class="text-xs text-gray-400 italic">No tasks in progress</p>
        </div>
      </section>

      <!-- Agent Status Section -->
      <section class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <button
          @click="showAgent = !showAgent"
          class="w-full flex items-center justify-between px-4 py-3 text-left hover:bg-gray-50 transition-colors"
        >
          <div class="flex items-center space-x-2">
            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
            </svg>
            <span class="text-sm font-medium text-gray-900">Agent Status</span>
          </div>
          <svg
            class="w-4 h-4 text-gray-400 transition-transform"
            :class="{ 'rotate-180': showAgent }"
            fill="none" stroke="currentColor" viewBox="0 0 24 24"
          >
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
          </svg>
        </button>
        <div v-if="showAgent" class="px-4 pb-4">
          <div v-if="activeAgent" class="space-y-3">
            <div class="flex items-center space-x-3">
              <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center">
                <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
              </div>
              <div>
                <p class="text-sm font-medium text-gray-900">{{ activeAgent.name }}</p>
                <p class="text-xs text-gray-500">Active</p>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-2">
              <div class="bg-gray-50 rounded-lg p-2">
                <p class="text-xs text-gray-500">Model</p>
                <p class="text-sm font-medium text-gray-900">{{ activeAgent.model || 'Default' }}</p>
              </div>
              <div class="bg-gray-50 rounded-lg p-2">
                <p class="text-xs text-gray-500">Session Cost</p>
                <p class="text-sm font-medium text-gray-900">${{ (totalCost || 0).toFixed(4) }}</p>
              </div>
            </div>
          </div>
          <p v-else class="text-xs text-gray-400 italic">No agent currently active</p>
        </div>
      </section>

      <!-- Suggestions Section -->
      <section class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <button
          @click="showSuggestions = !showSuggestions"
          class="w-full flex items-center justify-between px-4 py-3 text-left hover:bg-gray-50 transition-colors"
        >
          <div class="flex items-center space-x-2">
            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
            </svg>
            <span class="text-sm font-medium text-gray-900">Suggestions</span>
            <span
              v-if="suggestions.length > 0"
              class="px-1.5 py-0.5 text-xs font-medium rounded-full bg-yellow-100 text-yellow-700"
            >
              {{ suggestions.length }}
            </span>
          </div>
          <svg
            class="w-4 h-4 text-gray-400 transition-transform"
            :class="{ 'rotate-180': showSuggestions }"
            fill="none" stroke="currentColor" viewBox="0 0 24 24"
          >
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
          </svg>
        </button>
        <div v-if="showSuggestions" class="px-4 pb-4">
          <div v-if="suggestions.length > 0" class="space-y-2">
            <div
              v-for="(suggestion, index) in suggestions"
              :key="index"
              class="bg-gray-50 rounded-lg p-3 border border-gray-100"
            >
              <p class="text-sm text-gray-900 mb-2">{{ suggestion.text || suggestion.command || suggestion }}</p>
              <div class="flex items-center space-x-2">
                <button
                  @click="$emit('execute-suggestion', suggestion)"
                  class="px-3 py-1 text-xs font-medium bg-indigo-600 text-white rounded hover:bg-indigo-700 transition-colors"
                >
                  Execute
                </button>
                <button
                  @click="$emit('dismiss-suggestion', index)"
                  class="px-3 py-1 text-xs font-medium text-gray-600 bg-white border border-gray-300 rounded hover:bg-gray-50 transition-colors"
                >
                  Dismiss
                </button>
              </div>
            </div>
          </div>
          <p v-else class="text-xs text-gray-400 italic">No suggestions available</p>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import AtlasTaskTracker from './AtlasTaskTracker.vue'

defineProps({
  ast: {
    type: Object,
    default: null
  },
  tasks: {
    type: Array,
    default: () => []
  },
  activeAgent: {
    type: Object,
    default: null
  },
  suggestions: {
    type: Array,
    default: () => []
  },
  planProgress: {
    type: Number,
    default: 0
  },
  totalCost: {
    type: Number,
    default: 0
  }
})

defineEmits(['dismiss-suggestion', 'execute-suggestion'])

const showAst = ref(true)
const showTasks = ref(true)
const showAgent = ref(true)
const showSuggestions = ref(true)

function formatJson(obj) {
  try {
    return JSON.stringify(obj, null, 2)
  } catch {
    return String(obj)
  }
}
</script>

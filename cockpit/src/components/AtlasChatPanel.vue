<template>
  <div class="flex flex-col h-full bg-white">
    <!-- Header -->
    <div class="flex items-center justify-between px-6 py-3 border-b border-gray-200">
      <div class="flex items-center space-x-3">
        <div class="w-9 h-9 bg-indigo-600 rounded-full flex items-center justify-center text-white font-semibold">
          A
        </div>
        <div>
          <h2 class="font-semibold text-gray-900">Atlas</h2>
          <p class="text-xs text-gray-500">Natural Language Compiler</p>
        </div>
      </div>
      <div class="flex items-center space-x-2">
        <span v-if="isTyping" class="text-xs text-indigo-600 font-medium">Processing...</span>
      </div>
    </div>

    <!-- Messages Area -->
    <div ref="messagesContainer" class="flex-1 overflow-y-auto px-6 py-4 space-y-4">
      <!-- Empty state -->
      <div v-if="messages.length === 0" class="flex flex-col items-center justify-center h-full text-center">
        <div class="w-20 h-20 bg-indigo-100 rounded-full flex items-center justify-center mb-4">
          <svg class="w-10 h-10 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
          </svg>
        </div>
        <h3 class="text-xl font-semibold text-gray-900 mb-2">What would you like to do?</h3>
        <p class="text-sm text-gray-500 mb-6 max-w-md">
          Tell Atlas what you need in plain English. I can create flows, manage agents,
          query data, deploy changes, and much more.
        </p>
        <div class="flex flex-wrap justify-center gap-2">
          <button
            v-for="chip in quickChips"
            :key="chip"
            @click="$emit('send', chip)"
            class="px-4 py-2 text-sm bg-white border border-gray-300 rounded-full hover:bg-gray-50 hover:border-indigo-300 transition-colors"
          >
            {{ chip }}
          </button>
        </div>
      </div>

      <!-- Message list -->
      <div v-for="message in messages" :key="message.id" class="flex" :class="message.role === 'user' ? 'justify-end' : 'justify-start'">
        <div class="max-w-[75%]">
          <!-- Role badge -->
          <div class="flex items-center space-x-2 mb-1" :class="message.role === 'user' ? 'justify-end' : 'justify-start'">
            <span
              class="px-2 py-0.5 text-xs font-medium rounded-full"
              :class="message.role === 'user' ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-700'"
            >
              {{ message.role === 'user' ? 'You' : 'Atlas' }}
            </span>
            <span class="text-xs text-gray-400">{{ formatTimestamp(message.timestamp) }}</span>
          </div>

          <!-- Message content -->
          <div
            class="rounded-lg px-4 py-3"
            :class="{
              'bg-indigo-600 text-white': message.role === 'user',
              'bg-gray-100 text-gray-900': message.role === 'assistant' && !message.isError,
              'bg-red-50 text-red-900 border border-red-200': message.isError
            }"
          >
            <p class="text-sm whitespace-pre-wrap leading-relaxed">{{ message.content }}</p>
          </div>

          <!-- Atlas metadata -->
          <div v-if="message.role === 'assistant' && message.metadata && !message.isError" class="mt-1.5 flex items-center space-x-3 text-xs text-gray-400">
            <span v-if="message.metadata.agent" class="flex items-center space-x-1">
              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
              </svg>
              <span>{{ message.metadata.agent }}</span>
            </span>
            <span v-if="message.metadata.intent" class="flex items-center space-x-1">
              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
              </svg>
              <span>{{ message.metadata.intent }}</span>
            </span>
            <span v-if="message.metadata.cost" class="flex items-center space-x-1">
              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <span>${{ message.metadata.cost.toFixed(4) }}</span>
            </span>
            <span v-if="message.metadata.execution_time">
              {{ message.metadata.execution_time }}ms
            </span>
          </div>

          <!-- Inline task progress -->
          <div v-if="message.role === 'assistant' && hasInlineTasks(message)" class="mt-2">
            <div class="bg-white border border-gray-200 rounded-lg p-3">
              <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-medium text-gray-700">Plan Execution</span>
                <span class="text-xs text-gray-500">{{ inlineTaskProgress }}%</span>
              </div>
              <div class="w-full bg-gray-200 rounded-full h-1.5 mb-2">
                <div
                  class="bg-indigo-600 h-1.5 rounded-full transition-all duration-300"
                  :style="{ width: inlineTaskProgress + '%' }"
                />
              </div>
              <div class="space-y-1">
                <div
                  v-for="task in tasks.slice(0, 4)"
                  :key="task.id"
                  class="flex items-center space-x-2 text-xs"
                >
                  <span
                    class="w-1.5 h-1.5 rounded-full flex-shrink-0"
                    :class="{
                      'bg-gray-300': task.status === 'pending',
                      'bg-blue-500 animate-pulse': task.status === 'running',
                      'bg-green-500': task.status === 'completed',
                      'bg-red-500': task.status === 'failed'
                    }"
                  />
                  <span class="text-gray-600 truncate">{{ task.label }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Typing indicator -->
      <div v-if="isTyping" class="flex justify-start">
        <div class="bg-gray-100 rounded-lg px-4 py-3">
          <div class="flex space-x-1.5">
            <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0ms" />
            <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 150ms" />
            <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 300ms" />
          </div>
        </div>
      </div>
    </div>

    <!-- Input Area -->
    <div class="border-t border-gray-200 bg-white px-6 py-4">
      <AtlasCommandInput
        :slash-commands="slashCommands"
        :suggestions="suggestions"
        :disabled="isTyping"
        @send="$emit('send', $event)"
        @command="$emit('command', $event)"
        @voice="$emit('voice')"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import AtlasCommandInput from './AtlasCommandInput.vue'

const props = defineProps({
  messages: {
    type: Array,
    default: () => []
  },
  isTyping: {
    type: Boolean,
    default: false
  },
  slashCommands: {
    type: Array,
    default: () => []
  },
  suggestions: {
    type: Array,
    default: () => []
  },
  tasks: {
    type: Array,
    default: () => []
  }
})

defineEmits(['send', 'command', 'execute-suggestion'])

const messagesContainer = ref(null)

const quickChips = [
  'Show system status',
  'Create a new flow',
  'List active agents',
  'Show today\'s usage',
  'Deploy latest changes',
  'Run anomaly check'
]

const inlineTaskProgress = computed(() => {
  if (props.tasks.length === 0) return 0
  const completed = props.tasks.filter(t => t.status === 'completed').length
  return Math.round((completed / props.tasks.length) * 100)
})

function hasInlineTasks(message) {
  return message.role === 'assistant' && props.tasks.length > 0 && message === props.messages[props.messages.length - 1]
}

function formatTimestamp(ts) {
  if (!ts) return ''
  const date = new Date(ts)
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

// Auto-scroll to bottom
watch(
  () => [props.messages.length, props.isTyping],
  () => {
    nextTick(() => {
      if (messagesContainer.value) {
        messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
      }
    })
  }
)
</script>

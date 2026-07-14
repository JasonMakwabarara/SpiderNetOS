<template>
  <div class="atlas-chat flex flex-col h-full bg-gray-50 rounded-lg border border-gray-200">
    <!-- Header -->
    <div class="flex items-center justify-between px-4 py-3 bg-white border-b border-gray-200 rounded-t-lg">
      <div class="flex items-center space-x-2">
        <div class="w-8 h-8 bg-indigo-600 rounded-full flex items-center justify-center text-white font-semibold">
          A
        </div>
        <div>
          <h3 class="font-semibold text-gray-900">Atlas</h3>
          <p class="text-xs text-gray-500">Natural Language Compiler</p>
        </div>
      </div>
      <div class="flex items-center space-x-2">
        <span 
          v-if="usageStore.isDegraded"
          class="px-2 py-1 text-xs bg-yellow-100 text-yellow-800 rounded-full"
        >
          Degraded Mode
        </span>
        <button
          @click="clearChat"
          class="p-1.5 text-gray-400 hover:text-gray-600 rounded"
          title="Clear conversation"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Messages -->
    <div ref="messagesContainer" class="flex-1 overflow-y-auto p-4 space-y-4">
      <div v-if="messages.length === 0" class="text-center py-8">
        <div class="w-16 h-16 bg-indigo-100 rounded-full flex items-center justify-center mx-auto mb-4">
          <svg class="w-8 h-8 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
          </svg>
        </div>
        <h4 class="text-lg font-medium text-gray-900 mb-2">How can I help you today?</h4>
        <p class="text-sm text-gray-500 mb-4">Try these quick actions:</p>
        <div class="flex flex-wrap justify-center gap-2">
          <button
            v-for="suggestion in quickSuggestions"
            :key="suggestion"
            @click="sendMessage(suggestion)"
            class="px-3 py-1.5 text-sm bg-white border border-gray-300 rounded-full hover:bg-gray-50"
          >
            {{ suggestion }}
          </button>
        </div>
      </div>

      <div
        v-for="message in messages"
        :key="message.id"
        class="flex"
        :class="message.role === 'user' ? 'justify-end' : 'justify-start'"
      >
        <!-- User messages: plain text -->
        <div
          v-if="message.role === 'user'"
          class="max-w-[80%] rounded-lg px-4 py-2 bg-indigo-600 text-white"
        >
          <p class="text-sm whitespace-pre-wrap">{{ message.content }}</p>
        </div>

        <!-- Atlas messages: structured cognitive contract (B1) -->
        <div
          v-else
          class="max-w-[85%] rounded-lg px-4 py-3 space-y-2"
          :class="message.isError
            ? 'bg-red-50 border border-red-200'
            : 'bg-white border border-gray-200'"
        >
          <!-- Future state (primary, outcome-first) -->
          <p v-if="message.contract?.future_state" class="text-sm font-semibold text-gray-900">
            {{ message.contract.future_state }}
          </p>

          <!-- Value (quantified benefit) -->
          <p v-if="message.contract?.value" class="text-sm text-emerald-700">
            {{ message.contract.value }}
          </p>

          <!-- Emotional shift (relief/clarity/control) -->
          <p v-if="message.contract?.emotional_shift" class="text-xs text-gray-600 italic">
            {{ message.contract.emotional_shift }}
          </p>

          <!-- Action summary (non-technical) -->
          <p v-if="message.contract?.action_summary" class="text-xs text-gray-500 pt-1 border-t border-gray-100">
            {{ message.contract.action_summary }}
          </p>

          <!-- Details (collapsed by default; expanding emits DETAILS_EXPANDED) -->
          <div v-if="message.contract?.details" class="pt-1">
            <button
              v-if="!message.detailsExpanded"
              type="button"
              class="text-xs text-indigo-600 hover:text-indigo-800 underline"
              @click="expandDetails(message)"
            >
              Show details
            </button>
            <pre
              v-else
              class="text-xs text-gray-700 bg-gray-50 p-2 rounded whitespace-pre-wrap"
            >{{ message.contract.details }}</pre>
          </div>

          <!-- Action row: accept / feedback -->
          <div v-if="!message.isError && message.interaction_id" class="flex items-center space-x-2 pt-1">
            <button
              type="button"
              class="text-xs px-2 py-0.5 rounded border"
              :class="message.actionAccepted
                ? 'bg-emerald-50 border-emerald-300 text-emerald-700'
                : 'border-gray-300 text-gray-600 hover:bg-gray-50'"
              :disabled="message.actionAccepted"
              @click="acceptAction(message)"
            >
              {{ message.actionAccepted ? 'Accepted' : 'Accept' }}
            </button>
            <button
              type="button"
              class="text-xs text-gray-400 hover:text-emerald-600"
              title="This was helpful"
              @click="rateMessage(message, 1)"
            >👍</button>
            <button
              type="button"
              class="text-xs text-gray-400 hover:text-rose-600"
              title="This was not helpful"
              @click="rateMessage(message, -1)"
            >👎</button>
          </div>

          <!-- Meta -->
          <p v-if="message.metadata?.agent_used || message.metadata?.style" class="text-[10px] text-gray-400">
            via {{ message.metadata.agent_used || 'atlas' }}
            <span v-if="message.metadata?.style"> · {{ message.metadata.style }}</span>
            <span v-if="message.metadata?.source"> · {{ message.metadata.source }}</span>
          </p>
        </div>
      </div>

      <!-- Typing indicator -->
      <div v-if="isTyping" class="flex justify-start">
        <div class="bg-white border border-gray-200 rounded-lg px-4 py-3">
          <div class="flex space-x-1">
            <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0ms" />
            <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 150ms" />
            <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 300ms" />
          </div>
        </div>
      </div>
    </div>

    <!-- Input -->
    <div class="p-4 bg-white border-t border-gray-200 rounded-b-lg">
      <form @submit.prevent="handleSubmit" class="flex space-x-2">
        <input
          v-model="inputMessage"
          type="text"
          placeholder="Ask Atlas to do something..."
          class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
          :disabled="isTyping || isEnhancing"
        />
        <button
          type="button"
          @click="handleEnhance"
          class="px-3 py-2 border border-indigo-200 text-indigo-700 bg-indigo-50 rounded-lg hover:bg-indigo-100 disabled:opacity-50 disabled:cursor-not-allowed text-sm font-medium"
          :disabled="!inputMessage.trim() || isTyping || isEnhancing"
          title="Enhance Prompt"
        >
          {{ isEnhancing ? 'Enhancing...' : 'Enhance' }}
        </button>
        <button
          type="submit"
          class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="!inputMessage.trim() || isTyping || isEnhancing"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
          </svg>
        </button>
      </form>
      <p class="mt-2 text-xs text-gray-500 text-center">
        Atlas understands natural language commands for flows, agents, and system queries.
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, nextTick } from 'vue'
import { useAtlasChat } from '../composables/useAtlasChat.js'
import { useUsageStore } from '../stores/usage.js'

const {
  messages,
  isTyping,
  sendMessage,
  enhancePrompt,
  expandDetails,
  acceptAction,
  rateMessage,
  clearChat,
} = useAtlasChat()
const usageStore = useUsageStore()

const inputMessage = ref('')
const messagesContainer = ref(null)
const isEnhancing = ref(false)

const quickSuggestions = [
  'Show system status',
  'Create a new flow',
  'List all agents',
  'What is my usage?'
]

async function handleSubmit() {
  if (!inputMessage.value.trim() || isTyping.value || isEnhancing.value) return
  
  await sendMessage(inputMessage.value)
  inputMessage.value = ''
}

async function handleEnhance() {
  if (!inputMessage.value.trim() || isTyping.value || isEnhancing.value) return

  isEnhancing.value = true
  try {
    const result = await enhancePrompt(inputMessage.value, 'balanced')
    if (result?.enhanced) {
      inputMessage.value = result.enhanced
    }
  } catch (error) {
    // no-op; keep original text on enhancement failure
  } finally {
    isEnhancing.value = false
  }
}

// Auto-scroll to bottom
watch(messages, () => {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
    }
  })
}, { deep: true })
</script>

<template>
  <div class="relative">
    <!-- Quick suggestion chips -->
    <div v-if="suggestions.length > 0" class="flex flex-wrap gap-2 mb-3">
      <button
        v-for="(suggestion, index) in suggestions.slice(0, 4)"
        :key="index"
        @click="handleSuggestionClick(suggestion)"
        class="px-3 py-1.5 text-xs font-medium bg-gray-100 text-gray-700 rounded-full hover:bg-gray-200 transition-colors truncate max-w-[200px]"
      >
        {{ typeof suggestion === 'string' ? suggestion : (suggestion.text || suggestion.command) }}
      </button>
    </div>

    <!-- Slash command dropdown -->
    <div
      v-if="showSlashDropdown && filteredCommands.length > 0"
      class="absolute bottom-full left-0 right-0 mb-2 bg-white border border-gray-200 rounded-lg shadow-lg max-h-60 overflow-y-auto z-20"
    >
      <div
        v-for="(cmd, index) in filteredCommands"
        :key="cmd.command"
        @click="selectSlashCommand(cmd)"
        @mouseenter="highlightedIndex = index"
        class="flex items-center space-x-3 px-4 py-2.5 cursor-pointer transition-colors"
        :class="highlightedIndex === index ? 'bg-indigo-50 text-indigo-700' : 'text-gray-700 hover:bg-gray-50'"
      >
        <span class="font-mono text-sm font-medium">{{ cmd.command }}</span>
        <span class="text-xs text-gray-500">{{ cmd.description }}</span>
      </div>
    </div>

    <!-- Input area -->
    <div class="flex items-end space-x-3">
      <div class="flex-1 relative">
        <textarea
          ref="textareaRef"
          v-model="inputText"
          @input="handleInput"
          @keydown="handleKeydown"
          :disabled="disabled"
          :placeholder="disabled ? 'Waiting for response...' : 'Tell Atlas what to do... (/ for commands)'"
          class="w-full px-4 py-2.5 border border-gray-300 rounded-lg resize-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm leading-relaxed disabled:bg-gray-100 disabled:cursor-not-allowed"
          :rows="textareaRows"
          style="max-height: 160px"
        />
      </div>

      <!-- Enhance prompt button (Atlas) -->
      <EnhancePromptButton
        v-model="inputText"
        :disabled="disabled || !inputText.trim()"
        surface="atlas_chat"
        applyMode="replace"
        variant="ghost"
      />

      <!-- Send button -->
      <button
        @click="handleSend"
        :disabled="!canSend"
        class="flex-shrink-0 p-2.5 rounded-lg transition-colors"
        :class="canSend ? 'bg-indigo-600 text-white hover:bg-indigo-700' : 'bg-gray-200 text-gray-400 cursor-not-allowed'"
      >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
        </svg>
      </button>
    </div>

    <!-- Hint text -->
    <p class="mt-1.5 text-xs text-gray-400">
      Press <kbd class="px-1 py-0.5 bg-gray-100 border border-gray-200 rounded text-xs">Enter</kbd> to send,
      <kbd class="px-1 py-0.5 bg-gray-100 border border-gray-200 rounded text-xs">Shift+Enter</kbd> for newline
    </p>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import EnhancePromptButton from './prompt/EnhancePromptButton.vue'

const props = defineProps({
  slashCommands: {
    type: Array,
    default: () => []
  },
  suggestions: {
    type: Array,
    default: () => []
  },
  disabled: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['send', 'command'])

const inputText = ref('')
const textareaRef = ref(null)
const showSlashDropdown = ref(false)
const highlightedIndex = ref(0)
const slashFilter = ref('')

const canSend = computed(() => inputText.value.trim().length > 0 && !props.disabled)

const textareaRows = computed(() => {
  const lineCount = (inputText.value.match(/\n/g) || []).length + 1
  return Math.min(Math.max(lineCount, 1), 6)
})

const filteredCommands = computed(() => {
  if (!slashFilter.value) return props.slashCommands
  const filter = slashFilter.value.toLowerCase()
  return props.slashCommands.filter(cmd =>
    cmd.command.toLowerCase().includes(filter) ||
    cmd.description.toLowerCase().includes(filter)
  )
})

function handleInput() {
  const text = inputText.value
  // Check if user is typing a slash command
  if (text.startsWith('/')) {
    showSlashDropdown.value = true
    slashFilter.value = text
    highlightedIndex.value = 0
  } else {
    showSlashDropdown.value = false
    slashFilter.value = ''
  }
  autoResize()
}

function handleKeydown(event) {
  // Handle slash command navigation
  if (showSlashDropdown.value && filteredCommands.value.length > 0) {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      highlightedIndex.value = Math.min(highlightedIndex.value + 1, filteredCommands.value.length - 1)
      return
    }
    if (event.key === 'ArrowUp') {
      event.preventDefault()
      highlightedIndex.value = Math.max(highlightedIndex.value - 1, 0)
      return
    }
    if (event.key === 'Tab' || (event.key === 'Enter' && !event.shiftKey)) {
      event.preventDefault()
      selectSlashCommand(filteredCommands.value[highlightedIndex.value])
      return
    }
    if (event.key === 'Escape') {
      showSlashDropdown.value = false
      return
    }
  }

  // Enter to send (without shift)
  if (event.key === 'Enter' && !event.shiftKey) {
    event.preventDefault()
    handleSend()
  }
}

function handleSend() {
  const text = inputText.value.trim()
  if (!text || props.disabled) return

  // Check if it's a slash command
  if (text.startsWith('/')) {
    const matchedCmd = props.slashCommands.find(cmd => text.startsWith(cmd.command))
    if (matchedCmd) {
      emit('command', text)
    } else {
      emit('send', text)
    }
  } else {
    emit('send', text)
  }

  inputText.value = ''
  showSlashDropdown.value = false
  nextTick(() => autoResize())
}

function selectSlashCommand(cmd) {
  inputText.value = cmd.command + ' '
  showSlashDropdown.value = false
  nextTick(() => {
    textareaRef.value?.focus()
    autoResize()
  })
}

function handleSuggestionClick(suggestion) {
  const text = typeof suggestion === 'string' ? suggestion : (suggestion.command || suggestion.text)
  emit('send', text)
}

function autoResize() {
  nextTick(() => {
    if (textareaRef.value) {
      textareaRef.value.style.height = 'auto'
      textareaRef.value.style.height = Math.min(textareaRef.value.scrollHeight, 160) + 'px'
    }
  })
}

// Reset dropdown when slash commands change
watch(() => props.slashCommands, () => {
  highlightedIndex.value = 0
})
</script>

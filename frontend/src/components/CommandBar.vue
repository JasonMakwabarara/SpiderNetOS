<template>
  <Teleport to="body">
    <Transition name="commandbar-fade">
      <div
        v-if="visible"
        class="fixed inset-0 z-[9999] flex items-start justify-center pt-[15vh] bg-black/40 backdrop-blur-sm"
        @click.self="close"
        @keydown.escape="close"
      >
        <div
          ref="panelRef"
          class="w-full max-w-xl bg-white dark:bg-gray-900 rounded-xl shadow-2xl border border-gray-200 dark:border-gray-700 overflow-hidden"
          role="dialog"
          aria-label="Command bar"
        >
          <!-- Input -->
          <div class="flex items-center gap-2 px-4 py-3 border-b border-gray-200 dark:border-gray-700">
            <span class="text-gray-400 text-lg">/</span>
            <input
              ref="inputRef"
              v-model="query"
              type="text"
              placeholder="Type a command\u2026"
              class="flex-1 bg-transparent text-gray-900 dark:text-gray-100 text-base outline-none placeholder:text-gray-400"
              autocomplete="off"
              spellcheck="false"
              @input="onInput"
              @keydown.enter="executeSelected"
              @keydown.arrow-down.prevent="moveSelection(1)"
              @keydown.arrow-up.prevent="moveSelection(-1)"
            />
          </div>

          <!-- Autocomplete / recent list -->
          <ul
            v-if="displayItems.length > 0"
            class="max-h-72 overflow-y-auto py-1"
          >
            <li
              v-for="(item, idx) in displayItems"
              :key="item.id"
              class="flex items-center gap-3 px-4 py-2 cursor-pointer text-sm transition-colors"
              :class="idx === selectedIndex
                ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300'
                : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800'"
              @click="executeItem(item)"
              @mouseenter="selectedIndex = idx"
            >
              <span class="w-5 text-center text-base opacity-60">{{ item.icon || '/' }}</span>
              <span class="flex-1 truncate">{{ item.label }}</span>
              <span
                v-if="item.shortcut"
                class="text-xs text-gray-400 dark:text-gray-500 font-mono"
              >
                {{ item.shortcut }}
              </span>
            </li>
          </ul>

          <!-- Empty state -->
          <div
            v-else
            class="px-4 py-6 text-center text-sm text-gray-400"
          >
            No matching commands
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useCommandsStore } from '@/stores/commands'

// -------------------------------------------------------------------------- //
//  Emits
// -------------------------------------------------------------------------- //

const emit = defineEmits(['execute'])

// -------------------------------------------------------------------------- //
//  Store
// -------------------------------------------------------------------------- //

const commandsStore = useCommandsStore()

// -------------------------------------------------------------------------- //
//  State
// -------------------------------------------------------------------------- //

const visible = ref(false)
const query = ref('')
const selectedIndex = ref(0)
const inputRef = ref(null)
const panelRef = ref(null)

// -------------------------------------------------------------------------- //
//  Commands & filtering
// -------------------------------------------------------------------------- //

const filteredCommands = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return []

  return commandsStore.commands.filter((cmd) => {
    const haystack = `${cmd.label} ${cmd.id} ${cmd.description || ''}`.toLowerCase()
    return haystack.includes(q)
  })
})

const recentCommands = computed(() => {
  return commandsStore.recentCommands.slice(0, 8)
})

/** Show filtered results when the user is typing, otherwise show recents. */
const displayItems = computed(() => {
  return query.value.trim().length > 0 ? filteredCommands.value : recentCommands.value
})

// -------------------------------------------------------------------------- //
//  Actions
// -------------------------------------------------------------------------- //

function open() {
  visible.value = true
  query.value = ''
  selectedIndex.value = 0
  nextTick(() => inputRef.value?.focus())
}

function close() {
  visible.value = false
  query.value = ''
}

function onInput() {
  selectedIndex.value = 0
}

function moveSelection(delta) {
  const len = displayItems.value.length
  if (len === 0) return
  selectedIndex.value = (selectedIndex.value + delta + len) % len
}

function executeSelected() {
  const item = displayItems.value[selectedIndex.value]
  if (item) executeItem(item)
}

function executeItem(item) {
  commandsStore.recordRecent(item)
  emit('execute', item)
  close()
}

// -------------------------------------------------------------------------- //
//  Global keyboard listener: `/` to open (outside inputs)
// -------------------------------------------------------------------------- //

function onGlobalKeydown(event) {
  // Ignore when focus is inside an input, textarea, or contenteditable.
  const tag = event.target?.tagName?.toLowerCase()
  const isEditable =
    tag === 'input' ||
    tag === 'textarea' ||
    event.target?.isContentEditable

  if (event.key === '/' && !isEditable && !visible.value) {
    event.preventDefault()
    open()
  }
}

onMounted(() => {
  document.addEventListener('keydown', onGlobalKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onGlobalKeydown)
})

// Reset selection when items change.
watch(displayItems, () => {
  selectedIndex.value = 0
})

// -------------------------------------------------------------------------- //
//  Expose for parent components
// -------------------------------------------------------------------------- //

defineExpose({ open, close })
</script>

<style scoped>
.commandbar-fade-enter-active,
.commandbar-fade-leave-active {
  transition: opacity 0.15s ease;
}
.commandbar-fade-enter-from,
.commandbar-fade-leave-to {
  opacity: 0;
}
</style>

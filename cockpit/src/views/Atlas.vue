<template>
  <div class="atlas flex h-full">
    <!-- Left Panel: Chat (2/3) -->
    <div class="w-2/3 flex flex-col border-r border-gray-200">
      <AtlasChatPanel
        :messages="atlasStore.messages"
        :is-typing="atlasStore.isTyping"
        :slash-commands="atlasStore.slashCommands"
        :suggestions="atlasStore.suggestions"
        :tasks="atlasStore.tasks"
        @send="handleSend"
        @command="handleCommand"
        @execute-suggestion="handleExecuteSuggestion"
      />
    </div>

    <!-- Right Panel: Context (1/3) -->
    <div class="flex w-1/3 min-h-0 flex-col overflow-hidden bg-gray-50">
      <HannahGuidancePanel
        :force-onboarding-seed="showHannahBootstrap"
        @run-command="handleSend"
      />
      <AtlasContextPanel
        class="min-h-0 flex-1"
        :ast="atlasStore.commandAst"
        :tasks="atlasStore.tasks"
        :active-agent="atlasStore.activeAgent"
        :suggestions="atlasStore.suggestions"
        :plan-progress="atlasStore.planProgress"
        :total-cost="atlasStore.totalCost"
        @dismiss-suggestion="handleDismissSuggestion"
        @execute-suggestion="handleExecuteSuggestion"
      />
    </div>

    <!-- Loading overlay for session init -->
    <div
      v-if="atlasStore.isLoading"
      class="absolute inset-0 bg-white bg-opacity-75 flex items-center justify-center z-10"
    >
      <div class="text-center">
        <div class="animate-spin w-8 h-8 border-4 border-indigo-600 border-t-transparent rounded-full mx-auto" />
        <p class="mt-3 text-sm text-gray-500">Initializing Atlas session...</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useAtlasStore } from '../stores/atlas.js'
import AtlasChatPanel from '../components/AtlasChatPanel.vue'
import AtlasContextPanel from '../components/AtlasContextPanel.vue'
import HannahGuidancePanel from '../components/HannahGuidancePanel.vue'

const route = useRoute()
const atlasStore = useAtlasStore()
const showHannahBootstrap = computed(
  () => route.query.seed === 'onboarding' || route.query.hannah === '1'
)

onMounted(async () => {
  const sessionParam = route.query.session
  if (sessionParam) {
    await atlasStore.loadSession(sessionParam)
  } else if (!atlasStore.currentSessionId) {
    await atlasStore.initSession()
  }

  const key = atlasStore.currentSessionId
    ? `atlas.seed.done.${atlasStore.currentSessionId}`
    : null

  if (showHannahBootstrap.value && key && typeof sessionStorage !== 'undefined') {
    if (!sessionStorage.getItem(key)) {
      sessionStorage.setItem(key, '1')
      await atlasStore.sendMessage('Help me get started after onboarding.')
    }
  }
})

function handleSend(text) {
  atlasStore.sendMessage(text)
}

function handleCommand(command) {
  atlasStore.executeSlashCommand(command)
}

function handleExecuteSuggestion(suggestion) {
  atlasStore.executeSuggestion(suggestion)
}

function handleDismissSuggestion(index) {
  atlasStore.dismissSuggestion(index)
}
</script>

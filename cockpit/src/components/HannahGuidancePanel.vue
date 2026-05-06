<template>
  <div
    v-if="visible"
    class="border-b border-indigo-100 bg-gradient-to-br from-indigo-50 to-white p-4"
  >
    <div class="flex items-start justify-between gap-3">
      <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">Hannah</p>
        <h2 class="text-sm font-semibold text-gray-900">Suggested next steps</h2>
        <p class="mt-1 text-xs text-gray-600">
          One tap sends a concrete request to Atlas. You can dismiss this panel anytime.
        </p>
      </div>
      <button
        type="button"
        class="shrink-0 rounded-lg px-2 py-1 text-xs text-gray-500 hover:bg-white"
        aria-label="Dismiss Hannah suggestions"
        @click="dismiss"
      >
        Dismiss
      </button>
    </div>
    <ul class="mt-3 space-y-2">
      <li v-for="(step, idx) in steps" :key="idx">
        <button
          type="button"
          class="w-full rounded-lg border border-indigo-200 bg-white px-3 py-2 text-left text-sm font-medium text-indigo-800 shadow-sm hover:border-indigo-400 hover:bg-indigo-50"
          :disabled="creatingFlow && step.action === 'create-flow'"
          @click="run(step.prompt, step)"
        >
          {{ step.label }}
          <span v-if="creatingFlow && step.action === 'create-flow'" class="ml-2 animate-pulse">…</span>
        </button>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000'

const props = defineProps({
  forceOnboardingSeed: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['run-command', 'flow-created'])

const route = useRoute()
const dismissedLocal = ref(false)
const creatingFlow = ref(false)

const STORAGE_KEY = 'cockpit:hannah-guidance-dismissed'

const steps = [
  {
    label: 'Draft my first automation flow',
    prompt: 'Help me design and draft my first SpiderNet automation flow for my team.',
    action: 'create-flow',
    flowName: 'Quick Start Flow',
    flowSlug: 'quick-start-flow',
    flowDescription: 'Automated flow created from Hannah guidance.',
  },
  {
    label: 'Tune automation level safely',
    prompt: 'Explain how manual, assisted, and autonomous modes differ and recommend a starting level.',
  },
  {
    label: 'See observability basics',
    prompt: 'Walk me through what I should monitor this week after onboarding.',
  },
]

watch(
  () => route.fullPath,
  () => {
    dismissedLocal.value = false
  }
)

const visible = computed(() => {
  if (dismissedLocal.value) return false
  if (typeof window !== 'undefined' && window.localStorage.getItem(STORAGE_KEY) === '1') {
    return false
  }
  return !!(props.forceOnboardingSeed || route.query.seed === 'onboarding')
})

async function createFlow(step) {
  if (creatingFlow.value) return
  creatingFlow.value = true

  try {
    const response = await axios.post(`${API_URL}/api/flows`, {
      name: step.flowName,
      slug: `${step.flowSlug}-${Date.now()}`,
      description: step.flowDescription,
      dag: {
        nodes: [
          { id: 'trigger', type: 'trigger', config: { event: 'manual' } },
          { id: 'action', type: 'action', config: { action: 'notify', message: 'Flow started!' } },
        ],
        edges: [{ from: 'trigger', to: 'action' }],
      },
      triggers: ['manual'],
    })

    const flowId = response.data.id
    await axios.post(`${API_URL}/api/flows/${flowId}/publish`)
    emit('flow-created', { id: flowId, name: step.flowName })
    emit('run-command', `Flow "${step.flowName}" created and published successfully. What should it do next?`)
  } catch (err) {
    emit('run-command', `Failed to create flow: ${err.response?.data?.error || err.message}. Can you help me fix this?`)
  } finally {
    creatingFlow.value = false
  }
}

function run(prompt, step) {
  if (step?.action === 'create-flow') {
    createFlow(step)
  } else {
    emit('run-command', prompt)
  }
}

function dismiss() {
  dismissedLocal.value = true
  if (typeof window !== 'undefined') {
    window.localStorage.setItem(STORAGE_KEY, '1')
  }
}
</script>

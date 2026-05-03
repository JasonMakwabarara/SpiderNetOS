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
          @click="run(step.prompt)"
        >
          {{ step.label }}
        </button>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'

const props = defineProps({
  forceOnboardingSeed: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['run-command'])

const route = useRoute()
const dismissedLocal = ref(false)

const STORAGE_KEY = 'cockpit:hannah-guidance-dismissed'

const steps = [
  {
    label: 'Draft my first automation flow',
    prompt: 'Help me design and draft my first SpiderNet automation flow for my team.',
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

function run(prompt) {
  emit('run-command', prompt)
}

function dismiss() {
  dismissedLocal.value = true
  if (typeof window !== 'undefined') {
    window.localStorage.setItem(STORAGE_KEY, '1')
  }
}
</script>

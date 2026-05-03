<template>
  <div class="space-y-4">
    <div class="bg-indigo-50 rounded-lg p-4 mb-4">
      <p class="text-sm text-indigo-800">
        Here's how to track what Atlas is doing and maintain control over your automations.
      </p>
    </div>

    <div class="space-y-4">
      <div
        v-for="(slide, index) in slides"
        :key="index"
        class="p-4 border border-gray-200 rounded-lg"
        :class="{ 'bg-indigo-50 border-indigo-200': currentSlide === index }"
      >
        <div class="flex items-start gap-3">
          <div
            class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold"
            :class="currentSlide === index ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600'"
          >
            {{ index + 1 }}
          </div>
          <div class="flex-1">
            <h3 class="font-medium text-gray-900">{{ slide.title }}</h3>
            <p class="text-sm text-gray-600 mt-1">{{ slide.description }}</p>
            <div v-if="slide.feature" class="mt-2 inline-flex items-center gap-1 text-xs text-indigo-600 bg-indigo-50 px-2 py-1 rounded">
              <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                <path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/>
                <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/>
              </svg>
              {{ slide.feature }}
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="flex items-center justify-center gap-2 pt-2">
      <button
        v-for="(_, index) in slides"
        :key="index"
        class="w-2 h-2 rounded-full transition-colors"
        :class="currentSlide === index ? 'bg-indigo-600' : 'bg-gray-300'"
        @click="currentSlide = index"
      />
    </div>

    <div class="pt-4 border-t border-gray-100 text-center">
      <p class="text-sm text-gray-600">
        You're ready to start automating with Atlas!
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'

const props = defineProps({
  onObserved: { type: Function, default: null },
})

const currentSlide = ref(0)

const slides = [
  {
    title: 'Traces & Observability',
    description: 'Every action Atlas takes is logged with full context. Replay any execution to see exactly what happened, when, and why.',
    feature: 'Available in /traces',
  },
  {
    title: 'Approvals & Human-in-the-Loop',
    description: 'Sensitive actions pause for your approval. Review the context, modify if needed, then approve or reject with a click.',
    feature: 'Available in /approvals',
  },
  {
    title: 'Budget & Cost Controls',
    description: 'Set monthly limits and get alerts as you approach them. Atlas automatically scales down before hitting your cap.',
    feature: 'Available in /usage',
  },
  {
    title: 'Audit Log',
    description: 'Complete record of who did what, when. Required for compliance and invaluable for debugging.',
    feature: 'Available in Admin → Audit',
  },
]

// Auto-advance slides
let slideInterval
onMounted(() => {
  // Report observation for analytics
  if (props.onObserved) {
    props.onObserved('observability')
  }

  // Auto-advance every 5 seconds
  slideInterval = setInterval(() => {
    currentSlide.value = (currentSlide.value + 1) % slides.length
  }, 5000)
})

// Cleanup
import { onUnmounted } from 'vue'
onUnmounted(() => {
  if (slideInterval) {
    clearInterval(slideInterval)
  }
})
</script>

<template>
  <div class="min-h-[80vh] flex items-center justify-center p-6">
    <div class="w-full max-w-2xl bg-white rounded-xl shadow-lg p-8">
      <!-- Header -->
      <div class="mb-6">
        <p class="text-xs uppercase text-indigo-600 font-semibold mb-1">
          Step {{ currentStepIndex + 1 }} of {{ totalSteps }}
        </p>
        <h1 class="text-2xl font-bold">{{ currentStepMeta.title }}</h1>
        <p class="text-sm text-gray-600 mt-1">{{ currentStepMeta.description }}</p>
      </div>

      <!-- Progress -->
      <div class="h-2 bg-gray-100 rounded-full overflow-hidden mb-8">
        <div
          class="h-full bg-indigo-500 transition-all duration-300"
          :style="{ width: `${progress}%` }"
        />
      </div>

      <!-- Step Content -->
      <OnboardingStepShell
        :current-step="currentStepIndex"
        :is-last-step="isLastStep"
        :is-valid="isStepValid"
        :is-loading="isLoading"
        :error="error"
        @next="handleNext"
        @back="prevStep"
      >
        <StepTenant v-if="currentStepMeta.id === 'tenant'" v-model="stepData.tenant" />
        <StepBudget v-else-if="currentStepMeta.id === 'budget'" v-model="stepData.budget" />
        <StepInvites v-else-if="currentStepMeta.id === 'invites'" v-model="stepData.invites" />
        <StepStrictness v-else-if="currentStepMeta.id === 'strictness'" v-model="stepData.strictness" />
        <StepBranding v-else-if="currentStepMeta.id === 'branding'" v-model="stepData.branding" />
        <StepObservabilityTour v-else-if="currentStepMeta.id === 'observability'" :on-observed="handleObserve" />
      </OnboardingStepShell>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth.js'
import { useOnboarding } from '../composables/useOnboarding.js'

import OnboardingStepShell from '../components/onboarding/OnboardingStepShell.vue'
import StepTenant from '../components/onboarding/StepTenant.vue'
import StepBudget from '../components/onboarding/StepBudget.vue'
import StepInvites from '../components/onboarding/StepInvites.vue'
import StepStrictness from '../components/onboarding/StepStrictness.vue'
import StepBranding from '../components/onboarding/StepBranding.vue'
import StepObservabilityTour from '../components/onboarding/StepObservabilityTour.vue'

const router = useRouter()
const auth = useAuthStore()
const onboarding = useOnboarding()

// Step metadata
const stepMeta = [
  { id: 'tenant', title: 'Confirm company profile', description: 'Make sure your tenant details and region are right.' },
  { id: 'budget', title: 'Set your budget', description: 'Pick monthly limits. You can change this any time.' },
  { id: 'invites', title: 'Invite teammates', description: 'Paste emails to add collaborators. Skip if you prefer.' },
  { id: 'strictness', title: 'Pick automation level', description: 'Choose how much autonomy Atlas has. You can fine-tune later.' },
  { id: 'branding', title: 'Brand your workspace', description: 'Logo and primary color for your team.' },
  { id: 'observability', title: 'Tour audit & observability', description: 'A quick walk through the controls you now have.' },
]

// Computed from composable
const currentStepIndex = computed(() => onboarding.currentStep.value)
const currentStepMeta = computed(() => stepMeta[currentStepIndex.value] || stepMeta[0])
const isLastStep = computed(() => currentStepIndex.value === stepMeta.length - 1)
const isStepValid = computed(() => onboarding.isStepValid.value)
const isLoading = computed(() => onboarding.isLoading.value)
const error = computed(() => onboarding.error.value)
const progress = computed(() => onboarding.progress.value)
const totalSteps = computed(() => onboarding.totalSteps)

// Step data (reactive, synced to backend)
const stepData = reactive({
  tenant: {},
  budget: {},
  invites: {},
  strictness: {},
  branding: {},
})

// Load onboarding state on mount
onMounted(async () => {
  await onboarding.load()
  // Pre-populate from loaded state
  Object.assign(stepData, onboarding.onboarding.value || {})
})

// Navigation handlers
async function handleNext() {
  const stepId = currentStepMeta.value.id

  if (isLastStep.value) {
    // Complete onboarding
    try {
      await onboarding.complete()
      auth.markOnboardingComplete()
      router.push('/atlas?seed=onboarding')
    } catch (e) {
      // Error handled by composable
    }
  } else {
    // Save current step then advance
    if (stepData[stepId]) {
      try {
        await onboarding.saveStep(stepId, stepData[stepId])
        onboarding.nextStep()
      } catch (e) {
        // Error handled by composable
      }
    } else {
      onboarding.nextStep()
    }
  }
}

function prevStep() {
  onboarding.prevStep()
}

function handleObserve(step) {
  onboarding.observe(step)
}
</script>

<template>
  <div>
    <!-- Sensitive slot content is only rendered when step-up is fresh. -->
    <slot v-if="auth.stepUpValid()" :markStepUp="auth.markStepUp" />

    <div v-else class="rounded-lg border p-4 bg-amber-50 border-amber-300 text-amber-900" role="alert">
      <div class="flex items-start gap-2">
        <svg class="w-5 h-5 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M12 9v2m0 4h.01M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
        </svg>
        <div class="flex-1">
          <p class="font-semibold">Additional authentication required</p>
          <p class="text-sm mt-0.5">
            {{ reason || 'This action changes platform configuration. Verify it is you.' }}
          </p>

          <div class="mt-3 flex items-center gap-2">
            <input
              v-model="code"
              type="text"
              inputmode="numeric"
              autocomplete="one-time-code"
              maxlength="6"
              class="w-36 px-3 py-1.5 border border-amber-300 rounded font-mono text-lg tracking-widest"
              placeholder="123 456"
              @keyup.enter="verify"
              :aria-invalid="!!verifyError"
              aria-label="One-time verification code"
            />
            <button
              class="px-3 py-1.5 rounded bg-amber-600 hover:bg-amber-500 text-white font-medium disabled:opacity-50"
              :disabled="submitting || code.length < 4"
              @click="verify"
            >
              {{ submitting ? 'Verifying…' : 'Verify' }}
            </button>
          </div>

          <p v-if="verifyError" class="text-xs mt-2 text-red-700">{{ verifyError }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import axios from 'axios'
import { useAuthStore } from '../../stores/auth.js'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000'

const props = defineProps({
  reason: { type: String, default: '' },
})

const auth        = useAuthStore()
const code        = ref('')
const submitting  = ref(false)
const verifyError = ref('')

async function verify() {
  verifyError.value = ''
  submitting.value  = true
  try {
    await axios.post(`${API_URL}/api/auth/step-up`, { code: code.value })
    auth.markStepUp()
    code.value = ''
  } catch (err) {
    verifyError.value = err.response?.data?.message || 'Invalid code. Try again.'
  } finally {
    submitting.value = false
  }
}
</script>

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

          <div class="mt-3 space-y-2">
            <input
              v-model="password"
              type="password"
              autocomplete="current-password"
              class="w-full max-w-xs px-3 py-1.5 border border-amber-300 rounded"
              placeholder="Your password"
              @keyup.enter="verify"
              aria-label="Password"
            />
            <input
              v-if="mfaEnrolled"
              v-model="code"
              type="text"
              inputmode="numeric"
              autocomplete="one-time-code"
              maxlength="10"
              class="w-full max-w-xs px-3 py-1.5 border border-amber-300 rounded font-mono text-lg tracking-widest"
              placeholder="Authenticator code"
              @keyup.enter="verify"
              aria-label="Authenticator or recovery code"
            />
            <button
              class="px-3 py-1.5 rounded bg-amber-600 hover:bg-amber-500 text-white font-medium disabled:opacity-50"
              :disabled="submitting || !password"
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
import { ref, computed } from 'vue'
import api from '../../services/api.js'
import { useAuthStore } from '../../stores/auth.js'

const props = defineProps({
  reason: { type: String, default: '' },
})

const auth        = useAuthStore()
const password    = ref('')
const code        = ref('')
const submitting  = ref(false)
const verifyError = ref('')

const mfaEnrolled = computed(() => !!auth.user?.mfa_enrolled)

async function verify() {
  verifyError.value = ''
  submitting.value  = true
  try {
    await api.post('/api/auth/step-up', { password: password.value, mfa_code: code.value || undefined })
    auth.markStepUp()
    password.value = ''
    code.value = ''
  } catch (err) {
    const data = err.response?.data
    verifyError.value = data?.errors?.mfa_code?.[0] || data?.errors?.password?.[0] || data?.message || 'Verification failed. Try again.'
  } finally {
    submitting.value = false
  }
}
</script>

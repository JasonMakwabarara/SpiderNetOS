<template>
  <div class="dct-card p-6 space-y-4">
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Two-factor authentication</h2>
        <p class="text-sm mt-1" :style="{ color: 'var(--text-secondary)' }">
          Protects sensitive actions (plan changes, platform config, impersonation) with an authenticator app.
        </p>
      </div>
      <span class="text-xs px-2 py-1 rounded-full" :class="enrolled ? 'dct-pill-lime' : 'dct-pill-cyan'">
        {{ enrolled ? 'Enabled' : 'Not set up' }}
      </span>
    </div>

    <!-- Enrolled -->
    <div v-if="enrolled && !enrolling" class="flex gap-2">
      <button class="px-4 py-2 rounded-xl text-sm border"
        :style="{ borderColor: 'var(--border)', color: 'var(--text-secondary)', background: 'var(--surface-low)' }"
        @click="disable">Disable 2FA</button>
    </div>

    <!-- Not enrolled: start -->
    <div v-else-if="!enrolling">
      <button class="dct-btn-primary px-5 py-2.5 text-sm" @click="startEnroll" :disabled="busy">
        {{ busy ? 'Preparing…' : 'Set up 2FA' }}
      </button>
    </div>

    <!-- Enrolling: QR + confirm -->
    <div v-if="enrolling" class="space-y-4">
      <div class="flex flex-col md:flex-row gap-6 items-start">
        <img v-if="qrDataUrl" :src="qrDataUrl" alt="Scan with your authenticator app"
          class="w-44 h-44 rounded-lg border" :style="{ borderColor: 'var(--border)', background: '#fff' }" />
        <div class="space-y-2">
          <p class="text-sm" :style="{ color: 'var(--text-secondary)' }">
            Scan the QR with Google Authenticator, Authy, or 1Password — or enter this key manually:
          </p>
          <code class="text-xs px-2 py-1 rounded font-mono select-all" :style="{ background: 'var(--surface-low)', color: 'var(--text-primary)' }">{{ secret }}</code>
          <div class="flex items-center gap-2 pt-2">
            <input v-model="code" inputmode="numeric" maxlength="6"
              class="w-36 px-3 py-1.5 rounded font-mono text-lg tracking-widest border"
              :style="{ borderColor: 'var(--border)', background: 'var(--surface-low)', color: 'var(--text-primary)' }"
              placeholder="123 456" @keyup.enter="confirm" aria-label="Verification code" />
            <button class="dct-btn-primary px-4 py-1.5 text-sm" :disabled="busy || code.length < 6" @click="confirm">
              {{ busy ? 'Verifying…' : 'Confirm' }}
            </button>
            <button class="px-3 py-1.5 text-sm" :style="{ color: 'var(--text-muted)' }" @click="cancelEnroll">Cancel</button>
          </div>
          <p v-if="error" class="text-xs text-red-500">{{ error }}</p>
        </div>
      </div>
    </div>

    <!-- Recovery codes (shown once) -->
    <div v-if="recoveryCodes.length" class="rounded-xl p-4 space-y-2" :style="{ background: 'var(--surface-low)' }">
      <p class="text-sm font-semibold" :style="{ color: 'var(--text-primary)' }">Save your recovery codes</p>
      <p class="text-xs" :style="{ color: 'var(--text-muted)' }">
        Each can be used once if you lose your authenticator. They won't be shown again.
      </p>
      <div class="grid grid-cols-2 gap-1 font-mono text-sm" :style="{ color: 'var(--text-primary)' }">
        <span v-for="c in recoveryCodes" :key="c" class="select-all">{{ c }}</span>
      </div>
      <button class="text-xs underline" style="color: var(--accent);" @click="recoveryCodes = []">Done — I've saved them</button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import QRCode from 'qrcode'
import api from '../../services/api.js'
import { useAuthStore } from '../../stores/auth.js'

const auth = useAuthStore()
const enrolled = ref(!!auth.user?.mfa_enrolled)
const enrolling = ref(false)
const busy = ref(false)
const error = ref('')
const secret = ref('')
const qrDataUrl = ref('')
const code = ref('')
const recoveryCodes = ref([])

onMounted(async () => {
  // Refresh enrollment status from the server if the store is stale.
  try {
    const { data } = await api.get('/api/auth/me')
    enrolled.value = !!data.user?.mfa_enrolled
  } catch { /* keep store value */ }
})

async function startEnroll() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await api.post('/api/auth/mfa/enroll')
    secret.value = data.secret
    qrDataUrl.value = await QRCode.toDataURL(data.otpauth_uri, { width: 176, margin: 1 })
    enrolling.value = true
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not start setup.'
  } finally {
    busy.value = false
  }
}

async function confirm() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await api.post('/api/auth/mfa/confirm', { code: code.value })
    recoveryCodes.value = data.recovery_codes || []
    enrolled.value = true
    enrolling.value = false
    code.value = ''
    if (auth.user) auth.user.mfa_enrolled = true
  } catch (e) {
    error.value = e.response?.data?.errors?.code?.[0] || 'That code did not match. Try again.'
  } finally {
    busy.value = false
  }
}

function cancelEnroll() {
  enrolling.value = false
  secret.value = ''
  qrDataUrl.value = ''
  code.value = ''
  error.value = ''
}

async function disable() {
  const pw = window.prompt('Enter your password to disable 2FA:')
  if (!pw) return
  busy.value = true
  try {
    await api.post('/api/auth/mfa/disable', { password: pw })
    enrolled.value = false
    if (auth.user) auth.user.mfa_enrolled = false
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not disable 2FA.'
  } finally {
    busy.value = false
  }
}
</script>

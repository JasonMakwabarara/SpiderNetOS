<template>
  <div
    v-if="auth.isImpersonating"
    role="alert"
    aria-live="assertive"
    class="w-full px-4 py-2 flex items-center justify-between text-sm"
    style="background: #7f1d1d; color: #fee2e2; border-bottom: 2px solid #dc2626;"
  >
    <div class="flex items-center gap-2">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M12 15v2m0 4a2 2 0 01-2-2v-1a2 2 0 012-2h0a2 2 0 012 2v1a2 2 0 01-2 2zm6-10V7a6 6 0 00-12 0v4a2 2 0 002 2h8a2 2 0 002-2z" />
      </svg>
      <span>
        Impersonating <strong>{{ displayUser }}</strong>
        in tenant <strong>{{ displayTenant }}</strong>.
        Every action is audit-logged.
      </span>
      <span v-if="remainingMinutes !== null" class="ml-2 opacity-80">
        Expires in {{ remainingMinutes }}m
      </span>
    </div>
    <button
      class="px-3 py-1 rounded bg-red-600 hover:bg-red-500 text-white font-medium"
      @click="exit"
    >
      Exit impersonation
    </button>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useAuthStore } from '../../stores/auth.js'
import { useRouter } from 'vue-router'

const auth    = useAuthStore()
const router  = useRouter()

const displayUser   = computed(() => auth.impersonating?.user_id   || 'unknown user')
const displayTenant = computed(() => auth.impersonating?.tenant_id || 'unknown tenant')

const now = ref(Date.now())
let clock
onMounted(() => { clock = setInterval(() => { now.value = Date.now() }, 30_000) })
onUnmounted(() => clearInterval(clock))

const remainingMinutes = computed(() => {
  const exp = auth.impersonating?.expires_at
  if (!exp) return null
  const ms = new Date(exp).getTime() - now.value
  return ms > 0 ? Math.ceil(ms / 60_000) : 0
})

function exit() {
  auth.stopImpersonation()
  router.push('/platform')
}
</script>

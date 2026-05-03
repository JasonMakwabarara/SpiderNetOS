<template>
  <div class="min-h-screen flex items-center justify-center px-6 py-10 relative overflow-hidden">
    <!-- Ambient grid + glow -->
    <div class="absolute inset-0 sn-grid-bg opacity-40 pointer-events-none"></div>
    <div class="absolute -top-40 -left-40 w-[40rem] h-[40rem] rounded-full pointer-events-none"
         style="background: radial-gradient(circle, rgba(0,229,200,0.18), transparent 60%);"></div>
    <div class="absolute -bottom-40 -right-40 w-[34rem] h-[34rem] rounded-full pointer-events-none"
         style="background: radial-gradient(circle, rgba(0,229,200,0.10), transparent 60%);"></div>

    <div class="relative w-full max-w-[1020px] grid md:grid-cols-[1.1fr_1fr] gap-10 items-center">
      <!-- Left pitch column -->
      <div class="hidden md:block">
        <div class="flex items-center gap-2 mb-6">
          <div class="w-8 h-8 rounded-md flex items-center justify-center"
               style="background: linear-gradient(135deg,#00E5C8,#087D6E); box-shadow: 0 0 0 1px rgba(0,229,200,0.35);">
            <svg class="w-5 h-5" style="color:#05070A" viewBox="0 0 24 24" fill="currentColor">
              <path d="M12 2l2.4 4.9 5.4.8-3.9 3.8.9 5.4L12 14.3 7.2 17l.9-5.4-3.9-3.8 5.4-.8L12 2z"/>
            </svg>
          </div>
          <span class="font-semibold tracking-tight sn-grad-text text-lg">SpiderNetOS</span>
          <span class="sn-pill ml-2 text-[10px]">Cockpit v1.0</span>
        </div>

        <h1 class="font-heading font-semibold text-[44px] leading-[1.05] tracking-tight mb-4"
            style="color: var(--text-primary);">
          The operator console for your
          <span class="sn-grad-text">autonomous business.</span>
        </h1>
        <p class="text-[15px] leading-relaxed max-w-[44ch]" style="color: var(--text-secondary);">
          SpiderNetOS runs thousands of decisions a day — agents, flows, governance, approvals —
          and hands you a 5-minute weekly review. This is the flight deck.
        </p>

        <ul class="mt-8 space-y-2.5 text-sm" style="color: var(--text-secondary);">
          <li class="flex items-start gap-2.5">
            <span class="mt-1.5 w-1.5 h-1.5 rounded-full shrink-0" style="background: var(--accent);"></span>
            <span><b style="color: var(--text-primary);">Atlas</b> — one command surface across agents, flows, traces, memory.</span>
          </li>
          <li class="flex items-start gap-2.5">
            <span class="mt-1.5 w-1.5 h-1.5 rounded-full shrink-0" style="background: var(--accent);"></span>
            <span><b style="color: var(--text-primary);">Governance</b> — manual, assisted, autonomous. You own the control mode.</span>
          </li>
          <li class="flex items-start gap-2.5">
            <span class="mt-1.5 w-1.5 h-1.5 rounded-full shrink-0" style="background: var(--accent);"></span>
            <span><b style="color: var(--text-primary);">Traces & Approvals</b> — every decision auditable, every sensitive step confirmable.</span>
          </li>
        </ul>

        <div class="mt-10 text-xs font-mono" style="color: var(--text-muted);">
          <span class="sn-pill sn-pill-success"><span class="sn-dot-live w-1.5 h-1.5 rounded-full"></span>All systems operational</span>
          <span class="ml-3">build <span style="color: var(--text-secondary);">{{ buildId }}</span></span>
        </div>
      </div>

      <!-- Right auth card -->
      <div class="sn-panel p-7 sn-fade-in" data-testid="auth-card">
        <div class="flex mb-5 rounded-md overflow-hidden" style="background: var(--bg-elevated); border: 1px solid var(--border);">
          <button
            class="flex-1 py-2 text-sm font-medium transition-colors"
            :class="mode === 'login' ? '' : 'hover:text-white'"
            :style="mode === 'login' ? 'background: var(--accent-weak); color: var(--accent);' : 'color: var(--text-muted);'"
            data-testid="tab-signin"
            @click="mode = 'login'"
          >Sign in</button>
          <button
            class="flex-1 py-2 text-sm font-medium transition-colors"
            :class="mode === 'register' ? '' : 'hover:text-white'"
            :style="mode === 'register' ? 'background: var(--accent-weak); color: var(--accent);' : 'color: var(--text-muted);'"
            data-testid="tab-register"
            @click="mode = 'register'"
          >Create account</button>
        </div>

        <form v-if="mode === 'login'" class="space-y-3" @submit.prevent="handleLogin">
          <div>
            <label class="block mb-1">Email</label>
            <input v-model="loginForm.email" type="email" required autocomplete="email"
                   placeholder="operator@acme.ops" data-testid="login-email" />
          </div>
          <div>
            <label class="block mb-1">Password</label>
            <input v-model="loginForm.password" type="password" required autocomplete="current-password"
                   placeholder="••••••••" data-testid="login-password" />
          </div>

          <!-- Role selector (demo only) -->
          <div>
            <label class="block mb-1">Sign in as (demo)</label>
            <div class="grid grid-cols-3 gap-1.5" data-testid="login-role-picker">
              <button
                v-for="r in roleChoices" :key="r.value" type="button"
                class="px-2 py-1.5 text-xs rounded-md transition-colors"
                :class="loginForm.role === r.value ? '' : 'hover:text-white'"
                :style="loginForm.role === r.value
                  ? 'background: var(--accent-weak); color: var(--accent); border: 1px solid rgba(0,229,200,0.30);'
                  : 'background: var(--bg-elevated); color: var(--text-muted); border: 1px solid var(--border);'"
                :data-testid="`login-role-${r.value}`"
                @click="loginForm.role = r.value"
              >{{ r.label }}</button>
            </div>
          </div>

          <button type="submit" :disabled="authStore.isLoading"
                  class="sn-btn-primary w-full justify-center py-2.5 mt-2"
                  data-testid="login-submit">
            <svg v-if="authStore.isLoading" class="animate-spin w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor">
              <circle cx="12" cy="12" r="10" stroke-width="3" stroke-dasharray="50"/>
            </svg>
            {{ authStore.isLoading ? 'Authenticating…' : 'Sign in →' }}
          </button>

          <p class="text-[11px] text-center pt-1" style="color: var(--text-muted);">
            Demo: any email / password works. Role can also be swapped after login.
          </p>
        </form>

        <form v-else class="space-y-3" @submit.prevent="handleRegister">
          <div>
            <label class="block mb-1">Full name</label>
            <input v-model="registerForm.name" type="text" required data-testid="register-name" />
          </div>
          <div>
            <label class="block mb-1">Work email</label>
            <input v-model="registerForm.email" type="email" required data-testid="register-email" />
          </div>
          <div>
            <label class="block mb-1">Password</label>
            <input v-model="registerForm.password" type="password" required minlength="8" data-testid="register-password" />
          </div>
          <div>
            <label class="block mb-1">Organization</label>
            <input v-model="registerForm.tenantName" type="text" required placeholder="Acme Ops" data-testid="register-tenant" />
          </div>
          <button type="submit" :disabled="authStore.isLoading"
                  class="sn-btn-primary w-full justify-center py-2.5 mt-2"
                  data-testid="register-submit">
            {{ authStore.isLoading ? 'Creating…' : 'Create workspace →' }}
          </button>
        </form>

        <div v-if="authStore.error"
             class="mt-4 p-3 rounded-md text-xs"
             style="background: rgba(255,90,122,0.08); border: 1px solid rgba(255,90,122,0.28); color: var(--danger);"
             data-testid="auth-error">
          {{ authStore.error }}
        </div>

        <p class="mt-5 text-[11px] text-center" style="color: var(--text-muted);">
          By continuing you agree to the SpiderNetOS Terms &amp; Privacy policies.
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth.js'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const mode = ref('login')
const buildId = ref('1.0.0-' + Math.random().toString(36).slice(2, 7))

const roleChoices = [
  { value: 'user',        label: 'User' },
  { value: 'admin',       label: 'Admin' },
  { value: 'super_admin', label: 'Super' },
]

const loginForm = reactive({
  email: 'operator@acme.ops',
  password: 'demo',
  role: 'super_admin',
})

const registerForm = reactive({
  name: '',
  email: '',
  password: '',
  tenantName: '',
})

function postAuthRedirect() {
  const target = typeof route.query.return_to === 'string' ? route.query.return_to : null
  if (target && target.startsWith('/')) return router.push(target)
  if (authStore.requiresOnboarding()) return router.push('/onboarding')
  return router.push('/')
}

async function handleLogin() {
  const result = await authStore.login(loginForm.email, loginForm.password, loginForm.role)
  if (result.success) postAuthRedirect()
}

async function handleRegister() {
  const result = await authStore.register(
    registerForm.name, registerForm.email, registerForm.password, registerForm.tenantName
  )
  if (result.success) postAuthRedirect()
}
</script>

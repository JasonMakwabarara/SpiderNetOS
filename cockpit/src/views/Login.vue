<template>
  <div class="min-h-screen bg-gray-50 flex items-center justify-center p-4">
    <div class="max-w-md w-full">
      <!-- Logo -->
      <div class="text-center mb-8">
        <div class="w-16 h-16 bg-indigo-600 rounded-xl flex items-center justify-center mx-auto mb-4">
          <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
          </svg>
        </div>
        <h1 class="text-2xl font-bold text-gray-900">SpiderNet OS</h1>
        <p class="text-gray-500">Agent-Native Operating System</p>
      </div>

      <!-- Form Card -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <!-- Tabs -->
        <div class="flex mb-6">
          <button
            @click="mode = 'login'"
            class="flex-1 py-2 text-center font-medium rounded-lg transition-colors"
            :class="mode === 'login' ? 'bg-indigo-100 text-indigo-700' : 'text-gray-500 hover:text-gray-700'"
          >
            Sign In
          </button>
          <button
            @click="mode = 'register'"
            class="flex-1 py-2 text-center font-medium rounded-lg transition-colors"
            :class="mode === 'register' ? 'bg-indigo-100 text-indigo-700' : 'text-gray-500 hover:text-gray-700'"
          >
            Create Account
          </button>
        </div>

        <!-- Login Form -->
        <form v-if="mode === 'login'" @submit.prevent="handleLogin" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <input
              v-model="loginForm.email"
              type="email"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
            <input
              v-model="loginForm.password"
              type="password"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>
          <button
            type="submit"
            :disabled="authStore.isLoading"
            class="w-full py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50"
          >
            {{ authStore.isLoading ? 'Signing in...' : 'Sign In' }}
          </button>
        </form>

        <!-- Register Form -->
        <form v-else @submit.prevent="handleRegister" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
            <input
              v-model="registerForm.name"
              type="text"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <input
              v-model="registerForm.email"
              type="email"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
            <input
              v-model="registerForm.password"
              type="password"
              required
              minlength="8"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Organization Name</label>
            <input
              v-model="registerForm.tenantName"
              type="text"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>
          <button
            type="submit"
            :disabled="authStore.isLoading"
            class="w-full py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50"
          >
            {{ authStore.isLoading ? 'Creating Account...' : 'Create Account' }}
          </button>
        </form>

        <!-- Error -->
        <div v-if="authStore.error" class="mt-4 p-3 bg-red-50 border border-red-200 rounded-lg">
          <p class="text-sm text-red-700">{{ authStore.error }}</p>
        </div>
      </div>

      <!-- Footer -->
      <p class="mt-6 text-center text-sm text-gray-500">
        By using SpiderNet OS, you agree to our Terms of Service and Privacy Policy
      </p>
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

const loginForm = reactive({
  email: '',
  password: ''
})

const registerForm = reactive({
  name: '',
  email: '',
  password: '',
  tenantName: ''
})

function postAuthRedirect() {
  const target = typeof route.query.return_to === 'string' ? route.query.return_to : null
  if (target && target.startsWith('/')) {
    return router.push(target)
  }
  if (authStore.requiresOnboarding()) {
    return router.push('/onboarding')
  }
  return router.push('/')
}

async function handleLogin() {
  const result = await authStore.login(loginForm.email, loginForm.password)
  if (result.success) {
    postAuthRedirect()
  }
}

async function handleRegister() {
  const result = await authStore.register(
    registerForm.name,
    registerForm.email,
    registerForm.password,
    registerForm.tenantName
  )
  if (result.success) {
    postAuthRedirect()
  }
}
</script>

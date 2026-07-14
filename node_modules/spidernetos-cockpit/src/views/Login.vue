<template>
  <div class="min-h-screen flex items-center justify-center" style="background: #0a0a0f;">
    <div class="card max-w-md w-full p-8">
      <h1 class="text-2xl font-bold text-center mb-2 text-white">SpiderNetOS</h1>
      <p class="text-sm text-center text-gray-400 mb-8">Sign in to your cockpit</p>
      
      <form @submit.prevent="handleLogin">
        <div class="mb-4">
          <label class="block text-sm font-medium mb-1 text-gray-300">Email</label>
          <input v-model="email" type="email" placeholder="admin@spidernetos.com" 
                 class="w-full p-2 rounded bg-gray-800 border border-gray-700 text-white" />
        </div>
        <div class="mb-6">
          <label class="block text-sm font-medium mb-1 text-gray-300">Password</label>
          <input v-model="password" type="password" placeholder="Enter password" 
                 class="w-full p-2 rounded bg-gray-800 border border-gray-700 text-white" />
        </div>
        <button type="submit" class="btn-primary w-full">Sign In</button>
      </form>
      
      <p v-if="error" class="text-red-500 text-sm text-center mt-4">{{ error }}</p>
    </div>
  </div>
</template>

<script>
import { ref } from 'vue'
import { useAuthStore } from '../stores/auth.js'
import { useRouter } from 'vue-router'

export default {
  name: 'Login',
  setup() {
    const email = ref('admin@spidernetos.com')
    const password = ref('Zukaarimoto01!')
    const error = ref(null)
    const authStore = useAuthStore()
    const router = useRouter()

    const handleLogin = async () => {
      error.value = null
      try {
        const result = await authStore.login(email.value, password.value)
        if (result.success) {
          router.push('/dashboard')
        } else {
          error.value = result.error || 'Login failed'
        }
      } catch (err) {
        error.value = err.message || 'Login failed'
      }
    }

    return { email, password, error, handleLogin }
  }
}
</script>

<style scoped>
.btn-primary {
  background: #FF6B2C;
  color: #fff;
  border: none;
  padding: 0.5rem 1.5rem;
  border-radius: 6px;
  font-weight: 600;
  cursor: pointer;
}
.btn-primary:hover { background: #FF8C42; }
.card {
  background: #1a1a2e;
  border: 1px solid #2a2a4e;
  border-radius: 12px;
  padding: 1.5rem;
}
</style>

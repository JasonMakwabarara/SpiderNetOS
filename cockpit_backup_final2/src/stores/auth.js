import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../services/api.js'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const token = ref(null)
  const isLoading = ref(false)
  const error = ref(null)

  // Initialize from localStorage
  const initAuth = () => {
    const savedToken = localStorage.getItem('token')
    const savedUser = localStorage.getItem('user')
    if (savedToken) {
      token.value = savedToken
    }
    if (savedUser) {
      try {
        user.value = JSON.parse(savedUser)
      } catch {
        user.value = null
      }
    }
  }
  initAuth()

  const isAuthenticated = computed(() => {
    return !!token.value && !!user.value
  })

  const isOnboardingComplete = computed(() => true)
  const role = computed(() => user.value?.role || 'user')

  async function login(email, password) {
    isLoading.value = true
    error.value = null
    try {
      const response = await api.post('/login', { email, password })
      const data = response.data
      
      token.value = data.access_token || data.token
      user.value = data.user || data.data?.user
      
      if (token.value) {
        localStorage.setItem('token', token.value)
      }
      if (user.value) {
        localStorage.setItem('user', JSON.stringify(user.value))
      }
      
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || err.message || 'Login failed'
      return { success: false, error: error.value }
    } finally {
      isLoading.value = false
    }
  }

  function logout() {
    token.value = null
    user.value = null
    localStorage.removeItem('token')
    localStorage.removeItem('user')
  }

  function requiresOnboarding() {
    return false
  }

  function has(capability) {
    return user.value?.capabilities?.includes(capability) || false
  }

  return {
    user,
    token,
    isLoading,
    error,
    isAuthenticated,
    isOnboardingComplete,
    role,
    login,
    logout,
    requiresOnboarding,
    has,
  }
})

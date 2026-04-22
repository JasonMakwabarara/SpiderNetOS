import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000'

/**
 * Auth store
 *
 * Principal = user + tenant + role + capabilities + stepUp state.
 * UI permission checks are advisory; the server remains authoritative.
 *
 * Roles: 'user' | 'admin' | 'super_admin'
 */
export const useAuthStore = defineStore('auth', () => {
  // ---------------------------------------------------------------------
  // State
  // ---------------------------------------------------------------------
  const user         = ref(null)
  const token        = ref(localStorage.getItem('token'))
  const tenant       = ref(null)
  const capabilities = ref([])
  const isLoading    = ref(false)
  const error        = ref(null)

  // Step-up MFA freshness — epoch ms of the last successful step-up.
  const stepUpAt     = ref(Number(localStorage.getItem('stepUpAt') || 0))
  const STEP_UP_TTL_MS = 5 * 60 * 1000   // 5 min

  // Impersonation
  const impersonating = ref(JSON.parse(localStorage.getItem('impersonating') || 'null'))

  // ---------------------------------------------------------------------
  // Getters
  // ---------------------------------------------------------------------
  const isAuthenticated = computed(() => !!token.value)
  const role            = computed(() => user.value?.role || 'user')
  const isUser          = computed(() => role.value === 'user')
  const isAdmin         = computed(() => role.value === 'admin' || role.value === 'super_admin')
  const isSuperAdmin    = computed(() => role.value === 'super_admin')
  const isImpersonating = computed(() => !!impersonating.value)

  // Phase 1: Onboarding state (gated via middleware, advisory here)
  const isOnboardingComplete = computed(() => !!user.value?.onboarding_completed_at)
  const automationLevel      = computed(() => tenant.value?.automation_level || 'assisted')

  /**
   * Capability check. Server issues the list at login; ignore the empty
   * list for classic 'user' because it also implicitly has 'self.*'
   * capabilities server-side.
   */
  function has(cap) {
    if (!cap) return true
    if (isSuperAdmin.value) return true
    return capabilities.value.includes(cap)
  }

  /**
   * Role hierarchy check: user < admin < super_admin.
   */
  function atLeastRole(target) {
    const order = { user: 0, admin: 1, super_admin: 2 }
    return (order[role.value] ?? -1) >= (order[target] ?? 0)
  }

  /**
   * Step-up freshness — used by StepUpGuard for sensitive writes.
   */
  function stepUpValid() {
    if (!stepUpAt.value) return false
    return (Date.now() - stepUpAt.value) < STEP_UP_TTL_MS
  }

  /**
   * Check if onboarding is required. Used by router guard.
   * Server is authoritative; this is advisory for UI branching.
   */
  function requiresOnboarding() {
    if (!isAuthenticated.value) return false
    return !isOnboardingComplete.value
  }

  /**
   * Mark onboarding complete (called after successful completion API call)
   */
  function markOnboardingComplete(timestamp = new Date().toISOString()) {
    if (user.value) {
      user.value.onboarding_completed_at = timestamp
    }
  }

  function markStepUp() {
    stepUpAt.value = Date.now()
    localStorage.setItem('stepUpAt', String(stepUpAt.value))
  }

  function clearStepUp() {
    stepUpAt.value = 0
    localStorage.removeItem('stepUpAt')
  }

  // ---------------------------------------------------------------------
  // Actions
  // ---------------------------------------------------------------------

  async function login(email, password) {
    isLoading.value = true
    error.value = null

    try {
      const response = await axios.post(`${API_URL}/api/auth/login`, { email, password })
      applyPrincipal(response.data)
      return { success: true, firstLogin: !!response.data.first_login }
    } catch (err) {
      error.value = err.response?.data?.message || 'Login failed'
      return { success: false, error: error.value }
    } finally {
      isLoading.value = false
    }
  }

  async function register(name, email, password, tenantName) {
    isLoading.value = true
    error.value = null

    try {
      const response = await axios.post(`${API_URL}/api/auth/register`, {
        name,
        email,
        password,
        tenant_name: tenantName,
      })
      applyPrincipal(response.data)
      return { success: true, firstLogin: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Registration failed'
      return { success: false, error: error.value }
    } finally {
      isLoading.value = false
    }
  }

  async function fetchUser() {
    if (!token.value) return

    try {
      axios.defaults.headers.common['Authorization'] = `Bearer ${token.value}`
      const response = await axios.get(`${API_URL}/api/auth/me`)
      user.value         = response.data.user
      tenant.value       = response.data.tenant
      capabilities.value = response.data.user.capabilities || deriveCapabilities(response.data.user)
    } catch (err) {
      logout()
    }
  }

  function applyPrincipal(data) {
    token.value        = data.token
    user.value         = data.user
    tenant.value       = data.tenant
    capabilities.value = data.capabilities || deriveCapabilities(data.user)

    localStorage.setItem('token', token.value)
    axios.defaults.headers.common['Authorization'] = `Bearer ${token.value}`
  }

  /**
   * Defensive fallback: when the server does not return capabilities yet,
   * derive a minimum set from the role so the UI keeps working.
   */
  function deriveCapabilities(u) {
    if (!u?.role) return []
    if (u.role === 'super_admin') {
      return ['platform.*', 'tenant.*', 'flag.write', 'impersonate', 'rollout.cutover', 'rl.view', 'audit.export']
    }
    if (u.role === 'admin') {
      return ['tenant.view', 'users.invite', 'users.manage', 'budget.edit', 'audit.view', 'copy.manage', 'approvals.manage']
    }
    return ['self.flows', 'self.agents', 'self.usage']
  }

  function logout() {
    token.value        = null
    user.value         = null
    tenant.value       = null
    capabilities.value = []
    impersonating.value = null
    clearStepUp()
    localStorage.removeItem('token')
    localStorage.removeItem('impersonating')
    delete axios.defaults.headers.common['Authorization']
  }

  // ---------------------------------------------------------------------
  // Impersonation
  // ---------------------------------------------------------------------

  async function startImpersonation(userId, tenantId, reason = '') {
    const { data } = await axios.post(`${API_URL}/api/platform/impersonate`, {
      user_id:   userId,
      tenant_id: tenantId,
      reason,
    })
    impersonating.value = {
      user_id:    userId,
      tenant_id:  tenantId,
      expires_at: data.expires_at,
      actor_id:   user.value?.id,
      started_at: Date.now(),
    }
    localStorage.setItem('impersonating', JSON.stringify(impersonating.value))
  }

  function stopImpersonation() {
    impersonating.value = null
    localStorage.removeItem('impersonating')
  }

  // ---------------------------------------------------------------------
  // Bootstrap
  // ---------------------------------------------------------------------

  if (token.value) {
    axios.defaults.headers.common['Authorization'] = `Bearer ${token.value}`
    fetchUser()
  }

  return {
    // state
    user,
    token,
    tenant,
    capabilities,
    isLoading,
    error,
    impersonating,
    // getters
    isAuthenticated,
    role,
    isUser,
    isAdmin,
    isSuperAdmin,
    isImpersonating,
    isOnboardingComplete,
    automationLevel,
    // perms
    has,
    atLeastRole,
    stepUpValid,
    markStepUp,
    clearStepUp,
    requiresOnboarding,
    markOnboardingComplete,
    // auth
    login,
    register,
    fetchUser,
    logout,
    // impersonation
    startImpersonation,
    stopImpersonation,
  }
})

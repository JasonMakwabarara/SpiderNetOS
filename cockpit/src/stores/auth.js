import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../services/api.js'

/**
 * Auth store for SpiderNetOS Cockpit.
 *
 * Fake-login model (no backend secrets required): any email/password is
 * accepted by the FastAPI mock backend. The response returns a signed
 * token + principal. Role can be swapped from the user menu to demonstrate
 * the three surfaces (user / admin / super_admin).
 *
 * TODO (Laravel): swap `/api/auth/login` for Laravel Sanctum and remove
 * `switchRole` — the server will own role resolution.
 */
const DEFAULT_ROLE = 'super_admin'

const CAP_BY_ROLE = {
  user: ['self.flows', 'self.agents', 'self.usage'],
  admin: [
    'tenant.view', 'users.invite', 'users.manage',
    'budget.edit', 'audit.view', 'copy.manage',
    'approvals.manage',
  ],
  super_admin: [
    'platform.*', 'tenant.*', 'tenant.manage',
    'flag.write', 'impersonate', 'rollout.cutover',
    'ste.view', 'audit.export', 'copy.manage',
    'users.manage', 'budget.edit', 'audit.view',
    'approvals.manage',
  ],
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref(JSON.parse(localStorage.getItem('user') || localStorage.getItem('sn_user') || 'null'))
  const token = ref(localStorage.getItem('token') || localStorage.getItem('sn_access_token'))
  const tenant = ref(JSON.parse(localStorage.getItem('tenant') || localStorage.getItem('sn_tenant') || 'null'))
  const capabilities = ref(JSON.parse(localStorage.getItem('caps') || '[]'))
  const isLoading = ref(false)
  const error = ref(null)

  const stepUpAt = ref(Number(localStorage.getItem('stepUpAt') || 0))
  const STEP_UP_TTL_MS = 5 * 60 * 1000

  const impersonating = ref(JSON.parse(localStorage.getItem('impersonating') || 'null'))

  const isAuthenticated = computed(() => !!token.value && !!user.value)
  const role = computed(() => user.value?.role || 'user')
  const isAdmin = computed(() => role.value === 'admin' || role.value === 'super_admin')
  const isSuperAdmin = computed(() => role.value === 'super_admin')
  const isImpersonating = computed(() => !!impersonating.value)
  const isOnboardingComplete = computed(() => !!user.value?.onboarding_completed_at)
  const automationLevel = computed(() => tenant.value?.automation_level || 'assisted')

  function has(cap) {
    if (!cap) return true
    if (isSuperAdmin.value) return true
    if (capabilities.value.includes(cap)) return true
    // Prefix match like tenant.*
    return capabilities.value.some((c) => c.endsWith('.*') && cap.startsWith(c.slice(0, -1)))
  }

  function atLeastRole(target) {
    const order = { user: 0, admin: 1, super_admin: 2 }
    return (order[role.value] ?? -1) >= (order[target] ?? 0)
  }

  function stepUpValid() {
    if (!stepUpAt.value) return false
    return (Date.now() - stepUpAt.value) < STEP_UP_TTL_MS
  }

  function markStepUp() {
    stepUpAt.value = Date.now()
    localStorage.setItem('stepUpAt', String(stepUpAt.value))
  }
  function clearStepUp() {
    stepUpAt.value = 0
    localStorage.removeItem('stepUpAt')
  }

  function requiresOnboarding() {
    if (!isAuthenticated.value) return false
    return !isOnboardingComplete.value
  }

  function markOnboardingComplete(timestamp = new Date().toISOString()) {
    if (user.value) {
      user.value.onboarding_completed_at = timestamp
      localStorage.setItem('user', JSON.stringify(user.value))
    }
  }

  async function login(email, password, preferredRole = DEFAULT_ROLE) {
    isLoading.value = true
    error.value = null
    try {
      const { data } = await api.post('/api/auth/login', { email, password, role: preferredRole })
      applyPrincipal(data)
      return { success: true, firstLogin: !!data.first_login }
    } catch (err) {
      error.value = err.response?.data?.message || err.message || 'Login failed'
      return { success: false, error: error.value }
    } finally {
      isLoading.value = false
    }
  }

  async function register(name, email, password, tenantName) {
    isLoading.value = true
    error.value = null
    try {
      const { data } = await api.post('/api/auth/register', {
        name, email, password, tenant_name: tenantName,
      })
      applyPrincipal(data)
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
      const { data } = await api.get('/api/auth/me')
      user.value = data.user
      tenant.value = data.tenant
      capabilities.value = data.capabilities || CAP_BY_ROLE[data.user?.role] || []
      persist()
    } catch {
      logout()
    }
  }

  function applyPrincipal(data) {
    token.value = data.token
    user.value = data.user
    tenant.value = data.tenant
    capabilities.value = data.capabilities || CAP_BY_ROLE[data.user?.role] || []
    persist()
  }

  function persist() {
    if (token.value) {
      localStorage.setItem('token', token.value)
      localStorage.setItem('sn_access_token', token.value)
    }
    if (user.value) {
      localStorage.setItem('user', JSON.stringify(user.value))
      localStorage.setItem('sn_user', JSON.stringify(user.value))
    }
    if (tenant.value) {
      localStorage.setItem('tenant', JSON.stringify(tenant.value))
      localStorage.setItem('sn_tenant', JSON.stringify(tenant.value))
    }
    localStorage.setItem('caps', JSON.stringify(capabilities.value))
  }

  function logout() {
    token.value = null
    user.value = null
    tenant.value = null
    capabilities.value = []
    impersonating.value = null
    clearStepUp()
    localStorage.removeItem('token')
    localStorage.removeItem('sn_access_token')
    localStorage.removeItem('user')
    localStorage.removeItem('sn_user')
    localStorage.removeItem('tenant')
    localStorage.removeItem('sn_tenant')
    localStorage.removeItem('caps')
    localStorage.removeItem('impersonating')
  }

  /**
   * Demo-only: switch role on the fly. Re-derives capabilities from the
   * static map. A real deployment would revoke the token and issue a new
   * one from the server.
   */
  function switchRole(newRole) {
    if (!['user', 'admin', 'super_admin'].includes(newRole)) return
    if (!user.value) return
    user.value = { ...user.value, role: newRole }
    capabilities.value = CAP_BY_ROLE[newRole] || []
    persist()
  }

  async function startImpersonation(userId, tenantId, reason = '') {
    const { data } = await api.post('/api/platform/impersonate', {
      user_id: userId, tenant_id: tenantId, reason,
    })
    impersonating.value = {
      user_id: userId, tenant_id: tenantId,
      expires_at: data.expires_at,
      actor_id: user.value?.id,
      started_at: Date.now(),
    }
    localStorage.setItem('impersonating', JSON.stringify(impersonating.value))
  }

  function stopImpersonation() {
    impersonating.value = null
    localStorage.removeItem('impersonating')
  }

  return {
    user, token, tenant, capabilities, isLoading, error, impersonating,
    isAuthenticated, role, isAdmin, isSuperAdmin, isImpersonating,
    isOnboardingComplete, automationLevel,
    has, atLeastRole, stepUpValid, markStepUp, clearStepUp,
    requiresOnboarding, markOnboardingComplete,
    login, register, fetchUser, logout, switchRole,
    startImpersonation, stopImpersonation,
  }
})

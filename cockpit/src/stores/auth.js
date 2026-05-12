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
  // Dual-key bridge: hydrate from EITHER the legacy Vue keys (token/user/tenant/caps)
  // OR the React shell keys (sn_access_token/sn_user/sn_tenant). Whichever is
  // present, the cockpit picks it up so login through the React /sign-in or
  // /enterprise/register flow seamlessly authenticates the Vue cockpit.
  function _hydrate(legacyKey, bridgeKey, parse = false) {
    const raw = localStorage.getItem(legacyKey) || localStorage.getItem(bridgeKey)
    if (raw === null) return parse ? null : null
    return parse ? JSON.parse(raw || 'null') : raw
  }
  const user = ref(_hydrate('user', 'sn_user', true))
  const token = ref(_hydrate('token', 'sn_access_token'))
  const tenant = ref(_hydrate('tenant', 'sn_tenant', true))
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
      // Bridge: also write the React shell key so the React side stays in sync.
      localStorage.setItem('sn_access_token', token.value)
    }
    if (user.value) {
      const u = JSON.stringify(user.value)
      localStorage.setItem('user', u)
      localStorage.setItem('sn_user', u)
    }
    if (tenant.value) {
      const t = JSON.stringify(tenant.value)
      localStorage.setItem('tenant', t)
      localStorage.setItem('sn_tenant', t)
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
    // Clear BOTH the legacy and the React shell keys.
    ;['token', 'user', 'tenant', 'caps', 'impersonating',
      'sn_access_token', 'sn_user', 'sn_tenant'].forEach((k) => localStorage.removeItem(k))
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

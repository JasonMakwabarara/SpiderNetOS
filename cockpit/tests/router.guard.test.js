// Router guard helper tests.
// Exercises the role / capability / onboarding gates via a tiny factory
// function that mirrors the logic in src/router/index.js.
import { describe, it, expect } from 'vitest'

// Copy of the guard predicate — keeps the test hermetic and independent
// of the full vue-router boot.
function decide(to, auth) {
  if (to.meta.guest) return auth.isAuthenticated ? { to: '/' } : { to: null }
  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { to: '/login', query: { return_to: to.fullPath } }
  }
  if (auth.requiresOnboarding() && to.path !== '/onboarding' && !to.meta.guest) {
    return { to: '/onboarding' }
  }
  if (auth.isOnboardingComplete && to.path === '/onboarding') return { to: '/atlas' }
  if (to.meta.roles && !to.meta.roles.includes(auth.role)) {
    return { to: '/403', query: { required_roles: to.meta.roles.join(',') } }
  }
  if (to.meta.capability && !auth.has(to.meta.capability)) {
    return { to: '/403', query: { required_capability: to.meta.capability } }
  }
  return { to: null }
}

function stubAuth(partial = {}) {
  const role = partial.role ?? 'user'
  return {
    isAuthenticated: true,
    role,
    isOnboardingComplete: partial.onboarded ?? true,
    requiresOnboarding: () => !(partial.onboarded ?? true),
    has: (c) => (partial.caps || []).includes(c) || role === 'super_admin',
    ...partial,
  }
}

describe('router guard decide()', () => {
  it('redirects unauthenticated users to login with return_to', () => {
    const d = decide({ path: '/atlas', fullPath: '/atlas', meta: { requiresAuth: true } },
                     { isAuthenticated: false, requiresOnboarding: () => false, has: () => false })
    expect(d.to).toBe('/login')
    expect(d.query.return_to).toBe('/atlas')
  })

  it('sends incomplete onboarding to /onboarding', () => {
    const d = decide({ path: '/', fullPath: '/', meta: { requiresAuth: true } },
                     stubAuth({ onboarded: false }))
    expect(d.to).toBe('/onboarding')
  })

  it('blocks tenant user from admin routes', () => {
    const d = decide({ path: '/admin', fullPath: '/admin', meta: { requiresAuth: true, roles: ['admin', 'super_admin'] } },
                     stubAuth({ role: 'user' }))
    expect(d.to).toBe('/403')
    expect(d.query.required_roles).toContain('admin')
  })

  it('allows super_admin into platform + capability gated routes', () => {
    const d = decide({ path: '/platform/feature-flags', fullPath: '/platform/feature-flags',
                       meta: { requiresAuth: true, roles: ['super_admin'], capability: 'flag.write' } },
                     stubAuth({ role: 'super_admin' }))
    expect(d.to).toBe(null)
  })

  it('blocks capability shortfall', () => {
    const d = decide({ path: '/settings/automation-level', fullPath: '/settings/automation-level',
                       meta: { requiresAuth: true, capability: 'tenant.manage' } },
                     stubAuth({ role: 'user', caps: [] }))
    expect(d.to).toBe('/403')
    expect(d.query.required_capability).toBe('tenant.manage')
  })

  it('keeps authenticated users out of /login', () => {
    const d = decide({ path: '/login', fullPath: '/login', meta: { guest: true } },
                     stubAuth())
    expect(d.to).toBe('/')
  })

  // ── Slice-0 surfaces: skills / map / voice / systems ─────────────
  // All four are plain requiresAuth routes (no role, no capability).
  const AUTH_ONLY = { requiresAuth: true }

  it('sends an unauthenticated visitor to /skills/:slug to login with return_to', () => {
    const d = decide({ path: '/skills/cold-outreach', fullPath: '/skills/cold-outreach', meta: AUTH_ONLY },
                     { isAuthenticated: false, requiresOnboarding: () => false, has: () => false })
    expect(d.to).toBe('/login')
    expect(d.query.return_to).toBe('/skills/cold-outreach')
  })

  it('gates /map behind onboarding', () => {
    const d = decide({ path: '/map', fullPath: '/map', meta: AUTH_ONLY }, stubAuth({ onboarded: false }))
    expect(d.to).toBe('/onboarding')
  })

  it('lets a plain user into /settings/voice', () => {
    const d = decide({ path: '/settings/voice', fullPath: '/settings/voice', meta: AUTH_ONLY },
                     stubAuth({ role: 'user', caps: [] }))
    expect(d.to).toBe(null)
  })

  it('lets a plain user into /operate/systems', () => {
    const d = decide({ path: '/operate/systems', fullPath: '/operate/systems', meta: AUTH_ONLY },
                     stubAuth({ role: 'user', caps: [] }))
    expect(d.to).toBe(null)
  })

  it('still lets an onboarded plain user reach /brain and /agents/runs', () => {
    for (const path of ['/brain', '/agents/runs', '/agents/runs/run_1', '/gods-eye']) {
      expect(decide({ path, fullPath: path, meta: AUTH_ONLY }, stubAuth({ role: 'user' })).to).toBe(null)
    }
  })
})

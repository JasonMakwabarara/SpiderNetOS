// Auth store — Vitest unit tests.
// Validates role switching, capability resolution, login flow, and logout.
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

// Mock the api service used by the auth store
vi.mock('../src/services/api.js', () => ({
  default: {
    post: vi.fn(),
    get: vi.fn(),
  },
}))

import api from '../src/services/api.js'
import { useAuthStore } from '../src/stores/auth.js'

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    vi.resetAllMocks()
  })

  it('starts unauthenticated', () => {
    const auth = useAuthStore()
    expect(auth.isAuthenticated).toBe(false)
    expect(auth.role).toBe('user')
  })

  it('login applies principal and derives capabilities', async () => {
    api.post.mockResolvedValueOnce({
      data: {
        token: 'tkn',
        user: { id: 'u1', name: 'Op', email: 'o@a.co', role: 'admin',
                onboarding_completed_at: '2026-01-01T00:00:00Z' },
        tenant: { id: 't1', name: 'Acme' },
        capabilities: ['tenant.view', 'users.manage'],
      },
    })
    const auth = useAuthStore()
    const res = await auth.login('o@a.co', 'p', 'admin')
    expect(res.success).toBe(true)
    expect(auth.isAuthenticated).toBe(true)
    expect(auth.role).toBe('admin')
    expect(auth.has('users.manage')).toBe(true)
    expect(auth.has('platform.flags')).toBe(false)
  })

  it('super_admin short-circuits capability check', async () => {
    api.post.mockResolvedValueOnce({
      data: {
        token: 'tkn',
        user: { id: 'u', name: 'Root', email: 'r@a.co', role: 'super_admin',
                onboarding_completed_at: '2026-01-01T00:00:00Z' },
        tenant: { id: 't1', name: 'Acme' },
      },
    })
    const auth = useAuthStore()
    await auth.login('r@a.co', 'p', 'super_admin')
    expect(auth.has('literally.anything')).toBe(true)
    expect(auth.atLeastRole('admin')).toBe(true)
  })

  it('switchRole replaces capabilities from the static map', async () => {
    api.post.mockResolvedValueOnce({
      data: {
        token: 't',
        user: { id: 'u', name: 'X', email: 'x@a.co', role: 'user',
                onboarding_completed_at: '2026-01-01T00:00:00Z' },
        tenant: { id: 't1', name: 'A' },
      },
    })
    const auth = useAuthStore()
    await auth.login('x@a.co', 'p', 'user')
    expect(auth.has('users.manage')).toBe(false)
    auth.switchRole('admin')
    expect(auth.role).toBe('admin')
    expect(auth.has('users.manage')).toBe(true)
  })

  it('logout wipes principal + token', async () => {
    api.post.mockResolvedValueOnce({
      data: {
        token: 't',
        user: { id: 'u', name: 'X', email: 'x@a.co', role: 'admin',
                onboarding_completed_at: '2026-01-01T00:00:00Z' },
        tenant: { id: 't1', name: 'A' },
      },
    })
    const auth = useAuthStore()
    await auth.login('x@a.co', 'p', 'admin')
    expect(auth.isAuthenticated).toBe(true)
    auth.logout()
    expect(auth.isAuthenticated).toBe(false)
    expect(localStorage.getItem('token')).toBeNull()
  })
})

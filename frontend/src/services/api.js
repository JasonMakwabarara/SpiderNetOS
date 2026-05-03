import axios from 'axios'

/**
 * Central Axios instance for SpiderNetOS Cockpit.
 *
 * - Base URL via VITE_API_URL (resolves to the Laravel API gateway in prod).
 * - Bearer token from localStorage('token').
 * - 401 → logout + redirect /login, preserving return_to.
 * - 429 → surface retry-after through a CustomEvent so a toast can render.
 * - Writes a sensible X-Tenant header when an impersonation is active.
 *
 * TODO (Laravel): swap the base URL to the real /api gateway and delete
 * the mock backend under /app/backend/server.py.
 */
const api = axios.create({
  baseURL: (import.meta.env.VITE_API_URL || '').replace(/\/$/, ''),
  timeout: 20000,
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) config.headers.Authorization = `Bearer ${token}`

  try {
    const imp = JSON.parse(localStorage.getItem('impersonating') || 'null')
    if (imp?.tenant_id) config.headers['X-Impersonated-Tenant'] = imp.tenant_id
  } catch { /* noop */ }

  return config
})

api.interceptors.response.use(
  (r) => r,
  (err) => {
    if (err.response?.status === 401) {
      localStorage.removeItem('token')
      const here = window.location.pathname + window.location.search
      if (!here.startsWith('/login')) {
        window.location.href = `/login?return_to=${encodeURIComponent(here)}`
      }
    }
    if (err.response?.status === 429) {
      const retry = err.response.headers?.['retry-after'] || 15
      window.dispatchEvent(new CustomEvent('sn:rate-limited', { detail: { retry } }))
    }
    return Promise.reject(err)
  }
)

// Mirror defaults onto the global axios so legacy stores that import
// axios directly keep working without refactor.
axios.defaults.baseURL = api.defaults.baseURL
axios.interceptors.request.use((config) => {
  // If the call is absolute, leave it alone. Otherwise prepend baseURL.
  if (!config.url?.startsWith('http')) {
    // Already handled by baseURL
  }
  const token = localStorage.getItem('token')
  if (token && !config.headers.Authorization) config.headers.Authorization = `Bearer ${token}`
  return config
})
axios.interceptors.response.use(
  (r) => r,
  (err) => {
    if (err.response?.status === 401) {
      localStorage.removeItem('token')
      const here = window.location.pathname + window.location.search
      if (!here.startsWith('/login')) {
        window.location.href = `/login?return_to=${encodeURIComponent(here)}`
      }
    }
    return Promise.reject(err)
  }
)

export default api

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

/*
 * Tier 1 security: emit the same header set in dev as the API backend.
 * CSP is Report-Only in dev so HMR + Vite's inline scripts still work while
 * we collect violation reports. The production build is served behind
 * Traefik (see Batch E) which re-asserts the same headers in enforce mode.
 */
const apiOrigin = process.env.VITE_API_URL || 'http://localhost:8000'
const wsOrigin = process.env.VITE_WS_URL || 'ws://localhost:6001'

const securityHeaders = {
  'X-Frame-Options': 'DENY',
  'X-Content-Type-Options': 'nosniff',
  'Referrer-Policy': 'strict-origin-when-cross-origin',
  'Permissions-Policy': 'camera=(), microphone=(self), geolocation=(), payment=(), usb=()',
  'Cross-Origin-Opener-Policy': 'same-origin',
  'Cross-Origin-Resource-Policy': 'same-site',
  // Report-Only in dev; Vite injects inline scripts for HMR which we keep.
  'Content-Security-Policy-Report-Only': [
    "default-src 'self'",
    "script-src 'self' 'unsafe-inline' 'unsafe-eval'", // unsafe-eval for Vite HMR
    "style-src 'self' 'unsafe-inline'",
    "img-src 'self' data: https:",
    "font-src 'self' data:",
    `connect-src 'self' ${apiOrigin} ${wsOrigin} ws://localhost:* wss://localhost:*`,
    "frame-ancestors 'none'",
    "form-action 'self'",
    "base-uri 'self'",
    "object-src 'none'",
  ].join('; '),
}

export default defineConfig({
  plugins: [vue()],
  server: {
    host: '0.0.0.0',
    port: 5173,
    headers: securityHeaders,
  },
  preview: {
    headers: securityHeaders,
  },
})

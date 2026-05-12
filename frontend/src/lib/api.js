import axios from 'axios';

/**
 * Session storage strategy (security note)
 * ----------------------------------------
 * We currently persist the JWT access token + user/tenant context in
 * `localStorage`. This is the common SPA tradeoff — convenient, but readable
 * by any script executing in the document (i.e. an XSS payload).
 *
 * Mitigations in place:
 *   - Strict CSP and trusted-types are recommended at deploy time.
 *   - JWT tokens are short-lived (1h) and the backend supports rotation.
 *   - No long-lived secrets (SCIM bearer, signing keys) are stored client-side.
 *
 * Backlog (PRD P1): migrate to httpOnly + SameSite=strict cookies issued by
 * the backend on /api/enterprise/auth/* endpoints, with CSRF tokens for
 * state-changing requests. Requires backend cookie issuance + same-origin
 * fetch wiring, tracked in PRD.md.
 */
const BACKEND_URL = process.env.REACT_APP_BACKEND_URL || '';
export const API = `${BACKEND_URL}/api`;

export const api = axios.create({
  baseURL: API,
  headers: { 'Content-Type': 'application/json' },
});

api.interceptors.request.use((config) => {
  const tok = localStorage.getItem('sn_access_token');
  if (tok) config.headers.Authorization = `Bearer ${tok}`;
  return config;
});

export const auth = {
  saveSession({ access_token, user, tenant, caps }) {
    if (access_token) {
      localStorage.setItem('sn_access_token', access_token);
      localStorage.setItem('token', access_token);
    }
    if (user) {
      const u = JSON.stringify(user);
      localStorage.setItem('sn_user', u);
      localStorage.setItem('user', u);
    }
    if (tenant) {
      const t = JSON.stringify(tenant);
      localStorage.setItem('sn_tenant', t);
      localStorage.setItem('tenant', t);
    }
    if (Array.isArray(caps)) {
      localStorage.setItem('caps', JSON.stringify(caps));
    }
  },
  clear() {
    ['sn_access_token', 'sn_user', 'sn_tenant',
     'token', 'user', 'tenant', 'caps', 'impersonating'].forEach((k) =>
      localStorage.removeItem(k),
    );
  },
  getUser() {
    try {
      return JSON.parse(localStorage.getItem('sn_user') || 'null');
    } catch {
      return null;
    }
  },
  getTenant() {
    try {
      return JSON.parse(localStorage.getItem('sn_tenant') || 'null');
    } catch {
      return null;
    }
  },
  getToken() {
    return localStorage.getItem('sn_access_token');
  },
  isAuthed() {
    return !!localStorage.getItem('sn_access_token');
  },
};

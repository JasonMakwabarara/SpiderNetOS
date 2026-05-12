# SpiderNetOS — Test credentials & demo paths

Updated: 12 May 2026. Aligned with the React + FastAPI enterprise build.

## Sign-in flows (all working end-to-end without external credentials)

### 1. Enterprise SSO — Demo IdP
Path: `/sign-in` → pick **Enterprise SSO**
- Tenant slug: `demo` (any value works; tenant is auto-created if missing)
- Provider: **Demo IdP (OIDC, simulated)** or **Demo IdP (SAML 2.0)**
- Click **Continue with SSO** → backend `POST /api/enterprise/auth/sso/start` returns `{completed: true, access_token, user, tenant}` and the UI redirects to `/cockpit`.

### 2. Magic link
- Email: any value (e.g. `jane@acme.com`)
- Click **Send magic link** → backend returns `dev_link.token` in the response so the UI surfaces a clickable "↗ /verify?token=…" button.
- Click it to consume the token → session issued.

### 3. TOTP
- Email: any value
- Code: `000000` (demo bypass) — verified by `POST /api/enterprise/auth/totp/login`.

### 4. WebAuthn (demo-stubbed)
- Email: any value
- Clicking **Use passkey** issues a session via `POST /api/enterprise/auth/webauthn/login` (real WebAuthn ceremony will replace this in P1 backlog).

## Enterprise Registration Wizard
Path: `/enterprise/register` — 10 steps, all functional. Use any org name + corporate-style email (`jane@acme.com`). Domain verification, tenant creation, SCIM token issuance, bundle generation, and deployment start are all real backend calls. SCIM token is shown once on step 6.

## Bundle download (signed)
- Generate in Cockpit → AIOS Downloads (or wizard step 9).
- Backend: `GET /api/enterprise/aios/bundle/{bundle_id}/download` → real ZIP, headers:
  - `X-SpiderNet-SHA256: <hex>`
  - `X-SpiderNet-Signature: <ed25519 hex>`
- Verify endpoint: `GET /api/enterprise/aios/bundle/{bundle_id}/verify` returns signature + public key.

## SCIM 2.0
- Service provider config (no auth): `GET /api/scim/v2/ServiceProviderConfig`
- Users (bearer required): `GET /api/scim/v2/Users` with `Authorization: Bearer <token>`
- Token issued at wizard step 6 via `POST /api/enterprise/register/scim/generate`. The response field is `scim_token` (also used as the bearer). Hash stored in `db.scim_tokens`.

## Env / infra
- Frontend base URL: `https://aios-onboarding.preview.emergentagent.com`
- Backend (same origin, `/api/*` routed to port 8001 internally).
- Mongo: `mongodb://localhost:27017`, DB `spidernetos`.

## What's mocked vs real
| Flow | Status |
|---|---|
| OIDC real provider (Okta/Entra) code exchange | UI captures issuer/client_id/secret; backend has demo path only. Replace with Authlib in P1. |
| SAML real provider | Same as above — config fields plumbed; demo path only. |
| WebAuthn ceremony | Demo-stub session. Use `fido2` lib in P1. |
| Magic-link email send | Token returned in response body (`dev_link`); plug SendGrid/Resend in P1. |
| TOTP | Real `pyotp` verify + demo `000000` bypass for testability. |
| AIOS bundle | Real ZIP, real SHA-256, real Ed25519 signature (key generated in-process). |
| SCIM Users | Real bearer-guarded SCIM 2.0 List+Create. `/Groups` + PATCH = P2. |

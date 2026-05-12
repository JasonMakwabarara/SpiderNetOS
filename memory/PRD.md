# SpiderNetOS — Enterprise Frontend PRD

## Original problem statement
Build a complete modern landing page and enterprise-focused frontend for SpiderNetOS — the enterprise AI Operating System for governed automation. Includes hero + value proposition, security & compliance proof, integrations catalog, developer portal preview, customer story (Hannah AI), pricing/SLA tiers, a sandboxed sign-in experience with enterprise authentication options (OIDC/SAML, SCIM, WebAuthn, TOTP, magic link), a 10-step Enterprise Registration Wizard, Cockpit admin (tenants, RBAC, connectors, AIOS downloads, audit, anomaly), and a signed AIOS ZIP bundle download with SHA-256 + signature verification.

## User-confirmed choices (12 May 2026)
- Scope: full end-to-end (landing → register → cockpit). Focus = landing + registration/onboarding; cockpit improvements layered on top.
- Build on top of existing repo at `/app`. New React+FastAPI surface deployed alongside the existing Vue cockpit/Laravel backend files (which remain unchanged in the repo).
- "Real OIDC/SAML/SCIM integration" — implemented protocol-correct surface with a **demo IdP** path that simulates the ceremony end-to-end (so the flow is functional without provisioning Okta/Entra). Real-provider config fields exposed in Sign-in & Wizard.
- AIOS bundle = real signed ZIP with SHA-256 + Ed25519 signature on download.
- Design: electric cyan + SpiderNet orange on deep navy (`#070A12`), per problem statement.

## Personas
- CIO / CTO — enterprise AI adoption with governance.
- CISO — identity, MFA, encryption, audit, incident response.
- IT admin — tenant setup, SSO, SCIM, RBAC, provisioning.
- Data governance lead — residency, retention, access policies.
- Integration engineer — APIs, connectors, SDK, AIOS bundle install.

## Architecture (this iteration)
- **Frontend** (new): React (CRA) at `/app/frontend`, Tailwind, Geist Sans + Geist Mono via `@fontsource`. Pages: Landing, SignIn, Register Wizard, Cockpit (Overview, Tenants, AccessControl, Connectors, AiosDownloads, Audit, Anomaly, Developer Portal, Security, Support).
- **Backend** (extended): FastAPI at `/app/backend/server.py` + new `enterprise_api.py` module. Routes under `/api/enterprise/*` (registration, SSO/Magic/TOTP/WebAuthn demo, SCIM provisioning, AIOS bundle ZIP generation + download + verify, tenants, connectors, audit, cockpit overview). SCIM 2.0 endpoints at `/api/scim/v2/*` (Users + ServiceProviderConfig, bearer-token guarded).
- **Persistence**: MongoDB (`MONGO_URL`, DB `spidernetos`) — collections: `enterprises`, `tenants`, `connectors`, `audit_events`, `aios_bundles` (with embedded zip payload for demo), `scim_tokens`, `magic_tokens`, `deployments`, `sso_sessions`, `scim_users`.
- **AIOS bundle signing**: Ed25519 in-process key (production should be HSM/KMS-backed). Bundle ZIP contains MANIFEST.json, components/*/COMPONENT.json + payload.bin, README, platform installer (install.sh / install.ps1 / docker-compose.yml). Download response headers expose `X-SpiderNet-SHA256` and `X-SpiderNet-Signature`.
- **Auth in this build**: JWT (HS256) sessions stored in `localStorage` keys `sn_access_token`, `sn_user`, `sn_tenant`. Real OIDC/SAML provider exchange + WebAuthn ceremony are **demo-pathed** (clearly marked); the protocol surface (config fields, redirect URI, SCIM token, etc.) is plumbed.

## What's been implemented (12 May 2026)
- Landing page: MarketingHeader (sticky, mobile drawer), animated SVG network-graph hero, hero copy + 3 CTAs, trust bar, problem statement, 6-pillar feature grid, three-plane AIOS architecture SVG, 6-tab use-case explorer, security & compliance grid, failure-hardening matrix, integrations catalog (12 connectors), developer portal preview with terminal mockup, Hannah AI customer story, 3-tier pricing, final CTA with 10-step onboarding chips, footer.
- Sign-in page: tile selector for SSO / Magic link / WebAuthn / TOTP, demo IdP path completes & redirects to Cockpit, magic link returns dev token to consume, TOTP demo bypass `000000`.
- Enterprise Registration Wizard: 10 steps with left-rail vertical progress, sticky CTA, real backend persistence for steps 1 (start), 2 (verify-domain), 3 (create-tenant), 6 (SCIM token), 9 (bundle generate), 10 (deploy start). Steps 4/5/7/8 capture intent locally.
- Cockpit: layout with sidebar + tenant switcher, Overview metrics + sparkline + quick actions, Tenants table, RBAC roles + members + capability badges, Connectors grid, AIOS Downloads (new bundle + list + verify cards + installer guide), Audit timeline with filter, Anomaly dashboard, Developer Portal API explorer (sends real requests), Security policy panels, Support escalation.
- Backend enterprise API + SCIM 2.0 skeleton, all routes data-testid'd in frontend.

## Test results (iteration_2.json)
- Backend: 24/24 pass.
- Frontend: 36/36 Playwright assertions across two runs (landing, sign-in flows, full wizard transition, cockpit Overview + 9 sub-routes, AIOS bundle creation, audit, developer portal).
- No blocking issues. One minor doc nit (SCIM response field name) — non-blocking.

## Prioritized backlog
- P1: Real OIDC code-exchange + ID token verification using Authlib (when a real tenant configures provider creds).
- P1: Real SAML 2.0 ACS using `python3-saml` (cert validation, signed assertions).
- P1: Real WebAuthn ceremony with `fido2` library (currently demo-stub).
- P2: Step-up authentication for sensitive ops (tenant delete, bundle generate, key rotation).
- P2: SCIM `/Groups` + PATCH operations for full Okta/Entra compatibility.
- P2: AIOS bundle storage moved out of MongoDB into object storage (S3/MinIO).
- P3: Internationalization (locale files), accessibility audit pass (WCAG AAA on critical security copy), reduced-motion polish.
- P3: Multi-tenant tenant switcher with real workspace switching.
- P3: i18n for the landing copy.

## Files of note
- `/app/frontend/src/pages/LandingPage.jsx`
- `/app/frontend/src/pages/RegisterWizard.jsx`
- `/app/frontend/src/pages/SignInPage.jsx`
- `/app/frontend/src/cockpit/*.jsx`
- `/app/backend/enterprise_api.py`
- `/app/backend/server.py` (mounts enterprise + scim routers)
- `/app/design_guidelines.json`

## Next action items
- Wire a real OIDC provider (Authlib + tenant-stored client creds) and remove the demo bypass for production tenants.
- Hook in real email delivery (SendGrid/Resend) for magic links.
- Move AIOS bundle payload to object storage; sign bundles with KMS instead of in-memory key.

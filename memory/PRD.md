# SpiderNetOS — Product Frontend PRD

## Original problem statement
Build a complete modern landing page and frontend for SpiderNetOS — the AI Operating System for business automation. Includes hero + value proposition, security & compliance proof, integrations catalog, developer portal preview, customer story (Hannah AI), pricing/SLA tiers, a sandboxed sign-in experience with enterprise authentication options (OIDC/SAML, SCIM, WebAuthn, TOTP, magic link), a 10-step Registration Wizard, Cockpit admin (tenants, RBAC, connectors, AIOS downloads, audit, anomaly), and a signed AIOS ZIP bundle download with SHA-256 + signature verification.

## Positioning (current, 12 May 2026)
- **Headline:** "The AI Operating System for business automation."
- **Pill:** "AI Operating System"
- **Subcopy:** "Connect your people, data, applications, and AI agents through one operating layer. Deploy AIOS components into any environment — startups, teams, or enterprises — with signed bundles, identity, RBAC, observability, and lifecycle controls built in."
- **Primary CTA:** "Get started free"
- Shifted from prior "enterprise-only / governed automation" framing to broader **business automation** framing — still serves enterprises (security depth, compliance, audit, SCIM) but no longer excludes mid-market and team-scale customers from the top of funnel.

## User-confirmed choices (12 May 2026)
- Scope: full end-to-end (landing → register → cockpit). Focus = landing + registration/onboarding.
- Build on top of existing repo at `/app`.
- "Real OIDC/SAML/SCIM integration" — implemented protocol surface with a demo IdP path that simulates the ceremony end-to-end (so the flow is functional without provisioning Okta/Entra). Real-provider config fields exposed in Sign-in & Wizard.
- AIOS bundle = real signed ZIP with SHA-256 + Ed25519 signature on download.
- Design: electric cyan + SpiderNet orange on deep navy (`#070A12`).
- **(12 May 2026 add)** Public Trust Center at `/trust` wired to live audit + anomaly + bundle counts.
- **(12 May 2026 add)** Messaging shift from "enterprise AI / governed" to "AI / business" framing across landing, header, footer, sign-in.

## Personas
- Founder / Ops lead (NEW with broader positioning) — wants to wire AI into their stack without re-platforming.
- CIO / CTO — adoption with governance.
- CISO — identity, MFA, encryption, audit, incident response.
- IT admin — tenant setup, SSO, SCIM, RBAC, provisioning.
- Data governance lead — residency, retention, access policies.
- Integration engineer — APIs, connectors, SDK, AIOS bundle install.

## Architecture
- **Frontend**: React (CRA) at `/app/frontend`, Tailwind, Geist Sans + Geist Mono. Pages: Landing, **TrustCenter**, SignIn, RegisterWizard, Cockpit (Overview, Tenants, AccessControl, Connectors, AiosDownloads, Audit, Anomaly, DeveloperPortal, Security, Support).
- **Backend**: FastAPI at `/app/backend/server.py` + `enterprise_api.py`. Routes under `/api/enterprise/*` and SCIM at `/api/scim/v2/*`.
  - **(NEW)** `GET /api/enterprise/trust/summary` — uptime series, ops counters from audit/anomaly/bundle collections, compliance roadmap, sub-processors, incident response posture.
  - **(NEW)** `GET /api/enterprise/trust/audit-sample` — redacted 10-event sample with real Ed25519 envelope.
  - **(NEW)** `GET /api/enterprise/trust/status` — public uptime/status pulse with 6 component subsystems.
- **Persistence**: MongoDB collections: `enterprises`, `tenants`, `connectors`, `audit_events`, `aios_bundles`, `scim_tokens`, `magic_tokens`, `deployments`, `sso_sessions`, `scim_users`.
- **AIOS bundle signing**: Ed25519 in-process key. Same key signs Trust Center audit samples → public can verify the same envelope format used for production exports.

## What's been implemented
- **Landing page** with refreshed business-automation messaging across hero, problem, platform, solutions, final CTA, footer.
- **Trust Center** (`/trust`): live status grid, 30-day uptime chart with SLA target line, compliance roadmap table (SOC 2 / ISO 27001 / GDPR / HIPAA / CSA STAR with progress bars + auditor names), signed-audit-export sample with SHA-256 + Ed25519 + copy buttons, security posture 8-card grid, sub-processors table, contact-security CTA. Footer "Trust Center" + "Status" links now point to `/trust`.
- **MarketingHeader** with 8 nav items (added "Trust" between Customers and Pricing).
- **Sign-in** with 4 methods, all functional in demo mode.
- **10-step Registration Wizard** end-to-end with real backend persistence.
- **Cockpit** layout + 10 sub-pages.
- **Backend** enterprise + SCIM 2.0 + Trust Center endpoints.

## Test results
- iteration_2.json: Backend 24/24 ✅, Frontend 36/36 ✅, no blockers.
- Code-review fixes applied (undefined-variable guard, silent-catch fix, stable React keys, unused imports, localStorage tradeoff documented in `api.js`).
- Trust Center endpoints smoke-tested: uptime 99.815% on 30-point series, 5 compliance frameworks, 10-event signed sample with 128-char Ed25519 signature, 6 subsystem statuses operational.

## Prioritized backlog
- **P1 (carried)**: real OIDC/SAML code-exchange (Authlib / python3-saml); real WebAuthn ceremony (fido2); email provider for magic links (SendGrid/Resend).
- **P1 (new from review)**: httpOnly + SameSite=strict cookie session migration to remove localStorage XSS surface.
- **P2 (carried)**: move AIOS bundle payload to object storage; KMS-backed signing.
- **P2**: SCIM `/Groups` + PATCH operations for full Okta/Entra inbound compatibility.
- **P2**: real public status-page integration (StatusPage/Atlassian or Better Uptime ping checks) to replace deterministic series.
- **P3**: i18n / locale files, RTL layout, region-aware legal copy.
- **P3**: WCAG AAA audit pass for security-critical copy.
- **P3**: TypeScript migration starting from `src/lib/api.js` + shared components.

## Files of note
- `/app/frontend/src/pages/LandingPage.jsx`
- `/app/frontend/src/pages/TrustCenter.jsx` (NEW)
- `/app/frontend/src/pages/RegisterWizard.jsx`
- `/app/frontend/src/pages/SignInPage.jsx`
- `/app/frontend/src/cockpit/*.jsx`
- `/app/frontend/src/components/MarketingHeader.jsx`
- `/app/backend/enterprise_api.py` — trust router added end-of-file
- `/app/backend/server.py` (mounts enterprise + scim routers)
- `/app/design_guidelines.json`
- `/app/memory/test_credentials.md`


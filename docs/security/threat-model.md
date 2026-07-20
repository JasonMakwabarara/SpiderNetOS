# SpiderNet OS — Threat Model (STRIDE)

**Version:** 1.0 (Tier 1)
**Owner:** Platform / Security
**Last reviewed:** 2026-04-20
**Next review:** 2026-07-20 (quarterly) OR on any change to the data-flow diagram.

This document captures the Tier 1 threat model for SpiderNet OS using the **STRIDE** taxonomy (Spoofing, Tampering, Repudiation, Information Disclosure, Denial of Service, Elevation of Privilege). The scope is the pre-GA system as of the tier-1 security hardening commit.

---

## 1. Scope

In scope:

- Laravel API (`backend/`)
- Vue cockpit (`cockpit/`)
- Inference plane (`inference/main.py` — FastAPI)
- Intelligence plane (`intelligence/` — Python agents, bandit)
- Postgres (event_log, tenant data, projections)
- Redis (cache, queue, sessions, feature flags)
- Twilio voice webhooks (inbound/outbound)
- Soketi / Pusher realtime broadcast
- External LLM providers (OpenAI, Anthropic)

Out of scope (Tier 2/3):

- Kubernetes / Hetzner infra-layer threats (covered in the infra threat model when we migrate off docker-compose).
- Physical / supply-chain attacks on Hetzner itself.
- Post-quantum considerations.

---

## 2. Trust boundaries

```
┌────────────┐   HTTPS   ┌────────────────┐   internal    ┌────────────────┐
│  Browser   │──────────▶│  Laravel API   │──────────────▶│  Inference     │
│ (cockpit)  │◀──────────│  (backend)     │◀──────────────│  (Python)      │
└────────────┘  Pusher   └───────┬────────┘   HTTP        └────────────────┘
     ▲                            │                               │
     │ WSS                        │ Postgres/Redis                │ OpenAI/
     │                            ▼                               │ Anthropic
┌────────────┐             ┌────────────┐                         ▼
│  Soketi    │             │  Postgres  │                  ┌──────────────┐
└────────────┘             │   Redis    │                  │ LLM provider │
     ▲                     └────────────┘                  └──────────────┘
     │                            ▲
     │                            │ webhooks (signed)
┌────────────┐                    │
│   Twilio   │────────────────────┘
└────────────┘
```

Trust boundaries (numbered in the STRIDE table):

| # | Boundary | Direction |
|---|---|---|
| B1 | Internet → Laravel API | untrusted → trusted |
| B2 | Browser cockpit → Laravel API | authenticated user → tenant-scoped data |
| B3 | Twilio webhook → Laravel API | signed by shared secret |
| B4 | Laravel API → Postgres | trusted infra |
| B5 | Laravel API → Redis | trusted infra |
| B6 | Laravel API → Inference / Intelligence | trusted internal |
| B7 | Inference → LLM provider | egress over TLS |
| B8 | Admin user → Platform surface | privileged user → tenant metadata |
| B9 | Super-admin → Platform surface | privileged admin → all-tenant operations |

---

## 3. Assets

| Asset | Sensitivity | Where it lives |
|---|---|---|
| User credentials + sessions | Critical | `users`, Redis sessions |
| Personal access tokens | Critical | `personal_access_tokens` |
| Tenant event chain (tamper evidence) | Critical | `event_log` + `tenant_secrets` |
| Agent / flow / copy definitions | High | Postgres projections |
| Voice call transcripts | High (PII + business) | `voice_call_summaries` |
| LLM provider API keys | Critical | `.env` / Vault |
| Twilio auth token | Critical | `.env` / Vault |
| Bandit arm state | Medium | `atlas_copy_arms` |
| Budget / cost state | Medium | `usage_daily_aggregates` |
| Feature flag state | Medium | Redis + `feature_flags` |
| admin_audit_log | High (SOC 2 evidence) | Postgres, append-only |

---

## 4. STRIDE table (Tier 1 focus)

### 4.1 Spoofing

| ID | Threat | Boundary | Mitigation (current) | Residual risk |
|---|---|---|---|---|
| S1 | Attacker submits forged Twilio webhook | B3 | `VerifyTwilioSignature` middleware (`@/c:/Users/HP/CascadeProjects/windsurf-project-2/backend/app/Http/Middleware/VerifyTwilioSignature.php`) verifies HMAC-SHA1 signature using `TWILIO_AUTH_TOKEN`; all `/api/voice/*` routes gated | Low — rotation required on token leak (runbook §6) |
| S2 | Credential stuffing on `/api/auth/login` | B1 | `throttle:auth` 5/min IP-keyed (Batch B); password hashing via Laravel bcrypt | Medium — add device-fingerprint + hCaptcha in Tier 3 |
| S3 | Session hijack via stolen cookie | B2 | `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax` (Batch A); HttpOnly; Sanctum CSRF | Low in prod; Medium if a user is on HTTP dev environment |
| S4 | Inter-service call forgery (cockpit → inference direct) | B6 | Inference not exposed publicly in docker-compose; only accessible via backend in Tier 2 k3s | Medium until network policies ship (Tier 2) |
| S5 | LLM-provider impersonation (DNS or MITM) | B7 | TLS certificate pinning not in place; relies on system CA trust | Medium — accept for Tier 1 |

### 4.2 Tampering

| ID | Threat | Boundary | Mitigation (current) | Residual risk |
|---|---|---|---|---|
| T1 | Direct DB write bypassing event_log | B4 | DB credentials are app-only; no human DB access in prod; any out-of-band write is detected by `VerifyEventChain` HMAC check | Low |
| T2 | Replay / reorder of event_log rows | B4 | Hash-chained events (`previous_hash` + `sequence_num` UNIQUE) | Low |
| T3 | Projector compromise rewrites materialised state | B4 | Projections are reproducible from event_log via `EventStore::rebuildProjection()`; divergence caught by `ReplayDivergenceService` | Low |
| T4 | Man-in-the-middle on cockpit ↔ API | B2 | HSTS + HTTPS-only in prod (Batch B); HttpOnly cookies; CSP frame-ancestors=none | Low |
| T5 | Parameter tampering on platform routes | B9 | `role:super_admin` + `step.up` re-auth on all mutating routes; step-up requires a real TOTP second factor once the user enrols (RFC 6238, `MfaService`), password-only otherwise. Enforcing enrolment for platform admins is a policy follow-up. | Low |
| T6 | Cross-tenant data write via smuggled tenant_id | B2 | `ResolveTenant` middleware fixes tenant from authenticated user; all queries scoped | Low. **Verify at pen-test time.** |
| T7 | Prompt-injection in LLM input alters downstream actions | B7 | Approval engine requires HIL for destructive actions; tool allowlist per agent | Medium. Tier 3 will add output-constraint classifier. |

### 4.3 Repudiation

| ID | Threat | Boundary | Mitigation | Residual risk |
|---|---|---|---|---|
| R1 | User denies performing an admin action | B8 | `admin_audit_log` (append-only, includes user_id, action, ip, user_agent); `event_log` hash chain for tamper evidence | Low |
| R2 | Super-admin denies flipping a feature flag | B9 | `PlatformController::putFlag` writes to `admin_audit_log` with `step.up` proof of re-auth; `X-Request-ID` on every response | Low |
| R3 | Impersonation session confused with user's own actions | B9 | Impersonation events tagged in `admin_audit_log` with actor + subject user_ids; cockpit UI shows impersonation banner | Low |

### 4.4 Information Disclosure

| ID | Threat | Boundary | Mitigation | Residual risk |
|---|---|---|---|---|
| I1 | CORS wildcard exposes API to any origin | B2 | Explicit origin allowlist via `CORS_ALLOWED_ORIGINS` (Batch A) | Low |
| I2 | `APP_DEBUG=true` leaks stack traces | B1 | Default flipped to `false` (Batch A) | Low — enforce at CI (add check in Tier 2) |
| I3 | Error responses leak internals | B1 | Custom exception handler; security headers forbid framing | Medium — audit `App\Exceptions\Handler` at pen-test |
| I4 | Voice transcripts contain PII | B4 | Encrypted at rest via Postgres; DB-level encryption in Tier 2 | Medium until Vault-managed KMS (Tier 2) |
| I5 | LLM provider logs PII | B7 | Provider ToS opts out of training; no PII-scrubbing currently — Tier 3 adds redaction | Medium |
| I6 | Response headers leak server info | B1 | `X-Powered-By` still emitted — remove in Batch E (Traefik strip) | Low |
| I7 | Cross-tenant read via query-param manipulation | B2 | `ResolveTenant` + scoped Eloquent queries | Low. Verify at pen-test. |
| I8 | Memory graph embeddings leak content across tenants | B4 | Embeddings are per-tenant scoped in `memory_nodes.tenant_id` | Low |

### 4.5 Denial of Service

| ID | Threat | Boundary | Mitigation | Residual risk |
|---|---|---|---|---|
| D1 | Login flood | B1 | `throttle:auth` 5/min IP (Batch B) | Low — add Traefik-level global throttle in Batch E |
| D2 | LLM cost bomb via Atlas chat | B7 | `throttle:atlas_chat` 30/min + `cost.limit` middleware enforces `COST_CEILING_DEFAULT` per tenant | Low |
| D3 | Voice webhook flood | B3 | Twilio signature verification gates entry; `throttle:voice_webhook` 600/min | Low |
| D4 | Queue exhaustion via malicious flow creation | B2 | `throttle:api` 60/min per user; Horizon max-processes cap; CostGovernor halts on budget | Low |
| D5 | Redis memory exhaustion | B5 | `maxmemory-policy allkeys-lru` in Redis config; all cache keys TTL'd | Low |
| D6 | Postgres slow-query storm | B4 | Read queries covered by indexes; EXPLAIN review in migrations; no user-supplied raw SQL | Medium — load test in Tier 2 |
| D7 | Broadcast storm via `/broadcasting/auth` | B2 | `ThrottleBroadcast` middleware (pre-existing) | Low |

### 4.6 Elevation of Privilege

| ID | Threat | Boundary | Mitigation | Residual risk |
|---|---|---|---|---|
| E1 | Regular user accesses `/api/admin/*` | B8 | `role:admin` middleware + DB-backed role table | Low |
| E2 | Admin accesses `/api/platform/*` | B9 | `role:super_admin` + `step.up` required | Low |
| E3 | Horizontal priv-esc (admin of tenant A touches tenant B) | B8 | Admin routes still pass through `tenant` middleware → scoped to caller's tenant only; platform-surface bypass is `super_admin`-gated | Low. Pen-test verification required. |
| E4 | Mass-assignment on user update | B8 | All Eloquent models use explicit `$fillable` / `$guarded`; validated via FormRequests | Low |
| E5 | SSRF via user-supplied webhook URL | B6 | Integrations use allowlisted providers; no generic webhook URL accepted from users in Tier 1 | Low |
| E6 | Server-side template injection in flow definitions | B6 | Flow expressions use a safelisted mini-grammar (no `eval`); agent prompt templates use Laravel Blade w/ escaping | Low |
| E7 | Agent tool allowlist bypass | B6 | Tool dispatch via `CheckAgentPermission` middleware; allowlist stored in agents table | Medium — audit every new tool at review |

---

## 5. Tier 1 action items tracked elsewhere

| Item | Batch | Status |
|---|---|---|
| CORS fail-closed | A | ✅ |
| APP_DEBUG default false | A | ✅ |
| APP_KEY out of `.env.example` | A | ✅ |
| Security headers | B | ✅ |
| Rate-limit tiers | B | ✅ |
| Cockpit dev CSP | B | ✅ |
| `composer audit` / `npm audit` / `pip-audit` / Semgrep / gitleaks in CI | C | ✅ |
| Dependabot weekly | C | ✅ |
| Key-rotation runbook | D | ✅ (this doc's sibling) |
| Vault + ESO | E | pending |
| Traefik rate-limit + `Server` header strip | E | pending |

---

## 6. Findings deferred to later tiers

| # | Finding | Planned tier | Rationale |
|---|---|---|---|
| T7 | Prompt-injection counter-measures (output classifier) | Tier 3 | Requires the agent-router work already scoped for T3.3 |
| I4 | Voice transcript encryption with Vault-managed KMS | Tier 2 | Follows Vault + ESO ship in Batch E |
| I5 | LLM provider PII redaction (inbound) | Tier 3 | Heavy cost/latency tradeoff; needs user-opt-in tiering |
| D6 | Postgres slow-query load test | Tier 2 | Part of k6 load test suite (§T2.6) |
| S5 | TLS pinning to LLM providers | Tier 3 | Low ROI pre-GA; adds brittle ops burden |

---

## 7. Pen-test scope

External pen-test (gate before Tier 2) must exercise, at minimum:

1. Horizontal privilege escalation across tenants (T6, I7, E3)
2. Super-admin surface authz (E2)
3. Impersonation flow (R3)
4. CSRF on state-changing routes (T4)
5. Rate-limit bypass attempts (D1, D2, D3)
6. Twilio signature forgery (S1)
7. Prompt-injection smoke tests on Atlas chat (T7)
8. CORS / CSP bypasses (I1)
9. Session fixation + hijack (S3)
10. Arbitrary file upload (if any upload surface exposed — currently none)

Report must include severity per finding (Critical / High / Medium / Low / Informational). Tier 2 kickoff blocked on zero Critical + zero High findings.

---

## 8. Review cadence

- **Quarterly** — full re-walk, update STRIDE table.
- **On every new external surface** (new controller, new webhook, new provider integration) — incremental review, PR checkbox.
- **After every incident** — update residual-risk column and add post-mortem link.

Change log maintained at the top of this file.

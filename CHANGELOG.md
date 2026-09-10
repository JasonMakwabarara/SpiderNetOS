# Changelog

## 2026-09-10 — Partner outreach, PR3: inbound email (IMAP), classification, operator replies

Replies now come back into SpiderNet (stacked on PR2). Off by default: the poller
runs only when `outreach.inbound_poll` is on for the tenant and the mailbox has an
IMAP password.

- **IMAP reader seam**: `ImapMailboxReader` (bound to `WebklexImapReader`, new dependency
  `webklex/php-imap`), `InboundMessage` DTO. `PollPartnerMailboxJob` every two minutes /
  `outreach:poll-inbox`: fetch since the last UID (never flags mail as read), dedupe on
  Message-ID, remember the high-water mark in `settings.outreach.mailbox`.
- **Classification** (`InboundEmailClassifier` + `DsnParser`): bounce (RFC 3464 report or
  mailer-daemon with a status), auto-responder (Auto-Submitted, X-Autoreply, Precedence,
  List-Id, OOO subjects), STOP/unsubscribe, decline, reply; quoted history and signatures
  are stripped so only what the creator wrote is stored.
- **Matching** (`ThreadMatcher`): plus-address token in Reply-To first, then our own
  Message-IDs via In-Reply-To/References, then the sender address; all tenant-scoped.
- **Effects** (`InboundIngestor`): hard bounce → prospect `bounced` + consent `bounced`;
  soft bounce → retry tomorrow; STOP → `unsubscribed` + consent `stopped`, never wakes the
  bot; reply/decline → prospect `replied`, Lead `engaged`, event
  `conversation.message.received` tagged `bridge=laravel_outreach`; auto-replies and our own
  mail are recorded/ignored; unmatched mail is logged, nothing stored.
- **Operator reply**: `POST /api/sales/partners/{id}/reply` sends a human reply through the
  tenant mailbox with threading headers and the legal footer (`OutreachSender::sendOperatorReply`,
  which now shares the mailable builder with the sequence; threading follows both directions),
  or queues a DM draft. Cockpit thread view (`/sales/partners/:id`).
- `zoho_mail` connector test now also logs in over IMAP when an IMAP password is stored.
- Suites: `InboundEmailClassifierTest` (CiFast), `tests/Feature/Outreach/InboxPollTest`.

## 2026-09-10 — Partner outreach, PR2: prospects, import, tenant mailer, sequence, DM queue

The outreach engine itself (stacked on PR1). Still off by default: nothing sends until
`outreach.sending` is on for the tenant and a partner mailbox is connected.

- **Prospects**: `partner_prospects` (1:1 with `leads`; platform, handle, canonical
  profile URL + hash, invite token, lifecycle status, sequence position, claims) and a
  generic `webhook_receipts` ledger; `conversation_messages` gains subject, RFC
  Message-ID / In-Reply-To / References, classification, draft bookkeeping and `sent_at`
  (partial unique on tenant + Message-ID for inbound dedupe).
- **Import**: `outreach:import {tenant} {csv}` and `POST /api/sales/partners/import` read
  Affonso Finder shortlist exports (BOM-safe), dedupe on the normalised profile URL, and
  re-imports never touch email, status or timestamps. Finder-supplied emails are accepted
  through `EmailValidator` (syntax, role/disposable, MX) with a `legitimate_interest_b2b`
  consent record; everyone else parks in `needs_email`.
- **Sending**: `ProcessOutreachStepsJob` (every minute) / `outreach:send-due` runs one tick
  per tenant: DM drafts for prospects without email, retirement after the last call, then
  due email steps inside the warm-up / hourly / min-gap budget and outside quiet hours.
  Every send is claimed first (conditional UPDATE) and goes out AS the tenant mailbox via
  `TenantMailerFactory` with our own Message-ID, threading headers, `List-Unsubscribe`
  (mailto + one-click) and a CAN-SPAM/GDPR footer. `MessageDispatchService::send()` gained
  an `$options` passthrough and a `manual_dm` channel (operator drafts, never sent by us);
  `EmailChannel` sends a prebuilt Mailable through the tenant mailer and reports the real
  Message-ID.
- **Bridge gate**: `ConversationReplyBridgeProjection` skips events tagged
  `bridge=laravel_outreach`, so outreach replies never reach the Python CRM agent.
- **API** (`/api/sales/partners/*`, sales-crm gated): list/show/patch prospects, import,
  DM queue + mark-sent + paste-reply, pause/resume/retire, settings (validated, admin),
  manual dry-run tick. Public `GET|POST /api/public/outreach/unsubscribe/{token}`.
- **Cockpit**: Partners list (import, filters, inline email), DM queue (copy / mark sent /
  paste reply), Outreach settings; card on the Sales home.
- Suites: `tests/Unit/Outreach` (CiFast), `tests/Feature/Outreach/{ProspectImportTest,
  OutreachSendTest, PartnerApiTest}`.

## 2026-09-10 — Partner outreach, PR1: tenant bootstrap + Affonso / mailbox connectors

Groundwork for running Hannah AI's affiliate-recruitment outreach on SpiderNet
(tenant `hannah-ai`). Nothing sends yet; every `outreach.*` flag ships `off`.

- **`php artisan outreach:tenant {slug} --name= --admin-email= --automation-level=assisted`**
  creates or repairs an outreach tenant in one idempotent step: tenant row with
  onboarding marked complete (so the API gate opens), admin user (random
  password, first login via the reset flow), cost budget row, event-signing key,
  `settings.outreach` defaults (program facts, caps, 3-step sequence, reply mode
  `approve`), and the sales-crm pack entitlement through
  `spidernet:pack-install --grant` (no Dodo purchase). Pack agents stay inactive.
- **Connectors**: `affonso` (API key + program id; actions `find_affiliate` and
  `create_affiliate`, idempotent on email; stores the webhook signing secret for
  the later webhook receiver) and `zoho_mail` (tenant-owned SMTP/IMAP mailbox;
  connecting sends a test email to the From address). Credentials are encrypted
  in `tenant_secrets` exactly like the existing connectors.
- `TenantMailerFactory` builds an on-demand SMTP mailer from the tenant mailbox
  credentials, so outreach mail is sent AS the tenant, not through the platform
  transport. `AffonsoClient` wraps the Affonso REST API (list/find/create
  affiliates, portal token).
- Suites: `tests/Feature/Outreach/TenantBootstrapTest`,
  `tests/Feature/Outreach/OutreachConnectorsTest` (both run on sqlite).

## 2026-08-06 (later) — Enterprise sign-in methods wired to Laravel

The marketing-site sign-in page's SSO / magic-link / TOTP / WebAuthn tabs called
`/api/enterprise/auth/*` routes that existed only in the FastAPI dev mock — every
non-password method 404'd in prod. Now implemented in
`EnterpriseAuthController` (Laravel, canonical in prod):

- **TOTP login** (real, prod-enabled): users who enrolled TOTP in the cockpit can
  sign in with email + authenticator code. NOTE: this deliberately makes an
  enrolled TOTP secret a single sign-in factor (the page offers it as a
  first-class method); kill-switch `ENTERPRISE_TOTP_LOGIN_ENABLED`, per-email
  throttle on top of the IP tier.
- **Magic link** (real, prod-enabled): single-use 30-min emailed links
  (`magic_links` table, hashed tokens; email via BrandedMail). Doubles as the
  activation path for registration-created admins who never set a password.
  ⚠️ Inbox delivery requires SMTP (`MAIL_*`) — with the default `log` mailer the
  link lands in `laravel.log`. `/sign-in?magic_token=...` is consumed by a new
  SignInPage effect (token stripped from the URL before verify).
- **SSO / WebAuthn**: honest `{"detail": ...}` 400s until real IdP/passkey
  support lands; `GET sso/callback` (the published SP redirect URI) redirects to
  `/sign-in?sso_error=...` — never issues a session.
- **Demo flows** (demo SSO IdP, WebAuthn stub, TOTP `000000`, `dev_link` in
  magic-link responses) are gated by `ENTERPRISE_DEMO_AUTH` — default ON outside
  production, OFF in prod — and only ever sign in EXISTING users (the mock's
  fabricated identities were an auth bypass and were not ported).
- All error bodies on these routes (and `/api/auth/login` failures) now carry the
  `detail` key the SPA reads. Suite: `tests/Feature/Enterprise/EnterpriseAuthTest`
  (26 tests).

## 2026-08-06 — Atlas chat pipeline verified LLM-backed (#88), repo hygiene (#89)

### Atlas chat (#88)
- Confirmed the canonical `AtlasController::chat()` routes every message
  through `AtlasDiscoveryService` (absorb + evaluate) → `AtlasClarityGate`
  (clarify/confirm) → `MetaPlanner::processAtlasRequest` →
  `AtlasJarvisAugmentor`/OpenJarvis (`/v1/ask`, flag `atlas.openjarvis`,
  default on) → `TransformationEngine` contract. The keyword-matching
  implementation the issue cites lived only in the stale `backend/backend/`
  duplicate tree (since deleted); there is no `/api/agents/chat` regex twin.
- `tests/Feature/Atlas/AtlasChatPipelineTest` pins the acceptance criteria:
  free-form messages get a dynamic pipeline response (never a canned
  command list), discovery/clarity are invoked on the chat path, and
  slash commands keep their fast path.
- Correction to the 2026-06-22 entry below: its "Atlas discovery mode in
  `AtlasController::chat()`" claim is accurate for the canonical tree as of
  this date; it predated the wiring in the now-removed duplicate copy.

### Repo hygiene (#89)
- Removed duplicated nested trees (`docs/docs`, plus `backend/backend` and
  `cockpit/cockpit` upstream; `v1/v1`, `inference/inference`,
  `integrations/integrations`, `deploy/deploy`,
  `hermes-runtime/hermes-runtime` verified identical/stale against
  top-level by blob hash) and `Atlas.vue.backup*` files; `.gitignore`
  deduplicated with backup-file patterns added.

### Prod fixes (hotfix/registration-cockpit, deployed 2026-08-06)
- CSRF 419 on first browser POST: public funnel + login routes exempted
  (`bootstrap/app.php`) — registration and first-visit sign-in work again.
- "Opening Cockpit…" hang: cockpit now also deployed nested at
  `spidernetos.com/cockpit/` (same-origin auth handoff), built with base
  `/cockpit/`.
- Cockpit is an installable PWA (manifest + service worker + install
  prompt + offline page) with a TWA/APK scaffold (`cockpit/PWA.md`).

## 2026-06-23 — Atlas inference plane + real flow execution

### Inference (Gemma via Ollama)
- `ollama` + `inference` services in `docker-compose.unified.yml` (inference on `:9000`)
- `POST /v1/classify` on inference plane for `AtlasIntentCompiler`
- `ATLAS_INTENT_CONFIDENCE=true` enables confidence-driven clarity gate
- `SPIDERNET_PROMPT_ENHANCER_MODEL=gemma2:2b` for LLM contracts (`metadata.source: llm`)
- One-time model pull: `docker compose exec ollama ollama pull gemma2:2b`

### OpenJarvis bridge
- Inference-plane fallback when `OPENJARVIS_URL` is unset (`INFERENCE_URL` on bridge)
- Token/cost passthrough on bridge health (`inference_reachable`)

### Real automation execution
- `POST /api/flows/quick-create` — first-win templates with generated DAG
- `DagExecutionService` wired to `FlowController::execute` via `NodeActionRunner`
- `execution_dag_nodes` / `execution_dag_edges` tables for runtime DAG state
- `ExecuteFlowJob` + `DispatchScheduledFlowsJob` with `schedule:work` scheduler
- `AtlasController::executePlan()` creates flow + runs DAG execution
- First-win wizard and Sales home use `quick-create` then `execute`

## 2026-06-22 — Customer-first AIOS (Phases 1–5)

### Pack growth loop
- `PackGrowthService` — maps industry profile, pain points, usage signals, and feedback into pack relevance scores
- `tenant_pack_signals` table — pack views, installs, route visits, Atlas suggestions, thumbs up/down
- Personalized catalogue (`relevance_score`, `growth_reason`, industry-tailored outcomes)
- `GET /api/feature-packs/recommendations`, `POST /api/feature-packs/feedback`, `POST /api/feature-packs/signals`
- Cockpit Feature Packs UI: recommended strip, fit %, outcome feedback

### Backend
- `POST /api/feature-packs/{id}/install` — self-serve pack install via `FeaturePackInstaller`
- Enhanced `GET /api/feature-packs/catalogue` with `customer_outcomes`, agent/flow counts, `entry_path`
- `GET/PUT /api/business-profile` — tenant business learning profile
- `GET /api/compliance/obligations` — universal SME compliance discovery
- Atlas discovery mode in `AtlasController::chat()` via `AtlasDiscoveryService`
- **Atlas trust gate** (`AtlasClarityGate`) — clarify when unsure, confirm before consequential actions, trust accrual per intent in `learned_signals`
- `POST /api/atlas/confirm` — proceed/cancel pending actions (`atlas.confirmation.resolved` events)
- Migration: `tenant_business_profiles`

### Frontend
- Billing vs Financial OS copy separation; Financial OS nav in sidebar and command bar
- Feature Packs: install button, value outcome bullets, post-install redirect
- New routes: `/sales`, `/compliance`, `/operate/first-win`
- Atlas discovery question chips and suggested-next card
- Atlas confirm-before-act card (Proceed / Not yet) wired to `/api/atlas/confirm`
- Hannah guidance: first-win wizard and discovery prompts

### Feature packs
- `sales-crm` — generic Sales & CRM OS
- `compliance-radar` — universal compliance discovery pack

### Contract verification
- Extend `scripts/verify-unified.ps1` for new endpoints and live cockpit bundle parity
- Run `npm run build` in `cockpit/` after UI changes, then rebuild frontend container

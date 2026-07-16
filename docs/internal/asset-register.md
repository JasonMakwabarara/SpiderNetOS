# Asset register

Priestley's Assets A: the business (and this project) should run more on assets than raw activity each quarter. This is the project-level equivalent of the tenant-facing `business_assets` table (`GET /api/operating/assets`) — durable things that keep doing work without anyone re-doing the work that made them.

An asset earns a place here when it has *permanence*: it still helps in a year even if nobody touches it again.

## 2026-Q3

| Asset | Type | What it does without further effort |
|---|---|---|
| `packages/feature-packs/sales-crm/` (Lead-to-Sale Funnel bundle) | Feature pack | Sellable, installable vertical: discovery interview, script drafting, email+WhatsApp funnel |
| `packages/feature-packs/sales-crm/interview/questions.yaml` | Interview backbone | Reusable discovery flow for any future pack needing an onboarding interview |
| `App\Services\Integrations\DodoPaymentsAdapter` | Payment adapter | Reusable purchase/webhook plumbing for any future priced pack |
| `App\Services\Sales\FunnelSetupService` | Pipeline state machine | Purchased→interviewing→drafted→approved→live, reusable pattern for other "setup wizard → owner-approved artifact → activation" flows |
| `docs/internal/operating-model.md`, `3-1-90.md`, `awareness-list.md`, `asset-register.md` (this file) | Process docs | Priestley Five A's applied to the project itself, not just tenants |
| `tests/Unit/Payments/DodoPaymentsAdapterTest.php` | Test suite | Pure-crypto regression coverage for webhook signature verification, runs in CiFast |
| `tests/Feature/Sales/{LeadApiTest,FunnelSetupPipelineTest}.php` | Test suite | End-to-end regression coverage for the lead API and the full purchase→live pipeline |
| `docs/internal/awareness-list.md` findings (9 resolved) | Bug fixes | Approval system, agent-slug namespacing, STE column widths, and manifest-path bugs fixed once, not re-discovered per pack |
| `App\Services\DeploymentReadinessService` + `ReadinessGuidancePanel.vue` | Guidance mechanism | Replicates Hannah's guidance-panel pattern (tappable next-step items, forward-to-Atlas-chat) as a reusable deploy-readiness checklist — `GET /api/platform/readiness` (infra), `GET /api/sales/readiness` (tenant, auto-raises `awareness_items`). Reusable for any future pack's own go-live gaps, not just sales-crm. |

## How assets get registered automatically

`FunnelSetupService::activate()` writes a `business_assets` row for the tenant's approved sales script the moment a funnel goes live — this is the pattern for tenant-facing assets: register at the point something becomes durable (approved, published, activated), not at draft time.

## Review cadence

At the start of each 90-day cycle (see `3-1-90.md`), check: did this quarter produce more assets than it consumed in one-off activity? If a quarter shows zero new assets, that's an awareness item, not just a quiet quarter — `Jobs\WeeklyRhythmJob`'s tenant-facing equivalent already raises this automatically via `business_assets` grouped by quarter.

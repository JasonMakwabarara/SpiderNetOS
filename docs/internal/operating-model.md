# Operating Model — the Five A's

SpiderNetOS productizes Daniel Priestley's Five A's for tenants (see `packages/feature-packs/sales-crm/` and `GET /api/operating/*`). This document applies the same model to how the SpiderNetOS project itself runs, so the team dogfoods the framework it ships.

Unifying theme: **build a self-optimizing business operating system that lets an owner run a business without constant input.** Every change should move a tenant closer to that, not add a system they have to babysit.

## Alignment

Origin: SpiderNetOS started as an event-sourced core for a single tenant and grew into a multi-tenant, pack-extensible OS. The event log (`event_log`) and STE projections are the foundation everything else — including the Lead-to-Sale Funnel bundle — builds on.

Mission: every feature pack must make the owner do *less*, not more. If a pack requires daily hand-holding, it has failed its own thesis (see `docs/ROADMAP.md` — "the owner spends 5–10 minutes a week").

Vision: see `docs/ROADMAP.md` Phase 5 (Autonomous Mode GA).

Project 3-1-90: see `docs/internal/3-1-90.md`.

Cadence: a 90-day cycle review at the start of each `docs/ROADMAP.md` phase — reconnect with origin/mission/vision, review `awareness-list.md`, and set the next 90-day targets.

## Awareness

Awareness items are surfaced *before* they become incidents — see `docs/internal/awareness-list.md`. Anything found while building a feature that isn't in scope to fix goes there, not into a TODO comment that rots.

Rule: when you find something wrong while working on something else, don't silently fix unrelated code (raises blast radius) and don't silently ignore it either (loses the finding). Write it to the awareness list with enough context that someone else can act on it without re-deriving what you already know.

## Accountability

Numbers this project should track per area, mirroring the tenant-facing scoreboard (`GET /api/operating/scoreboard`):

| Area | Metric | Where it lives |
|---|---|---|
| CI health | CiFast pass rate | `.github/workflows/ci.yml` |
| Pack installs | Install success rate | `feature_packs.status`, `FeaturePackInstaller` |
| Purchase flow | Checkout → active entitlement conversion | `pack_entitlements` |
| Webhooks | p50/p99 latency, signature-reject rate | `VerifyDodoSignature`, `VerifyTwilioSignature` logs |
| Messaging | Send success rate (email/WhatsApp) | `conversation_messages.status` |

## Activity — the perfect repeatable week

Tenants get this via `Jobs\WeeklyRhythmJob` (Monday priorities, Friday check-in). For the project itself:

- **Monday**: 3–6 priorities for the week, tied to the current 90-day targets.
- **Friday**: done vs. planned, plus one **"locking antlers"** point — a place where the data (test results, usage, awareness items) creatively disagrees with a decision that was made. Write it down even if uncomfortable; that's the point of the exercise.

## Assets

The project's asset register mirrors `business_assets` (tenant-facing): things with permanence that keep working without further activity. See `docs/internal/asset-register.md`.

Examples already in this repo: `docs/feature-packs/SPEC.md` (lets anyone build a pack without asking), `SteEventMappingSeeder.php` (event routing that doesn't need re-explaining), the pack validator script (`scripts/validate_feature_pack.py`), this document.

## Future-state org chart

Priestley's repeatable structure — Head of Growth, Head of Delight, General Manager, with AI as the central brain — maps directly onto the sales-crm pack's dynamic agents (see `packages/feature-packs/sales-crm/pack.yaml` `roles:` block and `GET /api/operating/org-chart`):

| Role | Pack agent | Responsibility |
|---|---|---|
| Head of Growth | `growth` | Lead generation, campaigns, landing pages |
| General Manager | `crm` | Pipeline, conversations, day-to-day execution |
| Head of Delight | `retention` | Churn detection, win-back |
| — | `funnel_architect` | One-time: discovery interview + script drafting |

The tenant owner is the **Key Person of Influence** — the org chart surfaces them at the top, with Atlas as the central brain underneath, exactly as Priestley describes it for a small business built around one person's reputation and judgment.

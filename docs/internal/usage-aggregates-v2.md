# Usage Aggregates V2 — Developer Runbook

> Plan: `1776497457072-misty-planet` | Branch: `fix/blocker-a-usage-aggregates-atlas`

---

## Problem Summary

The `usage_daily_aggregates` table schema defined `total_calls` and `calculated_at` but
`AggregateUsageJob` was writing `request_count` + `updated_at`, causing:
- NOT NULL violation on insert (job failure)
- Empty Usage dashboard (reads return empty/500)
- 600+ `function json_extract(jsonb, unknown)` errors (MySQL functions on Postgres)

---

## What Changed (this PR)

| File | Change |
|---|---|
| `AggregateUsageJob.php` | Rewrote: Postgres jsonb operators, canonical columns, tokens, calculated_at, shadow diff |
| `ObservabilityController.php` | dailyUsage/monthlyUsage use `total_calls`, `to_char` for monthly; v2 contract response; 410 on v1 header |
| `cockpit/src/stores/usage.js` | Response path `daily_usage.breakdown` not `data.data`; `total_calls` not `request_count` |
| `cockpit/src/views/Usage.vue` | `day.total_calls`; Atlas copy surfaces wired |
| Migrations `000001`, `000002` | Reconcile table; add shadow_diffs; add Atlas copy tables |

---

## Shadow → Cutover Playbook

```bash
# Step 1: Enable v2 writer + shadow (48h)
php artisan feature:set atlas.usage_aggregates_v2 on
php artisan feature:set atlas.usage_aggregates_v2.shadow on

# Step 2: Wait 48h. Poll the gate (must return 0 for ≥ 24h):
php artisan tinker --execute="echo json_encode(app(App\Services\UsageAggregateShadow::class)->gateStatus());"

# Step 3: When ready_for_cutover=true AND consumer audit complete:
php artisan feature:set atlas.usage_aggregates_v2.shadow off
php artisan feature:set atlas.usage_aggregates_v2.cutover on

# Verify
curl -H "Authorization: Bearer $TOKEN" http://localhost:8000/api/usage/daily | jq .contract_version
# Expected: "2"
```

---

## Emergency Rollback (within 72h watch window only)

```bash
# Revert writer to legacy path
php artisan feature:set atlas.usage_aggregates_v2.rollback on
php artisan feature:set atlas.usage_aggregates_v2.cutover off

# DB rollback (drops canonical-only path, re-adds request_count column)
php artisan migrate:rollback --step=1
```

After 72h the DOWN migration no longer references `request_count` migrations safely.

---

## Backfill Existing Data

```bash
# Dry run first
php artisan usage:backfill --from=2026-01-01 --to=2026-04-17 --dry-run

# Execute (batched, idempotent)
php artisan usage:backfill --from=2026-01-01 --to=2026-04-17
```

---

## Schema Contract Tests

```bash
# PHP (in backend/)
./vendor/bin/phpunit tests/Feature/Usage/SchemaContractTest.php

# Python
python -m pytest intelligence/tests/ -v
```

---

## API Contract (v2)

`GET /api/usage/daily`
- Header: `X-Usage-Contract: 2` (returned by server)
- Sending `X-Usage-Contract: 1` → **HTTP 410 Gone**

Response:
```json
{
  "contract_version": "2",
  "daily_usage": {
    "breakdown": [
      { "date": "...", "resource_type": "...", "total_calls": 0, "total_tokens": 0, "total_cost": 0, "calculated_at": "..." }
    ],
    "daily_totals": [ { "date": "...", "total_calls": 0, "total_tokens": 0, "total_cost": 0 } ],
    "period_days": 30
  }
}
```

---

## Feature Flags Reference

```bash
php artisan feature:list
```

| Flag | Default | Purpose |
|---|---|---|
| `atlas.usage_aggregates_v2` | off | Gates new writer |
| `atlas.usage_aggregates_v2.shadow` | off | 48h dual-write + diff log |
| `atlas.usage_aggregates_v2.cutover` | off | Atomic flip to canonical |
| `atlas.usage_aggregates_v2.rollback` | off | Emergency revert |
| `atlas.copy.*` | on | Per-surface copy gates |
| `atlas.bandit.algo` | thompson | Bandit algorithm |
| `atlas.bandit.temperature` | 1.0 | Posterior temperature |
| `atlas.prompt_evolution` | off | Enable after D+7 |

---

*Generated: 2026-04-18*

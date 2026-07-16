# Atlas / SpiderNet OS — Observability Alert Reference

## Usage Aggregate Alerts

### `atlas.usage.schema_drift`
- **Trigger:** `SELECT COUNT(*) FROM information_schema.columns WHERE table_name='usage_daily_aggregates' AND column_name='request_count'` > 0
- **Severity:** Critical
- **Action:** Halt cutover; re-run reconciliation migration

### `atlas.usage.calculated_at_null`
- **Trigger:** `SELECT COUNT(*) FROM usage_daily_aggregates WHERE calculated_at IS NULL` > 0
- **Severity:** High
- **Action:** Run `php artisan usage:backfill --from=<earliest_null_date>`

### `atlas.usage.shadow_diff_open`
- **Trigger:** Open unresolved `usage_shadow_diffs` rows in last 24 h > 0
- **Severity:** High (blocks cutover)
- **Action:** Inspect diff rows; run `app(UsageAggregateShadow::class)->gateStatus()` via tinker

### `atlas.usage.aggregate_job_failure`
- **Trigger:** `AggregateUsageJob` dispatch returns failure (queue worker log)
- **Severity:** High
- **Action:** Check for Postgres jsonb errors; confirm `atlas.usage_aggregates_v2=on`

### `atlas.usage.json_extract_error`  *(legacy guard)*
- **Trigger:** Log line containing `function json_extract(jsonb, unknown) does not exist`
- **Severity:** Critical
- **Action:** Indicates the old job is still running; hard-deploy the new version

## Copy / Bandit Alerts

### `atlas.copy.trust_floor_breach`
- **Trigger:** Any selected variant has `trust_score < 0.80`
- **Severity:** High
- **Action:** Flip `atlas.copy.<surface>=fallback` for affected surface; freeze prompt-evolution

### `atlas.bandit.entropy_collapse`
- **Trigger:** `atlas_bandit_posterior_entropy{surface}` < 0.3 bits for > 1 hour
- **Severity:** Medium
- **Action:** Check for mode collapse; force-add experimental variant via backfill command

### `atlas.copy.TS_below_floor`
- **Trigger:** `atlas_bandit_selection_latency_ms` p95 > 120 ms
- **Severity:** Medium
- **Action:** Check atlas_copy_variants count; verify Postgres index on `(surface, status)`

## Contract / API Alerts

### `atlas.usage.unexpected_410`  *(post-cutover)*
- **Trigger:** Any `/usage/*` request returns 410 after D+2
- **Severity:** High
- **Action:** External consumer not migrated; provide hotfix PR + consumer inventory update

### `atlas.usage.latency_slo_breach`
- **Trigger:** `/usage/daily` or `/usage/monthly` p95 > 400 ms
- **Severity:** Medium
- **Action:** Check slow-query log; verify composite index on `(tenant_id, date)`

## Shadow Gate Check (manual, pre-cutover)
```sql
SELECT COUNT(*) FROM usage_shadow_diffs
WHERE detected_at >= now() - interval '24 hours'
  AND resolved_at IS NULL;
-- Must return 0 for >= 24 consecutive hours before flipping cutover flag
```

## Prometheus Metrics Reference
| Metric | Type | Labels |
|---|---|---|
| `atlas_bandit_selection_latency_ms` | histogram | `surface` |
| `atlas_bandit_posterior_entropy` | gauge | `surface` |
| `atlas_bandit_impressions_total` | counter | `surface`, `variant_id` |
| `atlas_bandit_arm_starvation_blocked_total` | counter | `surface` |
| `atlas_usage_shadow_diffs_open` | gauge | — |
| `atlas_usage_calculated_at_null` | gauge | — |

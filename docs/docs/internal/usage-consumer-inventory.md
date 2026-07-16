# Usage Consumer Inventory — V2 Migration Tracking (T9.5)

All consumers of `/usage/daily`, `/usage/monthly`, or the `request_count` column must be
migrated to the v2 contract before the `atlas.usage_aggregates_v2.cutover` flag is flipped.

**Owner:** `@backend`, `@pm`
**Gate:** All rows below must show `Status: ✅ Verified on staging` before cutover.

---

## Internal Consumers

| Consumer | Location | Migration PR | Status |
|---|---|---|---|
| Cockpit usage store | `cockpit/src/stores/usage.js` | This PR | ✅ Migrated |
| Cockpit Usage.vue | `cockpit/src/views/Usage.vue` | This PR | ✅ Migrated |
| ObservabilityController | `backend/app/Http/Controllers/ObservabilityController.php` | This PR | ✅ Migrated |
| AggregateUsageJob | `backend/app/Jobs/AggregateUsageJob.php` | This PR | ✅ Migrated |

---

## External / Dashboard Consumers

| Consumer | Query / Field | Owner | Migration PR | Status |
|---|---|---|---|---|
| Grafana — Usage dashboard | `SELECT request_count FROM usage_daily_aggregates` | SRE | — | ⬜ Not started |
| Metabase — Tenant cost report | `request_count` column | Analytics | — | ⬜ Not started |
| Partner integration (if any) | `/api/usage/daily` → `request_count` key | PM | — | ⬜ Not started |

> **Action required:** Owners of each ⬜ row must update their query/integration and verify on staging
> before the cutover date. File a GitHub issue per row and link the PR below.

---

## Cutover Gate Sign-off Checklist

- [ ] All above rows at ✅ Verified on staging
- [ ] `usage_shadow_diffs` open count = 0 for ≥ 24 h
- [ ] SchemaContractTest green in CI
- [ ] Cockpit Vitest suite green
- [ ] PHP unit tests green
- [ ] SRE confirmed: synthetic 410 alert wired
- [ ] PM signed off on release note copy (transformation-framed)

---

*Generated: 2026-04-18*
*Plan: `1776497457072-misty-planet`*

# Key Rotation Runbook

**Owner:** Platform / SRE
**Last reviewed:** 2026-04-20
**Applies to:** Tier 1 (pre-GA) and forward

Every secret below has a **rotation trigger**, **rotation procedure**, and **rollback path**. All rotations must be logged to `admin_audit_log` (automatic for PHP paths) and announced in `#spidernet-ops` with the runbook section used.

---

## 0. General principles

- **Grace windows.** All long-lived secrets keep the previous value valid for a defined grace period so in-flight workloads don't fail mid-request. The default grace is **24h** unless otherwise noted.
- **Order of operations.** Always: (1) provision new → (2) deploy with dual-read → (3) cut writes to new → (4) expire old after grace. Never delete the old value until monitoring shows zero reads against it.
- **Verification.** Every rotation ends with a documented smoke test.
- **Blast radius.** Rotations touching `APP_KEY`, DB, or per-tenant signing keys must happen during a declared maintenance window. All others are hot-rotatable.

---

## 1. `APP_KEY` (Laravel encryption key)

**Purpose:** encrypts session data, encrypted Eloquent casts, `Crypt::encryptString()` output (which includes `tenant_secrets.secret_value`).

**Trigger:**
- Annually (scheduled), OR
- Immediately if the value has leaked (e.g., accidental commit, repo exposure, developer laptop compromise).

**Grace:** Laravel supports `APP_PREVIOUS_KEYS` (already wired in `@/c:/Users/HP/CascadeProjects/windsurf-project-2/backend/config/app.php:14-18`). Encrypted payloads produced with the old key remain decryptable during the grace window.

**Procedure:**
1. Generate a new key locally: `php artisan key:generate --show` (do not write yet).
2. In the secrets store (Vault / ESO — Batch E) set:
   - `APP_KEY` = new value
   - `APP_PREVIOUS_KEYS` = previous value (comma-separated if chaining multiple rotations)
3. Roll the `api` + `worker` deployments. All pods now decrypt with either key, encrypt with the new one.
4. Wait 24h (or one full session lifetime × 2, whichever is longer).
5. Remove `APP_PREVIOUS_KEYS` and redeploy.

**Rollback:** move `APP_PREVIOUS_KEYS` back to `APP_KEY` and redeploy. Anything encrypted with the new key during the aborted rotation will be unreadable — acceptable only if the rotation is caught within minutes.

**Smoke test:**
```bash
kubectl exec deploy/api -- php artisan tinker --execute="echo decrypt(encrypt('ok'));"
# Must print: ok
```

---

## 2. Database credentials (`DB_USERNAME` / `DB_PASSWORD`)

**Trigger:** quarterly, or immediately on role compromise.

**Grace:** Postgres supports multiple roles; we rotate by creating a new role + password, re-pointing the app, then revoking the old role.

**Procedure:**
1. `CREATE ROLE spidernet_v2 LOGIN PASSWORD '<new>' IN ROLE spidernet;` (via DB admin).
2. Update `DB_USERNAME` / `DB_PASSWORD` in the secrets store.
3. Rolling restart of `api` + `worker` deployments.
4. Verify all pods report healthy on `/api/health`.
5. After 24h with no errors: `ALTER ROLE spidernet NOLOGIN;` (disable, don't drop — can be re-enabled for emergency rollback).
6. After 7 days: `DROP ROLE spidernet;`.

**Rollback:** `ALTER ROLE spidernet LOGIN;` and revert env.

**Smoke test:** `kubectl exec deploy/api -- php artisan db:show` must succeed.

---

## 3. Redis password (`REDIS_PASSWORD`)

**Trigger:** quarterly or on compromise.

**Grace:** Redis 6+ supports ACL users. We rotate by adding a new user, re-pointing the app, then disabling the old user.

**Procedure:**
1. `ACL SETUSER spidernet_v2 on ><new> ~* &* +@all` (Redis).
2. Update `REDIS_PASSWORD` (and optionally `REDIS_USERNAME` to `spidernet_v2`) in the secrets store.
3. Rolling restart — **queue + broadcast + cache all depend on Redis**; do Horizon last to avoid missed jobs.
4. Monitor queue depth in Horizon for 10 min.
5. `ACL SETUSER spidernet off` after 24h. Delete after 7 days.

**Rollback:** `ACL SETUSER spidernet on`, revert env.

**Smoke test:** `kubectl exec deploy/api -- php artisan horizon:status` → `Horizon is running`.

---

## 4. Pusher / Soketi credentials (`PUSHER_APP_ID` / `PUSHER_APP_KEY` / `PUSHER_APP_SECRET`)

**Trigger:** on compromise, or yearly.

**Grace:** none natively — clients hold one app key at a time. Rotate during low-traffic window.

**Procedure:**
1. Provision new Pusher app (or Soketi config) alongside the old one.
2. Update backend env + redeploy (server will now broadcast on the new app).
3. Update cockpit env + redeploy (clients reconnect to the new app).
4. Keep old app alive for 1h in case of stuck clients, then disable.

**Rollback:** flip env back, redeploy both.

**Smoke test:** open cockpit → watch the realtime `/broadcasting/auth` handshake succeed in devtools Network tab.

---

## 5. OpenAI / Anthropic / LLM provider keys

**Trigger:** monthly (best practice), or on suspected leak.

**Grace:** provider-dependent — OpenAI supports multiple active keys, Anthropic too.

**Procedure:**
1. Create new key in provider dashboard.
2. Update the corresponding env in the secrets store.
3. Rolling restart of `api`, `worker`, `inference`.
4. Monitor Grafana dashboard `llm.provider_errors` for 10 min.
5. Revoke old key in provider dashboard after 1h zero-error window.

**Rollback:** paste old key back, redeploy.

**Smoke test:**
```bash
curl -X POST http://api/api/atlas/chat \
  -H "Authorization: Bearer $TEST_TOKEN" \
  -d '{"message": "ping"}'
# Must return a 200 with a non-empty response.
```

---

## 6. Twilio account credentials (`TWILIO_ACCOUNT_SID` / `TWILIO_AUTH_TOKEN`)

**Trigger:** yearly, or on compromise. **Rotating the auth token invalidates all in-flight webhook signatures** — coordinate with ops.

**Grace:** Twilio supports a single auth token per account. Webhook signature verification happens in `@/c:/Users/HP/CascadeProjects/windsurf-project-2/backend/app/Http/Middleware/VerifyTwilioSignature.php`; it must accept both the old and new token during the rollover.

**Procedure:**
1. Create a **secondary** auth token in the Twilio console (Twilio supports this during rotation).
2. Update `VerifyTwilioSignature` middleware to accept either token via `TWILIO_AUTH_TOKEN` + `TWILIO_AUTH_TOKEN_PREVIOUS` env.
3. Update the primary token in Twilio console.
4. Update `TWILIO_AUTH_TOKEN` env to the new primary. Deploy.
5. Wait for all in-flight calls to drain (max call duration + 5 min).
6. Remove `TWILIO_AUTH_TOKEN_PREVIOUS`. Deploy.

**Rollback:** restore previous env pair.

**Smoke test:** inbound call to a smoke-test number → check `event_log` for `voice.call.started` within 10s.

---

## 7. Per-tenant event signing keys

**Purpose:** HMAC the `event_log.hash` chain per tenant (tamper evidence).

**Trigger:** annually per tenant, or immediately on suspected tenant-scoped compromise.

**Grace:** 24h dual-verify window, already implemented in `@/c:/Users/HP/CascadeProjects/windsurf-project-2/backend/app/Services/TenantKeyManager.php:87-132`.

**Procedure:** one-liner:

```bash
kubectl exec deploy/api -- php artisan tinker --execute="
  app(\App\Services\TenantKeyManager::class)->rotateSigningKey('<tenant-uuid>', 24);
"
```

This marks the old row `active=0 grace_until=NOW()+24h`, inserts a new row `active=1`, and busts the cache. Events written after this point sign with the new key; the chain verifier accepts both during the grace window.

**Rollback:** `UPDATE tenant_secrets SET active = 1, grace_until = NULL WHERE id = '<previous-id>';` and delete the new row. Only safe within the first minutes of rotation.

**Smoke test:** `php artisan events:verify-chain --tenant=<tenant-uuid>` (exists as `VerifyEventChain` command) must report 0 integrity failures.

---

## 8. Tenant API tokens (Sanctum personal access tokens)

**Trigger:** on user request, role change, or compromise.

**Grace:** none — tokens are single-use handles.

**Procedure:**
1. `DELETE FROM personal_access_tokens WHERE id = <token-id>;` OR user-initiated via `/api/auth/logout` on that token.
2. If rotating for a user: delete all their tokens + force re-login via front-channel notification.

**Smoke test:** invalidated token → 401 on `/api/auth/me`.

---

## 9. GitHub / container registry tokens (CI)

**Trigger:** quarterly, or on workflow repo change.

**Procedure:** GitHub OIDC preferred (no long-lived tokens). If using PATs:
1. Mint new PAT with minimal scopes (`read:packages`, `write:packages`).
2. Update `secrets.GHCR_TOKEN` in the repo settings.
3. Re-run one workflow on a dummy branch to confirm.
4. Revoke old PAT.

---

## Audit & monitoring

Every rotation MUST:
- Be preceded by an `#spidernet-ops` announcement (planned) or paged incident declaration (emergency).
- Append an entry to `admin_audit_log` (PHP paths do this automatically via `AdminAudit::log()`; manual SQL rotations must `INSERT` one by hand).
- Be followed by 24h of enhanced monitoring on the affected surface (Grafana alert suppression lifted).

Incident-response rotations additionally trigger:
- Sev 1 postmortem within 5 business days.
- SOC 2 control evidence capture (screenshot of rotation audit row + smoke-test result) in the `compliance/soc2/rotations/` folder.

---

## Emergency full rotation ("break glass")

If everything is suspected compromised (e.g., stolen production laptop with `.env`), rotate in this order to minimise downtime:

1. `APP_KEY` (with `APP_PREVIOUS_KEYS` chain)
2. DB credentials
3. Redis credentials
4. All third-party API keys (OpenAI, Anthropic, Twilio, Pusher)
5. Per-tenant signing keys (script a bulk rotation over `tenants`)
6. Force-logout all users: `TRUNCATE personal_access_tokens;` + invalidate all sessions: `php artisan session:flush` (if persisting in Redis, `redis-cli FLUSHDB` on the session DB index).

Expected downtime: ~30 min. Escalate to CTO before executing.

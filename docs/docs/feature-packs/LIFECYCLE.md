# Feature Pack Lifecycle

**Version:** 1.0.0  
**Date:** 2026-04-22  

## State Machine

```
                    ┌─────────────────────┐
                    │       ABSENT        │
                    │  (not installed)    │
                    └──────────┬──────────┘
                               │
                    spidernet pack install
                               │
                               ▼
                    ┌─────────────────────┐
                    │    DOWNLOADING      │
                    │  (fetching bytes)   │
                    └──────────┬──────────┘
                               │
                               ▼
                    ┌─────────────────────┐
                    │     VERIFYING       │
                    │  (signature + deps) │
                    └──────────┬──────────┘
                               │
              ┌────────────────┴────────────────┐
              │ verification failed                │
              ▼                                   │
    ┌─────────────────────┐                     │
    │       FAILED        │                     │
    │  (retry or abort)   │                     │
    └──────────┬──────────┘                     │
               │ retry                         │ success
               └───────────────────────────────┘
                               │
                               ▼
                    ┌─────────────────────┐
                    │    INSTALLING       │
                    │  (db transactions)  │
                    └──────────┬──────────┘
                               │
              ┌────────────────┴────────────────┐
              │ install failed                   │
              │ (auto-rollback)                  │
              ▼                                   │
    ┌─────────────────────┐                     │
    │     ROLLING BACK    │                     │
    │  (undo mutations)   │                     │
    └──────────┬──────────┘                     │
               │                                │
               └───────────────────────────────┘
                               │
                               ▼
                    ┌─────────────────────┐
                    │       ACTIVE        │
                    │  (ready for use)    │
                    └──────────┬──────────┘
                               │
                    spidernet pack upgrade
                               │
                               ▼
                    ┌─────────────────────┐
                    │     UPGRADING       │
                    │  (version bump)     │
                    └──────────┬──────────┘
                               │
                               ▼
                    ┌─────────────────────┐
                    │       ACTIVE        │
                    │  (new version)      │
                    └──────────┬──────────┘
                               │
                   spidernet pack uninstall
                               │
                               ▼
                    ┌─────────────────────┐
                    │   UNINSTALLING      │
                    │  (drain + archive)  │
                    └──────────┬──────────┘
                               │
                               ▼
                    ┌─────────────────────┐
                    │     ARCHIVED        │
                    │  (history kept)     │
                    └─────────────────────┘
```

## State Definitions

| State | Description | Transitions |
|---|---|---|
| **ABSENT** | Pack not installed on tenant | → DOWNLOADING (install command) |
| **DOWNLOADING** | Fetching pack bytes from registry or local path | → VERIFYING (success) / FAILED (network error) |
| **VERIFYING** | Validating signature, schema, dependencies | → INSTALLING (success) / FAILED (validation error) |
| **INSTALLING** | Executing database mutations | → ACTIVE (success) / ROLLING BACK (failure) |
| **ROLLING BACK** | Undoing partial mutations | → ABSENT (complete) |
| **ACTIVE** | Pack installed and operational | → UPGRADING (upgrade) / UNINSTALLING (uninstall) |
| **UPGRADING** | Installing newer version | → ACTIVE (success) / ROLLING BACK (failure) |
| **UNINSTALLING** | Draining flows, archiving agents | → ARCHIVED (complete) |
| **ARCHIVED** | Uninstalled, history preserved | Terminal state |
| **FAILED** | Installation/verification failed | → ABSENT (abort) / DOWNLOADING (retry) |

## Transition Details

### ABSENT → DOWNLOADING

**Trigger:** `spidernet pack install {pack_id}`

**Actions:**
- Resolve pack ID to registry URL or local path
- Check version compatibility with SpiderNetOS core
- Begin byte download

**Failure modes:**
- Network unreachable → FAILED
- Pack not found in registry → FAILED
- Version incompatible (core too old) → FAILED

### DOWNLOADING → VERIFYING

**Trigger:** Download complete

**Actions:**
- Compute manifest hash
- Verify signature against publisher key
- Validate YAML schema

**Failure modes:**
- Signature mismatch → FAILED
- Schema validation error → FAILED

### VERIFYING → INSTALLING

**Trigger:** All checks pass

**Actions:**
- Begin database transaction
- Check for conflicts (existing agents, chains)
- Prepare mutation plan

### INSTALLING → ACTIVE

**Trigger:** All mutations succeed

**Actions:**
- Commit transaction
- Activate dynamic agents
- Seed bandit priors
- Emit `feature_pack.installed` event

**Failure → ROLLING BACK:**
- Any mutation fails → automatic rollback
- Conflict detected → manual resolution required

### ROLLING BACK → ABSENT

**Trigger:** Rollback complete

**Actions:**
- Remove staged mutations
- Clean up temporary files
- Log failure reason

### ACTIVE → UPGRADING

**Trigger:** `spidernet pack upgrade {pack_id}` or newer version available

**Actions:**
- Download new version
- Verify compatibility (migration hooks)
- Execute migration hooks

**Migration hook format:**

```yaml
# pack.yaml (version 0.2.0)
migrations:
  from: 0.1.0
  hooks:
    - type: sql
      script: |
        UPDATE agents 
        SET config = jsonb_set(config, '{new_field}', 'true')
        WHERE pack_id = 'real-estate-crm';
    - type: event
      emit: tenant.migration.completed
      payload:
        from_version: "0.1.0"
        to_version: "0.2.0"
```

### ACTIVE → UNINSTALLING

**Trigger:** `spidernet pack uninstall {pack_id}`

**Actions (graceful):**
1. Set pack status to `uninstalling`
2. Stop accepting new flow instantiations
3. Wait for in-flight DAGs to complete (timeout: 5 min)
4. Archive dynamic agents (status → archived)
5. Mark pack as archived

**Actions (force):**
1. Immediate termination of in-flight flows
2. Mark flows as failed
3. Archive agents
4. Mark pack as archived

### UNINSTALLING → ARCHIVED

**Trigger:** Cleanup complete

**Actions:**
- Emit `feature_pack.uninstalled` event
- Retain `event_log` history (never delete)
- Retain `ste_event_mapping` rows (historical queries)
- Remove `flow_templates` entries
- Remove bandit priors (unless shared)

## Event Log Integration

Every lifecycle transition emits events:

```json
{
  "event_type": "feature_pack.installation.started",
  "tenant_id": "...",
  "payload": {
    "pack_id": "real-estate-crm",
    "version": "0.1.0",
    "from_state": "absent",
    "to_state": "downloading"
  }
}
```

Full event types:

| Event | Payload |
|---|---|
| `feature_pack.installation.started` | `{pack_id, version, from_state, to_state}` |
| `feature_pack.installation.completed` | `{pack_id, version, duration_ms}` |
| `feature_pack.installation.failed` | `{pack_id, version, error, retryable}` |
| `feature_pack.upgrade.started` | `{pack_id, from_version, to_version}` |
| `feature_pack.upgrade.completed` | `{pack_id, from_version, to_version}` |
| `feature_pack.uninstall.started` | `{pack_id, mode: graceful|force}` |
| `feature_pack.uninstall.completed` | `{pack_id, flows_terminated, flows_completed}` |

These events flow into the STE for pack adoption analytics.

## Failure Recovery

| Failure Point | Recovery Action |
|---|---|
| Download interrupted | Retry with exponential backoff (max 3 attempts) |
| Signature mismatch | Reject immediately, alert registry admin |
| Schema validation fail | Reject, provide detailed error log |
| Install mutation fail | Automatic rollback, keep retry count |
| Rollback fail | Manual intervention required, alert SRE |
| Upgrade migration fail | Rollback to previous version, alert pack author |

## Concurrency

**Per-tenant:** Only one pack operation at a time. Second operation queues or fails fast.

**Global registry:** Read-many, write-rare. Registry updates use atomic file operations.

## Related

- `SPEC.md` — Manifest schema and semantics
- `EXAMPLE-real-estate-crm.md` — Worked example

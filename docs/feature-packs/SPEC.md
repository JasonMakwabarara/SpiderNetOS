# SpiderNetOS Feature Pack Specification

**Version:** 1.0.0  
**Status:** Draft  
**Date:** 2026-04-22  

## 1. What is a Feature Pack?

A Feature Pack is a **versioned, signed bundle** that extends a SpiderNetOS tenant with vertical-specific capabilities. Each pack contains:

- **State Model:** STE chain extensions for domain-specific lifecycles
- **Agents:** DynamicAgent configurations for the vertical
- **Flows:** Pre-built DAG templates for common automations
- **Policies:** Bandit priors and optimization thresholds
- **Copy:** Localized messaging and action labels

Packs are **additive and namespaced** — they never overwrite core system state.

## 2. Manifest Schema

The `pack.yaml` manifest is the authoritative definition:

```yaml
apiVersion: spidernet/v1
kind: FeaturePack
metadata:
  id: real-estate-crm          # Unique identifier, kebab-case
  version: 0.1.0              # Semantic versioning
  vertical: real_estate       # Domain classification
  displayName: "Real Estate CRM"
  description: "Lead capture, viewing scheduling, and offer management for real estate professionals"
  
spec:
  requires:
    core_agents: [atlas, forge, hannah]
    min_spidernet_version: 3.2.0
    
  provides:
    dynamic_agents:
      - id: growth
        displayName: "Growth Agent"
        capabilities: [landing_pages, ad_campaigns, a_b_testing]
      - id: crm
        displayName: "CRM Agent"
        capabilities: [pipeline_management, lead_scoring, follow_ups]
      - id: retention
        displayName: "Retention Agent"
        capabilities: [churn_detection, re_engagement, win_back]
        
    flows:
      - id: lead-capture
        description: "Capture and qualify incoming leads"
      - id: viewing-scheduler
        description: "Automated property viewing coordination"
      - id: offer-pipeline
        description: "Track offers from submission to close"
      
    ste_chains:
      - id: lead_lifecycle
        description: "Lead state progression"
        states: [captured, qualified, contacted, viewing_scheduled, offer_made, closed]
        
    targets:
      - metric: lead_conversion_rate
        goal: ">= 0.25"
        baseline: 0.15
      - metric: response_time_p50
        goal: "<= 5m"
        baseline: 30m
        
  config:
    # Pack-scoped defaults
    default_automation_level: assisted
    enable_cross_tenant_learning: true
    
  signatures:
    publisher: "spidernet-official"  # Key ID from registry
    signature: "base64-encoded-sig"  # Signed manifest hash
```

## 3. Directory Structure

```
feature-pack/
├── pack.yaml              # Manifest (required)
├── pack.yaml.sig          # Signature (required for signed packs)
├── README.md              # Pack documentation
├── state-model.yaml       # STE chain definitions
├── agents/                # DynamicAgent configurations
│   ├── growth.yaml
│   ├── crm.yaml
│   └── retention.yaml
├── flows/                 # DAG templates
│   ├── lead-capture.dag.yaml
│   ├── viewing-scheduler.dag.yaml
│   └── offer-pipeline.dag.yaml
├── policies/              # Bandit priors & thresholds
│   ├── followup-timing.yaml
│   ├── conversion-scripts.yaml
│   └── ad-copy-variants.yaml
└── copy/                  # Localized strings
    ├── en.json
    └── es.json
```

## 4. Installation Semantics

```bash
# Install from official registry
spidernet pack install real-estate-crm

# Install specific version
spidernet pack install real-estate-crm@0.1.0

# Install from local path (development)
spidernet pack install ./local-path/real-estate-crm --dev

# Force reinstall
spidernet pack install real-estate-crm --force
```

**Installation is idempotent and transactional:**

1. **Download:** Fetch pack from registry or local path
2. **Verify:** Check signature against publisher key
3. **Validate:** Schema validation + dependency resolution
4. **Stage:** Prepare database mutations (no writes yet)
5. **Execute:** Apply mutations in dependency order
6. **Activate:** Mark pack as active

**Database mutations during install:**

```sql
-- 1. Agent registrations (type='dynamic')
INSERT INTO agents (tenant_id, slug, type, config, pack_id)
VALUES (?, 'real-estate-crm.growth', 'dynamic', '{...}', 'real-estate-crm');

-- 2. STE chain extensions (namespaced)
INSERT INTO ste_event_mapping (event_type, chain, from_state, to_state, extract_tags)
VALUES 
  ('lead.captured', 'pack.real-estate-crm.lead_lifecycle', NULL, 'captured', '{}'),
  ('lead.qualified', 'pack.real-estate-crm.lead_lifecycle', 'captured', 'qualified', '{}');

-- 3. DAG templates (stored as JSON, instantiated on demand)
INSERT INTO flow_templates (tenant_id, pack_id, slug, dag_json)
VALUES (?, 'real-estate-crm', 'lead-capture', '{...}');

-- 4. Pack registry entry
INSERT INTO feature_packs (tenant_id, pack_id, version, installed_at, status)
VALUES (?, 'real-estate-crm', '0.1.0', NOW(), 'active');

-- 5. Bandit prior seeding (if shared: true)
INSERT INTO atlas_copy_variants (pack_id, variant_id, surface, alpha, beta, ...)
VALUES ('real-estate-crm', 'followup-morning', 'whatsapp', 12.0, 4.0, ...);
```

## 5. Isolation Rules

Packs **MUST** follow these isolation rules:

1. **Namespace STE chains:** All pack chains are prefixed `pack.{pack_id}.{chain_id}`
2. **No overwrites:** Cannot modify existing `ste_event_mapping` rows
3. **Agent namespacing:** Dynamic agents are `{pack_id}.{agent_id}`
4. **Config scoping:** Policies only affect their own agents unless explicitly shared
5. **Clean uninstall:** Must remove all traces except event_log history

## 6. Cross-Tenant Learning Contract

Packs may opt into **shared learning pools**:

```yaml
policies:
  - id: followup-timing
    shared: true           # Writes to global pool
    aggregation: "mean"    # How to combine across tenants
    
  - id: lead-scoring-model
    shared: false          # Tenant-local only
```

**Shared policies:**
- Outcomes write to pack-scoped global bandit (`pack_id` column in `atlas_copy_variants`)
- New tenants start with pooled priors
- Privacy-preserving aggregation uses `keys_hash` + `field_count` (see ADR-0001 onboarding hardenings)

**Private policies:**
- Tenant-local only
- No cross-tenant data sharing
- Useful for competitive-sensitive optimizations

## 7. Signing & Integrity

**Registry model:**

```
SpiderNet Official Registry
├── keys/
│   └── spidernet-official.pub   # Ed25519 public key
├── packs/
│   ├── real-estate-crm/
│   │   ├── 0.1.0/
│   │   │   ├── pack.yaml
│   │   │   ├── pack.yaml.sig
│   │   │   └── ...
│   │   └── 0.2.0/
│   └── support-ticketing/
└── index.json                 # Catalog API
```

**Verification:**

```bash
# Unsigned packs require explicit flag
spidernet pack install ./local-pack --allow-unsigned

# Unsigned packs emit security warning to event_log
# and are excluded from cross-tenant learning pools
```

**Signature format:**

```yaml
# pack.yaml.sig
algorithm: ed25519
key_id: spidernet-official
signature: "base64-encoded-signature-of-manifest-hash"
timestamp: "2026-04-22T12:00:00Z"
```

## 8. Uninstall

```bash
# Graceful uninstall (drains in-flight)
spidernet pack uninstall real-estate-crm

# Force immediate (risk: fails in-flight flows)
spidernet pack uninstall real-estate-crm --force
```

**Uninstall actions:**

1. **Drain:** Wait for in-flight DAGs to complete (or fail immediately with `--force`)
2. **Archive agents:** Mark dynamic agents as `archived`, disable delegation
3. **Preserve history:** `event_log` rows remain forever (never delete)
4. **Retain STE chains:** Pack chains remain in `ste_event_mapping` for historical queries
5. **Update registry:** Set pack status to `uninstalled`

## 9. Pack Lifecycle

```
absent → downloading → verifying → installing → active
                                       │
                                       ▼
                                    upgrading ←── pulls newer version
                                       │
                                       ▼
                                     active
                                       │
                                       ▼
                                 uninstalling → archived
```

**Version compatibility:**

- Minor versions (0.1.0 → 0.1.1): In-place upgrade, no migration
- Major versions (0.1.x → 0.2.0): Migration hooks required in pack

## 10. Reference Implementation

See `EXAMPLE-real-estate-crm.md` for a fully worked pack.

## 11. Related Documents

- `LIFECYCLE.md` — State machine diagram
- `EXAMPLE-real-estate-crm.md` — Worked example
- `docs/adr/0001-agent-roster-reconciliation.md` — Dynamic agents
- `docs/ROADMAP.md` — Phase 3: Feature Packs v1

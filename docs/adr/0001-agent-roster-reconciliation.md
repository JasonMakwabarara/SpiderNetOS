# ADR-0001: Agent Roster Reconciliation

**Status:** Accepted  
**Date:** 2026-04-22  
**Author:** SpiderNet Core Team  

## Context

The vision document (§3) defines an expanded agent roster for vertical Feature Packs:

- GrowthAgent (revenue entry)
- CRM/RevenueAgent (pipeline management)
- SupportAgent (customer service)
- RetentionAgent (churn prevention)
- OpsAgent (system health)

These must be reconciled against the existing **six core agents** shipped in SpiderNet OS v3.2:

| Core Agent | Role | Runtime Class |
|---|---|---|
| Atlas | Executive interface, NL compiler | `AtlasAgent` |
| Hannah | Tutor, onboarding guide | `HannahAgent` |
| Forge | DAG builder, flow generator | `ForgeAgent` |
| Sentinel | Monitoring, observability | `SentinelAgent` |
| Prism | Analysis, projections | `PrismAgent` |
| Nexus | Execution runtime | `NexusAgent` |

Additionally, the system supports **dynamic agents** via the `DynamicAgent` base class (Python) and corresponding `Agent::TYPE_DYNAMIC` enum (PHP), which load their behaviour from YAML configuration at runtime.

The decision required: which agents are core classes vs dynamic configs vs aliases.

## Decision Matrix

| Vision Name | Resolution | Runtime | Rationale |
|---|---|---|---|
| **Atlas (Executive)** | Core class `AtlasAgent` | `intelligence/agents/atlas_agent.py` | Owner-facing NL compiler. Already shipped. No change. |
| **Hannah (Tutor)** | Core class `HannahAgent` | `intelligence/agents/hannah_agent.py` | Onboarding + guidance. Already shipped. No change. |
| **Forge (Builder)** | Core class `ForgeAgent` | `intelligence/agents/forge_agent.py` | DAG generation. Already shipped. No change. |
| **Sentinel (Monitoring)** | **Alias of OpsAgent** | `intelligence/agents/sentinel_agent.py` | Same runtime, renamed in owner-facing copy. |
| **Prism (Analysis)** | Core class `PrismAgent` | `intelligence/agents/prism_agent.py` | Shared analytics capability. Used by multiple agents. Not owner-facing. |
| **Nexus (Execution)** | Core class `NexusAgent` | `intelligence/agents/nexus_agent.py` | Execution runtime. Not owner-facing. |
| **GrowthAgent** | **Dynamic agent** | `agents::TYPE_DYNAMIC` | Config-driven. Shipped in `growth` Feature Pack. |
| **CRM/RevenueAgent** | **Dynamic agent** | `agents::TYPE_DYNAMIC` | Config-driven. Shipped in `crm` Feature Pack. |
| **SupportAgent** | **Dynamic agent** | `agents::TYPE_DYNAMIC` | Config-driven. Shipped in `support` Feature Pack. |
| **RetentionAgent** | **Dynamic agent** | `agents::TYPE_DYNAMIC` | Config-driven. Shipped in `retention` Feature Pack. |
| **OpsAgent** | **Alias of Sentinel** | `intelligence/agents/sentinel_agent.py` | Owner-facing name for Sentinel. |

### Key Principles

1. **Six core agents remain.** No new Python classes for vertical agents in Phase 1–3.
2. **Dynamic agents for verticals.** Feature Packs ship YAML configs that instantiate `DynamicAgent` with pack-scoped behaviour.
3. **Aliases are UI-layer only.** Sentinel/OpsAgent duality is resolved at presentation (Cockpit, Atlas responses), not runtime.
4. **Prism is a capability, not a persona.** Growth/Retention agents delegate analytics to Prism; they don't duplicate it.

## Consequences

### Positive

- **Minimal code churn:** No new core classes for Feature Packs v1.
- **Consistent runtime:** All vertical agents run through the same `DynamicAgent` execution path, tested and hardened.
- **Easy vertical addition:** New industry verticals ship as config bundles, not code deployments.
- **Clear ownership:** Core team owns 6 classes; vertical authors own YAML configs in their packs.

### Negative

- **Config complexity:** Dynamic agents require thorough schema validation; misconfigurations fail at runtime.
- **Performance ceiling:** Dynamic agents are interpreted; if a vertical hits throughput limits, promotion to core class may be needed.
- **Debugging friction:** Stack traces show `DynamicAgent` for all verticals; tenant_id + pack_id must be in logs.

## Delegation Graph

Hard Rule #2 (MetaPlanner is sole decision authority) applies. The existing `agent_delegation_edges` table seeds these rows:

```sql
-- Core-to-core (existing)
atlas → forge
atlas → prism
atlas → sentinel
hannah → atlas  -- onboarding delegates to executive after completion

-- Dynamic-to-core (new for Feature Packs)
growth → prism   -- analytics
growth → forge   -- create campaigns as flows
crm → prism      -- pipeline analytics
crm → nexus      -- execute pipeline steps
support → atlas  -- escalate to executive
retention → prism -- churn prediction
retention → forge -- create retention flows
```

Dynamic agents are prefixed by their pack: `real-estate-crm.crm`, `real-estate-crm.growth`. This namespaces their delegation rights.

## Revisit Criteria

A dynamic agent may be promoted to a core class when **all** of the following are true:

1. **Usage:** ≥ 3 active tenants use the agent daily.
2. **Divergence:** Behaviour cannot be expressed in config alone (requires custom tool implementations).
3. **Performance:** Dynamic interpretation adds > 50ms p99 latency vs equivalent core agent.
4. **Stability:** Zero critical bugs in the dynamic config for 30 consecutive days.

Promotion requires:
- New `intelligence/agents/{name}_agent.py` class
- Migration: existing `Agent::TYPE_DYNAMIC` rows → `Agent::TYPE_CORE` with same slug
- ADR superseding this one

## References

- Hard Rule #2: MetaPlanner as sole decision authority (`intelligence/core/agent_base.py`)
- DynamicAgent implementation: `intelligence/agents/dynamic_agent.py`
- Agent types enum: `backend/app/Models/Agent.php`
- Feature Pack specification: `docs/feature-packs/SPEC.md` (Artefact 2)

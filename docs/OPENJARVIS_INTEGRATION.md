# OpenJarvis × SpiderNetOS — Background Inference for Atlas AI

[OpenJarvis](https://github.com/open-jarvis/OpenJarvis) is a **background servant** to Atlas AI and SpiderNetOS. Operators never see Jarvis — they interact only with Atlas, Outcomes, and Billing surfaces.

## Design principle

| Layer | Visible to users? | Role |
|-------|-------------------|------|
| Cockpit (Atlas, Outcomes, Billing) | Yes | Business UX |
| Laravel (`AtlasJarvisAugmentor`, `JarvisTierGate`) | No | Routes inference by plan tier |
| `openjarvis-bridge` (internal :8000) | No | Adapter + skills catalog |
| Full OpenJarvis on edge node | No | Local-first models via `OPENJARVIS_URL` |

## Plan-tier gating

| Capability | Minimum plan | Feature flag |
|------------|--------------|--------------|
| Background Atlas augmentation (simple, orchestrator, code) | Starter | `atlas.openjarvis` |
| Weekly Atlas briefing (`morning_digest`) | **Growth+** | `atlas.jarvis.morning_digest` |
| Deep multi-hop research | **Enterprise** | `atlas.jarvis.deep_research` |

Flags resolve per-tenant via Redis override → env → `config/features.php`.

## Edge node — true local inference

1. Deploy [OpenJarvis](https://github.com/open-jarvis/OpenJarvis) on an edge GPU/CPU node (Ollama, vLLM, etc.).
2. Set environment on the bridge service:

```bash
OPENJARVIS_URL=http://edge-node.internal:8080
OPENJARVIS_API_KEY=your-key-if-required
```

3. Bridge proxies `/v1/ask` to the full OpenJarvis server when reachable; falls back to OpenAI or template responses when not.

In `docker-compose.unified.yml`, only the **bridge** is on the SpiderNet network — never expose Jarvis UI or `/api/atlas/jarvis/*` to operators.

## Architecture

```
Operator → Atlas chat / Outcomes / Billing
                │
                ▼
         AtlasController / OutcomesController
                │
                ▼
         AtlasJarvisAugmentor + JarvisTierGate + JarvisUsageRecorder
                │
                ▼
         openjarvis-bridge (internal)
                │
       ┌────────┴────────┐
       ▼                 ▼
 OPENJARVIS_URL      OpenAI fallback
 (edge node)
```

## Billing

Background inference spend is recorded as `resource_type=atlas_inference` in `usage_records` / `usage_daily_aggregates`.

`GET /api/billing/summary` returns:

- `spend.atlas_inference_daily_usd`
- `spend.atlas_inference_monthly_usd`

Cockpit Billing shows **Atlas inference** — not Jarvis branding.

## Vertical skills

One skill per feature-pack vertical under `integrations/openjarvis/skills/`:

| Skill | Vertical |
|-------|----------|
| `spidernet-real-estate` | `real_estate` |
| `spidernet-financial-services` | `financial_services` |
| `spidernet-workflow-coordination` | core |
| `spidernet-deep-research` | core (Enterprise-gated agent) |
| `spidernet-local-first-routing` | core |

Skills auto-attach when the tenant has an active feature pack with matching `vertical`.

## Verify

```powershell
powershell -ExecutionPolicy Bypass -File scripts\verify-unified.ps1
```

Tests bridge health (internal :8010 dev port), weekly-review briefing, and billing inference fields — **not** public Jarvis API routes.

## Removed user-facing surfaces

The following are intentionally **not** exposed:

- `GET/POST /api/atlas/jarvis/*`
- Cockpit Jarvis agent selector / Local-first badges
- `POST /api/outcomes/digest` (briefing is embedded in weekly review)

# Changelog

## 2026-06-23 — Atlas inference plane + real flow execution

### Inference (Gemma via Ollama)
- `ollama` + `inference` services in `docker-compose.unified.yml` (inference on `:9000`)
- `POST /v1/classify` on inference plane for `AtlasIntentCompiler`
- `ATLAS_INTENT_CONFIDENCE=true` enables confidence-driven clarity gate
- `SPIDERNET_PROMPT_ENHANCER_MODEL=gemma2:2b` for LLM contracts (`metadata.source: llm`)
- One-time model pull: `docker compose exec ollama ollama pull gemma2:2b`

### OpenJarvis bridge
- Inference-plane fallback when `OPENJARVIS_URL` is unset (`INFERENCE_URL` on bridge)
- Token/cost passthrough on bridge health (`inference_reachable`)

### Real automation execution
- `POST /api/flows/quick-create` — first-win templates with generated DAG
- `DagExecutionService` wired to `FlowController::execute` via `NodeActionRunner`
- `execution_dag_nodes` / `execution_dag_edges` tables for runtime DAG state
- `ExecuteFlowJob` + `DispatchScheduledFlowsJob` with `schedule:work` scheduler
- `AtlasController::executePlan()` creates flow + runs DAG execution
- First-win wizard and Sales home use `quick-create` then `execute`

## 2026-06-22 — Customer-first AIOS (Phases 1–5)

### Pack growth loop
- `PackGrowthService` — maps industry profile, pain points, usage signals, and feedback into pack relevance scores
- `tenant_pack_signals` table — pack views, installs, route visits, Atlas suggestions, thumbs up/down
- Personalized catalogue (`relevance_score`, `growth_reason`, industry-tailored outcomes)
- `GET /api/feature-packs/recommendations`, `POST /api/feature-packs/feedback`, `POST /api/feature-packs/signals`
- Cockpit Feature Packs UI: recommended strip, fit %, outcome feedback

### Backend
- `POST /api/feature-packs/{id}/install` — self-serve pack install via `FeaturePackInstaller`
- Enhanced `GET /api/feature-packs/catalogue` with `customer_outcomes`, agent/flow counts, `entry_path`
- `GET/PUT /api/business-profile` — tenant business learning profile
- `GET /api/compliance/obligations` — universal SME compliance discovery
- Atlas discovery mode in `AtlasController::chat()` via `AtlasDiscoveryService`
- **Atlas trust gate** (`AtlasClarityGate`) — clarify when unsure, confirm before consequential actions, trust accrual per intent in `learned_signals`
- `POST /api/atlas/confirm` — proceed/cancel pending actions (`atlas.confirmation.resolved` events)
- Migration: `tenant_business_profiles`

### Frontend
- Billing vs Financial OS copy separation; Financial OS nav in sidebar and command bar
- Feature Packs: install button, value outcome bullets, post-install redirect
- New routes: `/sales`, `/compliance`, `/operate/first-win`
- Atlas discovery question chips and suggested-next card
- Atlas confirm-before-act card (Proceed / Not yet) wired to `/api/atlas/confirm`
- Hannah guidance: first-win wizard and discovery prompts

### Feature packs
- `sales-crm` — generic Sales & CRM OS
- `compliance-radar` — universal compliance discovery pack

### Contract verification
- Extend `scripts/verify-unified.ps1` for new endpoints and live cockpit bundle parity
- Run `npm run build` in `cockpit/` after UI changes, then rebuild frontend container

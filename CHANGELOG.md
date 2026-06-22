# Changelog

## 2026-06-22 — Customer-first AIOS (Phases 1–5)

### Backend
- `POST /api/feature-packs/{id}/install` — self-serve pack install via `FeaturePackInstaller`
- Enhanced `GET /api/feature-packs/catalogue` with `customer_outcomes`, agent/flow counts, `entry_path`
- `GET/PUT /api/business-profile` — tenant business learning profile
- `GET /api/compliance/obligations` — universal SME compliance discovery
- Atlas discovery mode in `AtlasController::chat()` via `AtlasDiscoveryService`
- Migration: `tenant_business_profiles`

### Frontend
- Billing vs Financial OS copy separation; Financial OS nav in sidebar and command bar
- Feature Packs: install button, value outcome bullets, post-install redirect
- New routes: `/sales`, `/compliance`, `/operate/first-win`
- Atlas discovery question chips and suggested-next card
- Hannah guidance: first-win wizard and discovery prompts

### Feature packs
- `sales-crm` — generic Sales & CRM OS
- `compliance-radar` — universal compliance discovery pack

### Contract verification
- Extend `scripts/verify-unified.ps1` for new endpoints
- Run `npm run build` in `cockpit/` after UI changes

# Lead-to-Sale Funnel (sales-crm) Feature Pack

A SpiderNetOS Feature Pack that runs the entire lead-to-sale funnel over email and WhatsApp.

## What It Does

1. **Purchase** — the owner buys the bundle (Dodo Payments checkout).
2. **Discovery interview** — the Funnel Architect agent asks a fixed set of questions (origin/mission/vision, offer, ideal customer, objections, tone) plus one adaptive follow-up per section, using the tenant's brand and business profile as context.
3. **Script draft** — Funnel Architect drafts a versioned, per-channel sales script (opener, qualify, objections, close, follow-ups).
4. **Approval** — the owner reviews the script in Script Studio and grants the final go-ahead through the existing approvals system.
5. **Go-live** — pack agents activate, message templates are seeded from the approved script, and the funnel starts capturing, qualifying, and following up with leads on email and WhatsApp until they buy.

## Agents Included

| Agent | Operating role (Priestley) | Does |
|---|---|---|
| Growth Agent | Head of Growth | Landing pages, campaigns, lead generation |
| CRM Agent | General Manager | Pipeline, scoring, follow-ups, conversations |
| Retention Agent | Head of Delight | Churn detection, win-back |
| Funnel Architect | — | Discovery interview, script drafting, activation |

## State Model

```
lead_lifecycle:  captured -> qualified -> engaged -> meeting_booked -> proposal -> won
                                                                          -> lost -> recycled

funnel_setup:    purchased -> interviewing -> script_drafted -> awaiting_approval -> approved -> live
                                                     ^------------- revision loop -------------|
```

## Target Metrics

| Metric | Baseline | Goal |
|---|---|---|
| Lead Conversion Rate | 15% | 25%+ |
| Response Time (median) | 30 min | 5 min or less |
| CAC:LTV Ratio | 0.5 | 0.33 or less |

## Installation

```bash
php artisan spidernet:pack-validate
php artisan spidernet:pack-install sales-crm --tenant=<tenant-id>
```

Purchasing tenants install via the cockpit Feature Packs catalogue, gated on an active `pack_entitlements` row (see `docs/feature-packs/SPEC.md` and `backend/app/Services/Integrations/DodoPaymentsAdapter.php`).

## Related

- [Feature Pack Specification](../../docs/feature-packs/SPEC.md)
- [Agent Roster ADR](../../docs/adr/0001-agent-roster-reconciliation.md)
- [Operating Model](../../docs/internal/operating-model.md)

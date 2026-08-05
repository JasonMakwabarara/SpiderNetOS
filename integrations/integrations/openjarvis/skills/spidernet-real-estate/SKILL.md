---
name: spidernet-real-estate
display_name: Real Estate Vertical
description: Lead capture, viewing scheduling, offer management, and CRM workflows for real estate operators on SpiderNetOS.
category: vertical
vertical: real_estate
---

# SpiderNet Real Estate

Background skill for Atlas AI when the real-estate-crm feature pack is active.

## When to use

- Property lead triage and follow-up recommendations
- Viewing schedule conflicts and pipeline bottlenecks
- Offer comparison and commission impact analysis

## Data sources

- `/api/platform/overview`
- `/api/outcomes/weekly-review`
- Feature pack agents (finance-agent, compliance-agent when co-installed)

## Output

Actionable bullets tied to tenant CRM objects — never expose internal inference engine names to operators.

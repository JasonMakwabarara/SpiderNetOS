# Real Estate CRM Feature Pack

A SpiderNetOS Feature Pack for real estate professionals.

## What It Does

This pack enables real estate agents and brokerages to:

- **Capture leads** from multiple channels (website forms, social, referrals)
- **Qualify and score** leads automatically using ML models
- **Schedule viewings** with automated coordination and reminders
- **Track offers** through negotiation to closing
- **Retain past clients** for repeat business and referrals

## Agents Included

### Growth Agent
Finds and attracts potential buyers through optimized landing pages and ad campaigns.

### CRM Agent
Manages your lead pipeline, coordinates viewings, and tracks offers.

### Retention Agent
Maintains relationships with past clients for referrals and repeat business.

## State Model

```
captured → qualified → contacted → scheduled → offer → negotiate → closed → archived
                                    ↓
                                    lost
```

## Target Metrics

| Metric | Baseline | Goal |
|---|---|---|
| Lead Conversion Rate | 15% | 25%+ |
| Response Time (median) | 30 min | 5 min or less |
| Days to Close | 60 days | 45 days |
| Repeat/Referral Rate | 25% | 40%+ |

## Installation

```bash
spidernet pack install real-estate-crm
```

## Configuration

After installation, visit **Settings → Real Estate CRM** to configure:

- Your target neighborhoods
- Automated response templates
- Integration with your MLS
- Team member assignments

## Related

- [Feature Pack Specification](../../docs/feature-packs/SPEC.md)
- [Agent Roster ADR](../../docs/adr/0001-agent-roster-reconciliation.md)

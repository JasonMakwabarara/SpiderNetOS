# Example: Real Estate CRM Feature Pack

**Pack ID:** `real-estate-crm`  
**Version:** 0.1.0  
**Vertical:** Real Estate  

A complete worked example showing how a vertical pack extends SpiderNetOS with industry-specific agents, flows, and optimizations.

## Overview

This pack enables real estate professionals to:

1. **Capture leads** from multiple channels (website, social, referrals)
2. **Qualify and score** leads automatically
3. **Schedule viewings** with automated coordination
4. **Track offers** through to close
5. **Retain past clients** for repeat business and referrals

## State Model

### Lead Lifecycle Chain

```
┌──────────┐    ┌──────────┐    ┌──────────┐    ┌──────────┐
│ CAPTURED │───▶│QUALIFIED │───▶│CONTACTED │───▶│SCHEDULED │
└──────────┘    └──────────┘    └──────────┘    └──────────┘
                                                    │
                       ┌────────────────────────────┘
                       ▼
               ┌──────────┐    ┌──────────┐    ┌──────────┐
               │  OFFER   │───▶│ NEGOTIATE│───▶│  CLOSED  │
               └──────────┘    └──────────┘    └──────────┘
                                                    │
                       ┌────────────────────────────┘
                       ▼
               ┌──────────┐    ┌──────────┐
               │ARCHIVED  │◀───│   LOST   │
               └──────────┘    └──────────┘
```

**STE Mappings:**

```yaml
# state-model.yaml
chains:
  - id: lead_lifecycle
    namespace: pack.real-estate-crm
    states:
      - captured
      - qualified
      - contacted
      - scheduled
      - offer
      - negotiate
      - closed
      - archived
      - lost
      
    transitions:
      - event: lead.form.submitted
        from: null
        to: captured
        
      - event: lead.score.calculated
        from: captured
        to: qualified
        
      - event: agent.assigned
        from: qualified
        to: contacted
        
      - event: viewing.confirmed
        from: contacted
        to: scheduled
        
      - event: offer.received
        from: scheduled
        to: offer
        
      - event: counter.offer.sent
        from: offer
        to: negotiate
        
      - event: offer.accepted
        from: [offer, negotiate]
        to: closed
        
      - event: follow_up.completed
        from: closed
        to: archived
        
      - event: opportunity.lost
        from: [captured, qualified, contacted, scheduled, offer, negotiate]
        to: lost
```

## Agents

### Growth Agent

**Purpose:** Drive lead volume through optimized landing pages and campaigns.

```yaml
# agents/growth.yaml
id: growth
displayName: "Growth Agent"
description: "Generates and optimizes lead capture campaigns"

capabilities:
  - id: landing_pages
    description: "Create and A/B test landing pages"
    
  - id: ad_campaigns
    description: "Launch and manage paid advertising"
    
  - id: a_b_testing
    description: "Test messaging and creative variants"
    
triggers:
  - type: threshold
    metric: lead_volume_7d
    condition: "< 20"
    action: increase_ad_spend
    
  - type: scheduled
    cron: "0 9 * * 1"  # Mondays at 9am
    action: review_campaign_performance
    
policies:
  - id: ad-copy-variants
    shared: true
    variants:
      - id: luxury-focused
        text: "Discover your dream home in [Neighborhood]"
        alpha: 15
        beta: 5
      - id: urgency-focused
        text: "Just listed: [Property] - won't last long!"
        alpha: 12
        beta: 4
      - id: investment-focused
        text: "ROI-positive properties in [Neighborhood]"
        alpha: 8
        beta: 8
```

### CRM Agent

**Purpose:** Manage the lead pipeline from capture to close.

```yaml
# agents/crm.yaml
id: crm
displayName: "CRM Agent"
description: "Manages lead pipeline and agent coordination"

capabilities:
  - id: lead_scoring
    description: "Score leads by conversion probability"
    
  - id: follow_ups
    description: "Time and send follow-up messages"
    
  - id: viewing_coordination
    description: "Schedule and confirm property viewings"
    
  - id: offer_tracking
    description: "Track offers from submission to response"
    
triggers:
  - type: event
    event: lead.captured
    action: score_and_route
    
  - type: threshold
    metric: response_time_p50
    condition: "> 5m"
    action: escalate_to_alerts
    
policies:
  - id: followup-timing
    shared: true
    default: "within_5_minutes"
    variants:
      - id: immediate
        timing: "0m"
        alpha: 20
        beta: 3
      - id: within_5_minutes
        timing: "5m"
        alpha: 15
        beta: 5
      - id: within_15_minutes
        timing: "15m"
        alpha: 10
        beta: 10
        
  - id: channel-preference
    shared: false
    channels:
      - whatsapp
      - email
      - sms
```

### Retention Agent

**Purpose:** Re-engage past clients for repeat business and referrals.

```yaml
# agents/retention.yaml
id: retention
displayName: "Retention Agent"
description: "Maintains relationships with past clients"

capabilities:
  - id: churn_detection
    description: "Identify at-risk relationships"
    
  - id: re_engagement
    description: "Send personalized re-engagement campaigns"
    
  - id: win_back
    description: "Special offers for lost opportunities"
    
triggers:
  - type: threshold
    metric: days_since_contact
    condition: "> 90"
    action: send_check_in
    
  - type: scheduled
    cron: "0 10 1 * *"  # First of month at 10am
    action: market_update_campaign
    
policies:
  - id: anniversary-touch
    shared: false
    timing: "365d_after_close"
    message_variant: "happy_home_anniversary"
```

## Flows

### Lead Capture Flow

```yaml
# flows/lead-capture.dag.yaml
id: lead-capture
description: "Capture, qualify, and route new leads"

nodes:
  - id: capture
    type: trigger
    event: lead.form.submitted
    
  - id: score
    type: agent
    agent: crm
    action: score_lead
    
  - id: route
    type: decision
    condition: score >= 70
    
  - id: assign_agent
    type: agent
    agent: crm
    action: assign_to_available_agent
    
  - id: notify_growth
    type: agent
    agent: growth
    action: log_conversion
    
  - id: send_welcome
    type: agent
    agent: crm
    action: send_welcome_message
    
edges:
  - from: capture
    to: score
    
  - from: score
    to: route
    
  - from: route
    to: assign_agent
    condition: high_score
    
  - from: route
    to: notify_growth
    condition: any_score
    
  - from: assign_agent
    to: send_welcome
```

### Viewing Scheduler Flow

```yaml
# flows/viewing-scheduler.dag.yaml
id: viewing-scheduler
description: "Coordinate property viewings between leads and agents"

nodes:
  - id: request
    type: trigger
    event: viewing.requested
    
  - id: check_availability
    type: agent
    agent: crm
    action: check_calendar_slots
    
  - id: confirm_time
    type: agent
    agent: crm
    action: send_confirmation_options
    
  - id: await_response
    type: wait
    timeout: 24h
    
  - id: schedule_viewing
    type: agent
    agent: crm
    action: create_calendar_event
    
  - id: send_reminder
    type: agent
    agent: crm
    action: send_24h_reminder
    trigger: 24h_before_event
    
  - id: log_no_show
    type: agent
    agent: crm
    action: mark_no_show
    trigger: 1h_after_event_if_unconfirmed
```

## Policies

### Follow-up Timing (Shared)

```yaml
# policies/followup-timing.yaml
id: followup-timing
shared: true
description: "Optimal timing for lead follow-up messages"

variants:
  - id: immediate
    description: "Respond within 1 minute"
    timing: "0-1m"
    success_rate: 0.38
    alpha: 38
    beta: 62
    
  - id: quick
    description: "Respond within 5 minutes"
    timing: "1-5m"
    success_rate: 0.28
    alpha: 28
    beta: 72
    
  - id: standard
    description: "Respond within 15 minutes"
    timing: "5-15m"
    success_rate: 0.18
    alpha: 18
    beta: 82
    
  - id: slow
    description: "Respond within 1 hour"
    timing: "15-60m"
    success_rate: 0.10
    alpha: 10
    beta: 90
    
  - id: too_late
    description: "Respond after 1 hour"
    timing: ">60m"
    success_rate: 0.05
    alpha: 5
    beta: 95
```

### Conversion Scripts (Shared)

```yaml
# policies/conversion-scripts.yaml
id: conversion-scripts
shared: true
description: "High-performing sales scripts for different lead types"

scripts:
  - id: first-time-buyer
    context: "lead.type == 'first_time_buyer'"
    opening: "Congratulations on starting your home search! I'll help you navigate every step..."
    alpha: 22
    beta: 8
    
  - id: investor
    context: "lead.type == 'investor'"
    opening: "I see you're looking at investment properties. Let me share the ROI analysis for this area..."
    alpha: 18
    beta: 6
    
  - id: relocator
    context: "lead.type == 'relocator'"
    opening: "Moving to a new city can be overwhelming. I'll be your local expert..."
    alpha: 15
    beta: 10
    
  - id: upsizer
    context: "lead.type == 'upsizer'"
    opening: "Ready for more space? Let's find the perfect home for your growing needs..."
    alpha: 12
    beta: 8
```

### Ad Copy Variants (Shared)

```yaml
# policies/ad-copy-variants.yaml
id: ad-copy-variants
shared: true
description: "High-performing ad creative for real estate"

surfaces:
  facebook:
    - id: lifestyle-dream
      headline: "Your Dream Home Awaits"
      body: "Discover properties that match your lifestyle in [Neighborhood]"
      image: "family-home-lifestyle.jpg"
      alpha: 45
      beta: 15
      
    - id: urgency-scarcity
      headline: "Just Listed: Won't Last"
      body: "New properties in [Neighborhood] are selling in days, not weeks"
      image: "sold-sign.jpg"
      alpha: 38
      beta: 22
      
    - id: social-proof
      headline: "Join 500+ Happy Homeowners"
      body: "See why [Neighborhood] residents love where they live"
      image: "community-event.jpg"
      alpha: 30
      beta: 20
      
  google:
    - id: search-intent
      headline: "Homes for Sale in [Neighborhood]"
      description: "Browse all listings. Updated every 15 minutes."
      alpha: 52
      beta: 18
```

## Copy

```json
// copy/en.json
{
  "agents": {
    "growth": {
      "name": "Growth Agent",
      "description": "Finds and attracts potential buyers",
      "actions": {
        "launch_campaign": "Launch New Campaign",
        "a_b_test": "Run Message Test"
      }
    },
    "crm": {
      "name": "CRM Agent",
      "description": "Manages your lead pipeline",
      "actions": {
        "follow_up": "Follow Up",
        "schedule_viewing": "Schedule Viewing",
        "send_offer": "Send Offer Update"
      }
    },
    "retention": {
      "name": "Retention Agent",
      "description": "Keeps past clients engaged",
      "actions": {
        "send_market_update": "Send Market Update",
        "request_referral": "Request Referral"
      }
    }
  },
  "flows": {
    "lead-capture": {
      "name": "Lead Capture",
      "description": "Automatically capture and qualify new leads"
    },
    "viewing-scheduler": {
      "name": "Viewing Scheduler",
      "description": "Coordinate property showings with leads"
    },
    "offer-pipeline": {
      "name": "Offer Pipeline",
      "description": "Track offers from submission to close"
    }
  },
  "states": {
    "captured": "New Lead",
    "qualified": "Qualified",
    "contacted": "Contacted",
    "scheduled": "Viewing Scheduled",
    "offer": "Offer Received",
    "negotiate": "In Negotiation",
    "closed": "Closed",
    "archived": "Past Client",
    "lost": "Lost Opportunity"
  }
}
```

## Targets

```yaml
# pack.yaml targets section
targets:
  - metric: lead_conversion_rate
    displayName: "Lead Conversion Rate"
    description: "Percentage of leads that become clients"
    goal: ">= 0.25"
    baseline: 0.15
    current: 0.18
    
  - metric: response_time_p50
    displayName: "Response Time (Median)"
    description: "Time from lead inquiry to first response"
    goal: "<= 5m"
    baseline: 30m
    current: 12m
    
  - metric: viewings_per_week
    displayName: "Weekly Viewings"
    description: "Number of property showings scheduled"
    goal: ">= 10"
    baseline: 5
    current: 7
    
  - metric: days_to_close
    displayName: "Days to Close"
    description: "Average time from first contact to closing"
    goal: "<= 45d"
    baseline: 60d
    current: 52d
    
  - metric: repeat_referral_rate
    displayName: "Repeat & Referral Rate"
    description: "Percentage of business from past clients"
    goal: ">= 0.40"
    baseline: 0.25
    current: 0.28
```

## Installation

```bash
# Install from official registry
spidernet pack install real-estate-crm

# Verify installation
spidernet pack list

# View pack dashboard
curl https://cockpit.local/platform/packs/real-estate-crm
```

## Related

- `SPEC.md` — Feature Pack specification
- `LIFECYCLE.md` — Pack lifecycle state machine
- `docs/adr/0001-agent-roster-reconciliation.md` — Dynamic agents

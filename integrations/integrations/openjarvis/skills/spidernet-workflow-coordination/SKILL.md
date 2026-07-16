---
name: spidernet-workflow-coordination
display_name: SpiderNetOS Workflow Coordination
description: Coordinate multi-agent workflows across Atlas, Nexus, Forge, and Sentinel via Meta-Planner.
category: automation
---

# SpiderNetOS Workflow Coordination

Use this skill when an operator asks to automate, orchestrate, or run cross-agent workflows in SpiderNetOS.

## When to use

- Creating or executing flows
- Routing tasks to the correct agent (Atlas, Forge, Nexus, Prism, Sentinel, Hannah)
- Budget-aware dispatch through Cost Governor

## Steps

1. Parse intent with AtlasIntentCompiler (pattern match first, LLM fallback).
2. Dispatch only through Meta-Planner — agents never call agents directly.
3. Record outcomes in event_log for RL and weekly review.

## Example

User: "Run my lead nurture flow and alert me if budget is exceeded"

→ Intent: execute_flow → Nexus + Sentinel monitoring hook

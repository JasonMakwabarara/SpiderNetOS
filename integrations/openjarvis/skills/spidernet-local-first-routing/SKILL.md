---
name: spidernet-local-first-routing
display_name: Local-First Intelligence Routing
description: Route queries to local OpenJarvis when suitable; escalate to cloud only when necessary (Intelligence Per Watt).
category: efficiency
---

# Local-First Intelligence Routing

Inspired by OpenJarvis Intelligence Per Watt research — prefer on-device inference for single-turn chat and simple reasoning.

## Routing policy

1. Pattern-matched slash commands → zero-token AtlasIntentCompiler path
2. Simple chat → OpenJarvis `simple` agent or local model
3. Tool-heavy tasks → `orchestrator` or `native_react`
4. Code/flow tasks → `code_assistant` → Forge
5. Cloud LLM only when local confidence < threshold or tenant policy requires it

## Telemetry

Record `estimated_cost_usd`, `source`, and `local_first` on every Atlas interaction for RL bandit training.

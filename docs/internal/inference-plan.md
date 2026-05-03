# SpiderNet OS — Inference plan (canonical)

**Owner:** Platform / Intelligence  
**Status:** Consolidated reference (architecture + rollout expectations)  
**Last updated:** 2026-04-30  

This note is the single place that ties together **how inference works today in code**, **how it is bounded in production review**, and **where scale-up is documented**. Older scattered mentions remain valid; prefer this file when deciding capacity, tiering, and provider strategy.

---

## 1. Runtime shape

| Layer | Role |
| ----- | ---- |
| **Inference plane** | FastAPI service: policy routing, multi-provider execution, embeddings, health. Entry: [`inference/main.py`](../../inference/main.py). |
| **Backend** | Calls inference over HTTP (`INFERENCE_URL` in Compose / env); not assumed to embed models in PHP. |
| **External APIs** | OpenAI and Anthropic (HTTPS) — see production review interoperability. |
| **Local / optional** | Ollama: used for embeddings and generate paths when selected in routing — URLs from inference config (`OLLAMA_URL`). |

Threat model boundary (API ↔ inference ↔ providers): [`threat-model.md`](threat-model.md).

---

## 2. Routing and policy (source of truth)

**Authoritative behaviour** lives in Python, not only in prose:

| File | Responsibility |
| ---- | ---------------- |
| [`inference/policy_router.py`](../../inference/policy_router.py) | Rank models by **cost ceiling**, **latency cap**, **tenant tier**; pick primary + fallbacks; call **OpenAI** or **ollama**; retries and usage recording. |

High-level behaviour (matches module docstrings):

- **CostGovernor / budget**: requests with exhausted budget must not proceed (blocked at routing).
- **Multi-model**: ranked candidate list per policy; failover across providers/models.
- **Embeddings**: separate FastAPI route in `main.py` calling Ollama’s embed API (see [`inference/main.py`](../../inference/main.py)).

---

## 3. Voice and operational linkage

Voice runbook assumes inference remains healthy and, under load, can scale **Ollama-facing** capacity (replicas): [`voice-operations.md`](voice-operations.md) — see alerting on inference latency / Ollama.

---

## 4. Production scalability and roadmap (review cross-links)

The production review anchors **tiered scaling** for the inference plane and defers GPU to later tiers:

- **§ 3.2 — Horizontal scaling readiness** — Planned k3s/HPA trajectory; inference autoscaling framed around **queue depth / load** rather than single-node docker-compose forever.  
  → [SPIDERNETOS_PRODUCTION_REVIEW.md — § 3.2 Horizontal Scaling Readiness](../../SPIDERNETOS_PRODUCTION_REVIEW.md#32-horizontal-scaling-readiness)

- **§ 5.3 — Optimization opportunities** — **Long-term (Tier 3)** includes **GPU-accelerated inference for LLM calls** alongside broader platform optimisations (streaming, CQRS, edge cache, etc.).  
  → [SPIDERNETOS_PRODUCTION_REVIEW.md — § 5.3 Optimization Opportunities](../../SPIDERNETOS_PRODUCTION_REVIEW.md#53-optimization-opportunities)

Related context in the same document:

- Inference plane described as FastAPI orchestration (**§ 2.1**).  
  → [`../../SPIDERNETOS_PRODUCTION_REVIEW.md#21-system-components`](../../SPIDERNETOS_PRODUCTION_REVIEW.md#21-system-components)
- External LLM integrations (**§ 17.1**).  
  → [`../../SPIDERNETOS_PRODUCTION_REVIEW.md#171-integration-points`](../../SPIDERNETOS_PRODUCTION_REVIEW.md#171-integration-points)

**Note:** GitHub (and many Markdown viewers) generate heading anchors automatically; if a link jumps to the wrong line, search the review for **“3.2 Horizontal Scaling Readiness”** or **“5.3 Optimization Opportunities”**.

---

## 5. Summary decision guide

| Need | Typical approach in this codebase |
| ---- | --------------------------------- |
| Default production path | Hosted APIs via inference router + governance; scale inference **replicas** and manage quotas. |
| Local / cost-controlled path | Ollama in policy table; embeddings already Ollama-oriented. |
| Heavy self-hosted throughput or lowest per-token unit cost | Align with review **Tier 3**: dedicated **GPU inference** footprint (after k3s and plane autoscaling are in place). |

---

## 6. Related docs

| Document | Topic |
| -------- | ----- |
| [`REVIEW_SUMMARY.md`](../../REVIEW_SUMMARY.md) | Mentions multi-model inference routing / policy framing. |
| [`threat-model.md`](threat-model.md) | STRIDE scope including inference plane. |
| [`voice-operations.md`](voice-operations.md) | Inference + Ollama operational hooks. |

---

## Revision history

| Date | Change |
| ---- | ------ |
| 2026-04-30 | Initial consolidation with links to `policy_router.py` and review §§ 3.2 / 5.3. |

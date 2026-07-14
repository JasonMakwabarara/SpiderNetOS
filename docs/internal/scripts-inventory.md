# Auxiliary scripts inventory

SpiderNet OS ships automation that lives **outside** the core `backend/`, `cockpit/`, `intelligence/`, and `inference/` planes. Treat these as operator / R&D tooling — they are documented here so newcomers are not overwhelmed by repository root clutter.
> **2026-07-07 reorganization:** operator/R&D scripts formerly at the repo
> root now live in [`ops/`](../../ops/) (fix-*, mechanic-*, tunnel helpers,
> Hermes setup, site generators, cert/backup helpers). Loose spec documents
> and superseded self-review reports moved to [`docs/archive/`](../archive/).
> Update any muscle-memory paths accordingly — filenames are unchanged.


## Core product entry points

| Path | Purpose |
| --- | --- |
| `Makefile` | Local developer shortcuts (Docker, compose, codegen). |
| `docker-compose.yml` | Primary local / demo stack wiring. |
| `run-tests.sh` | Pytest-heavy harness; optionally chains training gates. |
| `.github/workflows/ci.yml` | PR CI: Laravel Pint, PHPUnit **CiFast**, cockpit build, Ruff (intelligence/inference), feature-pack validation. |

## Training data & Atlas freshness

| Path | Purpose |
| --- | --- |
| `scripts/convert_cursor_markdown_to_jsonl.py` | Cursor-export → JSONL training splits. |
| `scripts/eval_training_data_quality.py` | Dataset quality gate. |
| `scripts/training-gate.ps1` | One-shot Windows wrapper + optional Atlas copy. |
| `.github/workflows/training-data-quality-gate.yml` | Validates committed JSONL bundles. |
| `.github/workflows/atlas-training-refresh-nightly.yml` | Nightly + push transcripts → refresh `intelligence/atlas/training/current`. |

## Site / infra repair (examples)

Historical one-off remediation lives at repo root: `fix-nginx*.sh`, `fix-cloudflare-tunnel.sh`, `mechanic-*.py`, `generate-sites.py`, etc. Prefer **staging runbooks** in `deploy/` before executing these against production hosts.

## Hermes & Discord experimental paths

`hermes-runtime/`, `hermes-skills/`, and standalone `discord_bot.py` iterate on agent bridges. They coexist with Tier-1 Laravel + intelligence workers described in [`docs/security/threat-model.md`](security/threat-model.md).

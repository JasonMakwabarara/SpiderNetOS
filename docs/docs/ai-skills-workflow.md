# AI Skills Workflow

SpiderNetOS-native **skills-style engineering loops** for AI-assisted development. These patterns improve consistency, reduce token waste, and build durable team habits.

---

## Core Loops

### 1. Diagnose Loop (`/diagnose`)

Reproduce → Hypothesize → Instrument → Fix → Verify

**When to use:**
- Production incidents (500 errors, route mismatches, theme/CSS regressions)
- Configuration/env fallback failures
- Cache/deploy hardening issues

**Command recipe:**
```bash
# 1. Reproduce
php artisan test --filter=<failing_test>
curl -s http://localhost:8000/api/health | jq .

# 2. Hypothesize (document in issue/PR)
echo "Root cause: [describe]" >> diagnosis.md

# 3. Instrument (add logging/metrics)
# 4. Fix (minimal upstream change)
# 5. Verify (test + evidence)
php artisan test --filter=<failing_test>
```

---

### 2. TDD Slice Loop (`/tdd`)

Red → Green → Refactor

**When to use:**
- New features with clear acceptance criteria
- Bug fixes requiring regression tests
- API contract changes

**Command recipe:**
```bash
# 1. Red (write failing test)
php artisan make:test Feature/TestName
./vendor/bin/phpunit --filter=TestName

# 2. Green (minimal implementation)
# 3. Refactor (with test guard)
./vendor/bin/phpunit --filter=TestName
```

---

### 3. Training Data Loop (`/train`)

Convert → Evaluate → Gate → Apply

**When to use:**
- Processing Cursor conversation exports
- Building datasets for model fine-tuning
- Quality-gating training examples

**Command recipes:**

```bash
# Full pipeline (POSIX)
python scripts/convert_cursor_markdown_to_jsonl.py \
  --input "transcripts/export.md" \
  --out-dir "training_data/bundle_001" \
  --min-quality 2 \
  --min-quality-sft 2 \
  --quality-report

python scripts/eval_training_data_quality.py \
  --sft training_data/bundle_001/sft.jsonl \
  --preference training_data/bundle_001/preference.jsonl \
  --min-quality 2 \
  --min-sft-rows 20 \
  --min-preference-rows 20
```

```powershell
# PowerShell with auto-apply to Atlas
powershell -ExecutionPolicy Bypass -File scripts/training-gate.ps1 `
  -InputPath "transcripts/export.md" `
  -OutDir "training_data/bundle_001"
```

---

## Quality Gates

Training data must pass **all** gates:

| Gate | Threshold | Purpose |
|------|-----------|---------|
| `min_sft_rows` | 20 | Sufficient supervised fine-tuning examples |
| `min_preference_rows` | 20 | Sufficient preference pairs |
| `min_distinct_preference_ratio` | 0.95 | Chosen/rejected must differ |
| `sft_quality_hit_ratio` | 0.8 | 80% of SFT rows quality ≥ 2 |
| `preference_quality_hit_ratio` | 0.8 | 80% of preference rows quality ≥ 2 |

**Quality scoring (0-4):**
- +1 for verification/result wording
- +1 for executed test/command evidence
- +1 for measurable outcomes (9/9, passed, exit code)
- +1 for multiple code references (backticks)

---

## Directory Structure

```
training_data/
├── bundle_001/
│   ├── sft.jsonl              # Supervised fine-tuning data
│   ├── preference.jsonl     # Preference pairs (chosen/rejected)
│   ├── quality_report.json  # Score distributions
│   └── quality_gate.json    # Gate pass/fail result
├── bundle_002/
│   └── ...
└── transcripts/             # Raw markdown exports
    ├── export_2024_05.md
    └── export_2024_06.md
```

---

## GitHub Actions CI

**Automatic gate running:** `.github/workflows/training-data-quality-gate.yml`

Triggers on:
- Pull requests modifying `**/sft.jsonl` or `**/preference.jsonl`
- Pushes to `main` modifying training data
- Manual `workflow_dispatch`

Artifacts uploaded as `quality-gate-reports-{run_id}` for PR review.

---

## Atlas Integration

Gated training data auto-copies to:
```
intelligence/atlas/training/current/
├── sft.jsonl
└── preference.jsonl
```

**Nightly refresh:** `.github/workflows/atlas-training-refresh-nightly.yml`

---

## Best Practices

1. **Label outcomes** in conversation exports: `good_fix`, `partial_fix`, `regression`, `needs_followup`
2. **Extract reusable patterns** from debugging sessions
3. **Keep ~20-30% as eval set** — don't train on everything
4. **Use rule-based rejected samples** (missing verification, vague root cause) for stronger preference training
5. **Gate before merge** — failed gates block PRs

---

## References

- Matt Pocock Skills: https://github.com/mattpocock/skills
- Conversion script: `scripts/convert_cursor_markdown_to_jsonl.py`
- Evaluator: `scripts/eval_training_data_quality.py`
- PowerShell helper: `scripts/training-gate.ps1`

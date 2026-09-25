# business_launch — "Atlas, I want to start a business"

Deterministic back-end for the `business-launch` feature pack
(`packages/feature-packs/business-launch`, plan D7 §5). Laravel's
`App\Services\Launch\BusinessLaunchService` runs the interview and writes the
brain; this service does the parts that must never come from an LLM.

**Not legal or financial advice** — every response carries that line.

| Module | What it does |
|---|---|
| `finance_model.py` | `finance/assumptions.yaml` → month-by-month projection in Python → summary (year-1 revenue, gross margin, EBITDA, cash low point + month, runway months, break-even month, per-year table) → workbook laid out from `templates/finance-model.yaml` with **live formulas** (Assumptions, Revenue, Costs, P&L, CashFlow, Balance) → `.xlsx` via openpyxl when installed. |
| `formula_eval.py` | Dependency-free evaluator for exactly the formula grammar the model writes. `verify_workbook()` evaluates every monthly cell and checks it against the Python projection, so the spreadsheet and the summary cannot drift (`formulas_verified`). |
| `docgen.py` | `templates/business-plan.md.j2` → markdown (always) → `.docx` (python-docx, only when importable) → `.pdf` (weasyprint, else pandoc, only when present). Missing renderers are reported per format with a reason; nothing optional is imported at module import time. |
| `jurisdiction.py` | `jurisdictions/{uk,za,zw}.yaml` → checklist filtered by structure and triggers (hiring, personal data, licensed activity, VAT threshold), deadlines relative to incorporation turned into dates, staleness awareness after `review_after_days` (180). |
| `app.py` | FastAPI on `:9010`. |

## Endpoints

```
GET  /health
POST /finance/model        {"assumptions": {...}, "include_workbook": true}
POST /docgen/render        {"template": "business-plan.md.j2", "context": {...}, "formats": ["md", "docx", "pdf"]}
POST /compliance/checklist {"jurisdiction": "uk", "incorporated_on": "2026-10-01", "profile": {"structure": "company", "hires": true}}
```

`POST /finance/model` assumptions (percentages are whole numbers, `10` = 10%):

```yaml
currency: GBP            # three-letter code
starting_cash: 10000
setup_costs: 2000        # expensed in month 1
price_per_unit: 100
units_month_one: 20
monthly_growth_pct: 10
cost_of_sale_pct: 40
fixed_monthly_costs: 1500
marketing_monthly: 300   # optional, default 0
months: 36               # optional, 1..120
```

When `BACKEND_INTERNAL_KEY` is set, every route but `/health` requires the
same value in `X-Internal-Key`.

## Run

```bash
cd intelligence
pip install -r requirements.txt fastapi uvicorn   # openpyxl, pyyaml, jinja2 are in requirements.txt
python -m services.business_launch.app            # or: uvicorn services.business_launch.app:app --port 9010
```

Optional renderers: `pip install python-docx` for `.docx`; `pip install weasyprint`
(plus its system libraries) or a `pandoc` binary with a PDF engine for `.pdf`.

The pack directory is found at `../packages/feature-packs/business-launch`
relative to the repository root; containers that copy only `intelligence/` must
mount the pack and set `BUSINESS_LAUNCH_PACK_DIR`.

## Tests (offline)

```bash
python -m pytest intelligence/tests/business_launch -q
```

Golden values pin the finance model; workbook tests that need openpyxl skip
cleanly when it is not installed, while formula verification always runs.

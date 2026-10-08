"""Docgen — business-plan template, disclaimer, formatting, optional renderers."""

from __future__ import annotations

import base64

import pytest

from intelligence.services.business_launch import DISCLAIMER, docgen, finance_model as fm
from intelligence.services.business_launch.docgen import DocgenError

ASSUMPTIONS = {
    "currency": "GBP", "starting_cash": 10000, "setup_costs": 2000, "price_per_unit": 100,
    "units_month_one": 20, "monthly_growth_pct": 10, "cost_of_sale_pct": 40,
    "fixed_monthly_costs": 1500, "marketing_monthly": 300, "months": 12,
}


def full_context() -> dict:
    model = fm.run_model(ASSUMPTIONS, include_workbook=False)
    return {
        "generated_on": "2026-09-16",
        "jurisdiction": {"code": "uk", "name": "United Kingdom"},
        "business": {"name": "Tidy Tuesdays", "one_liner": "Weekly office cleaning for small studios in Leeds.",
                     "stage_today": "Two pilot clients.", "differentiator": "Same cleaner every week, booked by WhatsApp."},
        "alignment": {"origin": "I cleaned offices for ten years.", "mission": "Leave every studio spotless.",
                      "vision": "Twenty cleaners across Yorkshire.", "ninety_day_target": "Ten paying studios."},
        "offer": {"core_offer": "A two-hour weekly clean.", "problem_solved": "No more rota arguments.",
                  "pricing_model": "£100 per visit.", "proof_points": "Two pilots renewed."},
        "customers": {"ideal_customer": "Design studios of 5-15 people.", "disqualifiers": "Large corporates.",
                      "buying_trigger": "A client visit.", "where_customers_are": "Leeds creative meetups.",
                      "common_objection": "Price — we answer with the time saved."},
        "brand": {"preferred_tone": "warm and straight-talking", "never_say": "hype or guarantees"},
        "market": {"competitors": "Big contract cleaners at £140.", "market_geography": "Leeds",
                   "market_size_guess": "About 400 studios.", "market_trend": "More small studios since 2024.",
                   "research_status": "founder_guess"},
        "finance": {"currency": "GBP", "summary": model["summary"], "years": model["summary"]["years"],
                    "assumptions": model["assumptions"]},
        "compliance": {"structure": "Private company limited by shares (Ltd)", "items": [
            {"title": "Verify your identity with Companies House (ECCTA)", "owner": "founder", "due_on": None, "rule": None},
            {"title": "File your first accounts", "owner": "advisor", "due_on": "2028-07-01", "rule": None},
        ]},
        "gtm": {"launch_channel": "LinkedIn and meetups", "first_ten_customers": "Studios on my street.",
                "launch_date": "November 2026", "marketing_budget": "£300 a month", "success_signal": "Ten recurring clients."},
        "narrative": {
            "swot": {"strengths": ["Ten years' experience"], "weaknesses": ["One cleaner"],
                     "opportunities": ["Studio growth"], "threats": ["Price competition"]},
            "okrs": {"objective": "Prove the weekly model", "key_results": ["10 paying studios", "90% renewal"]},
        },
    }


def test_full_context_renders_every_section_with_model_numbers():
    md = docgen.render_markdown(full_context())

    assert md.startswith("# Tidy Tuesdays — Business plan")
    assert "> **Not legal or financial advice.**" in md
    assert md.count(DISCLAIMER.rstrip(".")) >= 2  # header and footer
    for heading in ["## 1. Summary", "## 2. The offer", "## 3. Customers", "## 4. Market", "## 5. Go-to-market",
                    "## 6. The numbers", "## 7. SWOT", "## 8. First-quarter objectives",
                    "## 9. Registration and compliance (United Kingdom)"]:
        assert heading in md

    # Numbers come straight from the model summary, formatted — never re-derived.
    assert "| Revenue, year 1 | £42,768.57 |" in md
    assert "| Gross margin, year 1 | 60% |" in md
    assert "| Lowest cash balance | £6,326.12 (month 5) |" in md
    assert "cash lasts the full 12 months modelled" in md
    assert "| Break-even month | month 6 |" in md
    assert "| 1 | £42,768.57 | £25,661.14 | 60% | £2,061.14 | £12,061.14 |" in md
    assert "growing 10% a month" in md

    assert "| File your first accounts | advisor | 2028-07-01 |" in md
    assert "- 90% renewal" in md
    assert "[to confirm]" not in md
    assert "None" not in md


def test_missing_context_renders_placeholders_not_guesses():
    md = docgen.render_markdown({"business": {"name": "Idea Co"}})
    assert md.startswith("# Idea Co — Business plan")
    assert "[to confirm]" in md
    assert "run the finance model to fill this section" in md
    assert "complete the compliance stage to fill this section" in md
    assert "None" not in md
    assert "Not legal or financial advice." in md


def test_disclaimer_is_added_when_a_template_forgets(tmp_path):
    (tmp_path / "bare.md.j2").write_text("# {{ title }}\n\nHello.\n", encoding="utf-8")
    md = docgen.render_markdown({"title": "Bare"}, "bare.md.j2", template_dir=tmp_path)
    assert md.startswith("> **Not legal or financial advice.**")


@pytest.mark.parametrize("name", ["../secrets.md.j2", "plan.txt", "Business-Plan.md.j2", "a/b.md.j2"])
def test_template_names_cannot_escape_the_templates_folder(name):
    with pytest.raises(DocgenError):
        docgen.render_markdown({}, name)


def test_money_and_percent_filters():
    assert docgen.format_money(1234.5, "ZAR") == "R1,234.50"
    assert docgen.format_money(-5000, "USD") == "-$5,000.00"
    assert docgen.format_money(10, "CHF") == "CHF 10.00"
    assert docgen.format_money(None, "GBP") == "[to confirm]"
    assert docgen.format_pct(60.0) == "60%"
    assert docgen.format_pct(12.345) == "12.35%"
    assert docgen.format_pct(None) == "n/a"


def test_markdown_to_html_handles_tables_quotes_and_lists():
    page = docgen.markdown_to_html("# T\n\n> **Not legal or financial advice.**\n\n| a | b |\n|---|---|\n| 1 | <br> |\n\n- one\n- two\n")
    assert "<h1>T</h1>" in page
    assert "<blockquote><strong>Not legal or financial advice.</strong></blockquote>" in page
    assert "<table><thead><tr><th>a</th><th>b</th></tr></thead><tbody><tr><td>1</td><td><br></td></tr></tbody></table>" in page
    assert "<ul><li>one</li><li>two</li></ul>" in page


def test_render_reports_each_format_honestly():
    result = docgen.render(full_context(), ["md", "docx", "pdf"])
    caps = docgen.capabilities()

    assert result["formats"]["md"] == {"ok": True, "reason": None}
    assert result["disclaimer"] == DISCLAIMER
    assert result["formats"]["docx"]["ok"] is caps["docx"]
    assert result["formats"]["pdf"]["ok"] is caps["pdf"] or result["formats"]["pdf"]["reason"]
    if caps["docx"]:
        assert base64.b64decode(result["docx_b64"])[:2] == b"PK"
    else:
        assert "python-docx" in result["formats"]["docx"]["reason"]
        assert "docx_b64" not in result
    if not caps["pdf"]:
        assert "no PDF renderer" in result["formats"]["pdf"]["reason"]
        assert "pdf_b64" not in result

    with pytest.raises(DocgenError):
        docgen.render(full_context(), ["odt"])


def test_docx_contains_the_disclaimer_when_python_docx_is_installed():
    docx = pytest.importorskip("docx")
    import io

    data, reason = docgen.markdown_to_docx(docgen.render_markdown(full_context()))
    assert reason is None
    text = "\n".join(p.text for p in docx.Document(io.BytesIO(data)).paragraphs)
    assert "Not legal or financial advice." in text


def test_docgen_endpoint():
    pytest.importorskip("fastapi")
    from fastapi.testclient import TestClient

    from intelligence.services.business_launch.app import app

    client = TestClient(app)
    response = client.post("/docgen/render", json={"context": full_context(), "formats": ["md"]})
    assert response.status_code == 200
    assert "£42,768.57" in response.json()["markdown"]
    assert client.post("/docgen/render", json={"template": "../x.md.j2", "context": {}}).status_code == 422

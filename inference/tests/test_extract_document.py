"""
Unit tests for /v1/extract-document pure helpers:
- _extract_json_deep (brace-counting deep JSON extractor)
- _heuristic_extract (regex fallback extraction)
- _clamp_intent (intent enum clamping for /v1/classify)
No network, no server.
"""
import pytest
from main import (
    ExtractedField,
    _clamp_intent,
    _coerce_amount,
    _extract_json_deep,
    _fields_from_dict,
    _heuristic_extract,
    _heuristic_response,
    _normalize_date,
    _strip_data_url,
)

# ─── _extract_json_deep ──────────────────────────────────────────────────────


class TestExtractJsonDeep:
    def test_plain_json_object(self):
        assert _extract_json_deep('{"a": 1}') == {"a": 1}

    def test_nested_line_items_with_trailing_prose(self):
        text = (
            "Here is the extraction you asked for:\n"
            '{"merchant": {"value": "Fresh Mart", "confidence": 0.92}, '
            '"line_items": [{"description": "Milk", "amount": 1.89, "confidence": 0.8}, '
            '{"description": "Bread", "amount": 2.49, "confidence": 0.85}]}\n'
            "Let me know if you need anything else!"
        )
        parsed = _extract_json_deep(text)
        assert parsed is not None
        assert parsed["merchant"]["value"] == "Fresh Mart"
        assert len(parsed["line_items"]) == 2
        assert parsed["line_items"][1]["amount"] == 2.49

    def test_braces_inside_string_values(self):
        text = 'noise {"merchant": {"value": "Bob\'s {Corner} Shop"}, "total": {"value": 9.99}} trailing'
        parsed = _extract_json_deep(text)
        assert parsed["merchant"]["value"] == "Bob's {Corner} Shop"
        assert parsed["total"]["value"] == 9.99

    def test_escaped_quotes_inside_strings(self):
        text = 'x {"merchant": {"value": "Say \\"hi\\" mart"}} y'
        parsed = _extract_json_deep(text)
        assert parsed["merchant"]["value"] == 'Say "hi" mart'

    def test_markdown_fenced_json(self):
        text = '```json\n{"total": {"value": 12.5, "confidence": 0.9}, "line_items": []}\n```'
        parsed = _extract_json_deep(text)
        assert parsed["total"]["value"] == 12.5

    def test_skips_broken_object_finds_valid_one(self):
        text = '{broken {"a": [1, 2, {"b": 3}]} also-broken'
        parsed = _extract_json_deep(text)
        assert parsed == {"a": [1, 2, {"b": 3}]}

    def test_no_json_returns_none(self):
        assert _extract_json_deep("no json here at all") is None
        assert _extract_json_deep("") is None
        assert _extract_json_deep("{never closed") is None

    def test_top_level_array_not_accepted(self):
        # Contract is a dict; a bare array has no top-level object to return.
        assert _extract_json_deep('[1, 2, 3]') is None


# ─── heuristic extractor ─────────────────────────────────────────────────────


SAMPLE_RECEIPT = """FRESH MART GROCERY
123 High Street
Date: 12/03/2026
Milk 2L          1.89
Bread            2.49
Eggs x12         3.99
Subtotal         8.37
VAT              0.42
TOTAL           $8.79
CASH            10.00
CHANGE           1.21
"""


class TestHeuristicExtract:
    def test_sample_receipt_fields(self):
        result = _heuristic_extract(SAMPLE_RECEIPT)

        assert result["merchant"]["value"] == "FRESH MART GROCERY"
        assert 0 < result["merchant"]["confidence"] <= 0.4

        # Boosted TOTAL line wins over the larger unboosted CASH amount.
        assert result["total"]["value"] == 8.79
        assert result["total"]["confidence"] == 0.4

        assert result["date"]["value"] == "2026-03-12"
        assert result["tax"]["value"] == 0.42
        assert result["currency"]["value"] == "USD"

    def test_sample_receipt_line_items(self):
        result = _heuristic_extract(SAMPLE_RECEIPT)
        descriptions = [item["description"] for item in result["line_items"]]
        assert "Milk 2L" in descriptions
        assert "Bread" in descriptions
        assert "Eggs x12" in descriptions
        # Totals, tax, and payment lines are not line items.
        joined = " ".join(descriptions).lower()
        assert "total" not in joined
        assert "vat" not in joined
        assert "cash" not in joined
        assert "change" not in joined

    def test_all_confidences_capped_at_0_4(self):
        result = _heuristic_extract(SAMPLE_RECEIPT)
        for name in ("merchant", "date", "total", "tax", "currency"):
            assert result[name]["confidence"] <= 0.4
        for item in result["line_items"]:
            assert item["confidence"] <= 0.4

    def test_no_boost_falls_back_to_max_amount(self):
        text = "CORNER SHOP\nWidget 3.50\nGadget 7.25\n"
        result = _heuristic_extract(text)
        assert result["total"]["value"] == 7.25
        assert result["total"]["confidence"] == 0.25

    def test_amount_due_boost(self):
        text = "ACME UTILITIES\nUsage charge 55.00\nAmount Due: 61.60\nLate fee if unpaid 75.00\n"
        result = _heuristic_extract(text)
        assert result["total"]["value"] == 61.6
        assert result["total"]["confidence"] == 0.4

    def test_empty_text(self):
        result = _heuristic_extract("   \n  ")
        assert result["merchant"]["value"] is None
        assert result["total"]["value"] is None
        assert result["date"]["value"] is None
        assert result["line_items"] == []

    def test_heuristic_response_wrapper(self):
        resp = _heuristic_response(SAMPLE_RECEIPT)
        assert resp.method == "heuristic"
        assert resp.model == "heuristic"
        assert resp.provider == "local"
        assert resp.cost == 0.0
        assert resp.tokens_used == 0
        assert resp.fields.total.value == 8.79
        assert 0.0 < resp.overall_confidence <= 0.4


# ─── date normalization ──────────────────────────────────────────────────────


class TestNormalizeDate:
    @pytest.mark.parametrize("raw,expected", [
        ("2026-03-12", "2026-03-12"),
        ("2026/03/12", "2026-03-12"),
        ("12/03/2026", "2026-03-12"),   # day-first preferred
        ("12/31/2026", "2026-12-31"),   # unambiguously US month-first
        ("12 Mar 2026", "2026-03-12"),
        ("March 12, 2026", "2026-03-12"),
        ("3rd Mar 2026", "2026-03-03"),
        ("12.03.2026", "2026-03-12"),
        ("12/03/26", "2026-03-12"),
        (None, None),
        ("", None),
        ("not a date", None),
    ])
    def test_formats(self, raw, expected):
        assert _normalize_date(raw) == expected


# ─── intent enum clamping (/v1/classify) ─────────────────────────────────────


class TestClampIntent:
    def test_valid_intent_default_enum(self):
        assert _clamp_intent("create_flow") == "create_flow"
        assert _clamp_intent("chat") == "chat"

    def test_invalid_intent_default_enum_falls_back_to_chat(self):
        assert _clamp_intent("hack_the_planet") == "chat"

    def test_custom_enum_valid(self):
        enum = ["approve_expense", "reject_expense", "escalate"]
        assert _clamp_intent("approve_expense", enum) == "approve_expense"

    def test_custom_enum_invalid_falls_back_to_first(self):
        enum = ["approve_expense", "reject_expense"]
        # "chat" is not in the custom enum, so first entry wins.
        assert _clamp_intent("chat", enum) == "approve_expense"
        assert _clamp_intent("bogus", enum) == "approve_expense"

    def test_custom_enum_containing_chat(self):
        enum = ["approve_expense", "chat"]
        assert _clamp_intent("bogus", enum) == "chat"

    def test_empty_custom_enum_uses_default(self):
        assert _clamp_intent("create_flow", []) == "create_flow"
        assert _clamp_intent("bogus", []) == "chat"

    def test_hardcoded_enum_not_used_when_custom_given(self):
        enum = ["approve_expense"]
        # "create_flow" is valid in the hardcoded enum but not the custom one.
        assert _clamp_intent("create_flow", enum) == "approve_expense"


# ─── validation/clamping helpers ─────────────────────────────────────────────


class TestFieldClamping:
    def test_confidence_clamped_to_unit_interval(self):
        assert ExtractedField(value="x", confidence=1.7).confidence == 1.0
        assert ExtractedField(value="x", confidence=-0.3).confidence == 0.0
        assert ExtractedField(value="x", confidence="nonsense").confidence == 0.0

    def test_fields_from_dict_normalizes_date_and_amounts(self):
        parsed = {
            "merchant": {"value": "  Fresh Mart  ", "confidence": 0.9},
            "date": {"value": "12/03/2026", "confidence": 0.8},
            "total": {"value": "$8.79", "confidence": 2.5},
            "tax": {"value": None, "confidence": 0.9},
            "currency": {"value": "usd", "confidence": 0.7},
            "line_items": [
                {"description": "Milk", "amount": "1.89", "confidence": 0.8},
                "not-a-dict-is-skipped",
            ],
        }
        fields = _fields_from_dict(parsed)
        assert fields.merchant.value == "Fresh Mart"
        assert fields.date.value == "2026-03-12"
        assert fields.total.value == 8.79
        assert fields.total.confidence == 1.0  # clamped
        assert fields.tax.value is None
        assert fields.tax.confidence == 0.0  # null value forces zero confidence
        assert fields.currency.value == "USD"
        assert len(fields.line_items) == 1
        assert fields.line_items[0].amount == 1.89

    def test_fields_from_dict_flat_values(self):
        # Models sometimes return flat values instead of {value, confidence}.
        fields = _fields_from_dict({"merchant": "Corner Shop", "total": 12.5})
        assert fields.merchant.value == "Corner Shop"
        assert fields.total.value == 12.5

    def test_coerce_amount(self):
        assert _coerce_amount("£1,234.50") == 1234.5
        assert _coerce_amount(7) == 7.0
        assert _coerce_amount("abc") is None
        assert _coerce_amount(None) is None

    def test_strip_data_url(self):
        assert _strip_data_url("data:image/png;base64,iVBORabc") == "iVBORabc"
        assert _strip_data_url("iVBORabc") == "iVBORabc"

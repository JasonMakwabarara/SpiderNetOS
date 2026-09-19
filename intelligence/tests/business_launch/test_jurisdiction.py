"""Jurisdiction packs — UK / ZA / ZW structure, selection, deadlines, staleness."""

from __future__ import annotations

from datetime import date

import pytest

from intelligence.services.business_launch import DISCLAIMER, jurisdiction as j

TODAY = date(2026, 9, 16)


def test_all_three_packs_load_and_are_current():
    assert j.available_codes() == ["uk", "za", "zw"]
    for code in ("uk", "za", "zw"):
        data = j.load(code)
        assert data["valid_as_of"] == TODAY
        assert data["review_after_days"] == 180
        assert data["checklist"], code
        assert {item["owner"] for item in data["checklist"]} <= {"founder", "atlas", "advisor"}
        assert "Not legal or financial advice" in data["disclaimer"]
        ids = [item["id"] for item in data["checklist"]]
        assert len(ids) == len(set(ids)), f"duplicate checklist ids in {code}"
        deadline_ids = {d["id"] for d in data.get("deadlines", [])}
        for item in data["checklist"]:
            if item.get("deadline"):
                assert item["deadline"] in deadline_ids, item["id"]
            for link in item.get("links", []):
                assert link.startswith("https://"), (code, item["id"], link)


def test_uk_eccta_identity_verification_and_atlas_never_incorporates():
    uk = j.load("uk")
    idv = uk["registration"]["identity_verification"]
    assert idv["mandatory_since"] == date(2025, 11, 18)
    assert idv["atlas_checks_field"] == "identity_verification_status"
    assert uk["registration"]["api"]["availability"] == "public_read"
    assert "check_identity_verification_status" in uk["registration"]["atlas_may"]
    assert "incorporate_company" in uk["registration"]["atlas_never"]

    result = j.checklist("uk", profile={"structure": "company"}, today=TODAY)
    by_id = {item["id"]: item for item in result["items"]}
    assert by_id["uk_verify_identity"]["owner"] == "founder"
    assert by_id["uk_atlas_check_identity_status"]["owner"] == "atlas"
    assert "never incorporates" in by_id["uk_atlas_check_identity_status"]["detail"]
    assert "uk_self_assessment" not in by_id  # sole-trader only
    assert result["registration"]["atlas_never"][0] == "incorporate_company"
    assert result["disclaimer"].startswith("Not legal or financial advice.")
    assert DISCLAIMER in result["disclaimer"]


def test_za_cipc_apiverse_companies_and_beneficial_ownership():
    za = j.load("za")
    api = za["registration"]["api"]
    assert api["availability"] == "partner_api"
    assert "APIVerse" in api["name"] and "Beneficial Ownership" in api["name"]
    assert "file_beneficial_ownership" in za["registration"]["atlas_never"]
    result = j.checklist("za", profile={"structure": "company"}, today=TODAY)
    ids = {item["id"] for item in result["items"]}
    assert {"za_register_company", "za_beneficial_ownership", "za_atlas_check_registry"} <= ids


def test_zw_is_portal_only():
    zw = j.load("zw")
    assert zw["registration"]["api"]["availability"] == "portal_only"
    assert zw["registration"]["api"]["name"] is None
    result = j.checklist("zw", today=TODAY)
    assert result["registration"]["api_availability"] == "portal_only"
    assert any(item["id"] == "zw_atlas_portal_only" and item["owner"] == "atlas" for item in result["items"])


def test_incorporation_relative_deadlines_become_dates():
    uk = j.checklist("uk", incorporated_on="2026-01-31", profile={"structure": "company"}, today=TODAY)
    by_id = {item["id"]: item for item in uk["items"]}
    assert by_id["uk_first_accounts"]["due_on"] == "2027-10-31"            # + 21 months
    assert by_id["uk_confirmation_statement"]["due_on"] == "2027-02-14"    # + 12 months + 14 days
    assert by_id["uk_confirmation_statement"]["recurring"] == "annual"
    assert by_id["uk_corporation_tax"]["due_on"] is None                   # relative to trading start
    assert "3 months" in by_id["uk_corporation_tax"]["rule"]

    za = j.checklist("za", incorporated_on="2026-03-02", profile={"structure": "company"}, today=TODAY)
    za_by_id = {item["id"]: item for item in za["items"]}
    # 2027-03-02 (Tue) + 30 business days → 2027-04-13
    assert za_by_id["za_annual_return"]["due_on"] == "2027-04-13"
    assert za_by_id["za_beneficial_ownership"]["due_on"] == "2026-03-02"


def test_date_helpers():
    assert j.add_months(date(2026, 1, 31), 1) == date(2026, 2, 28)
    assert j.add_months(date(2027, 12, 15), 2) == date(2028, 2, 15)
    assert j.add_business_days(date(2026, 9, 18), 1) == date(2026, 9, 21)  # Friday → Monday
    assert j.due_date({"relative_to": "trading_start", "offset": {"months": 3}}, date(2026, 1, 1)) is None


def test_triggers_and_unknown_structure():
    no = j.checklist("uk", profile={"structure": "sole_trader", "hires": False, "handles_personal_data": False,
                                    "regulated_activity": False, "vat_threshold": False}, today=TODAY)
    ids = {item["id"] for item in no["items"]}
    assert "uk_paye" not in ids and "uk_ico_fee" not in ids and "uk_vat" not in ids
    assert "uk_self_assessment" in ids and "uk_incorporate" not in ids

    yes = j.checklist("uk", profile={"structure": "sole_trader", "hires": "yes", "handles_personal_data": True}, today=TODAY)
    yes_ids = {item["id"]: item for item in yes["items"]}
    assert yes_ids["uk_paye"]["conditional"] is False
    assert yes_ids["uk_ico_fee"]["severity"] == "action_needed"

    unknown = j.checklist("uk", today=TODAY)
    unknown_ids = {item["id"]: item for item in unknown["items"]}
    assert unknown_ids["uk_incorporate"]["conditional"] is True
    assert unknown_ids["uk_self_assessment"]["conditional"] is True
    assert unknown_ids["uk_paye"]["severity"] == "awareness"
    assert unknown["counts"]["atlas"] >= 1


def test_staleness_awareness_after_review_window():
    fresh = j.checklist("za", today=TODAY)
    assert fresh["stale"] is False and fresh["awareness"] == []
    stale = j.checklist("za", today=date(2027, 3, 16))  # 181 days later
    assert stale["stale"] is True
    assert stale["days_since_valid"] == 181
    assert stale["awareness"][0]["id"] == "za_rules_may_have_changed"


@pytest.mark.parametrize("code", ["", "gb", "../uk", "UKK", "u1"])
def test_unknown_or_unsafe_codes_are_rejected(code):
    with pytest.raises(j.JurisdictionError):
        j.load(code)


def test_checklist_endpoint():
    pytest.importorskip("fastapi")
    from fastapi.testclient import TestClient

    from intelligence.services.business_launch.app import app

    client = TestClient(app)
    ok = client.post("/compliance/checklist", json={"jurisdiction": "zw", "profile": {"hires": True}, "today": "2026-09-16"})
    assert ok.status_code == 200
    assert ok.json()["registration"]["api_availability"] == "portal_only"
    assert client.post("/compliance/checklist", json={"jurisdiction": "fr"}).status_code == 404

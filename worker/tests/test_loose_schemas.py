"""AO gets loose schemas; the worker prunes answers against the strict contract (D19)."""

from __future__ import annotations

import copy
import json

import pytest

from aoq_worker import contracts
from aoq_worker.errors import ContractError
from aoq_worker.pipeline import run_once

from .fakes import FakeBridge, FakeWP, deps, good_responses


def _keys(node, out=None):
    out = set() if out is None else out
    if isinstance(node, dict):
        for k, v in node.items():
            out.add(k)
            _keys(v, out)
    elif isinstance(node, list):
        for v in node:
            _keys(v, out)
    return out


def test_model_schema_has_no_per_item_rules_and_no_worker_fields():
    loose = contracts.model_schema("topic_candidates")
    assert not _keys(loose) & {"minLength", "maxLength", "pattern", "minItems", "maxItems"}
    item = loose["properties"]["candidates"]["items"]
    assert "evidence" not in item["properties"] and "evidence" not in item["required"]
    assert item["additionalProperties"] is False, "types and required fields stay"


def test_prune_drops_bad_items_and_keeps_the_rest():
    data = copy.deepcopy(good_responses()["novelty"])
    data["judgments"][0]["candidate_id"] = "not-an-id"
    data["judgments"][1]["reason"] = "x" * 5000
    out, dropped = contracts.prune(data, "stages/novelty", stage="novelty")
    assert len(out["judgments"]) == len(data["judgments"]) - 1
    assert len(out["judgments"][0]["reason"]) == 1000
    assert any("judgments/0" in d for d in dropped) and any("cut to 1000" in d for d in dropped)


def test_prune_fails_when_a_required_field_cannot_be_saved():
    data = copy.deepcopy(good_responses()["novelty"])
    data["chosen_candidate_id"] = "bad id"
    with pytest.raises(ContractError, match="chosen_candidate_id"):
        contracts.prune(data, "stages/novelty", stage="novelty")


async def test_ao_sees_the_loose_schema():
    bridge = FakeBridge(good_responses())
    outcome = await run_once(deps(FakeWP(trigger="test"), bridge))
    assert outcome.status == "succeeded", outcome.reason
    sent = [c["json_schema"] for c in bridge.calls if "json_schema" in c]
    assert sent and all(not _keys(s) & {"minLength", "pattern", "minItems", "maxItems"} for s in sent)


class FormatError(RuntimeError):
    code = "direct_agent_format"
    detail = "response failed jsonSchema validation: '' should be non-empty on instance['candidates'][6]['evidence'][0]['url']"


async def test_the_retry_carries_aos_exact_validation_error():
    r = good_responses()
    good = r["research"]
    r["research"] = lambda n, kw: FormatError("The agent returned a response in an unexpected format.") if n == 1 else good
    bridge = FakeBridge(r)
    outcome = await run_once(deps(FakeWP(trigger="test"), bridge))
    assert outcome.status == "succeeded", outcome.reason
    retry = [c for c in bridge.calls if c["text"].startswith("AO Quill task: research:structure2")][0]
    assert "should be non-empty" in retry["text"] and "Never invent" in retry["text"]


async def test_a_worker_side_contract_failure_also_retries_only_the_json_step():
    r = good_responses()
    good = r["research"]
    broken = copy.deepcopy(good)
    broken["claims"] = []  # below the minimum of 1
    r["research"] = lambda n, kw: broken if n == 1 else good
    bridge = FakeBridge(r)
    outcome = await run_once(deps(FakeWP(trigger="test"), bridge))
    assert outcome.status == "succeeded", outcome.reason
    heads = [c["text"].split("\n", 1)[0] for c in bridge.calls]
    assert heads.count("AO Quill task: research") == 1, "the research agent ran once"
    retry = [c for c in bridge.calls if c["text"].startswith("AO Quill task: research:structure2")][0]
    assert "at least 1 needed" in retry["text"]


async def test_non_tool_calls_retry_with_the_error_too():
    r = good_responses()
    good = r["novelty"]
    bad = json.dumps({"judgments": [], "chosen_candidate_id": None})
    r["novelty"] = lambda n, kw: good if kw["text"].startswith("AO Quill task: novelty:retry2") else bad
    bridge = FakeBridge(r)
    outcome = await run_once(deps(FakeWP(trigger="test"), bridge))
    assert outcome.status == "succeeded", outcome.reason
    heads = [c["text"].split("\n", 1)[0] for c in bridge.calls]
    assert "AO Quill task: novelty:retry2" in heads


async def test_a_garbled_agent_answer_is_asked_again_before_structuring():
    r = good_responses()
    r["prose:research"] = ["function"]
    bridge = FakeBridge(r)
    outcome = await run_once(deps(FakeWP(trigger="test"), bridge))
    assert outcome.status == "succeeded", outcome.reason
    heads = [c["text"].split("\n", 1)[0] for c in bridge.calls]
    assert "AO Quill task: research:again" in heads
    again = [c for c in bridge.calls if c["text"].startswith("AO Quill task: research:again")][0]
    assert "'function'" in again["text"]


async def test_research_uses_its_second_attempt_when_the_first_gives_nothing_usable():
    r = good_responses()
    r["prose:research"] = ["function"]  # first attempt: garbled, and garbled again when asked again
    r["prose:research:again"] = ["function"]
    bridge = FakeBridge(r)
    wp = FakeWP(trigger="test")
    outcome = await run_once(deps(wp, bridge))
    assert outcome.status == "succeeded", outcome.reason
    heads = [c["text"].split("\n", 1)[0] for c in bridge.calls]
    assert "AO Quill task: research:retry2" in heads
    detail = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "research"][-1]["detail"]
    assert "too short" in detail["precheck"][0]["error"]


def test_scores_on_a_ten_or_hundred_scale_are_rescaled():
    data = {"score": 8.0, "summary": "ok", "issues": []}
    out, dropped = contracts.prune(data, "stages/qa", stage="qa")
    assert out["score"] == 0.8 and "out of 10" in dropped[0]
    out, _ = contracts.prune(dict(data, score=85), "stages/qa", stage="qa")
    assert out["score"] == 0.85
    with pytest.raises(ContractError):
        contracts.prune(dict(data, score=-2), "stages/qa", stage="qa")


@pytest.mark.parametrize(
    "slug, fixed",
    [
        ("istraživanje-zimskih-čudova-parka-prirode-blidinje", "istrazivanje-zimskih-cudova-parka-prirode-blidinje"),
        ("Međugorje Ćevapi", "medjugorje-cevapi"),
        ("straße-über-die-brücke", "strasse-uber-die-brucke"),
    ],
)
def test_slug_in_local_letters_is_made_ascii(slug, fixed):
    assert contracts.ascii_slug(slug) == fixed
    node = {"type": "string", "minLength": 1, "maxLength": 200, "pattern": contracts.SLUG_PATTERN}
    dropped: list[str] = []
    assert contracts._prune(node, slug, "/slug", dropped) == fixed
    assert dropped


def test_slug_with_no_latin_letters_still_fails():
    node = {"type": "string", "minLength": 1, "maxLength": 200, "pattern": contracts.SLUG_PATTERN}
    with pytest.raises(contracts._Invalid):
        contracts._prune(node, "استكشاف-بليدينيه", "/slug", [])


def test_translated_slug_with_no_latin_letters_is_left_to_wordpress():
    node = contracts.schema("stages/translation")["properties"]["slug"]
    dropped: list[str] = []
    assert contracts._prune(node, "استكشاف-بليدينيه", "/slug", dropped) == ""
    assert contracts._prune(node, "istraživanje-blidinje", "/slug", []) == "istrazivanje-blidinje"

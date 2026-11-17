"""Topic candidates without search evidence are dropped instead of failing the run."""

from __future__ import annotations

import copy

from aoq_worker.pipeline import run_once

from .fakes import FakeBridge, FakeWP, deps, good_responses


class NoResultsFor:
    """Worker search that finds nothing for the given titles and three results for others."""

    def __init__(self, empty: set[str]) -> None:
        self.empty = empty
        self.ledger = None

    async def web_search(self, query: str, max_results: int = 8, language: str = "") -> dict:
        if query in self.empty:
            return {"query": query, "results": []}
        return {"query": query, "results": [
            {"title": f"R{i}", "url": f"https://real.example.net/{i}", "snippet": "s"} for i in range(max_results)
        ]}

    search_patiently = web_search


async def test_candidates_without_evidence_are_dropped():
    r = good_responses()
    last = r["topics"]["candidates"][-1]
    r["novelty"] = copy.deepcopy(r["novelty"])
    assert r["novelty"]["chosen_candidate_id"] != last["id"]
    r["novelty"]["judgments"] = [j for j in r["novelty"]["judgments"] if j["candidate_id"] != last["id"]]
    wp = FakeWP(trigger="test")
    outcome = await run_once(deps(wp, FakeBridge(r), web_tools=NoResultsFor({last["title"]})))
    assert outcome.status == "succeeded", outcome.reason
    detail = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "topics"][-1]["detail"]
    assert detail["dropped_without_evidence"] == [last["id"]]


async def test_too_few_candidates_with_evidence_fail_the_topics_stage():
    r = good_responses()
    empty = {c["title"] for c in r["topics"]["candidates"][2:]}
    outcome = await run_once(deps(FakeWP(trigger="test"), FakeBridge(r), web_tools=NoResultsFor(empty)))
    assert outcome.status == "failed" and outcome.reason.startswith("topics")


async def test_unknown_category_ids_are_removed_and_category_less_candidates_kept():
    r = good_responses()
    r["topics"] = copy.deepcopy(r["topics"])
    first, last = r["topics"]["candidates"][0], r["topics"]["candidates"][-1]
    first["target_category_ids"] = first["target_category_ids"][:1] + [999999]
    last["target_category_ids"] = [999998]
    wp = FakeWP(trigger="test")
    bridge = FakeBridge(r)
    outcome = await run_once(deps(wp, bridge))
    assert outcome.status == "succeeded", outcome.reason
    detail = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "topics"][-1]["detail"]
    assert detail["unknown_categories_removed"] == {first["id"]: [999999], last["id"]: [999998]}
    assert detail["without_valid_category"] == [last["id"]]
    import json
    call = [c for c in bridge.calls if c["text"].startswith("AO Quill task: novelty")][0]
    offered = {c["id"]: c for c in json.loads(call["context"])["candidates"]}
    assert offered[last["id"]]["target_category_ids"] == [], "kept, for taxonomy to decide later"


async def test_topics_is_one_direct_call_and_the_worker_numbers_candidates():
    r = good_responses()
    r["topics"] = copy.deepcopy(r["topics"])
    for c in r["topics"]["candidates"]:
        c["id"] = c["title"].lower().replace(" ", "_")[:20]
    bridge = FakeBridge(r)
    outcome = await run_once(deps(FakeWP(trigger="test"), bridge))
    assert outcome.status == "succeeded", outcome.reason
    calls = [c for c in bridge.calls if c["text"].split("\n", 1)[0].startswith("AO Quill task: topics")]
    assert len(calls) == 1 and "mcp_provider_ids" not in calls[0]
    assert "id" not in calls[0]["json_schema"]["properties"]["candidates"]["items"]["properties"]
    assert '"today"' in calls[0]["context"]


async def test_other_ao_failures_are_not_retried():
    r = good_responses()
    r["research"] = lambda n, kw: RuntimeError("connection reset")
    bridge = FakeBridge(r)
    outcome = await run_once(deps(FakeWP(trigger="test"), bridge))
    assert outcome.status == "failed" and "connection reset" in outcome.reason
    heads = [c["text"].split("\n", 1)[0] for c in bridge.calls]
    assert "AO Quill task: research:structure2" not in heads

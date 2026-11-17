"""When novelty finds nothing new, the run goes back to topics (up to TOPIC_ROUNDS)."""

from __future__ import annotations

from aoq_worker.pipeline import TOPIC_ROUNDS, run_once

from .fakes import FakeBridge, FakeWP, deps, good_responses


def none_novel(novelty: dict) -> dict:
    return {"judgments": [{**j, "novel": False, "reason": "Covered by an existing post."} for j in novelty["judgments"]],
            "chosen_candidate_id": None}


async def test_a_round_with_nothing_new_goes_back_to_topics_with_the_rejected_titles():
    r = good_responses()
    good = r["novelty"]
    r["novelty"] = lambda n, kw: none_novel(good) if n == 1 else good
    wp = FakeWP(trigger="test")
    bridge = FakeBridge(r)
    outcome = await run_once(deps(wp, bridge))
    assert outcome.status == "succeeded", outcome.reason
    heads = [c["text"].split("\n", 1)[0] for c in bridge.calls]
    assert heads.count("AO Quill task: topics") == 1 and heads.count("AO Quill task: topics:round2") == 1
    round2 = [c for c in bridge.calls if c["text"].startswith("AO Quill task: topics:round2")][0]
    first_title = r["topics"]["candidates"][0]["title"]
    assert "judged already covered" in round2["text"] and first_title in round2["text"]
    stages = [(p["stage"], p["attempt"], p["status"]) for n, p in wp.calls if n == "report-stage" and p["stage"] in ("topics", "novelty")]
    assert stages == [("topics", 1, "ok"), ("novelty", 1, "failed"), ("topics", 2, "ok"), ("novelty", 2, "ok")]


async def test_the_run_fails_after_the_last_round_finds_nothing_new():
    r = good_responses()
    r["novelty"] = none_novel(r["novelty"])
    wp = FakeWP(trigger="test")
    bridge = FakeBridge(r)
    outcome = await run_once(deps(wp, bridge))
    assert outcome.status == "failed" and outcome.reason.startswith("novelty") and f"{TOPIC_ROUNDS} rounds" in outcome.reason
    heads = [c["text"].split("\n", 1)[0] for c in bridge.calls]
    assert sum(1 for h in heads if h.startswith("AO Quill task: topics")) == TOPIC_ROUNDS
    assert "commit-post" not in wp.names()

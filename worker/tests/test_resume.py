"""Checkpoints after every step; a test run can resume an earlier one from a step."""

from __future__ import annotations

from aoq_worker.pipeline import run_once

from .fakes import FakeBridge, FakeWP, deps, good_responses


async def first_run():
    wp = FakeWP(trigger="test")
    outcome = await run_once(deps(wp, FakeBridge(good_responses())))
    assert outcome.status == "succeeded", outcome.reason
    return wp


async def test_every_step_saves_a_checkpoint_and_the_last_holds_the_bundle():
    wp = await first_run()
    saved = [p for n, p in wp.calls if n == "save-checkpoint"]
    steps = [p["step"] for p in saved]
    assert steps[:3] == ["inventory", "site_profile", "style_profile"] and steps[-1] == "commit"
    last = saved[-1]
    assert last["completed"][-1] == "commit"
    import json

    state = json.loads(last["state"])
    assert state["bundle"]["posts"], "a finished test run can be published from its checkpoint"
    assert state["topic"]["title"] and state["draft"]["content_html"]


async def test_resume_reuses_the_checkpoint_up_to_the_chosen_step():
    wp1 = await first_run()
    state = [p for n, p in wp1.calls if n == "save-checkpoint"][-1]["state"]
    resume = {"resumed_from": "11111111-2222-4333-8444-555555555555", "from_step": "qa", "state": state}
    wp = FakeWP(trigger="test", resume=resume)
    bridge = FakeBridge(good_responses())
    outcome = await run_once(deps(wp, bridge))
    assert outcome.status == "succeeded", outcome.reason
    tasks = {c["text"].split("\n", 1)[0].removeprefix("AO Quill task: ") for c in bridge.calls}
    # Reused, not asked again:
    for t in ("topics", "novelty", "research", "outline", "draft"):
        assert t not in tasks, t
    # Run again: the site is read afresh, and QA onwards runs.
    assert any(t.startswith("qa:") for t in tasks)
    reused = [p for n, p in wp.calls if n == "report-stage" and (p.get("detail") or {}).get("reused_from_run")]
    assert [p["stage"] for p in reused] == ["topics", "novelty", "research", "outline", "draft", "taxonomy", "images"]
    assert "get-inventory" in wp.names()
    # Reused steps are checkpointed too, so this resumed run can itself be resumed from any of them.
    saved = [p["step"] for n, p in wp.calls if n == "save-checkpoint"]
    assert {"topics", "novelty", "research", "outline", "draft", "taxonomy", "images"} <= set(saved)


async def test_resume_from_topics_reuses_nothing_after_the_fresh_steps():
    wp1 = await first_run()
    state = [p for n, p in wp1.calls if n == "save-checkpoint"][-1]["state"]
    wp = FakeWP(trigger="test", resume={"resumed_from": "11111111-2222-4333-8444-555555555555", "from_step": "topics", "state": state})
    bridge = FakeBridge(good_responses())
    outcome = await run_once(deps(wp, bridge))
    assert outcome.status == "succeeded", outcome.reason
    assert not [p for n, p in wp.calls if n == "report-stage" and (p.get("detail") or {}).get("reused_from_run")]
    assert any(c["text"].startswith("AO Quill task: topics") for c in bridge.calls)


async def test_empty_objects_survive_the_round_trip_as_objects():
    """PHP turns {} into [] when it decodes JSON; the state travels as text so taxonomy stays a dict."""
    import json

    wp1 = await first_run()
    saved = [p for n, p in wp1.calls if n == "save-checkpoint"]
    early = next(p for p in saved if p["step"] == "novelty")
    assert json.loads(early["state"])["taxonomy"] == {}
    wp = FakeWP(trigger="test", resume={"resumed_from": "11111111-2222-4333-8444-555555555555", "from_step": "research", "state": early["state"]})
    outcome = await run_once(deps(wp, FakeBridge(good_responses())))
    assert outcome.status == "succeeded", outcome.reason


def test_old_checkpoints_with_php_lists_restore_as_dicts():
    from aoq_worker.pipeline import restore

    class Ctx:
        pass

    ctx = Ctx()
    restore(ctx, {"taxonomy": [], "claims": [], "image_texts": {"en": []}, "topic": {"title": "T"}})
    assert ctx.taxonomy == {} and ctx.claims == {} and ctx.image_texts == {"en": {}} and ctx.topic == {"title": "T"}


async def test_a_finished_language_is_checkpointed_and_kept_on_resume():
    import json

    wp1 = await first_run()
    saved = [p for n, p in wp1.calls if n == "save-checkpoint"]
    # Saved after Bosnian, inside the translate step (translate is not completed yet).
    mid = [p for p in saved if p["step"] == "translate" and "translate" not in p["completed"]]
    assert mid, [p["step"] for p in saved]
    state = json.loads(mid[-1]["state"])
    assert "bs" in state["translated"] and "bs" in state["translations"]

    wp = FakeWP(trigger="test", resume={"resumed_from": "11111111-2222-4333-8444-555555555555", "from_step": "translate", "state": mid[-1]["state"]})
    bridge = FakeBridge(good_responses())
    outcome = await run_once(deps(wp, bridge))
    assert outcome.status == "succeeded", outcome.reason
    tasks = [c["text"].split("\n", 1)[0].removeprefix("AO Quill task: ") for c in bridge.calls]
    assert not [t for t in tasks if t.startswith(("translate:bs", "back_translate:bs", "fidelity:bs"))], "Bosnian was done again"
    kept = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "translate" and p.get("language") == "bs"]
    assert kept and kept[0]["detail"]["reused"] and kept[0]["detail"]["translation"]["content_html"]
    bundle = json.loads([p for n, p in wp.calls if n == "save-checkpoint"][-1]["state"])["bundle"]
    assert [p["language"] for p in bundle["posts"]] == ["en", "bs"]


async def test_a_language_from_another_draft_is_translated_again():
    import json

    wp1 = await first_run()
    mid = [p for n, p in wp1.calls if n == "save-checkpoint" and p["step"] == "translate" and "translate" not in p["completed"]][-1]
    state = json.loads(mid["state"])
    state["translated"]["bs"] = state["checked"]["bs"] = "0000000000000000"  # translated from a different draft
    wp = FakeWP(trigger="test", resume={"resumed_from": "11111111-2222-4333-8444-555555555555", "from_step": "translate", "state": json.dumps(state)})
    bridge = FakeBridge(good_responses())
    outcome = await run_once(deps(wp, bridge))
    assert outcome.status == "succeeded", outcome.reason
    assert any(c["text"].startswith("AO Quill task: translate:bs") for c in bridge.calls)
    assert sum(1 for s in json.loads([p for n, p in wp.calls if n == "save-checkpoint"][-1]["state"])["scores"] if s["language"] == "bs") == 5


async def test_a_language_whose_categories_failed_gets_only_its_categories_on_resume():
    import json

    wp1 = await first_run()
    saved = [p for n, p in wp1.calls if n == "save-checkpoint" and p["step"] == "translate" and "translate" not in p["completed"]]
    # The first save inside the step is after Bosnian's checks passed, before its categories.
    state = json.loads(saved[0]["state"])
    assert "bs" in state["checked"] and "bs" not in state["translated"]
    state["taxonomy"].pop("bs", None)  # as if the categories had failed
    wp = FakeWP(trigger="test", resume={"resumed_from": "11111111-2222-4333-8444-555555555555", "from_step": "translate", "state": json.dumps(state)})
    bridge = FakeBridge(good_responses())
    outcome = await run_once(deps(wp, bridge))
    assert outcome.status == "succeeded", outcome.reason
    tasks = [c["text"].split("\n", 1)[0].removeprefix("AO Quill task: ") for c in bridge.calls]
    assert not [t for t in tasks if t.startswith(("translate:bs", "back_translate:bs", "fidelity:bs", "qa:grammar:bs"))]
    assert any(t.startswith("taxonomy:bs") for t in tasks)


async def test_categories_are_tried_again_after_an_ao_server_error(monkeypatch):
    from aoq_worker.stages import translate as tr_stage

    monkeypatch.setattr(tr_stage, "TERMS_RETRY_WAITS", (0, 0))
    responses = good_responses()
    good = responses["taxonomy:bs"]

    def flaky(n, kw):
        if n == 1:
            return RuntimeError("Ollama /api/chat failed: HTTP Error 500: Internal Server Error")
        return good

    responses["taxonomy:bs"] = flaky
    wp = FakeWP(trigger="test")
    outcome = await run_once(deps(wp, FakeBridge(responses)))
    assert outcome.status == "succeeded", outcome.reason

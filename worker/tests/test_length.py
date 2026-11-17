"""Minimum word count: a rule for the draft and every revision, checked in QA."""

from __future__ import annotations

from aoq_worker.pipeline import run_once

from .fakes import FakeBridge, FakeWP, config, deps, good_responses


def wp_with_min(words: int) -> FakeWP:
    cfg = config()
    cfg["min_words"] = words
    return FakeWP(trigger="test", cfg=cfg)


async def test_a_short_post_is_expanded_by_the_worker_without_a_rewrite():
    """D31: only the word count failed, so no full rewrite: new sections are written, inserted and counted."""
    r = good_responses()
    short = dict(r["draft"], content_html=r["draft"]["content_html"].split("<h2>Tips for your visit</h2>")[0])
    r["draft"] = short
    wp = wp_with_min(400)
    bridge = FakeBridge(r)
    outcome = await run_once(deps(wp, bridge))
    assert outcome.status == "succeeded", outcome.reason
    draft = [c for c in bridge.calls if c["text"].startswith("AO Quill task: draft\n")][0]
    assert "at least 400 words" in draft["text"]
    expand = [c for c in bridge.calls if c["text"].startswith("AO Quill task: draft:expand")]
    assert expand and "must reach at least 400" in expand[0]["text"]
    assert not [c for c in bridge.calls if c["text"].startswith("AO Quill task: draft:revise")], "no rewrite for length alone"
    rounds = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "qa"]
    length = [sc for sc in rounds[0]["scores"] if sc["check"] == "length"][0]
    assert not length["passed"] and len(rounds) == 2, "the second round passed after the expansion"


async def test_a_post_below_the_minimum_fails_after_the_revisions():
    r = good_responses()
    short = dict(r["draft"], content_html=r["draft"]["content_html"].split("<h2>Tips for your visit</h2>")[0])
    r["draft"] = short
    r["draft:expand"] = {"html": "<p>Short.</p>"}
    outcome = await run_once(deps(wp_with_min(400), FakeBridge(r)))
    assert outcome.status == "failed" and "length" in outcome.reason


async def test_an_english_only_site_records_a_translate_step():
    from .fakes import inventory

    inv = inventory()
    inv["languages"] = [lang for lang in inv["languages"] if lang["primary"]]
    wp = FakeWP(trigger="test", inv=inv)
    outcome = await run_once(deps(wp, FakeBridge(good_responses())))
    assert outcome.status == "succeeded", outcome.reason
    stages = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "translate"]
    assert len(stages) == 1 and stages[0]["status"] == "ok" and stages[0]["detail"]["languages"] == []


def test_missing_and_extra_image_placeholders_are_fixed_by_the_worker():
    from aoq_worker import markup

    html = "<p>Intro.</p><h2>A</h2><p>a</p><h2>B</h2><p>b</p><h2>C</h2><p>c</p><!-- aoq:image inline-3 -->"
    out = markup.place_images(html, ["inline-1"])
    assert markup.image_keys(out) == ["inline-1"]
    assert out.index("<!-- aoq:image inline-1 -->") > out.index("<h2>A</h2>"), "never before the first section"
    two = markup.place_images("<p>a</p><p>b</p><p>c</p>", ["inline-1", "inline-2"])
    assert markup.image_keys(two) == ["inline-1", "inline-2"]
    dup = "<p>a</p><!-- aoq:image inline-1 --><p>b</p><!-- aoq:image inline-1 -->"
    assert markup.image_keys(markup.place_images(dup, ["inline-1"])) == ["inline-1"]


async def test_a_draft_without_its_image_spot_is_repaired_not_failed():
    r = good_responses()
    r["draft"] = dict(r["draft"], content_html=r["draft"]["content_html"].replace("<!-- aoq:image inline-1 -->", ""))
    outcome = await run_once(deps(FakeWP(trigger="test"), FakeBridge(r)))
    assert outcome.status == "succeeded", outcome.reason


async def test_title_and_meta_description_lengths_are_fixed_by_counting():
    """D31: test run 8065d509 kept a 71-character title through three revisions."""
    from .fakes import good_responses as gr

    r = gr()
    r["draft"] = dict(r["draft"], title="Seeing the Stone Bridge at dawn: a complete guide for early risers today", seo_description="d" * 205)
    r["text_checks"] = lambda n, kw: _structure_for(kw)
    wp = FakeWP(trigger="test")
    bridge = FakeBridge(r)
    outcome = await run_once(deps(wp, bridge))
    assert outcome.status == "succeeded", outcome.reason
    fits = [c["text"].split("\n", 1)[0] for c in bridge.calls if c["text"].startswith("AO Quill task: draft:fit")]
    assert fits == ["AO Quill task: draft:fit:title", "AO Quill task: draft:fit:seo_description"]
    assert not [c for c in bridge.calls if c["text"].startswith("AO Quill task: draft:revise")]
    qa = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "qa"]
    assert qa[0]["detail"]["revision"]["fitted"]["title"]["to"] <= 70


def _structure_for(kw):
    import json

    from .fakes import good_responses as gr

    body = kw["text"].split("\n", 1)[1]
    payload = json.loads(body[body.find("{"):])
    title = payload.get("title", "")
    desc = payload.get("seo_description", "")
    problems = []
    if len(title) > 70:
        problems.append(f"Title is {len(title)} characters; keep it at 70 or fewer.")
    if not 120 <= len(desc) <= 160:
        problems.append(f"Meta description is {len(desc)} characters; aim for 120 to 160.")
    out = json.loads(json.dumps(gr()["text_checks"]))
    out["structure"].update(problems=problems, score=round(1 - 0.2 * len(problems), 4), title_chars=len(title), seo_description_chars=len(desc))
    return out

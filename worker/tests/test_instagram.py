"""D29: Instagram captions travel with the post to WordPress."""

from __future__ import annotations


async def test_captions_are_written_for_instagram_languages_and_sent_with_the_post():
    from aoq_worker.pipeline import run_once

    from .fakes import FakeBridge, FakeWP, config, deps, good_responses

    r = good_responses()
    r["instagram"] = {"caption": "Dawn at the bridge.\n\nRead the full article: link in bio.", "hashtags": ["#StoneBridge", "visit valley", "Riverton"]}
    cfg = config()
    cfg["instagram"] = {"enabled": True, "languages": ["en"], "max_hashtags": 10, "link_hint": "Read the full article: link in bio.", "caption_style": "Warm."}
    wp = FakeWP(cfg=cfg)
    bridge = FakeBridge(r)
    outcome = await run_once(deps(wp, bridge))
    assert outcome.status == "succeeded", outcome.reason
    bundle = [p for n, p in wp.calls if n == "commit-post"][-1]
    by_lang = {p["language"]: p for p in bundle["posts"]}
    assert by_lang["en"]["instagram"] == {"caption": "Dawn at the bridge.\n\nRead the full article: link in bio.", "hashtags": ["StoneBridge", "visitvalley", "Riverton"]}
    assert all("instagram" not in p for lang, p in by_lang.items() if lang != "en")
    asked = [c for c in bridge.calls if c["text"].startswith("AO Quill task: instagram:en")]
    assert asked and "link in bio" in asked[0]["text"]


async def test_no_captions_when_instagram_is_off():
    from aoq_worker.pipeline import run_once

    from .fakes import FakeBridge, FakeWP, deps, good_responses

    wp = FakeWP()
    bridge = FakeBridge(good_responses())
    outcome = await run_once(deps(wp, bridge))
    assert outcome.status == "succeeded", outcome.reason
    assert not [c for c in bridge.calls if c["text"].startswith("AO Quill task: instagram")]
    assert all("instagram" not in p for p in [p for n, p in wp.calls if n == "commit-post"][-1]["posts"])


async def test_an_instagram_job_writes_a_caption_and_reports_it():
    """D30: the Instagram generation tab asks for a caption for one post; no post run happens."""
    from aoq_worker.pipeline import run_once

    from .fakes import FakeBridge, FakeWP, config, deps, good_responses

    r = good_responses()
    r["instagram"] = {"caption": "Old Bridge at dawn.\n\nLink in bio.", "hashtags": ["OldBridge", "#Mostar"]}
    cfg = config()
    cfg["instagram"] = {"enabled": True, "languages": ["en"], "max_hashtags": 10, "link_hint": "Link in bio.", "caption_style": ""}
    wp = FakeWP(cfg=cfg)
    wp.ig_job = {"job_id": "3f2b8c1e-9a4d-4c7e-8b1a-2d3e4f5a6b7c", "mode": "test", "language": "en", "title": "Old Bridge", "excerpt": "Dawn.", "text": "The bridge at dawn."}
    bridge = FakeBridge(r)
    outcome = await run_once(deps(wp, bridge))
    assert outcome.status == "succeeded", outcome.reason
    reported = [p for n, p in wp.calls if n == "report-instagram"]
    assert reported == [{"job_id": wp.ig_job["job_id"], "status": "ok", "caption": "Old Bridge at dawn.\n\nLink in bio.", "hashtags": ["OldBridge", "Mostar"]}]
    assert not [n for n, _ in wp.calls if n in ("finish-run", "commit-post", "report-stage")]


async def test_a_failed_instagram_job_is_reported_as_failed():
    from aoq_worker.pipeline import run_once

    from .fakes import FakeBridge, FakeWP, deps, good_responses

    r = good_responses()
    r["instagram"] = RuntimeError("AO is down")
    wp = FakeWP()
    wp.ig_job = {"job_id": "3f2b8c1e-9a4d-4c7e-8b1a-2d3e4f5a6b7c", "mode": "post", "language": "en", "title": "Old Bridge", "excerpt": "", "text": ""}
    outcome = await run_once(deps(wp, FakeBridge(r)))
    assert outcome.status == "failed"
    reported = [p for n, p in wp.calls if n == "report-instagram"][0]
    assert reported["status"] == "failed" and "AO is down" in reported["error"]

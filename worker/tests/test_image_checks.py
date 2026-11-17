"""Images sent to the vision model must be complete files (test run 18)."""

from __future__ import annotations

from aoq_worker.stages.images import image_problem

JPEG = bytes([0xFF, 0xD8, 0xFF, 0xE0]) + b"x" * 100 + bytes([0xFF, 0xD9])
PNG = bytes([0x89]) + b"PNG\r\n" + bytes([0x1A]) + b"\n" + b"x" * 50 + b"IEND" + bytes([0xAE, 0x42, 0x60, 0x82])


def webp(size_field: int, total: int) -> bytes:
    body = b"RIFF" + size_field.to_bytes(4, "little") + b"WEBP"
    return body + b"x" * (total - len(body))


def test_complete_images_pass():
    assert image_problem(JPEG, "image/jpeg") is None
    assert image_problem(PNG, "image/png") is None
    assert image_problem(webp(92, 100), "image/webp") is None


def test_cut_short_images_are_named():
    assert image_problem(JPEG[:60], "image/jpeg") == "JPEG is cut short"
    assert image_problem(PNG[:30], "image/png") == "PNG is cut short"
    assert image_problem(webp(500, 100), "image/webp") == "WebP is cut short"
    assert image_problem(b"<html>error page</html>", "image/jpeg") == "not a JPEG"


async def test_the_images_step_lists_the_chosen_images_for_the_gallery():
    from aoq_worker.pipeline import run_once

    from .fakes import FakeBridge, FakeWP, deps, good_responses

    wp = FakeWP(trigger="test")
    outcome = await run_once(deps(wp, FakeBridge(good_responses())))
    assert outcome.status == "succeeded", outcome.reason
    detail = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "images"][-1]["detail"]
    chosen = detail["chosen"]
    assert chosen and {"key", "preview_url", "full_url", "alt", "caption", "license"} <= set(chosen[0])
    assert chosen[0]["key"] == "featured" and chosen[0]["preview_url"].startswith("http")


async def test_an_invented_image_key_does_not_matter():
    """The vision model never sees keys; the worker attaches each verdict to the image it sent."""
    import copy

    from aoq_worker.pipeline import run_once

    from .fakes import FakeBridge, FakeWP, deps, good_responses

    r = good_responses()
    r["images:judge"] = copy.deepcopy(r["images:judge"])
    for v in r["images:judge"]["images"]:
        v["image_key"] = "media_library:1"
    wp = FakeWP(trigger="test")
    bridge = FakeBridge(r)
    outcome = await run_once(deps(wp, bridge))
    assert outcome.status == "succeeded", outcome.reason
    judge = [c for c in bridge.calls if c["text"].startswith("AO Quill task: images:judge") and "json_schema" in c][0]
    assert "image_key" not in judge["json_schema"]["properties"]["images"]["items"]["properties"]


def test_vision_answers_come_from_the_vision_models_own_reply():
    from aoq_worker.stages.images import vision_answers

    full = "relevant: yes\nhas_text: no\nhas_logo: No\n- has_watermark: no\n**has_recognizable_people**: YES\nalt text: A woman"
    answers, missing = vision_answers(full)
    assert answers == {"relevant": True, "has_text": False, "has_logo": False, "has_watermark": False, "has_recognizable_people": True}
    assert missing == []
    # Test run a0100eed: only alt text and caption came back. Nothing may count in the image's favour.
    answers, missing = vision_answers("alt text: Woman in brown coat\ncaption: A woman near a bridge.")
    assert answers == {"relevant": False, "has_text": True, "has_logo": True, "has_watermark": True, "has_recognizable_people": True}
    assert len(missing) == 5


async def test_an_image_whose_checks_were_not_answered_is_rejected():
    from aoq_worker.pipeline import run_once

    from .fakes import FakeBridge, FakeWP, deps, good_responses

    r = good_responses()
    r["prose:images:judge:media_library:1"] = ["alt text: A bridge\ncaption: The bridge."]
    wp = FakeWP(trigger="test")
    outcome = await run_once(deps(wp, FakeBridge(r)))
    detail = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "images"][-1]["detail"]
    assert detail["media_library"]["unanswered"]["1:501"]
    assert "1:501" in [x["image_key"] for x in detail["media_library"]["rejected"]]
    assert outcome.status in ("succeeded", "failed")


async def test_the_text_model_scores_relevance_from_the_descriptions():
    import json

    from aoq_worker.pipeline import run_once

    from .fakes import FakeBridge, FakeWP, deps, good_responses

    r = good_responses()
    # The text check scores 1:501 low (say, a hotel room for a food post); the vision model had accepted it.
    r["images:relevance"] = lambda n, kw: {"images": [
        {"image_key": i["image_key"], "score": 1 if i["image_key"] == "1:501" else 9, "shows": "none" if i["image_key"] == "1:501" else "stone bridge",
         "reason": "Hotel room." if i["image_key"] == "1:501" else "Shows it."}
        for i in json.loads(kw["context"])["images"]
    ]}
    wp = FakeWP(trigger="test")
    outcome = await run_once(deps(wp, FakeBridge(r)))
    assert outcome.status == "succeeded", outcome.reason
    detail = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "images"][-1]["detail"]
    assert detail["media_library"]["scores"]["1:501"] == {"score": 1.0, "shows": "none", "reason": "Hotel room."}
    assert detail["subjects"] == ["stone bridge", "river at dawn"]
    assert "1:501" not in [c.get("key") for c in detail.get("chosen", [])] and all(c["preview_url"] for c in detail["chosen"])


async def test_the_best_scored_image_becomes_featured_not_the_first():
    """Test run 41137d8d: the first image that passed (ruins) became featured, the better one in-body."""
    import json

    from aoq_worker.pipeline import run_once

    from .fakes import FakeBridge, FakeWP, deps, good_responses

    r = good_responses()
    score = {"1:501": 6, "1:502": 7, "1:503": 9}
    r["images:relevance"] = lambda n, kw: {"images": [
        {"image_key": i["image_key"], "score": score[i["image_key"]], "shows": "x", "reason": "x"} for i in json.loads(kw["context"])["images"]
    ]}
    wp = FakeWP(trigger="test")
    outcome = await run_once(deps(wp, FakeBridge(r)))
    assert outcome.status == "succeeded", outcome.reason
    detail = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "images"][-1]["detail"]
    assert [c["score"] for c in detail["chosen"]] == [9.0, 7.0]
    assert detail["featured_choice"] == "best"


async def test_images_fall_back_instead_of_failing():
    """Nothing reaches the pass marks: the best usable image is featured, in-body images are dropped."""
    import json

    from aoq_worker.pipeline import run_once

    from .fakes import FakeBridge, FakeWP, deps, good_responses

    r = good_responses()
    r["images:relevance"] = lambda n, kw: {"images": [
        {"image_key": i["image_key"], "score": 2 if i["image_key"] == "1:502" else 1, "shows": "none", "reason": "Region only."} for i in json.loads(kw["context"])["images"]
    ]}
    wp = FakeWP(trigger="test")
    outcome = await run_once(deps(wp, FakeBridge(r)))
    assert outcome.status == "succeeded", outcome.reason
    detail = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "images"][-1]["detail"]
    assert detail["featured_choice"] == "best available"
    assert detail["inline_short"] == {"wanted": 1, "used": 0}
    assert [c["key"] for c in detail["chosen"]] == ["featured"]
    bundle = [p for n, p in wp.calls if n == "commit-post"]
    if bundle:
        assert "aoq:image inline" not in json.dumps(bundle[-1])


async def test_the_fallback_image_setting_is_used_when_nothing_relates():
    import json

    from aoq_worker.pipeline import run_once

    from .fakes import FakeBridge, FakeWP, config, deps, good_responses

    r = good_responses()
    r["images:relevance"] = lambda n, kw: {"images": [
        {"image_key": i["image_key"], "score": 0, "shows": "none", "reason": "Unrelated."} for i in json.loads(kw["context"])["images"]
    ]}
    cfg = config()
    cfg["fallback_image"] = "1:503"
    wp = FakeWP(cfg=cfg, trigger="test")
    outcome = await run_once(deps(wp, FakeBridge(r)))
    assert outcome.status == "succeeded", outcome.reason
    detail = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "images"][-1]["detail"]
    assert detail["featured_choice"] == "fallback image"
    assert "photo-503" in detail["chosen"][0]["preview_url"]


def test_the_library_is_searched_for_the_subjects_and_skips_logos():
    from aoq_worker.stages.images import library_matches

    def item(i, title, date="2026-01-01T00:00:00Z"):
        return {"key": f"1:{i}", "url": f"https://x.example/{title}.jpg", "title": title, "alt": "", "caption": "", "date": date}

    media = [item(1, "jahorina-bosnia-ski-centar-min"), item(2, "tour-guide-mostar-logo"), item(3, "stone-ruins", "2026-05-01T00:00:00Z"),
             item(4, "winter-mostar"), item(5, "eastern-balkan-map")]
    out, matched = library_matches(media, ["snowy ski slope", "winter in Mostar"], 40)
    keys = [m["key"] for m in out]
    assert matched == 2 and set(keys[:2]) == {"1:1", "1:4"}
    assert "1:2" not in keys and "1:5" not in keys  # logo and map left out
    assert keys[2:] == ["1:3"]  # few matches: recent photos fill the fallback pool


async def test_stock_search_is_retried_with_new_terms_until_enough_images_pass():
    import json

    from aoq_worker.pipeline import run_once

    from .fakes import FakeWebTools, FakeWP
    from .test_pipeline import run, stock_responses, tunnel_config

    r = stock_responses()
    # Round 1: the two stock images are judged not relevant; the retry offers a third that is.
    r["images:relevance"] = lambda n, kw: {"images": [
        {"image_key": i["image_key"], "score": 9 if i["image_key"] == "commons:4402" else 1, "shows": "x", "reason": "x"} for i in json.loads(kw["context"])["images"]
    ]}
    r["images:stock:retry2"] = {"candidates": [{"image_id": "commons:4401", "reason": "again"}, {"image_id": "commons:4402", "reason": "new terms"}]}
    r["images:stock:retry3"] = {"candidates": []}
    r["images:judge:stock_retry2"] = {"images": [
        {"image_key": "commons:4402", "relevant": True, "has_text": False, "has_logo": False, "has_watermark": False,
         "has_recognizable_people": False, "alt_text": "Alt 4402", "caption": "Caption 4402"}
    ]}
    outcome, wp, bridge = await run(r, wp=FakeWP(cfg=tunnel_config(), trigger="test"), web_tools=FakeWebTools(), tunnel=True)
    detail = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "images"][-1]["detail"]
    retry = [c for c in bridge.calls if c["text"].startswith("AO Quill task: images:stock:retry2")]
    assert retry and "Do not pick these image_ids again" in retry[0]["text"] and "commons:4401" in retry[0]["text"]
    assert detail["stock_rounds"] >= 2
    judged_again = [c for c in bridge.calls if c["text"].startswith("AO Quill task: images:judge:stock_retry2") and "images" in c]
    assert [[i["name"] for i in c["images"]] for c in judged_again] == [["commons:4402"]], "an image judged before is not offered again"
    assert outcome.status in ("succeeded", "failed")


async def test_web_image_search_finds_images_and_keeps_their_source_page():
    """D28: SearXNG image search for each subject; license unchecked, linked to the page it was found on."""
    from .fakes import FakeWebTools, FakeWP
    from .test_pipeline import run, tunnel_config

    r = __import__("tests.fakes", fromlist=["good_responses"]).good_responses()
    cfg = tunnel_config()
    for src in cfg["image_sources"]:
        src["enabled"] = src["id"] in ("web_search",)
    cfg["image_sources"].append({"id": "web_search", "enabled": True, "order": 1})
    web = FakeWebTools()
    outcome, wp, bridge = await run(r, wp=FakeWP(cfg=cfg, trigger="test"), web_tools=web, tunnel=True)
    assert outcome.status == "succeeded", outcome.reason
    assert web.web_queries == ["stone bridge", "river at dawn"]
    detail = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "images"][-1]["detail"]
    assert detail["web_search"]["skipped"]["watermarked_stock"] == 2
    featured = detail["chosen"][0]
    assert featured["source"] == "remote" and featured["license"] == "Not checked (found by web image search)"
    assert featured["source_page_url"].startswith("https://recipes.example.com/")


def test_trust_prefers_own_photos_then_photos_from_pages_naming_the_place():
    from aoq_worker.stages.images import trust

    library = {"source": "media_library"}
    istanbul = {"source": "remote", "source_page_url": "https://www.reddit.com/r/divinebuildings/comments/1853nok/the_blue_mosque_in_istanbul_also_known_by_its/", "url": "https://i.redd.it/0l5y1z61dw2c1.jpg"}
    mostar = {"source": "remote", "source_page_url": "https://example.org/travel/stari-most-mostar-at-dusk", "url": "https://example.org/img/1.jpg"}
    assert trust(library, "Mostar") == 2
    assert trust(mostar, "Mostar") == 1
    assert trust(istanbul, "Mostar") == 0
    assert trust(mostar, "") == 0


def test_own_photo_at_the_pass_mark_beats_a_higher_scoring_web_photo():
    from aoq_worker.stages.images import assign

    def cand(key, score, source, t):
        return {"key": key, "trust": t, "verdict": {"score": score, "image_key": key, "alt_text": f"photo {key}"},
                "record": {"source": source, "media": {"id": key} if source == "media_library" else None, "width": 1600, "height": 900}}

    pool = [cand("web:istanbul", 10, "remote", 0), cand("4:150", 8, "media_library", 2), cand("4:60", 8, "media_library", 2)]
    featured, inline, how = assign(pool, 1, 6, 8)
    assert featured["key"] == "4:150" and how == "best"
    assert inline[0]["key"] == "4:60"

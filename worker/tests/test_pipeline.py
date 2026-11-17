"""Full pipeline with a fake bridge and a fake WordPress: success, revision loop,
profile cache, dry run, and every gate stopping the run with nothing written."""

from __future__ import annotations

import copy
import json

import pytest

from aoq_worker import contracts, dryrun, markup
from aoq_worker.pipeline import run_once

from .fakes import DRAFT_HTML, TRANSLATED_HTML, FakeBridge, FakeCatalog, FakeWebTools, FakeWP, config, deps, good_responses, inventory


async def run(responses=None, *, wp=None, catalog=None, dry_run=False, on_done=None, catalog_error=None, web_tools=None, tunnel=False):
    wp = wp or FakeWP()
    bridge = FakeBridge(responses or good_responses())
    bridge.mcp_tunnel = tunnel
    outcome = await run_once(deps(wp, bridge, catalog, catalog_error, web_tools), dry_run=dry_run, on_done=on_done)
    return outcome, wp, bridge


async def test_success_commits_one_bundle_and_reports_every_stage():
    outcome, wp, bridge = await run()
    assert outcome.status == "succeeded", outcome.reason
    assert wp.names().count("commit-post") == 1
    bundle = dict(wp.calls)["commit-post"]
    assert contracts.errors(bundle, "abilities/commit-post.input") == []
    assert [p["language"] for p in bundle["posts"]] == ["en", "bs"]  # excluded "de" is not written
    for post in bundle["posts"]:
        assert "{{c:" not in post["content"]
        assert "status" not in post  # the plugin decides status
    assert {img["key"] for img in bundle["images"]} == {"featured", "inline-1"}
    assert all(img["license"]["allows_download_and_reuse"] for img in bundle["images"])
    finish = dict(wp.calls)["finish-run"]
    assert finish["status"] == "succeeded"
    assert finish["result"]["ao"]["engine_version"] == "2.12.1"
    stages = {p["stage"] for n, p in wp.calls if n == "report-stage"}
    assert {"connect", "inventory", "site_profile", "style_profile", "topics", "novelty", "research", "verify_quotes", "outline", "draft", "taxonomy", "images", "qa", "translate", "translation_qa", "commit"} <= stages
    assert bridge.stopped
    # tool stages are split: prose call with MCP ids, then a JSON call without them
    research_calls = [c for c in bridge.calls if c["text"].startswith("AO Quill task: research")]
    assert "mcp_provider_ids" in research_calls[0] and "response_format" not in research_calls[0]
    assert "response_format" in research_calls[1] and "mcp_provider_ids" not in research_calls[1]
    # every model call goes out at a low queue priority
    assert {c["priority"] for c in bridge.calls} == {"background"}


async def test_no_run_due_exits_without_ao_calls():
    outcome, wp, bridge = await run(wp=FakeWP(claim=False))
    assert outcome.status == "idle"
    assert bridge.calls == []
    assert wp.names() == ["claim-run"]


async def test_heartbeat_reports_catalog_with_families():
    _, wp, _ = await run(wp=FakeWP(claim=False))
    catalog = wp.calls[0][1]["ao"]["catalog"]
    fam = {a["id"]: a["family"] for a in catalog["agents"]}
    assert fam["client.aoq_writer"] != fam["client.aoq_editor"]
    det = {a["id"]: a["deterministic"] for a in catalog["agents"]}
    assert det["aoq_quote_verifier"] is True


async def test_qa_revision_loop_recovers():
    responses = good_responses()
    responses["qa:grammar"] = lambda n, kw: {"score": 0.5 if n == 1 else 0.95, "summary": "x", "issues": [{"severity": "major", "location": "p1", "problem": "typo", "fix": "fix it"}] if n == 1 else []}
    outcome, wp, bridge = await run(responses)
    assert outcome.status == "succeeded", outcome.reason
    qa_reports = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "qa"]
    assert [r["status"] for r in qa_reports] == ["failed", "ok"]
    assert qa_reports[1]["revision_count"] == 1
    assert any(c["text"].startswith("AO Quill task: draft:revise") for c in bridge.calls)


async def test_fresh_profiles_are_reused():
    from aoq_worker.stages.common import now_iso

    inv = inventory()
    keys = sorted(p["key"] for p in inv["posts"])
    profiles = {
        "site_profile": {"fingerprint": keys, "built_at": now_iso(), "data": good_responses()["site_profile"]},
        "style_profiles": [
            {"language": "en", "fingerprint": sorted(p["key"] for p in inv["posts"] if p["language"] == "en" and not p["ai_generated"]), "built_at": now_iso(), "data": good_responses()["style_profile:en"]},
            {"language": "bs", "fingerprint": sorted(p["key"] for p in inv["posts"] if p["language"] == "bs"), "built_at": now_iso(), "data": good_responses()["style_profile:bs"]},
        ],
    }
    outcome, wp, bridge = await run(wp=FakeWP(profiles=profiles))
    assert outcome.status == "succeeded", outcome.reason
    tasks = [c["text"].split("\n")[0] for c in bridge.calls]
    assert not any("site_profile" in t or "style_profile" in t for t in tasks)
    assert "save-profiles" not in wp.names()


async def test_style_profile_ignores_ai_posts():
    _, _, bridge = await run()
    call = next(c for c in bridge.calls if c["text"].startswith("AO Quill task: style_profile:en"))
    assert "Earlier AI post" not in call["context"]


async def test_dry_run_writes_nothing_to_wordpress_and_outputs_files(tmp_path):
    written = {}

    def on_done(ctx, outcome):
        written["path"] = dryrun.write(ctx, outcome, tmp_path)

    outcome, wp, _ = await run(dry_run=True, on_done=on_done)
    assert outcome.status == "succeeded", outcome.reason
    assert wp.writes() == []
    out = written["path"]
    assert (out / "bundle.json").is_file()
    run_json = json.loads((out / "run.json").read_text(encoding="utf-8"))
    assert run_json["status"] == "succeeded"
    for lang in ("en", "bs"):
        page = (out / f"preview-{lang}.html").read_text(encoding="utf-8")
        assert 'class="claim"' in page and "Verified claims" in page and "<img" in page


def mutate(path: str, value):
    def apply(r):
        r[path] = value
    return apply


def _gate_cases():
    g = good_responses

    def research_unknown_source(r):
        r["research"] = copy.deepcopy(g()["research"])
        r["research"]["claims"][0]["source_id"] = "s9"

    def few_verified(r):
        r["verify_quotes"] = copy.deepcopy(g()["verify_quotes"])
        for c in r["verify_quotes"]["claims"][2:]:
            c["verified"] = False

    def outline_bad_link(r):
        r["outline"] = copy.deepcopy(g()["outline"])
        r["outline"]["internal_links"][0]["post_id"] = 4242

    def draft_unverified_claim(r):
        r["draft"] = {**g()["draft"], "content_html": DRAFT_HTML.replace("{{c:c4}}", "{{c:c99}}")}

    def draft_missing_image(r):
        r["draft"] = {**g()["draft"], "content_html": DRAFT_HTML.replace("<!-- aoq:image inline-1 -->", "")}

    def vision_flags_all(r):
        r["images:judge"] = copy.deepcopy(g()["images:judge"])
        for img in r["images:judge"]["images"]:
            img["has_watermark"] = True

    def qa_never_passes(r):
        r["qa:style"] = {"score": 0.3, "summary": "Off style.", "issues": [{"severity": "major", "location": "", "problem": "tone", "fix": "warmer"}]}

    def facts_unsupported(r):
        r["qa:facts"] = {"score": 0.95, "summary": "x", "unsupported_sentences": [{"sentence": "The bridge is 30 m high.", "why": "No claim."}], "issues": []}

    def originality_high(r):
        r["text_checks"] = copy.deepcopy(g()["text_checks"])
        r["text_checks"]["originality"]["max_overlap"] = 0.4

    def originality_fetch_error(r):
        r["text_checks"] = copy.deepcopy(g()["text_checks"])
        r["text_checks"]["originality"]["fetch_errors"] = ["https://source.example.org/bridge: 404"]

    def translation_bad_link(r):
        r["translate:bs"] = {**g()["translate:bs"], "content_html": TRANSLATED_HTML.replace('data-aoq-post="20"', 'data-aoq-post="10"')}

    def fidelity_low(r):
        r["fidelity"] = {"score": 0.4, "summary": "Changed numbers.", "issues": [{"severity": "critical", "location": "", "problem": "2004 became 2014", "fix": "2004"}]}

    def ao_timeout(r):
        r["outline"] = TimeoutError("slow")

    def ao_bad_json(r):
        r["novelty"] = "not json at all"

    return {
        "no topic survives": mutate("novelty", {"judgments": [{**j, "novel": False} for j in g()["novelty"]["judgments"]], "chosen_candidate_id": None}),
        "chosen topic not novel and none other is": mutate("novelty", {"judgments": [{**j, "novel": False} for j in g()["novelty"]["judgments"]], "chosen_candidate_id": "t2"}),
        "claim cites unknown source": research_unknown_source,
        "too few verified quotes": few_verified,
        "draft cites unverified claim": draft_unverified_claim,
        "unknown category": mutate("taxonomy:en", {"category_ids": [999], "tag_ids": []}),
        "no category": mutate("taxonomy:en", {"category_ids": [], "tag_ids": [10]}),
        "vision rejects every image": vision_flags_all,
        "qa below threshold after loops": qa_never_passes,
        "originality overlap too high": originality_high,
        "originality source unfetched": originality_fetch_error,
        "translation links to a post in another language": translation_bad_link,
        "translation fidelity too low": fidelity_low,
        "ao timeout": ao_timeout,
        "ao invalid json": ao_bad_json,
    }


@pytest.mark.parametrize("name", list(_gate_cases()))
async def test_every_gate_stops_the_run_with_nothing_written(name):
    responses = good_responses()
    _gate_cases()[name](responses)
    outcome, wp, bridge = await run(responses)
    assert outcome.status == "failed", name
    assert outcome.reason
    assert "commit-post" not in wp.names()
    finish = dict(wp.calls)["finish-run"]
    assert finish["status"] == "failed" and finish["failure_reason"]
    failed = [p for n, p in wp.calls if n == "report-stage" and p["status"] == "failed"]
    assert failed and failed[-1]["failure_reason"]
    assert bridge.stopped


async def test_qa_loop_stops_at_the_limit():
    responses = good_responses()
    _gate_cases()["qa below threshold after loops"](responses)
    outcome, wp, bridge = await run(responses)
    qa_reports = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "qa"]
    assert len(qa_reports) == config()["max_revision_loops"] + 1
    assert "after 2 revision loops" in outcome.reason


async def test_inventory_gate_language_without_category():
    inv = inventory()
    inv["terms"] = [t for t in inv["terms"] if not (t["language"] == "bs" and t["taxonomy"] == "category")]
    outcome, wp, bridge = await run(wp=FakeWP(inv=inv))
    assert outcome.status == "failed" and "no category" in outcome.reason
    assert "commit-post" not in wp.names()
    assert bridge.calls == []


async def test_planned_image_source_fails_the_run():
    cfg = config()
    cfg["image_sources"][2]["enabled"] = True
    outcome, wp, _ = await run(wp=FakeWP(cfg=cfg))
    assert outcome.status == "failed" and "ai_generated" in outcome.reason
    assert "commit-post" not in wp.names()


async def test_missing_server_checks_fail_at_connect():
    outcome, wp, bridge = await run(catalog=FakeCatalog(agents=[]))
    assert outcome.status == "failed" and "aoq_quote_verifier" in outcome.reason
    assert bridge.calls == []
    assert "commit-post" not in wp.names()


async def test_unreachable_ao_fails_the_claimed_run():
    outcome, wp, _ = await run(catalog_error=OSError("connection refused"))
    assert outcome.status == "failed" and "unreachable" in outcome.reason
    assert dict(wp.calls)["claim-run"]["ao"]["reachable"] is False


def test_markup_helpers():
    assert markup.claim_ids(DRAFT_HTML) == {"c1", "c2", "c3", "c4"}
    assert "{{c:" not in markup.strip_claims(DRAFT_HTML)
    assert markup.image_keys(DRAFT_HTML) == ["inline-1"]
    assert markup.link_ids(DRAFT_HTML) == {10}


# ------------------------------------------------------------------ worker-hosted MCPs (D14)


def tunnel_config(*, stock_first: bool = True) -> dict:
    cfg = config()
    cfg["mcp"] = {"search": "client.aoq_search", "fetch": "client.aoq_fetch", "stock_images": "client.aoq_images"}
    for src in cfg["image_sources"]:
        if src["id"] == "stock":
            src["enabled"], src["order"] = True, 1 if stock_first else 2
        elif src["id"] == "media_library":
            src["order"] = 2 if stock_first else 1
    return cfg


def stock_responses() -> dict:
    r = good_responses()
    r["images:stock"] = {"candidates": [
        {"image_id": "commons:4401", "reason": "Bridge"},
        {"image_id": "openverse:ffffffff", "reason": "Invented by the model"},
        {"image_id": "openverse:0a1b2c3d", "reason": "River"},
    ]}
    r["images:judge:stock"] = {"images": [
        {"image_key": k, "relevant": True, "has_text": False, "has_logo": False, "has_watermark": False,
         "has_recognizable_people": False, "alt_text": f"Alt {k}", "caption": f"Caption {k}"}
        for k in ("commons:4401", "openverse:0a1b2c3d")
    ]}
    return r


async def test_tunnel_mcps_need_the_tunnel():
    outcome, wp, bridge = await run(stock_responses(), wp=FakeWP(cfg=tunnel_config()), web_tools=FakeWebTools(), tunnel=False)
    assert outcome.status == "failed" and "mcp_tunnel" in outcome.reason
    assert bridge.calls == [] and "commit-post" not in wp.names()


async def test_heartbeat_lists_worker_hosted_mcps():
    _, wp, _ = await run(wp=FakeWP(claim=False))
    mcps = wp.calls[0][1]["ao"]["catalog"]["mcps"]
    assert {"client.aoq_search", "client.aoq_fetch", "client.aoq_images"} <= set(mcps)


async def test_stock_images_come_from_the_ledger_not_the_model():
    web = FakeWebTools()
    outcome, wp, bridge = await run(stock_responses(), wp=FakeWP(cfg=tunnel_config()), web_tools=web, tunnel=True)
    assert outcome.status == "succeeded", outcome.reason
    bundle = dict(wp.calls)["commit-post"]
    assert contracts.errors(bundle, "abilities/commit-post.input") == []
    images = {img["key"]: img for img in bundle["images"]}
    featured = images["featured"]
    assert featured["source"] == "remote" and featured["media"] is None
    assert featured["url"] == "https://images.example.org/4401.jpg"
    assert featured["license"]["code"] == "cc-by-sa" and featured["license"]["requires_attribution"]
    assert featured["attribution"].startswith('"Stone bridge.jpg" by Jane Example')
    assert images["inline-1"]["license"]["code"] == "cc0"
    # the invented ID was never fetched or judged
    assert web.fetched_images == ["commons:4401", "openverse:0a1b2c3d"]
    # one vision call per image, each carrying only that image
    judges = [c for c in bridge.calls if c["text"].startswith("AO Quill task: images:judge:stock") and "images" in c]
    assert [[i["name"] for i in c["images"]] for c in judges] == [["commons:4401"], ["openverse:0a1b2c3d"]]
    # the stock shortlist ran as a tool call with the worker-hosted image MCP
    stock = [c for c in bridge.calls if c["text"].startswith("AO Quill task: images:stock")][0]
    assert stock["mcp_provider_ids"] == ["client.aoq_images"]
    # stock filled every slot, so the media library was not needed
    assert not any(c["text"].startswith("AO Quill task: images:shortlist") for c in bridge.calls)
    images_report = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "images"][-1]
    assert images_report["detail"]["stock"]["unknown_ids"] == ["openverse:ffffffff"]


async def test_media_library_fills_in_when_stock_falls_short():
    r = stock_responses()
    r["images:judge:stock"]["images"][1]["has_watermark"] = True
    outcome, wp, _ = await run(r, wp=FakeWP(cfg=tunnel_config()), web_tools=FakeWebTools(), tunnel=True)
    assert outcome.status == "succeeded", outcome.reason
    images = {img["key"]: img for img in dict(wp.calls)["commit-post"]["images"]}
    # The site's own photo is trusted over a stock photo whose page does not name the post's place.
    assert images["featured"]["source"] == "media_library"
    assert images["inline-1"]["source"] in ("media_library", "remote")  # own photos are used first, stock fills the rest


async def test_stock_without_web_tools_fails():
    outcome, wp, _ = await run(stock_responses(), wp=FakeWP(cfg=tunnel_config()), web_tools=None, tunnel=True)
    assert outcome.status == "failed" and "web tools" in outcome.reason
    assert "commit-post" not in wp.names()


@pytest.mark.parametrize(
    "name, stage, key",
    [("outline links to missing post", "outline", "removed"), ("unknown tag in translation", "translate", None)],
)
async def test_stray_references_are_removed_not_fatal(name, stage, key):
    responses = good_responses()
    if name == "outline links to missing post":
        responses["outline"] = copy.deepcopy(responses["outline"])
        responses["outline"]["internal_links"][0]["post_id"] = 4242
    else:
        mutate("taxonomy:bs", {"category_ids": [4], "tag_ids": [10]})(responses)
    outcome, wp, bridge = await run(responses)
    assert outcome.status == "succeeded", outcome.reason
    if key:
        detail = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == stage][-1]["detail"]
        assert detail[key]["unpublished_link_targets"]


@pytest.mark.parametrize("chosen", ["t2", None])
async def test_novelty_falls_back_to_a_candidate_the_judge_found_novel(chosen):
    responses = good_responses()
    responses["novelty"] = {**responses["novelty"], "chosen_candidate_id": chosen}
    outcome, wp, bridge = await run(responses)
    assert outcome.status == "succeeded", outcome.reason
    detail = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "novelty"][-1]["detail"]
    assert "used t1" in detail["choice_replaced"]


async def test_unsupported_sentences_alone_do_not_fail_facts_above_the_pass_mark():
    responses = good_responses()
    responses["qa:facts"] = {"score": 0.95, "summary": "x", "unsupported_sentences": [{"sentence": "The bridge is 30 m high.", "why": "No claim."}], "issues": []}
    outcome, wp, bridge = await run(responses)
    assert outcome.status == "succeeded", outcome.reason


async def test_facts_below_the_pass_mark_still_fails_after_the_revisions():
    responses = good_responses()
    responses["qa:facts"] = {"score": 0.3, "summary": "x", "unsupported_sentences": [{"sentence": "The bridge is 30 m high.", "why": "No claim."}], "issues": []}
    outcome, wp, bridge = await run(responses)
    assert outcome.status == "failed" and "facts 0.30" in outcome.reason


async def test_translation_that_drops_a_claim_marker_is_repaired_not_failed():
    responses = good_responses()
    responses["translate:bs"] = {**responses["translate:bs"], "content_html": TRANSLATED_HTML.replace("{{c:c4}}", "")}
    outcome, wp, bridge = await run(responses)
    assert outcome.status == "succeeded", outcome.reason
    bs = [p for p in dict(wp.calls)["commit-post"]["posts"] if p["language"] == "bs"][0]
    assert "{{c:" not in bs["content"]


async def test_translation_in_another_script_is_asked_again():
    responses = good_responses()
    good = responses["translate:bs"]
    slipped = {**good, "content_html": good["content_html"] + "<p>" + "冒险者可以挑战未标记路线通往山峰" * 6 + "</p>"}
    responses["translate:bs"] = lambda n, kw: slipped if n == 1 else good
    outcome, wp, bridge = await run(responses)
    assert outcome.status == "succeeded", outcome.reason
    asks = [c["text"] for c in bridge.calls if c["text"].startswith("AO Quill task: translate:bs") and "response_format" in c]
    assert len(asks) >= 2 and "another script" in asks[1]


async def test_translation_that_stays_in_another_script_fails_before_publishing():
    responses = good_responses()
    good = responses["translate:bs"]
    responses["translate:bs"] = {**good, "content_html": good["content_html"] + "<p>" + "冒险者可以挑战未标记路线通往山峰" * 6 + "</p>"}
    outcome, wp, bridge = await run(responses)
    assert outcome.status == "failed" and "not all in bs" in outcome.reason
    assert "commit-post" not in wp.names()


class GemmaBridge(FakeBridge):
    """FakeBridge where client.aoq_translator answers like TranslateGemma: the text, translated."""

    async def direct_agent(self, **kw):
        if kw.get("agent_provider_id") != "client.aoq_translator":
            return await super().direct_agent(**kw)
        self.calls.append(kw)
        import re

        text = re.search(r"<aoq-text>\s*(.*?)\s*</aoq-text>", kw["text"], re.S).group(1)
        # Words become "BS-word" ("BT-word" the second time the same text comes); placeholders stay.
        self.seen = getattr(self, "seen", {})
        self.seen[text] = self.seen.get(text, 0) + 1
        mark = "BS" if self.seen[text] == 1 else "BT"
        out = re.sub(r"(?<![{\w])([A-Za-z]{3,})", mark + r"\1", text)
        return {"ok": True, "text": f"<aoq-text>{out}</aoq-text>"}


async def test_translation_goes_through_translategemma_when_the_overlay_has_it():
    from aoq_worker.pipeline import run_once


    wp = FakeWP()
    bridge = GemmaBridge(good_responses())
    d = deps(wp, bridge)
    base = d.overlay_agents()
    d.overlay_agents = lambda: base + [{"id": "client.aoq_translator", "type": "ollama", "model": "translategemma:12b"}]
    outcome = await run_once(d)
    assert outcome.status == "succeeded", outcome.reason
    gemma = [c for c in bridge.calls if c.get("agent_provider_id") == "client.aoq_translator"]
    assert gemma and all(c["text"].startswith(("AO Quill task: translate:bs", "AO Quill task: back_translate:bs")) for c in gemma)
    # Back translation by TranslateGemma too (Bosnian into English), not by the editor.
    assert any(c["text"].startswith("AO Quill task: back_translate:bs") and "Bosnian (bs) to English (en)" in c["text"] for c in gemma)
    assert not [c for c in bridge.calls if c["text"].startswith("AO Quill task: back_translate:bs") and "response_format" in c]
    assert not [c for c in bridge.calls if c["text"].startswith("AO Quill task: translate:bs") and "response_format" in c]
    bs = [p for p in dict(wp.calls)["commit-post"]["posts"] if p["language"] == "bs"][0]
    assert bs["title"].startswith("BS") and "{{c:" not in bs["content"] and "aoq-c" not in bs["content"]
    detail = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "translate" and p.get("language") == "bs"][0]["detail"]
    assert detail["translator"] == "client.aoq_translator" and detail["translation"]["content_html"]


async def test_translategemma_translates_again_instead_of_a_writer_revision():
    from aoq_worker.pipeline import run_once

    responses = good_responses()
    good = responses["fidelity"]
    low = {"score": 0.6, "summary": "Changed numbers.", "issues": [{"severity": "major", "location": "", "problem": "2004 became 2014", "fix": "2004"}]}
    responses["fidelity"] = lambda n, kw: low if n == 1 else good
    wp = FakeWP()
    bridge = GemmaBridge(responses)
    d = deps(wp, bridge)
    base = d.overlay_agents()
    d.overlay_agents = lambda: base + [{"id": "client.aoq_translator", "type": "ollama", "model": "translategemma:12b"}]
    outcome = await run_once(d)
    assert outcome.status == "succeeded", outcome.reason
    assert not [c for c in bridge.calls if c["text"].startswith("AO Quill task: translate:bs:revise")]
    qa_rows = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "translation_qa" and p.get("language") == "bs"]
    assert len(qa_rows) == 2 and "Translating again" in (qa_rows[0].get("failure_reason") or "")
    bs = [p for p in dict(wp.calls)["commit-post"]["posts"] if p["language"] == "bs"][0]
    assert bs["title"].startswith("BT")  # the second translation is the one published


def test_meaning_check_forgives_name_spellings_only_for_other_alphabets():
    from aoq_worker import markup
    from aoq_worker.stages.translate import NAMES_IN_OTHER_SCRIPT

    assert markup.SCRIPT_OF.get("ar") == "Arabic" and markup.SCRIPT_OF.get("bs", "Latin") == "Latin"
    assert "spelled differently is not a change in" in NAMES_IN_OTHER_SCRIPT


async def test_meaning_check_prompt_for_a_latin_language_has_no_name_rule():
    outcome, wp, bridge = await run()
    assert outcome.status == "succeeded", outcome.reason
    fidelity = [c["text"] for c in bridge.calls if c["text"].startswith("AO Quill task: fidelity:bs")]
    assert fidelity and all("another alphabet" not in t for t in fidelity)


async def test_translation_lengths_are_fitted_without_translating_again():
    responses = good_responses()
    tr = dict(responses["translate:bs"])
    tr["seo_title"] = "Zimske aktivnosti | Prirodni park Blidinje | Turistički vodič Mostar"  # 68 characters
    tr["seo_description"] = ("Otkrijte zimske ljepote prirodnog parka Blidinje, oaze za ljubitelje skijanja i šetnje sa snježnim "
                             "cipelama. Uživajte u dobro označenim stazama i baštini krajolika.")
    assert len(tr["seo_title"]) > 60 and len(tr["seo_description"]) > 160
    responses["translate:bs"] = tr
    responses["translate:fit"] = {"options": [
        "Zimske aktivnosti u parku prirode Blidinje i okolini za cijelu porodicu",  # too long, skipped
        "Зимске активности у парку Блидиње",  # wrong alphabet, skipped
        "Zimske aktivnosti u parku prirode Blidinje",
        "Otkrijte zimu u parku prirode Blidinje: skijanje, staze za šetnju sa snježnim cipelama i stara baština koju vrijedi vidjeti.",
    ]}
    outcome, wp, bridge = await run(responses)
    assert outcome.status == "succeeded", outcome.reason
    bs = [p for p in dict(wp.calls)["commit-post"]["posts"] if p["language"] == "bs"][0]
    assert bs["seo"]["title"] == "Zimske aktivnosti u parku prirode Blidinje"
    assert 120 <= len(bs["seo"]["description"]) <= 160
    # Fixed in place: one translation, one round of checks, the body as translated.
    assert sum(1 for c in bridge.calls if c["text"].startswith("AO Quill task: translate:bs") and "response_format" in c) == 1
    assert len([p for n, p in wp.calls if n == "report-stage" and p["stage"] == "translation_qa" and p.get("language") == "bs"]) == 1
    detail = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "translate" and p.get("language") == "bs"][0]["detail"]
    assert set(detail["fitted"]) == {"seo_title", "seo_description"}


def test_a_title_too_long_is_cut_at_a_separator():
    from aoq_worker.stages.draft import _cut

    assert _cut("seo_title", "Zimske aktivnosti | Prirodni park Blidinje | Turistički vodič Mostar.") == "Zimske aktivnosti | Prirodni park Blidinje"

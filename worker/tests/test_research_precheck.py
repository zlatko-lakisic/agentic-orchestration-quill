"""Research pre-check: claims must cite pages the agent actually fetched and quote them."""

from __future__ import annotations

import copy

from aoq_worker.pipeline import run_once

from .fakes import FakeBridge, FakeWebTools, FakeWP, deps, good_responses

BRIDGE = "https://source.example.org/bridge"
RIVER = "https://other.example.net/river"  # a second site: research needs claims from at least two


def web_with_pages() -> FakeWebTools:
    web = FakeWebTools()
    web.fetched = {
        BRIDGE: " ".join(f"Here is verbatim supporting quote {i} in the text." for i in (1, 3, 5)),
        RIVER: " ".join(f"Also verbatim supporting quote {i} on this page." for i in (2, 4, 6)),
    }
    return web


async def run(responses, web):
    wp = FakeWP(trigger="test")
    bridge = FakeBridge(responses)
    outcome = await run_once(deps(wp, bridge, web_tools=web))
    return outcome, wp, bridge


def verifier_payload(bridge):
    import json

    call = [c for c in bridge.calls if c["text"].startswith("AO Quill task: verify_quotes")][0]
    return json.loads(call["text"].split("\n", 2)[2])


async def test_claims_on_fetched_pages_pass_without_retry():
    outcome, wp, bridge = await run(good_responses(), web_with_pages())
    assert outcome.status == "succeeded", outcome.reason
    assert not any("research:retry" in c["text"].split("\n", 1)[0] for c in bridge.calls)
    assert len(verifier_payload(bridge)["claims"]) == 6


async def test_unfetched_sources_trigger_one_retry_with_feedback():
    r = good_responses()
    bad = copy.deepcopy(r["research"])
    bad["sources"][0]["url"] = "https://invented.example.org/never-read"
    bad["claims"] = [dict(c, supporting_quote="a paraphrase that is on no page") if c["source_id"] == "s2" else c for c in bad["claims"]]
    r["research"] = bad
    r["research:retry2"] = good_responses()["research"]
    outcome, wp, bridge = await run(r, web_with_pages())
    assert outcome.status == "succeeded", outcome.reason
    retry = [c for c in bridge.calls if c["text"].startswith("AO Quill task: research:retry2")]
    assert retry, "research ran a second time"
    assert "claim(s) were rejected" in retry[0]["text"]
    assert BRIDGE in retry[0]["text"], "the retry lists the pages that were really read"
    detail = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "research"][-1]["detail"]
    # Attempt 1: the three s1 claims cite an invented URL but quote the bridge page, so they are
    # re-attributed and kept; the three s2 claims misquote the river page and are dropped.
    assert [a["kept"] for a in detail["precheck"]] == [3, 6]


async def test_only_surviving_claims_reach_the_verifier():
    r = good_responses()
    r["research"] = copy.deepcopy(r["research"])
    r["research"]["claims"][0]["supporting_quote"] = "not on the page at all"
    r["verify_quotes"] = copy.deepcopy(r["verify_quotes"])
    r["verify_quotes"]["claims"] = [v for v in r["verify_quotes"]["claims"] if v["claim_id"] != "c1"]
    outcome, wp, bridge = await run(r, web_with_pages())
    assert [c["claim_id"] for c in verifier_payload(bridge)["claims"]] == ["c2", "c3", "c4", "c5", "c6"]
    # The dropped claim is not usable later either: the fixture draft still cites c1, so the draft gate stops the run.
    assert outcome.status == "failed" and "c1" in outcome.reason and outcome.reason.startswith("draft")



def test_precheck_reattributes_root_urls_and_cuts_ellipsis_quotes():
    from aoq_worker.stages.research import precheck

    page = "https://exploremostar.ba/en/listing/open-dance-fest/"
    fetched = {page: "In the period from May 31 to June 1, 2025, Mostar will host a great dance spectacle. "
                     "The festival, called MO STARS OPEN DANCE FEST 2025, is being organized for the sixth time."}
    sources = [{"source_id": "s001", "url": "https://exploremostar.ba", "title": "Explore Mostar"},
               {"source_id": "s002", "url": "https://www.mostarinfo.com/summer-fest/", "title": "Summer"}]
    claims = [
        {"claim_id": "c001", "claim": "x", "source_id": "s001",
         "supporting_quote": "In the period from May 31 to June 1, 2025, Mostar will host a great dance spectacle... The festival, called MO STARS"},
        {"claim_id": "c002", "claim": "y", "source_id": "s002", "supporting_quote": "This three-day music festival gathers top names"},
    ]
    kept, dropped, srcs = precheck(fetched, sources, claims)
    assert [c["claim_id"] for c in kept] == ["c001"]
    assert kept[0]["supporting_quote"] == "In the period from May 31 to June 1, 2025, Mostar will host a great dance spectacle"
    assert [s["url"] for s in srcs] == [page] and kept[0]["source_id"] == srcs[0]["source_id"]
    assert dropped == [{"claim_id": "c002", "reason": "source https://www.mostarinfo.com/summer-fest/ was never fetched with fetch_url, and the quote is on no page that was"}]


def test_precheck_prefers_the_cited_page_and_rejects_short_pieces():
    from aoq_worker.stages.research import find_quote

    assert find_quote("built in 1566 ... spans the Neretva", "It was built in 1566 and spans the Neretva river.") is None
    assert find_quote("Exactly this sentence is here", "So: Exactly this sentence is here.") == "Exactly this sentence is here"


async def test_claims_without_sources_are_attached_to_the_fetched_page_with_the_quote():
    r = good_responses()
    r["research"] = copy.deepcopy(r["research"])
    r["research"]["sources"] = []
    for c in r["research"]["claims"]:
        c.pop("source_id")
        c.pop("claim_id")
    outcome, wp, bridge = await run(r, web_with_pages())
    assert outcome.status == "succeeded", outcome.reason
    payload = verifier_payload(bridge)
    urls = {s["url"] for s in payload["sources"]}
    assert urls == {BRIDGE, RIVER}
    assert [c["claim_id"] for c in payload["claims"]] == [f"c{i}" for i in range(1, 7)]
    structure = [c for c in bridge.calls if c["text"].startswith("AO Quill task: research:structure")][0]
    assert "sources" not in structure["json_schema"]["properties"]


def test_loosely_copied_quotes_snap_to_the_page_sentence():
    from aoq_worker.stages.research import precheck, snap_quote

    page = ("Herbal teas are popular. Tea made from herbs has been popular for a long time. Ancient Egyptian era "
            "documents have been found that speak of the benefits and usage of herbal tea (Kara, 2009). Prices vary.")
    quote = "Tea made from herbs has been popular for a long time, with documents dating back to ancient Egyptian times"
    snapped = snap_quote(quote, page)
    assert snapped and snapped.startswith("Tea made from herbs") and "Egyptian era documents" in snapped
    assert snap_quote("Mostar has the oldest bridge in Europe built by the Romans in 50 BC", page) is None

    url = "https://example.org/tea"
    kept, dropped, srcs = precheck({url: page}, [], [
        {"claim_id": "c1", "claim": "x", "source_id": "", "supporting_quote": quote},
        {"claim_id": "c2", "claim": "y", "source_id": "", "supporting_quote": "Herbal tea was invented in Mostar in 1820 by a monk"},
    ])
    assert [c["claim_id"] for c in kept] == ["c1"] and kept[0]["supporting_quote"] in page
    assert srcs[0]["url"] == url and dropped[0]["claim_id"] == "c2"

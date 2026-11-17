"""Topic evidence comes from the worker's own search; novelty sees a shortlist of similar posts."""

from __future__ import annotations

import json

from aoq_worker.pipeline import run_once
from aoq_worker.stages.novelty import shortlist

from .fakes import FakeBridge, FakeWebTools, FakeWP, deps, good_responses


class SearchingWeb(FakeWebTools):
    """FakeWebTools plus a web_search that finds results for every title but one."""

    def __init__(self, empty_title: str) -> None:
        super().__init__()
        self.empty_title = empty_title
        self.queries: list[str] = []

    async def web_search(self, query: str, max_results: int = 8, language: str = "") -> dict:
        self.queries.append(query)
        if query == self.empty_title:
            return {"query": query, "results": []}
        slug = "-".join(query.lower().split())[:40]
        return {"query": query, "results": [
            {"title": f"Result {i} for {query}", "url": f"https://real.example.net/{slug}/{i}", "snippet": f"Snippet {i}."}
            for i in range(1, max_results + 1)
        ]}

    search_patiently = web_search


async def test_evidence_is_replaced_by_real_search_results():
    r = good_responses()
    candidates = r["topics"]["candidates"]
    for c in candidates:
        c["evidence"] = [{"url": "http://example.com/invented", "title": "x", "note": "invented"}]
    empty = candidates[-1]
    assert r["novelty"]["chosen_candidate_id"] != empty["id"]
    r["novelty"]["judgments"] = [j for j in r["novelty"]["judgments"] if j["candidate_id"] != empty["id"]]
    web = SearchingWeb(empty["title"])
    wp = FakeWP(trigger="test")
    bridge = FakeBridge(r)
    outcome = await run_once(deps(wp, bridge, web_tools=web))
    assert outcome.status == "succeeded", outcome.reason
    assert web.queries[: len(candidates)] == [c["title"] for c in candidates]
    detail = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "topics"][-1]["detail"]
    assert detail["dropped_without_evidence"] == [empty["id"]]
    novelty_call = [c for c in bridge.calls if c["text"].startswith("AO Quill task: novelty")][0]
    offered = json.loads(novelty_call["context"])["candidates"]
    urls = {e["url"] for c in offered for e in c["evidence"]}
    assert urls and all(u.startswith("https://real.example.net/") for u in urls), "no invented URL survives"


def test_shortlist_ranks_posts_by_shared_distinctive_words():
    posts = [
        {"key": "en:1", "language": "en", "title": "Street art murals in the old town", "excerpt": "Murals and artists."},
        {"key": "en:2", "language": "en", "title": "Best burek bakeries", "excerpt": "Food in the old town."},
        {"key": "en:3", "language": "en", "title": "Hiking Velez mountain", "excerpt": "Trails and views."},
        {"key": "bs:1", "language": "bs", "title": "Murali i ulična umjetnost", "excerpt": "Murali u starom gradu."},
    ]
    cands = [{"id": "t1", "title": "Mostar street art walking tour", "angle": "Decoding the murals"},
             {"id": "t2", "title": "Velez mountain hiking trails", "angle": "Trail difficulty"}]
    out = shortlist(cands, posts, per_candidate=2)
    assert [p["key"] for p in out["t1"]] == ["en:1"]
    assert [p["key"] for p in out["t2"]] == ["en:3"]


async def test_novelty_context_has_a_shortlist_not_every_post():
    r = good_responses()
    bridge = FakeBridge(r)
    outcome = await run_once(deps(FakeWP(trigger="test"), bridge))
    assert outcome.status == "succeeded", outcome.reason
    call = [c for c in bridge.calls if c["text"].startswith("AO Quill task: novelty")][0]
    ctx = json.loads(call["context"])
    assert "existing_posts" not in ctx
    assert all("similar_existing_posts" in c for c in ctx["candidates"])

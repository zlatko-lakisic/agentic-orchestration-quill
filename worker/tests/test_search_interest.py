"""Search interest from Google Trends RSS and Google suggestions (no account): parsing, filtering, caching."""

from __future__ import annotations

import json

from aoq_worker import search_interest
from aoq_worker.search_interest import SearchInterest, matches

RSS = b"""<?xml version="1.0"?><rss xmlns:ht="https://trends.google.com/trending/rss"><channel>
<item><title>sarajevo film festival</title><ht:approx_traffic>2000+</ht:approx_traffic></item>
<item><title>mostar bridge diving</title><ht:approx_traffic>500+</ht:approx_traffic></item>
<item><title>premier league</title><ht:approx_traffic>100000+</ht:approx_traffic></item>
</channel></rss>"""


class FakePublic:
    def __init__(self):
        self.urls = []

    async def _get_public(self, url, max_bytes):
        self.urls.append(url)
        if "trends.google.com" in url:
            return RSS, "application/rss+xml", url
        q = url.split("q=", 1)[1]
        return json.dumps([q, [f"{q} winter", f"{q} wild mushrooms season", "mostarda recipe"]]).encode(), "application/json", url


async def test_collect_filters_trends_and_gathers_questions(tmp_path, monkeypatch):
    monkeypatch.setattr(search_interest, "PAUSE", 0)
    web = FakePublic()
    si = SearchInterest(web, tmp_path / "cache.json")
    out = await si.collect(["Mostar"], ["BA", "DE"])
    queries = [t["query"] for t in out["trending"]]
    # Only trending searches that touch the seed terms, in every country.
    assert "premier league" not in queries and queries.count("mostar bridge diving") == 2
    assert "Mostar winter" in out["questions"] and out["errors"] == []
    assert "mostarda recipe" not in out["questions"], "the seed must be a whole word"
    n = len(web.urls)
    again = await si.collect(["Mostar"], ["BA", "DE"])
    assert again == out and len(web.urls) == n, "cached: Google is not asked again"


async def test_failures_are_reported_not_raised(tmp_path, monkeypatch):
    monkeypatch.setattr(search_interest, "PAUSE", 0)

    class Broken:
        async def _get_public(self, url, max_bytes):
            raise OSError("blocked")

    out = await SearchInterest(Broken(), tmp_path / "c.json").collect(["Mostar"], ["BA"])
    assert out["trending"] == [] and out["questions"] == [] and out["errors"]


def test_candidates_match_searches_sharing_two_words():
    interest = {"trending": [{"query": "mostar bridge diving"}], "questions": ["wild mushrooms season herzegovina", "mostar weather"]}
    assert matches({"title": "Foraging wild mushrooms", "angle": "When the season starts"}, interest) == ["wild mushrooms season herzegovina"]
    assert matches({"title": "Bridge diving in Mostar", "angle": "History"}, interest) == ["mostar bridge diving"]

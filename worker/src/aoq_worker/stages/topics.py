"""Stage 4: up to ten topic candidates from the analyst; the worker attaches real search evidence."""

from __future__ import annotations

from datetime import datetime, timezone
from pathlib import Path

from .. import search_interest
from ..context import RunContext
from .common import clip, dumps, gate

MIN_CANDIDATES = 3
EVIDENCE_RESULTS = 3


async def attach_evidence(ctx: RunContext, candidates: list[dict]) -> list[dict]:
    """Replace model-written evidence with real search results for each working title.

    Models invent plausible URLs (example.com in one test run), so the worker searches each
    title itself and keeps up to EVIDENCE_RESULTS results. Without worker web tools the model's
    evidence is kept as it is.
    """
    if ctx.web is None or not hasattr(ctx.web, "search_patiently"):
        return [dict(c, evidence=c.get("evidence", [])) for c in candidates]
    out = []
    for c in candidates:
        try:
            found = (await ctx.web.search_patiently(c["title"], max_results=EVIDENCE_RESULTS))["results"]
        except Exception:  # noqa: BLE001 - a failed search just means no evidence for this one
            found = []
        evidence = [
            {"url": r["url"], "title": r["title"], "note": r["snippet"] or r["title"] or "Search result for the title."}
            for r in found
        ]
        out.append(dict(c, evidence=evidence))
    return out

PROMPT = """Propose 10 new post topics for this site, for today's date in the context.
Make them different from each other: no two about the same event, place, dish or route, and spread them over the
site's themes and content gaps (food, history, nature and hiking, practical tips, events, culture, day trips).
Each topic needs a working title, the angle that makes it useful to a visitor, and one to three target categories
from the category list in the context (give their "id" values). Avoid subjects the existing titles already cover.
Do not list URLs or sources: the worker searches every title itself and drops topics nobody writes about.
The context's search_interest lists what people search right now (trending searches and the questions people
type into Google). Prefer topics that answer such searches when they fit the site; do not chase news that does
not."""


REJECTED = """

These topics were proposed earlier in this run and judged already covered by the site; propose different subjects,
not rewordings of them:
{rejected}"""


async def collect_interest(ctx: RunContext) -> dict:
    """Search interest for the topics prompt; empty when the worker has no web tools or Google fails."""
    if ctx.web is None or not hasattr(ctx.web, "_get_public"):
        return {}
    seeds = list(ctx.config.get("trend_seeds") or [])
    geos = list(ctx.config.get("trend_geos") or [])
    if not seeds and not geos:
        return {}
    cache = Path.home() / ".cache" / "aoq" / "search_interest.json"
    try:
        return await search_interest.SearchInterest(ctx.web, cache).collect(seeds, geos)
    except Exception:  # noqa: BLE001 - search interest is a hint, never a reason to fail
        return {}


async def run(ctx: RunContext) -> list[dict]:
    async with ctx.reporter.stage("topics", attempt=ctx.topic_round) as rec:
        inv = ctx.inv
        primary = inv.primary
        # Ideas come from one direct JSON call (no tool loop: with tools the model searched once and
        # proposed three variations of one festival); the worker's own search per title is the evidence.
        interest = await collect_interest(ctx)
        titles = sorted(inv.posts_in(primary), key=lambda p: p["date"], reverse=True)
        context = dumps(
            {
                "today": datetime.now(timezone.utc).date().isoformat(),
                "site_profile": ctx.site_profile,
                "site_brief": ctx.config["site_brief"],
                "categories": [{"id": t["id"], "name": t["name"]} for t in inv.terms_in(primary, "category")],
                "existing_titles": [clip(p["title"], 100) for p in titles],
                "search_interest": {"trending": [t["query"] for t in interest.get("trending", [])][:25],
                                    "questions": interest.get("questions", [])[:50]},
            }
        )
        prompt, task = PROMPT, "topics"
        if ctx.rejected_topics:
            task = f"topics:round{ctx.topic_round}"
            prompt += REJECTED.format(rejected="\n".join(f"- {r['title']} ({r['why']})" for r in ctx.rejected_topics[-20:]))
        res = await ctx.client.json_call("topics", task, prompt, context, "topic_candidates")
        rec.add_calls(res.records)
        # Candidate IDs are assigned by the worker (models used names like "fortica_hill").
        numbered = [dict(c, id=f"t{i}", target_category_ids=c.get("target_category_ids", []))
                    for i, c in enumerate(res.data["candidates"], 1)]
        # The schema lets a candidate through without evidence so one gap does not fail the run;
        # such candidates are dropped here instead.
        # Category IDs that do not exist on the site are removed the same way; a candidate with no
        # real category left is dropped.
        categories = inv.term_ids(primary, "category")
        candidates, without, removed_categories, no_category = [], [], {}, []
        # Without the worker's search there is no evidence to require (AO-hosted tools only).
        searched = ctx.web is not None and hasattr(ctx.web, "search_patiently")
        for c in await attach_evidence(ctx, numbered):
            if searched and not c["evidence"]:
                without.append(c["id"])
                continue
            unknown = [i for i in c["target_category_ids"] if i not in categories]
            if unknown:
                removed_categories[c["id"]] = unknown
                c = dict(c, target_category_ids=[i for i in c["target_category_ids"] if i in categories])
            if not c["target_category_ids"]:
                # Kept: the taxonomy stage chooses the post's real categories later.
                no_category.append(c["id"])
            candidates.append(c)
        gate(len(candidates) >= MIN_CANDIDATES, "topics", f"Only {len(candidates)} topic candidates have search evidence.")
        ids = [c["id"] for c in candidates]
        gate(len(ids) == len(set(ids)), "topics", "Topic candidate IDs are not unique.")
        for c in candidates:
            c["search_interest"] = search_interest.matches(c, interest)
        rec.detail = {
            "candidates": [c["title"] + (f" (searched: {'; '.join(c['search_interest'][:2])})" if c["search_interest"] else "") for c in candidates],
            "search_interest": {"trending": len(interest.get("trending", [])), "questions": len(interest.get("questions", [])),
                                "examples": interest.get("questions", [])[:8], "errors": interest.get("errors", [])},
            "dropped_without_evidence": without,
            "without_valid_category": no_category,
            "unknown_categories_removed": removed_categories,
        }
        ctx.candidates = candidates
        return candidates

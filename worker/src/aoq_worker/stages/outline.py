"""Stage 7: outline with key points mapped to verified claims and internal links."""

from __future__ import annotations

from ..context import RunContext
from .common import clip, dumps, gate

PROMPT = """Write an outline for a post titled roughly "{title}" with this angle: {angle}.
Use only the verified claims in the context as facts, and map each key point to the claim IDs it uses
(an empty list for points that state no facts).
Headings form a hierarchy (level 2, 3 or 4), like a well edited magazine article:
- Level 2 only for the 3 to 6 main parts of the article (for example "Must-try dishes", "Where to eat them").
- Several things of the same kind (dishes, places, tour stops, tips, days) are level 3 subsections under one
  level 2 section, never level 2 sections of their own.
- Level 4 only for details inside a level 3 subsection. Never skip a level (no level 3 without a level 2 above).
Pick up to five internal links from the existing posts list, by post ID, where a reader would benefit."""


async def run(ctx: RunContext) -> dict:
    assert ctx.topic is not None
    async with ctx.reporter.stage("outline") as rec:
        primary = ctx.inv.primary
        posts = sorted(ctx.inv.posts_in(primary, published_only=True), key=lambda p: p["date"], reverse=True)[:300]
        context = dumps(
            {
                "site_profile": ctx.site_profile,
                "verified_claims": list(ctx.claims.values()),
                "existing_posts": [{"id": p["id"], "title": clip(p["title"], 160)} for p in posts],
            }
        )
        prompt = PROMPT.format(title=ctx.topic["title"], angle=ctx.topic["angle"])
        res = await ctx.client.json_call("outline", "outline", prompt, context, "outline")
        rec.add_calls(res.records)
        outline = res.data
        # Links to posts that are not published and claim IDs that were not verified are removed
        # here, so the writer never sees them; the draft gate still checks the written post.
        valid_ids = ctx.inv.published_ids(primary)
        bad_links = [l["post_id"] for l in outline["internal_links"] if l["post_id"] not in valid_ids]
        bad_claims = sorted({c for s in outline["sections"] for p in s["key_points"] for c in p["claim_ids"] if c not in ctx.claims})
        outline = dict(
            outline,
            internal_links=[l for l in outline["internal_links"] if l["post_id"] in valid_ids],
            sections=[
                dict(s, key_points=[dict(p, claim_ids=[c for c in p["claim_ids"] if c in ctx.claims]) for p in s["key_points"]])
                for s in outline["sections"]
            ],
        )
        rec.detail = {"title": outline["title"], "sections": [s["heading"] for s in outline["sections"]], "internal_links": len(outline["internal_links"])}
        if bad_links or bad_claims:
            rec.detail["removed"] = {"unpublished_link_targets": bad_links, "unverified_claim_ids": bad_claims}
        ctx.outline = outline
        return outline

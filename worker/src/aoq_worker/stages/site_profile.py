"""Stage 2: site profile (AO), cached in WordPress by inventory fingerprint."""

from __future__ import annotations

from datetime import datetime, timedelta, timezone

from ..context import RunContext
from .common import change_ratio, clip, dumps, now_iso, parse_iso

MAX_CHANGE = 0.05
MAX_AGE = timedelta(days=7)

PROMPT = """Describe this website so a writer can add one new post a day that fits it.
Use the site brief, the categories, the most used tags, and the existing titles and excerpts in the context.
Return what the site covers, the audience, recurring themes, content gaps worth filling, and topics to avoid."""


def fingerprint(ctx: RunContext) -> list[str]:
    return sorted(ctx.inv.post_keys())


def is_fresh(cached: dict | None, current: list[str], now: datetime | None = None) -> bool:
    if not cached:
        return False
    now = now or datetime.now(timezone.utc)
    if now - parse_iso(cached["built_at"]) > MAX_AGE:
        return False
    return change_ratio(cached["fingerprint"], current) <= MAX_CHANGE


async def run(ctx: RunContext) -> dict:
    async with ctx.reporter.stage("site_profile") as rec:
        current = fingerprint(ctx)
        cached = ctx.profiles.get("site_profile")
        if is_fresh(cached, current):
            assert cached is not None
            rec.detail = {"cache": "reused", "built_at": cached["built_at"]}
            ctx.site_profile = cached["data"]
            return cached["data"]
        inv = ctx.inv
        primary = inv.primary
        tags = sorted(inv.terms_in(primary, "post_tag"), key=lambda t: -t["count"])[:200]
        posts = sorted(inv.posts_in(primary, published_only=True), key=lambda p: p["date"], reverse=True)[:300]
        context = dumps(
            {
                "site_brief": ctx.config["site_brief"],
                "categories": [t["name"] for t in inv.terms_in(primary, "category")],
                "top_tags": [t["name"] for t in tags],
                "posts": [{"title": p["title"], "excerpt": clip(p["excerpt"], 200)} for p in posts],
            }
        )
        res = await ctx.client.json_call("site_profile", "site_profile", PROMPT, context, "site_profile")
        rec.add_calls(res.records)
        ctx.site_profile = res.data
        rec.detail = {
            "cache": "rebuilt",
            "posts_used": len(posts),
            "summary": ctx.site_profile["summary"][:600],
            "audience": ctx.site_profile["audience"][:300],
            "themes": ctx.site_profile["themes"][:10],
        }
        if not ctx.dry_run:
            await ctx.wp.call(
                "save-profiles",
                {
                    "run_id": ctx.run_id,
                    "site_profile": {"fingerprint": current, "built_at": now_iso(), "data": res.data},
                    "style_profiles": [],
                },
            )
        return res.data

"""Stage 1: inventory from WordPress (aoq/get-inventory, paginated)."""

from __future__ import annotations

from ..context import Inventory, RunContext
from .common import gate

PER_PAGE = 100


async def run(ctx: RunContext) -> Inventory:
    async with ctx.reporter.stage("inventory") as rec:
        languages: list[dict] = []
        posts: list[dict] = []
        terms: list[dict] = []
        media: list[dict] = []
        page, total = 1, 1
        while page <= max(total, 1):
            payload: dict = {"page": page, "per_page": PER_PAGE}
            if not ctx.dry_run:
                payload["run_id"] = ctx.run_id
            res = await ctx.wp.call("get-inventory", payload)
            if page == 1:
                languages = res["languages"]
            posts += res["posts"]
            terms += res["terms"]
            media += res["media"]
            total = res["total_pages"]
            page += 1
        inv = Inventory(languages=languages, posts=posts, terms=terms, media=media)
        active = inv.active_languages
        gate(bool(active), "inventory", "No site language is available.")
        gate(any(lang["primary"] for lang in active), "inventory", "The primary language is missing.")
        codes = [lang["code"] for lang in active]
        gate(len(codes) == len(set(codes)), "inventory", "Two active sites resolve to the same language.")
        for code in codes:
            gate(bool(inv.term_ids(code, "category")), "inventory", f"Language {code} has no category.")
        rec.detail = {"languages": codes, "posts": len(posts), "terms": len(terms), "media": len(media)}
        ctx.inventory = inv
        return inv

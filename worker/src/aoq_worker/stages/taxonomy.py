"""Stage 9: categories and tags chosen from existing terms only. The worker gate
checks that every chosen term ID exists for that language."""

from __future__ import annotations

from ..context import RunContext
from .common import dumps, gate

PROMPT = """Choose categories and tags for this post in language "{lang}" from the lists in the context.
Use only IDs from those lists: at least one category, at most three, and up to ten fitting tags.
Never invent new terms."""


async def choose(ctx: RunContext, lang: str, title: str, excerpt: str, rec, hint: dict | None = None) -> dict:
    cats = ctx.inv.terms_in(lang, "category")
    tags = sorted(ctx.inv.terms_in(lang, "post_tag"), key=lambda t: -t["count"])[:600]
    context = dumps(
        {
            "post": {"title": title, "excerpt": excerpt},
            "primary_language_terms": hint,
            "categories": [{"id": t["id"], "name": t["name"]} for t in cats],
            "tags": [{"id": t["id"], "name": t["name"]} for t in tags],
        }
    )
    res = await ctx.client.json_call("taxonomy", f"taxonomy:{lang}", PROMPT.format(lang=lang), context, "taxonomy")
    rec.add_calls(res.records)
    data = res.data
    unknown_cats = set(data["category_ids"]) - ctx.inv.term_ids(lang, "category")
    unknown_tags = set(data["tag_ids"]) - ctx.inv.term_ids(lang, "post_tag")
    # Unknown IDs are removed (only existing terms are ever assigned); the run stops only if no real
    # category is left.
    cat_ids = sorted(set(data["category_ids"]) - unknown_cats)
    gate(bool(cat_ids), "taxonomy", f"[{lang}] No existing category chosen (unknown IDs: {sorted(unknown_cats)}).")
    out = {"category_ids": cat_ids, "tag_ids": sorted(set(data["tag_ids"]) - unknown_tags)}
    if unknown_cats or unknown_tags:
        ctx.taxonomy_removed[lang] = {"categories": sorted(unknown_cats), "tags": sorted(unknown_tags)}
    ctx.taxonomy[lang] = out
    return out


def names(ctx: RunContext, lang: str, chosen: dict) -> dict:
    by_id = {(t["taxonomy"], t["id"]): t["name"] for t in ctx.inv.terms if t["language"] == lang}
    return {
        "categories": [by_id[("category", i)] for i in chosen["category_ids"]],
        "tags": [by_id[("post_tag", i)] for i in chosen["tag_ids"]],
    }


async def run(ctx: RunContext) -> dict:
    assert ctx.draft is not None
    lang = ctx.inv.primary
    async with ctx.reporter.stage("taxonomy", language=lang) as rec:
        out = await choose(ctx, lang, ctx.draft["title"], ctx.draft["excerpt"], rec)
        rec.detail = names(ctx, lang, out)
        if lang in ctx.taxonomy_removed:
            rec.detail["unknown_ids_removed"] = ctx.taxonomy_removed[lang]
        return out

"""Stage 14: build the multilingual bundle and hand it to aoq/commit-post in one call.
The plugin downloads images, writes posts, links translations, sets status from its
own setting, marks everything, and rolls back on any failure."""

from __future__ import annotations

import logging
import re

from .. import contracts, markup
from ..context import RunContext
from .common import clip, dumps, gate

LOG = logging.getLogger("aoq_worker")

INSTAGRAM = """Write an Instagram caption in language "{lang}" for the blog post in the context. {style}
Start with one short hook line, then two to four short sentences that make people want to read the
whole article; use only facts from the post. End with this call to action on its own line, in the same
language: "{link_hint}". No links or URLs (they do not work in Instagram captions), no hashtags in the
caption itself, at most two emoji. Under 900 characters.
Also give 5 to {max} hashtags without the # sign: specific to the post's subject and place first, then
broader travel ones. Single words or CamelCase, no spaces."""

IMAGE_FIELDS = ("key", "source", "media", "url", "source_page_url", "license", "author", "attribution")


def post_entry(ctx: RunContext, lang: str, doc: dict) -> dict:
    texts = ctx.image_texts[lang]
    return {
        "language": lang,
        "title": doc["title"],
        "slug": doc["slug"],
        "excerpt": doc["excerpt"],
        "content": markup.strip_claims(doc["content_html"]),
        "category_ids": ctx.taxonomy[lang]["category_ids"],
        "tag_ids": ctx.taxonomy[lang]["tag_ids"],
        "seo": {"title": doc["seo_title"], "description": doc["seo_description"], "focus_keyword": doc["focus_keyword"]},
        "images": [{"key": img["key"], "alt": texts[img["key"]]["alt"], "caption": texts[img["key"]]["caption"]} for img in ctx.images],
        **({"instagram": ctx.instagram[lang]} if lang in ctx.instagram else {}),
    }


def build_bundle(ctx: RunContext) -> dict:
    assert ctx.draft is not None
    posts = [post_entry(ctx, ctx.inv.primary, ctx.draft)]
    for lang in ctx.target_languages():
        posts.append(post_entry(ctx, lang, ctx.translations[lang]))
    expected = sorted(lang["code"] for lang in ctx.inv.active_languages)
    gate(sorted(p["language"] for p in posts) == expected, "commit", "The bundle does not cover every site language.")
    for p in posts:
        gate("{{c:" not in p["content"], "commit", f"[{p['language']}] Claim markers left in content.")
    bundle = {
        "run_id": ctx.run_id,
        "posts": posts,
        "images": [{k: img[k] for k in IMAGE_FIELDS} for img in ctx.images],
    }
    return contracts.validate(bundle, "abilities/commit-post.input", stage="commit")


async def write_caption(client, cfg: dict, lang: str, title: str, excerpt: str, text: str) -> tuple[dict, list]:
    """One Instagram caption and its hashtags (no #). Returns (caption, call records)."""
    res = await client.json_call(
        "draft",
        f"instagram:{lang}",
        INSTAGRAM.format(lang=lang, style=cfg.get("caption_style", ""), link_hint=cfg.get("link_hint", ""), max=max(5, int(cfg.get("max_hashtags", 15)))),
        dumps({"title": title, "excerpt": excerpt, "text": clip(re.sub(r"\s+", " ", text), 6000)}),
        "instagram_caption",
    )
    tags = [re.sub(r"[^\w]", "", t, flags=re.UNICODE) for t in res.data["hashtags"]]
    return {"caption": res.data["caption"].strip(), "hashtags": [t for t in tags if t][:30]}, list(res.records)


async def instagram_captions(ctx: RunContext, detail: dict) -> None:
    """An Instagram caption and hashtags for each post language that posts to Instagram (D29).
    A caption that cannot be written is left out: WordPress then builds one from the title and
    excerpt, so this never fails the run."""
    cfg = ctx.config.get("instagram") or {}
    if not cfg.get("enabled"):
        return
    docs = {ctx.inv.primary: ctx.draft, **ctx.translations}
    for lang in cfg.get("languages") or [ctx.inv.primary]:
        doc = docs.get(lang)
        if doc is None or lang in ctx.instagram:
            continue
        text = re.sub(r"<[^>]+>", " ", markup.strip_claims(doc["content_html"]))
        try:
            ctx.instagram[lang], records = await write_caption(ctx.client, cfg, lang, doc["title"], doc["excerpt"], text)
        except Exception as exc:  # noqa: BLE001 - optional output
            LOG.warning("no Instagram caption for %s: %s", lang, exc)
            detail.setdefault("instagram_errors", {})[lang] = str(exc)[:300]
            continue
        detail.setdefault("_calls", []).extend(records)


async def run(ctx: RunContext) -> dict | None:
    async with ctx.reporter.stage("commit") as rec:
        ig: dict = {}
        await instagram_captions(ctx, ig)
        if ig.get("_calls"):
            rec.add_calls(ig.pop("_calls"))
        ctx.bundle = build_bundle(ctx)
        if ctx.dry_run or ctx.test:
            ctx.preview = {
                "posts": [
                    {"language": p["language"], "title": p["title"], "excerpt": p["excerpt"], "content": p["content"]}
                    for p in ctx.bundle["posts"]
                ]
            }
            rec.detail = {"dry_run": ctx.dry_run, "test": ctx.test, "languages": [p["language"] for p in ctx.bundle["posts"]], "instagram": ctx.instagram, **ig}
            return None
        ctx.commit_result = await ctx.wp.call("commit-post", ctx.bundle)
        rec.detail = {
            "status": ctx.commit_result["status"],
            "posts": ctx.commit_result["posts"],
            "translation_group": ctx.commit_result["translation_group"],
            "instagram": ctx.instagram,
            **ig,
        }
        return ctx.commit_result

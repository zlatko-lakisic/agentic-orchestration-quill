"""Stage 3: style profiles per language, built only from human posts.

Profiles are passed to later stages as call context (docs/decisions.md D5)."""

from __future__ import annotations

from ..context import RunContext
from .common import clip, dumps, gate, now_iso

SAMPLE_POSTS = 12
SAMPLE_CHARS = 6000

PROMPT = """Describe the writing style of these posts, written by people for this site in language "{lang}".
Cover voice, sentence length, grammatical person, formality, structure, typical length in words,
heading and formatting habits, and phrases a new post should avoid. Describe the style; do not copy text.
Set "language" to "{lang}"."""


def languages_to_build(ctx: RunContext) -> list[str]:
    """The primary language always; other languages only with enough human posts."""
    minimum = ctx.config["min_human_posts_for_style"]
    out = [ctx.inv.primary]
    for code in ctx.target_languages():
        if len(ctx.inv.posts_in(code, published_only=True, human_only=True)) >= minimum:
            out.append(code)
    return out


def needs_rebuild(cached: dict | None, keys: list[str]) -> bool:
    """Rebuild when there is no profile or a human post appeared since it was built."""
    return cached is None or bool(set(keys) - set(cached["fingerprint"]))


def borrowed_profiles(ctx: RunContext) -> tuple[str, dict[str, dict]]:
    """The writing style borrowed from another site (source, language -> profile), if any."""
    b = ctx.profiles.get("borrowed_style") or {}
    return str(b.get("source") or ""), {p["language"]: p["data"] for p in b.get("profiles", []) if p.get("data")}


async def run(ctx: RunContext) -> dict[str, dict]:
    async with ctx.reporter.stage("style_profile") as rec:
        cached = {p["language"]: p for p in ctx.profiles.get("style_profiles", [])}
        rebuilt: list[dict] = []
        detail: dict[str, str] = {}
        minimum = ctx.config["min_human_posts_for_style"]
        source, borrowed = borrowed_profiles(ctx)
        # A language with too few human posts uses the borrowed style (a site written only by AO Quill,
        # whose style comes from the company's main site), the primary language included.
        for code in [ctx.inv.primary, *ctx.target_languages()]:
            if code in borrowed and len(ctx.inv.posts_in(code, published_only=True, human_only=True)) < minimum:
                ctx.style_profiles[code] = borrowed[code]
                detail[code] = f"borrowed from {source}"
        for code in languages_to_build(ctx):
            if code in ctx.style_profiles:
                continue
            human = sorted(
                ctx.inv.posts_in(code, published_only=True, human_only=True), key=lambda p: p["date"], reverse=True
            )
            if code == ctx.inv.primary:
                gate(bool(human), "style_profile", "The primary language has no human-written posts to learn the style from, and no writing style is borrowed from another site.")
            keys = sorted(p["key"] for p in human)
            if not needs_rebuild(cached.get(code), keys):
                ctx.style_profiles[code] = cached[code]["data"]
                detail[code] = "reused"
                continue
            context = dumps([{"title": p["title"], "content": clip(p["content"], SAMPLE_CHARS)} for p in human[:SAMPLE_POSTS]])
            res = await ctx.client.json_call(
                "style_profile", f"style_profile:{code}", PROMPT.format(lang=code), context, "style_profile"
            )
            rec.add_calls(res.records)
            gate(res.data["language"] == code, "style_profile", f"Style profile came back for {res.data['language']}, expected {code}.")
            ctx.style_profiles[code] = res.data
            rebuilt.append({"language": code, "fingerprint": keys, "built_at": now_iso(), "data": res.data})
            detail[code] = "rebuilt"
        rec.detail = detail
        if rebuilt and not ctx.dry_run:
            await ctx.wp.call("save-profiles", {"run_id": ctx.run_id, "site_profile": None, "style_profiles": rebuilt})
        return ctx.style_profiles

"""Stage 8: draft in the primary language, plus the draft gates reused after every
revision in the QA loop."""

from __future__ import annotations

import re

from .. import markup
from ..context import RunContext
from .common import dumps, gate

RULES = """Format rules (checked by code, a draft that breaks them is rejected):
- content_html uses <p>, <h2>, <h3>, <h4>, <ul>, <ol>, <li>, <strong>, <em>, <blockquote>. No <h1>, no images, no scripts.
- Headings follow the outline's levels: <h2> only for the main parts of the article (at most 6), <h3> for each of
  several things of the same kind inside a part (dishes, places, stops, tips), <h4> for details inside an <h3>.
  Never skip a level.
- After every sentence that states a fact, add a marker with the claim IDs it rests on, like {{{{c:c1,c4}}}}.
  Use only claim IDs from the verified claims. Do not state facts that no claim supports.
- Internal links are written as <a data-aoq-post="POST_ID">anchor text</a> with IDs from the outline.
- Place exactly these image placeholders where in-body images belong, each once: {placeholders}.
- slug is lowercase ASCII words joined by hyphens. seo_title stays under 60 characters, seo_description
  between 120 and 160 characters, and focus_keyword is a short phrase from the post."""

PROMPT = """Write the full post from the outline in the context, in language "{lang}", following the style profile.
Length: at least {minimum} words (a hard rule; aim for about {target}). Reach it with the verified claims, practical advice for visitors
and context that states no new facts; never pad with facts that no verified claim supports.
{rules}"""

LENGTH_MAX, LENGTH_DEFAULT = 1500, 800


def length_target(ctx: RunContext) -> tuple[int, int]:
    """(target, minimum) words. The minimum is the "Minimum word count" setting (a rule for the draft and
    every revision, checked in QA); the target is the site's typical post length from its style profile,
    never below the minimum."""
    minimum = int(ctx.config.get("min_words") or 300)
    style = ctx.style_profiles.get(ctx.inv.primary) or {}
    typical = int(style.get("typical_length_words") or LENGTH_DEFAULT)
    return max(minimum, min(LENGTH_MAX, typical)), minimum


def word_count(content_html: str) -> int:
    import re as _re

    text = _re.sub(r"<[^>]+>", " ", markup.strip_claims(content_html))
    return len(_re.findall(r"[^\W_]+", text, _re.UNICODE))

REVISE = """Apply the revision plan in the context to the draft, edit by edit. Change only what the plan names;
keep every other sentence, heading, link, image placeholder and claim marker exactly as it is.
- The post must keep at least {minimum} words (it has {words} now).
- Never add a fact, number, name, date or detail that no verified claim states. New material comes from
  the verified claims (with their markers) or is practical advice for visitors that states no facts.
- A sentence the plan calls unsupported: delete it, or rewrite it to say only what the verified claims say.
{rules}"""

PLAN = """You are the editor. Read the QA results in the context and write a short revision plan for the writer:
numbered, concrete edits that fix every failing check, most important first. Each edit names the check it
fixes, where in the post (heading or the start of the sentence) and exactly what to change, with numbers
where they matter. Do not ask for anything the passing checks would lose: list in "keep" what must stay
as it is. Never ask for facts that the verified claims do not state.
Not your job (the worker fixes them itself, so leave them out): the length of the title, SEO title and
meta description, and the word count."""

FIT = """Write {n} alternative versions of this post's {label} in language "{lang}": each {rule}, keeping its
meaning and the focus keyword where it fits. Count the characters. Best first."""

EXPAND = """The post in the context has {words} words; it must reach at least {minimum}. Write about {ask} words of NEW
content to add to it, in language "{lang}", on something the post does not cover yet that visitors would want
to know. Prefer one or two <h3> subsections (an <h3> heading followed by paragraphs) that belong inside one of
the post's existing <h2> sections, and give that <h2> heading's exact text as "under_heading". Only if the
material is a new main part of the article, write one <h2> section (with <h3> subsections if it covers several
things) and leave "under_heading" empty. Use only facts from the verified claims, each fact followed by its
marker like {{{{c:c3}}}}; anything else is practical advice for visitors that states no facts (how to get there,
when to go, what to look for, what to try). Do not repeat the post. Use only <h2>, <h3>, <h4>, <p>, <ul>, <li>,
<strong>, <em>."""

# Limits of the structure check (ao-server/checks/aoq_ao_checks/textchecks.py) and what the worker aims for.
FIELDS = {
    "title": ("title", 1, 70, 60, "at most 60 characters"),
    "seo_title": ("SEO title", 1, 60, 55, "at most 55 characters"),
    "seo_description": ("meta description", 120, 160, 150, "between 130 and 155 characters, one or two full sentences"),
}
EXPAND_TRIES = 2


def _fits(field: str, value: str) -> bool:
    _, lo, hi, _, _ = FIELDS[field]
    return lo <= len(value) <= hi


def _cut(field: str, value: str) -> str:
    """Last resort when no option fits: cut at a sentence end (descriptions), a separator such as " | "
    (titles: drop the trailing parts), or a word, within the limit."""
    _, lo, hi, _, _ = FIELDS[field]
    value = value.strip()
    if len(value) <= hi:
        return value
    if field == "seo_description":
        ends = [m.end() for m in re.finditer(r"[.!?](\s|$)", value) if lo <= m.end() <= hi]
        if ends:
            return value[: ends[-1]].strip()
        cut = value[: hi - 1].rsplit(" ", 1)[0].rstrip(",;:-")
        return cut + "."
    parts = re.split(r"\s+[|–—-]\s+", value)
    while len(parts) > 1 and len(" | ".join(parts)) > hi:
        parts.pop()  # "Zimske aktivnosti | Prirodni park Blidinje | Turistički vodič Mostar" keeps its first parts
    joined = " | ".join(parts).rstrip(".")
    if len(parts) > 1 or len(joined) <= hi:
        return joined if len(joined) <= hi else joined[: hi + 1].rsplit(" ", 1)[0]
    return value[: hi + 1].rsplit(" ", 1)[0].rstrip(",;:-–— ")


async def fit_fields(ctx: RunContext, rec, doc: dict | None = None, lang: str | None = None) -> dict:
    """Title, SEO title and meta description within the structure check's limits. Models cannot count
    characters (test run 8065d509 kept a 71-character title through three revisions), so the writer
    proposes options and the worker counts; when none fits it cuts at a sentence end, a separator or a
    word. ``doc`` and ``lang`` are a translation and its language (default: the draft); for a translation
    an option must also be in that language's writing system."""
    assert ctx.draft is not None
    doc = ctx.draft if doc is None else doc
    lang = lang or ctx.inv.primary
    translation = doc is not ctx.draft
    done: dict = {}
    for field, (label, lo, hi, aim, rule) in FIELDS.items():
        value = str(doc.get(field, "")).strip()
        if _fits(field, value):
            continue
        res = await ctx.client.json_call(
            "draft",
            # A translation's task ends with its language, so the Translate view shows it under that language.
            f"translate:fit:{field}:{lang}" if translation else f"draft:fit:{field}",
            FIT.format(n=4, label=label, lang=lang, rule=rule),
            dumps({"current": value, "current_characters": len(value), "title": doc["title"], "focus_keyword": doc["focus_keyword"], "excerpt": doc["excerpt"]}),
            "field_options",
        )
        rec.add_calls(res.records)
        options = [o.strip() for o in res.data["options"]]
        if translation:
            options = [o for o in options if not markup.foreign_script(o + " " + value, lang)]
        chosen = next((o for o in options if _fits(field, o)), None)
        how = "option"
        if chosen is None:
            # Cut the shortest text that is still long enough; a description that is too short
            # everywhere stays as it is (the next QA round reports it again).
            long_enough = [o for o in [*options, value] if len(o) >= lo]
            if long_enough:
                chosen, how = _cut(field, min(long_enough, key=len)), "cut"
            else:
                chosen, how = value, "kept"
        done[field] = {"from": len(value), "to": len(chosen), "how": how}
        doc[field] = chosen
    return done


def _clean_expansion(ctx: RunContext, html: str) -> str:
    """New HTML as the gates accept it: no images, no H1, only verified claim markers and real links."""
    html = re.sub(r"<!--.*?-->", "", html, flags=re.S)
    html = re.sub(r"<(/?)h1\b", r"<\1h2", html, flags=re.I)
    html = re.sub(r"<img\b[^>]*>", "", html, flags=re.I)

    def marker(m: re.Match) -> str:
        ids = [i.strip() for i in m.group(1).split(",") if i.strip() in ctx.claims]
        return "{{c:" + ",".join(ids) + "}}" if ids else ""

    html = markup.CLAIM_MARKER.sub(marker, html)
    allowed = ctx.inv.published_ids(ctx.inv.primary)

    def link(m: re.Match) -> str:
        return m.group(0) if int(m.group(1)) in allowed else m.group(2)

    return re.sub(r'<a\s+data-aoq-post="(\d+)"[^>]*>(.*?)</a>', link, html, flags=re.S)


MAX_H2 = 6  # the headings check's limit (qa.heading_check): H2 only for the main parts of an article
HEADING_TAG = re.compile(r"<(/?)h([1-6])\b([^>]*)>", re.I)
H2_BLOCK = re.compile(r"<h2\b[^>]*>(.*?)</h2>", re.I | re.S)


def _heading_text(inner_html: str) -> str:
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", "", inner_html)).strip()


def _norm_heading(text: str) -> str:
    return _heading_text(text).casefold()


def normalize_heading_levels(html: str) -> tuple[str, int]:
    """Enforce H2 first and no skipped levels (max H4). Returns (html, number of tags changed)."""
    prev = 1  # so the first heading may be level 2
    changed = 0
    out: list[str] = []
    last = 0
    for m in HEADING_TAG.finditer(html):
        out.append(html[last:m.start()])
        closing, level_s, attrs = m.group(1), m.group(2), m.group(3)
        level = int(level_s)
        if closing:
            # Closing tags are rewritten with the open-tag remap via a stack below.
            out.append(m.group(0))
        else:
            want = min(4, max(2, min(level, prev + 1) if prev >= 2 else 2))
            if level == 1:
                want = 2
            if want != level:
                changed += 1
            out.append(f"<h{want}{attrs}>")
            prev = want
        last = m.end()
    out.append(html[last:])
    html2 = "".join(out)
    # Fix closing tags to match: walk again with a stack of open levels.
    stack: list[int] = []
    out2: list[str] = []
    last = 0
    for m in HEADING_TAG.finditer(html2):
        out2.append(html2[last:m.start()])
        closing, level_s, attrs = m.group(1), m.group(2), m.group(3)
        level = int(level_s)
        if closing:
            if stack:
                level = stack.pop()
            out2.append(f"</h{level}>")
        else:
            stack.append(level)
            out2.append(f"<h{level}{attrs}>")
        last = m.end()
    out2.append(html2[last:])
    return "".join(out2), changed


def _split_h2_sections(html: str) -> tuple[str, list[tuple[str, str]]]:
    """Prefix before the first H2, then list of (heading_inner_html, section_body_html)."""
    matches = list(H2_BLOCK.finditer(html))
    if not matches:
        return html, []
    prefix = html[: matches[0].start()]
    sections: list[tuple[str, str]] = []
    for i, m in enumerate(matches):
        end = matches[i + 1].start() if i + 1 < len(matches) else len(html)
        sections.append((m.group(1), html[m.end():end]))
    return prefix, sections


def dedupe_h2_sections(html: str, title: str = "") -> tuple[str, dict]:
    """Merge duplicate H2 titles; fold an H2 that repeats the article title into the previous section."""
    prefix, sections = _split_h2_sections(html)
    if not sections:
        return html, {"merged": 0, "folded_title": 0}
    title_n = _norm_heading(title) if title else ""
    merged = 0
    folded = 0
    kept: list[tuple[str, str]] = []
    seen: dict[str, int] = {}
    for inner, body in sections:
        key = _norm_heading(inner)
        if title_n and key == title_n:
            folded += 1
            if kept:
                h, b = kept[-1]
                kept[-1] = (h, b.rstrip() + "\n" + body.lstrip())
            else:
                prefix = prefix.rstrip() + "\n" + body.lstrip()
            continue
        if key in seen:
            merged += 1
            i = seen[key]
            h, b = kept[i]
            kept[i] = (h, b.rstrip() + "\n" + body.lstrip())
            continue
        seen[key] = len(kept)
        kept.append((inner, body))
    parts = [prefix]
    for inner, body in kept:
        parts.append(f"<h2>{inner}</h2>{body}")
    return "".join(parts), {"merged": merged, "folded_title": folded}


def apply_heading_fixes(html: str, title: str = "") -> tuple[str, dict]:
    """Normalize levels then dedupe H2s. Returns (html, detail)."""
    html, level_fixes = normalize_heading_levels(html)
    html, dedupe = dedupe_h2_sections(html, title=title)
    return html, {"levels_fixed": level_fixes, **dedupe}


def _demote(html: str) -> str:
    """Every heading one level down (h3 -> h4 first, then h2 -> h3), so new material nests under an
    existing section instead of adding main parts."""
    html = re.sub(r"<(/?)h3\b", r"<\1h4", html, flags=re.I)
    return re.sub(r"<(/?)h2\b", r"<\1h3", html, flags=re.I)


def _existing_h2_titles(content: str) -> set[str]:
    return {_norm_heading(m.group(1)) for m in H2_BLOCK.finditer(content)}


def insert_section(content: str, html: str, under: str = "") -> str:
    """New material goes at the end of the H2 section it belongs to (under_heading), else before the
    last H2 (usually the closing section), else at the end. When its H2 headings would take the post
    past MAX_H2, they become H3 (Medjugorje run 21ac26e8: each expansion round added H2 sections until
    the post had nine and failed the headings check in every round). Existing H2 titles in the new
    block are demoted to H3 so expand cannot spawn a second Tips section."""
    existing = _existing_h2_titles(content)
    if existing:

        def demote_dup(m: re.Match) -> str:
            if _norm_heading(m.group(1)) in existing:
                return f"<h3>{m.group(1)}</h3>"
            return m.group(0)

        html = H2_BLOCK.sub(demote_dup, html)
    have = len(re.findall(r"<h2\b", content, re.I))
    if have + len(re.findall(r"<h2\b", html, re.I)) > MAX_H2:
        html = _demote(html)
    heads = [m.start() for m in re.finditer(r"<h2\b", content, re.I)]
    want = re.sub(r"\s+", " ", under).strip().lower()
    if want:
        for i, m in enumerate(H2_BLOCK.finditer(content)):
            if _norm_heading(m.group(1)) == want or _heading_text(m.group(1)).lower() == want:
                end = heads[i + 1] if i + 1 < len(heads) else len(content)
                return content[:end].rstrip() + "\n" + html.strip() + "\n" + content[end:]
    if len(heads) >= 2:
        return content[: heads[-1]] + html.strip() + "\n" + content[heads[-1]:]
    return content.rstrip() + "\n" + html.strip()


async def expand_to_minimum(ctx: RunContext, rec) -> dict | None:
    """Reach the minimum word count with new sections the worker inserts and counts at once, instead
    of rewriting the whole post and finding out in the next QA round (test run 8065d509 crept from
    46% to 94% over three full revisions)."""
    assert ctx.draft is not None
    target, minimum = length_target(ctx)
    words = word_count(ctx.draft["content_html"])
    if words >= minimum:
        return None
    start = words
    for attempt in range(1, EXPAND_TRIES + 1):
        ask = min(max(minimum - words + 60, 120), max(target - words, 120))
        res = await ctx.client.json_call(
            "draft",
            f"draft:expand:{attempt}",
            EXPAND.format(words=words, minimum=minimum, ask=ask, lang=ctx.inv.primary),
            draft_context(ctx, {"title": ctx.draft["title"], "headings": re.findall(r"<h[23][^>]*>(.*?)</h[23]>", ctx.draft["content_html"], re.I | re.S), "post": ctx.draft["content_html"]}),
            "expansion",
        )
        rec.add_calls(res.records)
        html = _clean_expansion(ctx, res.data["html"])
        if word_count(html) < 20:
            continue
        ctx.draft["content_html"] = insert_section(ctx.draft["content_html"], html, res.data.get("under_heading") or "")
        words = word_count(ctx.draft["content_html"])
        if words >= minimum:
            break
    return {"from": start, "to": words, "minimum": minimum}


async def make_plan(ctx: RunContext, results: list, rec) -> dict:
    """The editor's numbered plan from the QA results (failing checks with their issues, passing
    checks to keep), so the writer applies concrete edits instead of a list of raw findings."""
    failing = [{"check": r.check, "score": round(r.score, 2), "pass_mark": r.threshold, "issues": r.issues} for r in results if not r.passed]
    passing = [{"check": r.check, "score": round(r.score, 2)} for r in results if r.passed]
    res = await ctx.client.json_call(
        "outline",
        "draft:plan",
        PLAN,
        dumps({"draft": ctx.draft, "failing_checks": failing, "passing_checks": passing, "verified_claims": list(ctx.claims.values())}),
        "revision_plan",
    )
    rec.add_calls(res.records)
    return res.data


# Checks the worker fixes without rewriting the post.
MECHANICAL = {"length"}
FIELD_PROBLEM = re.compile(r"^(Title|SEO title|Meta description) is \d+ characters")
# Heading order/skips are fixed by normalize_heading_levels + dedupe_h2_sections, not the model.
HEADING_STRUCTURE_PROBLEM = re.compile(
    r"Headings skip a level|do not start at H2",
    re.I,
)


def _is_mechanical_structure_issue(issue: dict) -> bool:
    problem = issue.get("problem", "") if isinstance(issue, dict) else str(issue)
    return bool(FIELD_PROBLEM.match(problem) or HEADING_STRUCTURE_PROBLEM.search(problem))


def _needs_rewrite(results: list) -> bool:
    for r in results:
        if r.passed or r.check in MECHANICAL:
            continue
        if r.check == "structure" and all(_is_mechanical_structure_issue(i) for i in r.issues):
            continue  # field lengths + heading order: fit_fields / heading cleaners handle them
        return True
    return False


def apply_draft_heading_fixes(ctx: RunContext) -> dict:
    """Normalize heading levels and dedupe H2 sections on the current draft. Mutates ctx.draft."""
    assert ctx.draft is not None
    html, detail = apply_heading_fixes(ctx.draft["content_html"], title=ctx.draft.get("title") or "")
    ctx.draft["content_html"] = html
    return detail


async def revise(ctx: RunContext, results: list, rec) -> dict:
    """One revision round: an editor's plan and a rewrite when a check needs one, then the worker's own
    fixes (word count, field lengths, heading order/dedupe) checked by counting before the next QA round."""
    assert ctx.draft is not None
    target, minimum = length_target(ctx)
    detail: dict = {}
    if _needs_rewrite(results):
        plan = await make_plan(ctx, results, rec)
        detail["plan"] = plan
        prompt = REVISE.format(rules=rules(ctx), target=target, minimum=minimum, words=word_count(ctx.draft["content_html"]))
        res = await ctx.client.json_call("draft", "draft:revise", prompt, draft_context(ctx, {"draft": ctx.draft, "revision_plan": plan}), "draft")
        rec.add_calls(res.records)
        ctx.draft = res.data
        apply_draft_heading_fixes(ctx)
        check_draft(ctx, "qa", ctx.draft)
    expanded = await expand_to_minimum(ctx, rec)
    if expanded:
        detail["expanded"] = expanded
    fitted = await fit_fields(ctx, rec)
    if fitted:
        detail["fitted"] = fitted
    headings = apply_draft_heading_fixes(ctx)
    if any(headings.get(k) for k in ("levels_fixed", "merged", "folded_title")):
        detail["headings"] = headings
    check_draft(ctx, "qa", ctx.draft)
    rec.detail = {**(rec.detail or {}), "revision": detail}
    return ctx.draft


def rules(ctx: RunContext) -> str:
    keys = ctx.inline_keys()
    placeholders = ", ".join(f"<!-- aoq:image {k} -->" for k in keys) or "none"
    return RULES.format(placeholders=placeholders)


def check_content(ctx: RunContext, stage: str, content: str, lang: str, *, allowed_links: set[int]) -> None:
    """Gates on any post body: claims verified, links valid, image placeholders exact."""
    cited = markup.claim_ids(content)
    gate(markup.claim_marker_count(content) > 0, stage, f"[{lang}] The post cites no verified claim.")
    unknown = cited - set(ctx.claims)
    gate(not unknown, stage, f"[{lang}] The post cites claims that were not verified: {sorted(unknown)}.")
    bad_links = markup.link_ids(content) - allowed_links
    gate(not bad_links, stage, f"[{lang}] Internal links point to posts that do not exist in {lang}: {sorted(bad_links)}.")
    found = sorted(markup.image_keys(content))
    expected = sorted(ctx.inline_keys())
    gate(found == expected, stage, f"[{lang}] Image placeholders {found} do not match the expected {expected}.")
    gate("<h1" not in content.lower(), stage, f"[{lang}] The post must not contain an H1.")


def check_draft(ctx: RunContext, stage: str, draft: dict) -> None:
    primary = ctx.inv.primary
    # Image placeholders are structure the worker can fix itself: a missing one is not worth a run.
    draft["content_html"] = markup.place_images(draft["content_html"], ctx.inline_keys())
    check_content(ctx, stage, draft["content_html"], primary, allowed_links=ctx.inv.published_ids(primary))


def draft_context(ctx: RunContext, extra: dict | None = None) -> str:
    return dumps(
        {
            "outline": ctx.outline,
            "verified_claims": list(ctx.claims.values()),
            "style_profile": ctx.style_profiles.get(ctx.inv.primary),
            "site_profile": ctx.site_profile,
            **(extra or {}),
        }
    )


async def run(ctx: RunContext) -> dict:
    async with ctx.reporter.stage("draft") as rec:
        target, minimum = length_target(ctx)
        prompt = PROMPT.format(lang=ctx.inv.primary, rules=rules(ctx), target=target, minimum=minimum)
        res = await ctx.client.json_call("draft", "draft", prompt, draft_context(ctx), "draft")
        rec.add_calls(res.records)
        ctx.draft = res.data
        headings = apply_draft_heading_fixes(ctx)
        check_draft(ctx, "draft", ctx.draft)
        detail = {
            "title": ctx.draft["title"],
            "excerpt": ctx.draft["excerpt"][:400],
            "words": word_count(ctx.draft["content_html"]),
            "target_words": target,
            "claims_cited": sorted(markup.claim_ids(ctx.draft["content_html"])),
        }
        if any(headings.get(k) for k in ("levels_fixed", "merged", "folded_title")):
            detail["headings"] = headings
        rec.detail = detail
        return ctx.draft


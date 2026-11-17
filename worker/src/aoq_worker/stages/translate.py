"""Stages 12 and 13: translate into every other site language, then per-language QA
(grammar, meaning fidelity by back translation, style, structure and originality)
and per-language terms. Same loop and gate rules as stage 11. A failure in any
language fails the run."""

from __future__ import annotations

import logging
import re

from .. import markup
from ..context import RunContext
from ..errors import GateFailure
from . import qa, taxonomy
from .common import clip, dumps, gate

log = logging.getLogger(__name__)

PROMPT = """Translate the post in the context from "{src}" into "{lang}" for this site's readers in that language.
Keep the meaning exactly; do not add or drop facts. Follow the style profile for "{lang}" when one is given and use
the site's own term names from the glossary. Keep every claim marker like {{{{c:c1}}}} after the same sentence, and keep
every image placeholder <!-- aoq:image ... --> unchanged. For internal links, replace each data-aoq-post ID with the
ID of the post in the "{lang}" post list that covers the same subject, or remove the link and keep its text when no
such post exists. Translate the alt text and captions of every image. Write a new slug in "{lang}" (lowercase ASCII
words joined by hyphens). Set "language" to "{lang}"."""

REVISE = """Revise the "{lang}" translation in the context. Fix every issue the QA reviews list; keep everything else,
including claim markers, image placeholders and link IDs."""

BACK = """Translate this "{lang}" post back into "{src}" as literally as possible. Return the title and the body as
plain text."""

FIDELITY = """Compare the original "{src}" post with the back translation of its "{lang}" version, both in the context.
List every change in meaning: added, dropped or altered facts, numbers, names or claims. Score 1.0 when the meaning
is identical.
A back translation never has the original's exact words. Wording, synonyms ("iconic" and "famous", "taverns" and
"restaurants"), emphasis, tone, sentence order, punctuation, possessive forms and slightly shorter or longer
descriptions are not changes in meaning: do not list them and do not lower the score for them. List only what a
reader would learn differently: a fact, number, date, name, place or claim that was added, dropped or changed."""

# For a language in another writing system the back translation has to guess how each name was spelled in
# Latin letters: Arabic run 4e69d957 failed meaning twice at 0.70 for "Blidinje" -> "Blidinge", "Risovac" ->
# "Risovak" and "stećci" -> "Sticchi", the same names spelled differently.
NAMES_IN_OTHER_SCRIPT = """
The "{lang}" version is written in another alphabet, so names of places, people, dishes and things come back
in the back translation with spellings guessed from that alphabet. A name spelled differently is not a change in
meaning (for example "Blidinje" and "Blidinge", "Risovac" and "Risovak"); do not list it and do not lower the
score for it. List a name only when it clearly refers to a different place, person or thing, or when a number
that goes with it changed."""


def check_translation(ctx: RunContext, lang: str, tr: dict) -> None:
    stage = "translate"
    assert ctx.draft is not None
    tr["content_html"] = markup.place_images(tr["content_html"], markup.image_keys(ctx.draft["content_html"]))
    gate(tr["language"] == lang, stage, f"Translation came back as {tr['language']}, expected {lang}.")
    src_claims = markup.claim_ids(ctx.draft["content_html"])
    tr["content_html"], restored = markup.place_claims(ctx.draft["content_html"], tr["content_html"])
    if restored:
        log.info("[%s] claim markers put back after translation: %s", lang, ", ".join(restored))
    gate(markup.claim_ids(tr["content_html"]) == src_claims, stage, f"[{lang}] Claim markers changed in translation.")
    draft_links = ctx.inv.published_ids(lang)
    qa_stage_check = markup.link_ids(tr["content_html"]) - draft_links
    gate(not qa_stage_check, stage, f"[{lang}] Internal links point to posts that do not exist in {lang}: {sorted(qa_stage_check)}.")
    gate(
        sorted(markup.image_keys(tr["content_html"])) == sorted(markup.image_keys(ctx.draft["content_html"])),
        stage,
        f"[{lang}] Image placeholders changed in translation.",
    )
    # Text for an image the post no longer has (fewer in-body images were found) is dropped.
    post_keys = {img["key"] for img in ctx.images}
    tr["images"] = [i for i in tr["images"] if i["key"] in post_keys]
    keys = sorted(i["key"] for i in tr["images"])
    gate(keys == sorted(img["key"] for img in ctx.images), stage, f"[{lang}] Image text does not cover exactly the post images.")


def _context(ctx: RunContext, lang: str, extra: dict | None = None) -> str:
    assert ctx.draft is not None
    src = ctx.inv.primary
    posts = sorted(ctx.inv.posts_in(lang, published_only=True), key=lambda p: p["date"], reverse=True)[:300]
    tags = sorted(ctx.inv.terms_in(lang, "post_tag"), key=lambda t: -t["count"])[:300]
    return dumps(
        {
            "post": ctx.draft,
            "images": [{"key": k, **v} for k, v in ctx.image_texts[src].items()],
            "style_profile": ctx.style_profiles.get(lang),
            "glossary": {
                "note": "The network stores no term translations; these are the site's own term names in the target language.",
                "categories": [t["name"] for t in ctx.inv.terms_in(lang, "category")],
                "tags": [t["name"] for t in tags],
            },
            "posts_in_target_language": [{"id": p["id"], "title": clip(p["title"], 160)} for p in posts],
            **(extra or {}),
        }
    )


SCRIPT_RETRY = """

Your previous answer was not entirely in "{lang}": {problem}. Write every part (title, excerpt, SEO fields, body,
image texts) only in "{lang}"; do not switch to another language or writing system."""

SCRIPT_TRIES = 3


def wrong_script(lang: str, tr: dict) -> str:
    """Why the translation is not all in the language's writing system, or ""."""
    text = " ".join(
        [str(tr.get(k, "")) for k in ("title", "excerpt", "seo_title", "seo_description", "content_html")]
        + [f"{i.get('alt', '')} {i.get('caption', '')}" for i in tr.get("images", [])]
    )
    return markup.foreign_script(text, lang)


async def _translation_call(ctx: RunContext, lang: str, task: str, prompt: str, context: str, rec) -> dict:
    """The translation call, made again when the answer slips into another language or script."""
    problem = ""
    for _ in range(SCRIPT_TRIES):
        res = await ctx.client.json_call("translate", task, prompt + (SCRIPT_RETRY.format(lang=lang, problem=problem) if problem else ""), context, "translation")
        rec.add_calls(res.records)
        problem = wrong_script(lang, res.data)
        if not problem:
            check_translation(ctx, lang, res.data)
            return res.data
        log.warning("[%s] %s: %s; asking again", lang, task, problem)
    gate(False, "translate", f"[{lang}] The translation is not all in {lang} after {SCRIPT_TRIES} tries: {problem}.")
    raise AssertionError("unreachable")


class _Quiet:
    """The language-site Translator's progress hooks, for a run: AO calls are awaited, notes logged."""

    async def wait_ao(self, what: str, call):  # noqa: ANN001 - an awaitable
        return await call

    def say(self, msg: str) -> None:
        log.info("%s", msg)


CLAIM_TAG = re.compile(r'<span data-aoq-c="([^"]*)"></span>')
POST_LINK_FULL = re.compile(r'<a\s+data-aoq-post="([0-9]+)"([^>]*)>(.*?)</a>', re.S)


def _map_links(ctx: RunContext, lang: str, html: str) -> tuple[str, list[int]]:
    """Point each internal link at the same post in ``lang`` (same translation group); a link to a
    post with no published translation keeps its text only. Returns the body and the unlinked IDs."""
    src = ctx.inv.primary
    group = {p["id"]: p.get("translation_group") for p in ctx.inv.posts_in(src)}
    target = {p["translation_group"]: p["id"] for p in ctx.inv.posts_in(lang, published_only=True) if p.get("translation_group")}
    published = ctx.inv.published_ids(lang)
    dropped: list[int] = []

    def fix(m: re.Match) -> str:
        pid = int(m.group(1))
        if pid in published:
            return m.group(0)
        to = target.get(group.get(pid))
        if to:
            return f'<a data-aoq-post="{to}"{m.group(2)}>{m.group(3)}</a>'
        dropped.append(pid)
        return m.group(3)

    return POST_LINK_FULL.sub(fix, html), dropped


def _language_name(ctx: RunContext, lang: str) -> str:
    return next((str(x.get("name") or lang) for x in ctx.inv.languages if x["code"] == lang), lang)


async def gemma_translate(ctx: RunContext, lang: str, rec) -> dict | None:
    """The post through TranslateGemma (the aoq_translator agent), field by field, the HTML with its
    tags, image placeholders and claim markers held as numbered placeholders. The worker writes the
    slug and the internal links. None when TranslateGemma failed; the writer agent translates then."""
    from ..language_site import AOOutage, GemmaFailed, Translator  # the language sites' translator, shared

    assert ctx.draft is not None
    src = ctx.inv.primary
    tr = Translator(
        ctx.client, _language_name(ctx, lang), _Quiet(), code=lang, waits=(), task=f"translate:{lang}",  # type: ignore[arg-type]
        source=(_language_name(ctx, src), src), fallback=False,
    )
    if not tr.gemma:
        return None
    images = ctx.image_texts[src]
    items = [{"key": k, "text": str(ctx.draft[k])} for k in ("title", "excerpt", "seo_title", "seo_description", "focus_keyword")]
    for key, v in images.items():
        items.append({"key": f"alt:{key}", "text": v["alt"]})
        if v.get("caption"):
            items.append({"key": f"caption:{key}", "text": v["caption"]})
    body = markup.CLAIM_MARKER.sub(lambda m: f'<span data-aoq-c="{m.group(1)}"></span>', ctx.draft["content_html"])
    try:
        texts = await tr.strings(items, f"{lang}: title, excerpt, SEO and image texts")
        html = await tr.html("content", body, f"{lang}: body") if tr.gemma else ""
    except AOOutage as exc:
        raise GateFailure("translate", f"[{lang}] AO stopped answering: {exc}") from exc
    except GemmaFailed as exc:
        log.warning("[%s] %s; the writer agent translates instead", lang, exc)
        return None
    finally:
        rec.add_calls(tr.records)
    missing = [i["key"] for i in items if not texts.get(i["key"], "").strip()]
    if not tr.gemma or missing or not html:
        log.warning("[%s] TranslateGemma did not translate everything (%s); the writer agent translates instead", lang, ", ".join(missing) or "body")
        return None
    html = CLAIM_TAG.sub(lambda m: "{{c:" + m.group(1) + "}}", html)
    html, unlinked = _map_links(ctx, lang, html)
    from .. import contracts

    out = {
        "language": lang,
        "title": texts["title"],
        "slug": contracts.ascii_slug(texts["title"])[:200].strip("-"),
        "excerpt": texts["excerpt"],
        "seo_title": texts["seo_title"],
        "seo_description": texts["seo_description"],
        "focus_keyword": texts["focus_keyword"],
        "content_html": html,
        "images": [{"key": k, "alt": texts[f"alt:{k}"], "caption": texts.get(f"caption:{k}", "")} for k in images],
    }
    out, _dropped = contracts.prune(out, "stages/translation", stage="translate")
    # The admin's Translate view shows the text from here: TranslateGemma's answers are not JSON.
    rec.detail = {"translator": ctx.client.agents.get("translator"), "unlinked_posts": unlinked,
                  "translation": {"title": out["title"], "excerpt": out["excerpt"], "content_html": out["content_html"]}}
    return out


async def translate(ctx: RunContext, lang: str, rec) -> dict:
    src = ctx.inv.primary
    tr = await gemma_translate(ctx, lang, rec)
    if tr is not None:
        problem = wrong_script(lang, tr)
        if not problem:
            check_translation(ctx, lang, tr)
            return tr
        log.warning("[%s] TranslateGemma's translation: %s; the writer agent translates instead", lang, problem)
    return await _translation_call(ctx, lang, f"translate:{lang}", PROMPT.format(src=src, lang=lang), _context(ctx, lang), rec)


async def revise(ctx: RunContext, lang: str, tr: dict, issues: list[dict], rec) -> dict:
    return await _translation_call(
        ctx, lang, f"translate:{lang}:revise", REVISE.format(lang=lang), _context(ctx, lang, {"translation": tr, "qa_issues": issues}), rec
    )


BLOCK_BREAK = re.compile(r"</(?:p|h[1-6]|li|blockquote|figcaption|td)\s*>|<br\s*/?>", re.I)


def _plain(html_text: str) -> str:
    """Body HTML as plain text: one paragraph per block."""
    import html as _html

    text = BLOCK_BREAK.sub("\n\n", markup.strip_claims(html_text))
    text = _html.unescape(re.sub(r"<[^>]+>", "", text))
    return re.sub(r"\n\s*\n+", "\n\n", text).strip()


async def gemma_back_translate(ctx: RunContext, lang: str, tr: dict, rec) -> dict | None:
    """Back translation by TranslateGemma: it is loaded already (it just translated), so the Jetson
    does not swap models, and it renders the meaning more faithfully than the editor's literal
    rendering. None when it is not available or failed; the editor back-translates then."""
    from ..language_site import AOOutage, GemmaFailed, Translator

    src = ctx.inv.primary
    t = Translator(
        ctx.client, _language_name(ctx, src), _Quiet(), code=src, waits=(), task=f"back_translate:{lang}",  # type: ignore[arg-type]
        source=(_language_name(ctx, lang), lang), fallback=False,
    )
    if not t.gemma:
        return None
    try:
        title = (await t.strings([{"key": "title", "text": tr["title"]}], f"{lang}: back-translated title")).get("title", "")
        body = await t.html("content", markup.strip_claims(tr["content_html"]), f"{lang}: back-translated body")
    except (AOOutage, GemmaFailed) as exc:
        log.warning("[%s] back translation by TranslateGemma failed (%s); the editor back-translates", lang, exc)
        return None
    finally:
        rec.add_calls(t.records)
    text = _plain(body)
    if not title.strip() or not text:
        return None
    return {"title": title.strip(), "content_text": text}


async def fidelity_pass(ctx: RunContext, rec, lang: str, tr: dict) -> qa.PassResult:
    assert ctx.draft is not None
    src = ctx.inv.primary
    back_data = await gemma_back_translate(ctx, lang, tr, rec)
    if back_data is None:
        back = await ctx.client.json_call(
            "back_translate", f"back_translate:{lang}", BACK.format(lang=lang, src=src), dumps({"post": tr}), "back_translation"
        )
        rec.add_calls(back.records)
        back_data = back.data
    cmp = await ctx.client.json_call(
        "translation_qa",
        f"fidelity:{lang}",
        FIDELITY.format(lang=lang, src=src)
        + (NAMES_IN_OTHER_SCRIPT.format(lang=lang) if markup.SCRIPT_OF.get(lang, "Latin") != markup.SCRIPT_OF.get(src, "Latin") else ""),
        dumps({"original": ctx.draft, "back_translation": back_data}),
        "qa",
    )
    rec.add_calls(cmp.records)
    th = ctx.config["thresholds"]["translation_fidelity"]
    score, issues = cmp.data["score"], qa._issues("fidelity", cmp.data["issues"])
    swapped = swapped_places(ctx.draft, back_data)
    if swapped:
        # Counted by the worker: a model forgives a town that came back different as a spelling.
        score = min(score, th - 0.1)
        issues.append({
            "check": "fidelity", "severity": "critical", "location": "",
            "problem": f"The translation names {', '.join(swapped)}, which the original does not mention: a place was changed.",
            "fix": "Translate every place name as the original has it.",
        })
    return qa.PassResult("fidelity", score, th, score >= th, issues)


# Towns a translation of these sites' posts can mix up, as the back translation writes them (Arabic
# run 205ad21e: "Touring Mostar" came back as "Tour of Sarajevo" and passed the meaning check).
KNOWN_PLACES = (
    "Sarajevo", "Mostar", "Medjugorje", "Dubrovnik", "Split", "Zagreb", "Belgrade", "Banja Luka", "Tuzla", "Zenica",
    "Trebinje", "Neum", "Konjic", "Jajce", "Travnik", "Blagaj", "Pocitelj", "Stolac", "Capljina", "Ljubuski",
    "Siroki Brijeg", "Bihac", "Visoko", "Srebrenica", "Kotor", "Podgorica", "Istanbul", "Vienna",
)


def _place_words(text: str) -> str:
    from . import images as _images

    return _images._plain(text)


def swapped_places(original: dict, back: dict) -> list[str]:
    """Known towns the back translation names that the original post never mentions."""
    src = _place_words(" ".join(str(original.get(k, "")) for k in ("title", "excerpt", "content_html")))
    out = _place_words(" ".join(str(back.get(k, "")) for k in ("title", "content_text")))
    return [p for p in KNOWN_PLACES if f" {_place_words(p).strip()} " in out and f" {_place_words(p).strip()} " not in src]


async def language_passes(ctx: RunContext, rec, lang: str, tr: dict) -> list[qa.PassResult]:
    th = ctx.config["thresholds"]
    # Meaning first: its back translation runs on TranslateGemma, still loaded from translating;
    # then every check on the editor model, so the Jetson swaps models once per attempt.
    fidelity = await fidelity_pass(ctx, rec, lang, tr)
    results = [
        await qa.model_pass(ctx, rec, "grammar", "translation_qa", lang, tr, th["translation_grammar"], {}),
        fidelity,
    ]
    style = ctx.style_profiles.get(lang)
    if style is not None:
        res = await qa.model_pass(ctx, rec, "style", "translation_qa", lang, tr, th["translation_style"], {"style_profile": style})
        results.append(res)
    results += await qa.text_checks(ctx, rec, lang, tr)
    return results


async def fit_lengths(ctx: RunContext, lang: str, tr: dict, rec) -> None:
    """Title, SEO title and meta description of a translation within the structure check's limits, fixed
    on their own (Bosnian run c8ffeaef: SEO title 68 characters, description 162): the rest of the
    translation is kept, and no new translation or check round is needed for a length."""
    from . import draft

    done = await draft.fit_fields(ctx, rec, doc=tr, lang=lang)
    if not done:
        return
    log.info("[%s] lengths fitted: %s", lang, done)
    if isinstance(rec.detail, dict):
        rec.detail["fitted"] = done
        if isinstance(rec.detail.get("translation"), dict):
            rec.detail["translation"]["title"] = tr["title"]


async def run_language(ctx: RunContext, lang: str) -> dict:
    loops = ctx.config["max_revision_loops"]
    async with ctx.reporter.stage("translate", language=lang) as rec:
        tr = await translate(ctx, lang, rec)
        await fit_lengths(ctx, lang, tr, rec)
        by_gemma = bool(rec.detail.get("translator")) if isinstance(rec.detail, dict) else False
    for attempt in range(1, loops + 2):
        async with ctx.reporter.stage("translation_qa", language=lang, attempt=attempt) as rec:
            rec.revision_count = attempt - 1
            results = await language_passes(ctx, rec, lang, tr)
            for r in results:
                rec.score(r.check, r.score, r.threshold, r.passed)
            failing = [r for r in results if not r.passed]
            if not failing:
                ctx.scores += [{"language": lang, "check": r.check, "score": round(r.score, 4)} for r in results]
                break
            if attempt > loops:
                raise GateFailure("translation_qa", f"[{lang}] QA below threshold after {loops} revision loops: {qa.summarize(failing)}.")
            if by_gemma:
                # TranslateGemma cannot follow review notes, and the writer's revisions of its text made
                # it worse (Bosnian, 2026-11-16): it translates again instead, and only that model and
                # the editor stay loaded on the GPU.
                again = await gemma_translate(ctx, lang, rec)
                if again is not None and not wrong_script(lang, again) and again["content_html"] != tr["content_html"]:
                    check_translation(ctx, lang, again)
                    rec.soft_failure = f"Translating again: {qa.summarize(failing)}."
                    tr = again
                    await fit_lengths(ctx, lang, tr, rec)
                    continue
                if again is not None and again["content_html"] == tr["content_html"]:
                    raise GateFailure("translation_qa", f"[{lang}] QA below threshold and TranslateGemma gave the same translation again: {qa.summarize(failing)}.")
                by_gemma = False  # TranslateGemma failed: the writer revises from here on
            rec.soft_failure = f"Revising: {qa.summarize(failing)}."
            tr = await revise(ctx, lang, tr, [i for r in failing for i in r.issues], rec)
            await fit_lengths(ctx, lang, tr, rec)
    ctx.translations[lang] = tr
    ctx.image_texts[lang] = {i["key"]: {"alt": i["alt"], "caption": i["caption"]} for i in tr["images"]}
    return tr


# Waits before trying the categories again after an AO server error (Arabic, run 2491e12b: Ollama
# answered 500 when the GPU had no room to load the analyst next to the translator and the editor;
# it unloads idle models after a short while).
TERMS_RETRY_WAITS: tuple[float, ...] = (60, 180)


async def choose_terms(ctx: RunContext, lang: str) -> None:
    """Categories and tags for a checked translation, tried again after an AO server error."""
    import asyncio

    from ..errors import AOError

    tr = ctx.translations[lang]
    hint = taxonomy.names(ctx, ctx.inv.primary, ctx.taxonomy[ctx.inv.primary])
    async with ctx.reporter.stage("taxonomy", language=lang) as rec:
        for n in range(len(TERMS_RETRY_WAITS) + 1):
            try:
                chosen = await taxonomy.choose(ctx, lang, tr["title"], tr["excerpt"], rec, hint=hint)
                break
            except AOError as exc:
                if n == len(TERMS_RETRY_WAITS):
                    raise
                log.warning("[%s] categories: %s; trying again in %g s", lang, exc, TERMS_RETRY_WAITS[n])
                await asyncio.sleep(TERMS_RETRY_WAITS[n])
        rec.detail = taxonomy.names(ctx, lang, chosen)


async def run(ctx: RunContext) -> dict[str, dict]:
    targets = ctx.target_languages()
    if not targets:
        # Recorded so the step shows as done and the Generation tab's estimate has a time for it.
        async with ctx.reporter.stage("translate") as rec:
            rec.detail = {"languages": [], "note": "No other active languages: nothing to translate."}
        return ctx.translations
    source = source_hash(ctx)
    for lang in targets:
        done = ctx.translated.get(lang) == source and lang in ctx.translations and lang in ctx.taxonomy
        checked = ctx.checked.get(lang) == source and lang in ctx.translations
        if done or checked:
            # From the run this one resumes, from the same draft and images: kept. A language whose
            # categories were not chosen yet gets only its categories.
            async with ctx.reporter.stage("translate", language=lang) as rec:
                tr = ctx.translations[lang]
                rec.detail = {
                    "reused": True,
                    "note": "Translated and checked in the run this one resumes; kept as it was."
                    + ("" if done else " Only its categories and tags are chosen again."),
                    "scores": [s for s in ctx.scores if s.get("language") == lang],
                    "translation": {"title": tr["title"], "excerpt": tr["excerpt"], "content_html": tr["content_html"]},
                }
            if done:
                continue
        else:
            ctx.scores = [s for s in ctx.scores if s.get("language") != lang]  # a language done again scores again
            await run_language(ctx, lang)
            # Saved once its checks pass: a failure in the categories step keeps the translation.
            ctx.checked[lang] = source
            if ctx.save_progress is not None:
                await ctx.save_progress()
        await choose_terms(ctx, lang)
        ctx.translated[lang] = source
        if ctx.save_progress is not None:
            await ctx.save_progress()
    return ctx.translations


def source_hash(ctx: RunContext) -> str:
    """What a translation was made from: the draft and the post's image texts."""
    import hashlib
    import json as _json

    src = ctx.inv.primary
    raw = _json.dumps({"draft": ctx.draft, "images": ctx.image_texts.get(src)}, sort_keys=True, ensure_ascii=False)
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()[:16]

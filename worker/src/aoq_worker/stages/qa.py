"""Stage 11: QA loop on the primary draft, and the passes reused for translations.

Mechanism per pass (recorded in docs/architecture.md):
- grammar, facts, style, seo: separate QA agents on a different provider family than
  the drafter (enforced by the plugin on save), each returning a score and fixes.
- structure and originality: the deterministic aoq_text_checks agent on the server
  (heading order, lengths, word n-gram overlap with every source and existing post).
Failing passes go back to the drafter; the loop stops at the configured limit.
"""

from __future__ import annotations

from dataclasses import dataclass, field

from .. import markup
from ..context import RunContext
from ..errors import GateFailure
from . import draft as draft_stage
from .common import dumps

NGRAM = 5

PROMPTS = {
    "grammar": """Review the post in the context for grammar, spelling and punctuation in language "{lang}".
Score 1.0 for clean text. List each problem with its location and the exact fix.""",
    "facts": """Check factual grounding. Every sentence that states a fact must be supported by the verified claims
in the context, which carry markers like {{{{c:c1}}}}. List every sentence that states something the claims do not
support, including facts with no marker. Score 1.0 only when nothing goes beyond the claims.""",
    "style": """Compare the post with the site's style profile in the context: voice, person, formality, sentence
length, structure and phrases to avoid. Score how well it matches and list concrete fixes.""",
    "seo": """Review title, SEO title, meta description, focus keyword and heading structure for search and for
readers. Score and list concrete fixes. Lengths and heading order are checked separately by code.""",
}


@dataclass
class PassResult:
    check: str
    score: float
    threshold: float
    passed: bool
    issues: list[dict] = field(default_factory=list)


def _issues(check: str, items: list[dict]) -> list[dict]:
    return [{**i, "check": check} for i in items]


SCORE_RULE = "\nGive the score as a number from 0.0 to 1.0 (for example 0.85), not out of 10 or 100."


async def model_pass(ctx: RunContext, rec, check: str, role: str, lang: str, doc: dict, threshold: float, extra: dict) -> PassResult:
    schema = "qa_facts" if check == "facts" else "qa"
    context = dumps({"post": doc, **extra})
    res = await ctx.client.json_call(role, f"qa:{check}:{lang}", PROMPTS[check].format(lang=lang) + SCORE_RULE, context, schema)
    rec.add_calls(res.records)
    data = res.data
    issues = _issues(check, data["issues"])
    passed = data["score"] >= threshold
    if check == "facts":
        unsupported = data["unsupported_sentences"]
        # Listed for the revision and the step details, but the score against the pass mark in
        # Settings decides whether the check passes (an unsupported sentence no longer blocks alone).
        issues += [
            {"check": "facts", "severity": "major", "location": u["sentence"][:200], "problem": u["why"], "fix": "Remove the sentence or support it with a verified claim."}
            for u in unsupported
        ]
    return PassResult(check, data["score"], threshold, passed, issues)


async def text_checks(ctx: RunContext, rec, lang: str, doc: dict) -> list[PassResult]:
    """Deterministic structure and originality checks on the AO server."""
    refs = [{"id": p["key"], "text": p["content"]} for p in ctx.inv.posts_in(lang)]
    payload = {
        "language": lang,
        "title": doc["title"],
        "seo_title": doc["seo_title"],
        "seo_description": doc["seo_description"],
        "content_html": markup.strip_claims(doc["content_html"]),
        "n": NGRAM,
        "source_urls": [s["url"] for s in ctx.sources],
        "references": refs,
    }
    res = await ctx.client.deterministic("text_checks", f"text_checks:{lang}", payload, "text_checks")
    rec.add_calls(res.records)
    st = res.data["structure"]
    orig = res.data["originality"]
    limit = ctx.config["originality_max_overlap"]
    structure = PassResult(
        "structure",
        st["score"],
        ctx.config["thresholds"]["structure"],
        st["score"] >= ctx.config["thresholds"]["structure"] and st["heading_order_ok"] and st["h1_count"] == 0,
        [{"check": "structure", "severity": "major", "location": "", "problem": p, "fix": p} for p in st["problems"]],
    )
    if orig["fetch_errors"]:
        # A revision cannot fix an unreachable source: fail now instead of looping.
        raise GateFailure(rec.stage, f"[{lang}] Sources could not be fetched for the originality check: " + "; ".join(orig["fetch_errors"]))
    orig_ok = orig["max_overlap"] <= limit
    orig_issues = []
    if orig["max_overlap"] > limit:
        orig_issues.append(
            {
                "check": "originality",
                "severity": "critical",
                "location": "",
                "problem": f"{orig['max_overlap']:.0%} of the post's {orig['n']}-word sequences also appear in {orig['worst_reference']}.",
                "fix": "Rewrite the overlapping passages in your own words.",
            }
        )
    originality = PassResult("originality", 1.0 - orig["max_overlap"], 1.0 - limit, orig_ok, orig_issues)
    return [structure, originality]


async def primary_passes(ctx: RunContext, rec) -> list[PassResult]:
    assert ctx.draft is not None
    lang = ctx.inv.primary
    th = ctx.config["thresholds"]
    style = ctx.style_profiles.get(lang)
    claims = list(ctx.claims.values())
    results = [
        await model_pass(ctx, rec, "grammar", "qa_grammar", lang, ctx.draft, th["grammar"], {}),
        await model_pass(ctx, rec, "facts", "qa_facts", lang, ctx.draft, th["facts"], {"verified_claims": claims}),
        await model_pass(ctx, rec, "style", "qa_style", lang, ctx.draft, th["style"], {"style_profile": style}),
        await model_pass(ctx, rec, "seo", "qa_seo", lang, ctx.draft, th["seo"], {}),
    ]
    results += await text_checks(ctx, rec, lang, ctx.draft)
    results.append(length_check(ctx))
    results.append(heading_check(ctx))
    return results


MAX_H2 = draft_stage.MAX_H2  # main parts of an article; more means items that belong under one H2 as H3


def heading_check(ctx: RunContext) -> PassResult:
    """Heading hierarchy, counted by the worker: post 5647 had nine H2 headings (one per dish) and
    almost no H3. Fails with more than MAX_H2 H2 headings, or five or more H2 and no H3 at all."""
    import re

    html = ctx.draft["content_html"]
    h2 = len(re.findall(r"<h2\b", html, re.I))
    h3 = len(re.findall(r"<h3\b", html, re.I))
    ok = h2 <= MAX_H2 and not (h2 >= 5 and h3 == 0)
    issues = []
    if not ok:
        issues.append({
            "check": "headings", "severity": "major", "location": "",
            "problem": f"The post has {h2} H2 headings and {h3} H3; H2 is only for the main parts of the article (at most {MAX_H2}).",
            "fix": "Group related sections (dishes, places, stops, tips) as <h3> subsections under fewer <h2> sections, each <h2> a main part of the article; use <h4> only inside an <h3>. Keep the text.",
        })
    score = 1.0 if ok else round(min(0.99, MAX_H2 / max(1, h2)), 4)
    return PassResult("headings", score, 1.0, ok, issues)


def length_check(ctx: RunContext) -> PassResult:
    """Words against the site's typical length (draft_stage.length_target); counted by the worker."""
    target, minimum = draft_stage.length_target(ctx)
    words = draft_stage.word_count(ctx.draft["content_html"])
    issues = []
    if words < minimum:
        issues.append({
            "check": "length", "severity": "major", "location": "",
            "problem": f"The post has {words} words; it needs at least {minimum} (about {target}).",
            "fix": "Expand with the verified claims and with practical, fact-free advice for visitors (how to visit, when, what to look for). Do not add unsupported facts.",
        })
    # Score: words against the minimum (1.0 at or above it); pass mark 1.0 = the minimum.
    return PassResult("length", round(min(1.0, words / minimum), 4), 1.0, words >= minimum, issues)


def summarize(results: list[PassResult]) -> str:
    return ", ".join(f"{r.check} {r.score:.2f} < {r.threshold:.2f}" if r.score < r.threshold else f"{r.check} has blocking issues" for r in results)


async def mechanical_last_chance(ctx: RunContext, rec) -> list[PassResult]:
    """After model revision loops fail: one more pass of heading cleaners + fit_fields, then re-check.
    Does not call the model. Recorded as revision.failsafe = mechanical_last_chance."""
    assert ctx.draft is not None
    detail: dict = {"failsafe": "mechanical_last_chance"}
    fitted = await draft_stage.fit_fields(ctx, rec)
    if fitted:
        detail["fitted"] = fitted
    headings = draft_stage.apply_draft_heading_fixes(ctx)
    if any(headings.get(k) for k in ("levels_fixed", "merged", "folded_title")):
        detail["headings"] = headings
    draft_stage.check_draft(ctx, "qa", ctx.draft)
    rec.detail = {**(rec.detail or {}), "revision": detail}
    results = await primary_passes(ctx, rec)
    for r in results:
        rec.score(r.check, r.score, r.threshold, r.passed)
    return results


async def run(ctx: RunContext) -> list[PassResult]:
    loops = ctx.config["max_revision_loops"]
    lang = ctx.inv.primary
    for attempt in range(1, loops + 2):
        async with ctx.reporter.stage("qa", language=lang, attempt=attempt) as rec:
            rec.revision_count = attempt - 1
            results = await primary_passes(ctx, rec)
            for r in results:
                rec.score(r.check, r.score, r.threshold, r.passed)
            failing = [r for r in results if not r.passed]
            if not failing:
                ctx.scores += [{"language": lang, "check": r.check, "score": round(r.score, 4)} for r in results]
                return results
            if attempt > loops:
                # In-run mechanical last chance before failing the run.
                results = await mechanical_last_chance(ctx, rec)
                failing = [r for r in results if not r.passed]
                if not failing:
                    ctx.scores += [{"language": lang, "check": r.check, "score": round(r.score, 4)} for r in results]
                    return results
                raise GateFailure("qa", f"QA below threshold after {loops} revision loops: {summarize(failing)}.")
            rec.soft_failure = f"Revising: {summarize(failing)}."
            await draft_stage.revise(ctx, results, rec)
    raise AssertionError("unreachable")

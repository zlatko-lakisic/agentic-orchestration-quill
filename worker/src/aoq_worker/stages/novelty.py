"""Stage 5: novelty check against existing posts in every language.

Partial (docs/ao-gaps.md item 3): a model judge with titles and excerpts in
context, until AO can do embedding retrieval over client documents.

Sending every post (hundreds, in several languages) to the judge made this step take
far too long on small hardware, so the worker first shortlists, for each candidate, the
existing posts that share the most distinctive words with it (rarer words count more).
Posts in other languages mostly match through their primary-language counterpart."""

from __future__ import annotations

import math
import re
from collections import Counter

from ..context import RunContext
from .common import clip, dumps, gate

SIMILAR_PER_CANDIDATE = 8
_WORD = re.compile(r"[^\W\d_]{4,}", re.UNICODE)
_STOP = {
    "with", "from", "that", "this", "your", "what", "when", "where", "which", "about", "into", "their", "there",
    "best", "guide", "tips", "visit", "visiting", "travel", "mostar", "bosnia", "herzegovina", "koji", "koja",
    "koje", "kako", "gdje",
}


def _words(text: str) -> set[str]:
    return {w for w in (m.lower() for m in _WORD.findall(text or "")) if w not in _STOP}


def shortlist(candidates: list[dict], posts: list[dict], per_candidate: int = SIMILAR_PER_CANDIDATE) -> dict[str, list[dict]]:
    """For each candidate, the posts sharing the most distinctive words (IDF-weighted overlap)."""
    post_words = [_words(f"{p['title']} {p.get('excerpt', '')}") for p in posts]
    df = Counter(w for ws in post_words for w in ws)
    n = max(1, len(posts))
    idf = {w: math.log(1 + n / c) for w, c in df.items()}
    out: dict[str, list[dict]] = {}
    for c in candidates:
        cw = _words(f"{c['title']} {c.get('angle', '')}")
        scored = []
        for p, ws in zip(posts, post_words):
            score = sum(idf.get(w, 0.0) for w in cw & ws)
            if score > 0:
                scored.append((score, p["key"], p))
        scored.sort(key=lambda t: (-t[0], t[1]))
        out[c["id"]] = [p for _, _, p in scored[:per_candidate]]
    return out


PROMPT = """Decide which topic candidates are new for this site. For each candidate the context lists the existing
posts most similar to it (key, language, title, excerpt). A candidate is not novel only when one of those posts is
about the same specific subject (the same event, place, dish, route or practical question) and gives the same kind
of information. Sharing a broad theme such as festivals, food, history or hiking does not make a candidate old: a
post about one festival does not cover a different festival. For each candidate give novel true or false, up to five keys of the
closest existing posts, and the reason. Then choose the one novel candidate that best fits the site profile, preferring candidates whose
search_interest lists real searches, or
null when none is novel."""


async def run(ctx: RunContext) -> dict:
    async with ctx.reporter.stage("novelty", attempt=ctx.topic_round) as rec:
        posts = [
            {"key": p["key"], "language": p["language"], "title": clip(p["title"], 160), "excerpt": clip(p["excerpt"], 160)}
            for p in ctx.inv.posts
        ]
        similar = shortlist(ctx.candidates, posts)
        offered = [dict(c, similar_existing_posts=similar[c["id"]]) for c in ctx.candidates]
        context = dumps({"site_profile": ctx.site_profile, "candidates": offered})
        res = await ctx.client.json_call("novelty", "novelty", PROMPT, context, "novelty")
        rec.add_calls(res.records)
        data = res.data
        known = {c["id"]: c for c in ctx.candidates}
        keys = ctx.inv.post_keys()
        # Stray references are removed rather than failing the run: judgments for candidates that
        # were never offered are ignored, and post keys that do not exist are dropped.
        ignored = [j["candidate_id"] for j in data["judgments"] if j["candidate_id"] not in known]
        bad_keys = sorted({k for j in data["judgments"] for k in j["closest_post_keys"] if k not in keys})
        data = dict(data, judgments=[
            dict(j, closest_post_keys=[k for k in j["closest_post_keys"] if k in keys])
            for j in data["judgments"] if j["candidate_id"] in known
        ])
        judgments = {j["candidate_id"]: j for j in data["judgments"]}
        # The judge's own verdicts decide: if it picks a candidate it did not judge novel (or none, or an
        # unknown one) while others were judged novel, the first of those is used instead.
        chosen = data["chosen_candidate_id"]
        novel_ids = [c["id"] for c in ctx.candidates if c["id"] in judgments and judgments[c["id"]]["novel"]]
        replaced = None
        if chosen not in novel_ids and novel_ids:
            replaced, chosen = chosen, novel_ids[0]
        if chosen is None or chosen not in novel_ids:
            # Not fatal here: the pipeline goes back to topics for new ideas (TOPIC_ROUNDS) and only
            # fails the run when the last round also finds nothing new.
            ctx.topic = None
            ctx.rejected_topics.extend(
                {"title": c["title"], "why": judgments[c["id"]]["reason"] if c["id"] in judgments else "not judged"}
                for c in ctx.candidates
            )
            rec.detail = {
                "candidates_judged_new": f"0 of {len(data['judgments'])}",
                "rejected": [f"{c['title']}: {judgments[c['id']]['reason']}" for c in ctx.candidates if c["id"] in judgments][:10],
            }
            rec.soft_failure = "No topic candidate was judged new; asking for new topics."
            return None
        ctx.topic = {**known[chosen], "novelty_reason": judgments[chosen]["reason"]}
        rec.detail = {
            "chosen": ctx.topic["title"],
            "candidates_judged_new": f'{sum(1 for j in data["judgments"] if j["novel"])} of {len(data["judgments"])}',
            "method": f"model judge over the {SIMILAR_PER_CANDIDATE} most similar of {len(posts)} posts per candidate",
        }
        if chosen != data["chosen_candidate_id"]:
            rec.detail["choice_replaced"] = f"the judge chose {replaced}, which it did not judge novel; used {chosen}"
        if ignored or bad_keys:
            rec.detail["ignored"] = {"unknown_candidates": ignored, "unknown_post_keys": bad_keys}
        return ctx.topic

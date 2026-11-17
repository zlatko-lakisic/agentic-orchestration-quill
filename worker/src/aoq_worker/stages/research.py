"""Stage 6: research with search and fetch on AO, then quote verification by the
deterministic aoq_quote_verifier agent, which fetches every source on the server
and checks that each supporting quote is an exact substring of the source text.
Neither a model nor the worker decides whether a quote is verified.

Before that, a pre-check uses the pages AO's agent actually read through the worker's
fetch_url tool: claims citing a page that was never fetched, or quoting text that is not
on it, are dropped, and if too few remain research runs once more with that feedback.
The pre-check only filters; AO's verifier still decides every remaining quote."""

from __future__ import annotations

import html
import re
import unicodedata

from ..context import RunContext
from ..errors import ContractError
from .common import dumps, gate

RESEARCH_ATTEMPTS = 2
MIN_SITES = 2  # claims from one site only make a thin post that copies its source (originality)
_QUOTES = str.maketrans({"\u2018": "'", "\u2019": "'", "\u201a": "'", "\u201b": "'", "\u201c": '"', "\u201d": '"', "\u201e": '"',
                         "\u2032": "'", "\u2033": '"', "\u2013": "-", "\u2014": "-", "\u2212": "-", "\u00a0": " "})


def _norm(text: str) -> str:
    """Same normalization as AO's verifier: entities, quotes, dashes, whitespace. Case is kept."""
    text = unicodedata.normalize("NFKC", html.unescape(text)).translate(_QUOTES)
    text = re.sub(r"!?\[([^\]]*)\]\([^)]*\)", r"\1", text)
    return re.sub(r"\s+", " ", re.sub(r"(\*\*|__|`)", "", text)).strip()


_ELLIPSIS = re.compile(r"\s*(?:\.\.\.+|\u2026)\s*")
MIN_SEGMENT = 20


def find_quote(quote: str, page: str) -> str | None:
    """The quote, or its longest verbatim piece, if it is on the (normalized) page.

    Research often joins excerpts with "..."; such a quote can never be an exact substring,
    so it is cut to the longest piece of at least MIN_SEGMENT characters that is on the page.
    """
    if _norm(quote) in page:
        return quote
    pieces = sorted((p.strip(" .,;:") for p in _ELLIPSIS.split(quote)), key=len, reverse=True)
    for piece in pieces:
        if len(piece) >= MIN_SEGMENT and _norm(piece) in page:
            return piece
    return None


_SENTENCE_END = re.compile(r"(?<=[.!?])\s+(?=[A-Z0-9\"'(\[])")
_WORD_RE = re.compile(r"[^\W_]{3,}", re.UNICODE)
SNAP_RECALL = 0.7     # share of the model's quote words that the page sentence must contain
SNAP_PRECISION = 0.4  # share of the sentence's words that come from the quote (no huge paragraphs)
SNAP_MAX_CHARS = 600


def _words(text: str) -> set[str]:
    return {w.lower() for w in _WORD_RE.findall(text)}


def snap_quote(quote: str, page: str) -> str | None:
    """The page sentence (or two adjacent sentences) that a loosely copied quote came from.

    Models often reword a sentence slightly when "quoting" it. When most of the quote's words
    are in one sentence of the (normalized) page, that exact sentence becomes the quote, so
    AO's verifier checks real page text; anything not close to a page sentence is not saved.
    """
    q = _words(quote)
    if len(q) < 4:
        return None
    sentences = [x.strip() for x in _SENTENCE_END.split(page) if 20 <= len(x.strip())]
    candidates = sentences + [f"{a} {b}" for a, b in zip(sentences, sentences[1:])]
    best, best_score = None, 0.0
    for cand in candidates:
        if len(cand) > SNAP_MAX_CHARS:
            continue
        w = _words(cand)
        if not w:
            continue
        common = len(q & w)
        recall, precision = common / len(q), common / len(w)
        if recall >= SNAP_RECALL and precision >= SNAP_PRECISION and recall + precision > best_score:
            best, best_score = cand, recall + precision
    return best


def _host(url: str) -> str:
    from urllib.parse import urlparse

    return (urlparse(url).hostname or "").removeprefix("www.")


def precheck(fetched: dict[str, str], sources: list[dict], claims: list[dict]) -> tuple[list[dict], list[dict], list[dict]]:
    """Keep claims whose quote is on a page fetched in this run; return (claims, dropped, sources).

    The cited page is checked first, then other fetched pages on the same site, then every
    fetched page; a claim found on another page is re-attributed to that page's exact URL
    (the structurer often shortens URLs to the site root). Quotes joined with "..." are cut to
    their longest verbatim piece. AO's verifier then checks every kept quote on its own.
    """
    by_id = {s["source_id"]: s for s in sources}
    pages = {u.rstrip("/"): _norm(t) for u, t in fetched.items()}
    exact = {u.rstrip("/"): u for u in fetched}  # the URL as fetched, for AO's verifier to fetch again
    out_sources = {s["source_id"]: dict(s) for s in sources}
    by_url = {s["url"].rstrip("/"): s["source_id"] for s in sources}
    kept, dropped, snapped = [], [], []
    for c in claims:
        src = by_id.get(c.get("source_id", ""), {"url": ""})
        cited = src["url"].rstrip("/")
        order = [cited] if cited in pages else []
        order += [u for u in pages if u != cited and _host(u) == _host(cited)]
        order += [u for u in pages if u not in order]
        found = None
        for url in order:
            quote = find_quote(c["supporting_quote"], pages[url])
            if quote is not None:
                found = (url, quote)
                break
        if found is None:
            # Not word for word anywhere: take the page sentence the quote was loosely copied from.
            for url in order:
                quote = snap_quote(c["supporting_quote"], pages[url])
                if quote is not None:
                    found = (url, quote)
                    snapped.append(c["claim_id"])
                    break
        if found is None:
            if cited in pages:
                reason = f"quote is not on {src['url']}"
            elif src["url"]:
                reason = f"source {src['url']} was never fetched with fetch_url, and the quote is on no page that was"
            else:
                reason = "the quote is on no page fetched in this run"
            dropped.append({"claim_id": c["claim_id"], "reason": reason})
            continue
        url, quote = found
        if url != cited:
            sid = by_url.get(url)
            if sid is None:
                sid = f"s{len(out_sources) + 1:03d}"
                while sid in out_sources:
                    sid += "x"
                out_sources[sid] = {"source_id": sid, "url": exact[url], "title": src.get("title") or exact[url]}
                by_url[url] = sid
            c = dict(c, source_id=sid)
        if quote != c["supporting_quote"]:
            c = dict(c, supporting_quote=quote)
        kept.append(c)
    used = {c["source_id"] for c in kept}
    precheck.snapped = snapped  # type: ignore[attr-defined]  # for the stage detail
    return kept, dropped, [s for s in out_sources.values() if s["source_id"] in used]


PROMPT = """Research this topic for a post on the site: {title}. Angle: {angle}.
Use web search to find reliable sources, then open each one with the fetch tool and read it. Open at least
three different pages from different sites, so the post does not lean on one source.
Write down factual claims the post can make. For every claim give the claim in one sentence and a supporting
quote copied word for word from the text fetch_url returned, at least ten characters long (one full sentence is
best). Do not paraphrase quotes. Search result snippets are not sources: only text from fetched pages counts.
The worker finds the page each quote came from, so a quote that is on no fetched page is dropped.
Aim for {target} or more claims."""

RETRY = """

Your previous research could not be used. {dropped} claim(s) were rejected:
{reasons}
Pages you actually fetched in this run: {fetched}.
Research again: cite only pages fetch_url returned, open more pages if needed, and copy each quote character for
character from the fetched text (one full sentence is best)."""


RETRY_UNUSABLE = """

Your previous research answer could not be used. Research again: fetch the pages you rely on with fetch_url
(some sites refuse automated fetches; open others instead), then write each claim with a quote copied word for
word from a fetched page."""


async def run(ctx: RunContext) -> dict:
    assert ctx.topic is not None
    minimum = ctx.config["min_verified_claims"]
    async with ctx.reporter.stage("research") as rec:
        mcp = [ctx.config["mcp"]["search"], ctx.config["mcp"]["fetch"]]
        base = PROMPT.format(title=ctx.topic["title"], angle=ctx.topic["angle"], target=minimum + 4)
        fetched = getattr(ctx.web, "fetched", None) if ctx.web is not None else None
        prompt, attempts = base, []
        for attempt in range(1, RESEARCH_ATTEMPTS + 1):
            task = "research" if attempt == 1 else f"research:retry{attempt}"
            try:
                res = await ctx.client.json_call("research", task, prompt, dumps({"site_profile": ctx.site_profile}), "research", mcp_ids=mcp)
            except ContractError as exc:
                # Nothing usable came back (a garbled answer, or JSON that could not be saved): use
                # the second research attempt instead of failing the run.
                attempts.append({"attempt": attempt, "claims": 0, "kept": 0, "dropped": [], "error": exc.reason[:300]})
                if attempt == RESEARCH_ATTEMPTS:
                    raise
                prompt = base + RETRY_UNUSABLE
                continue
            rec.add_calls(res.records)
            # Sources and IDs are worker fields (D19): models no longer give them, and when they do they
            # are only a hint; the pre-check attaches each quote to the fetched page it is on.
            sources = res.data.get("sources", [])
            claims = res.data["claims"]
            ids = [c.get("claim_id") for c in claims]
            if None in ids or len(ids) != len(set(ids)):
                claims = [dict(c, claim_id=f"c{i}") for i, c in enumerate(claims, 1)]
            claims = [dict(c, source_id=c.get("source_id", "")) for c in claims]
            if fetched is None:  # no worker tools (AO-hosted fetch): the model's sources are all there is
                source_ids = [s["source_id"] for s in sources]
                gate(len(source_ids) == len(set(source_ids)), "research", "Source IDs are not unique.")
                for c in claims:
                    gate(c["source_id"] in source_ids, "research", f"Claim {c['claim_id']} cites unknown source {c['source_id'] or '(none)'}.")
                break
            kept, dropped, kept_sources = precheck(fetched, sources, claims)
            attempts.append({"attempt": attempt, "claims": len(claims), "kept": len(kept), "dropped": dropped,
                             "quotes_snapped_to_page": list(getattr(precheck, "snapped", []))})
            claims, sources = kept, kept_sources
            sites = {_host(s["url"]) for s in kept_sources}
            if (len(kept) >= minimum and len(sites) >= MIN_SITES) or attempt == RESEARCH_ATTEMPTS:
                break
            if len(sites) < MIN_SITES:
                dropped = dropped + [{"claim_id": "-", "reason": f"all kept claims come from {len(sites)} site(s); read and quote pages from at least {MIN_SITES} different sites"}]
            prompt = base + RETRY.format(
                dropped=len(dropped),
                reasons="\n".join(f"- {d['claim_id']}: {d['reason']}" for d in dropped) or "- too few claims",
                fetched=", ".join(sorted(fetched)) or "none",
            )
        claim_ids = [c["claim_id"] for c in claims]
        rec.detail = {
            "sources": [f"{s.get('title') or s['url']} ({s['url']})" for s in sources][:15],
            "claims": len(claims),
            "pages_read": len(fetched) if fetched is not None else None,
            "precheck": attempts,
        }
        gate(bool(claims), "research", "No claim survived the check against the pages that were actually read.")

    async with ctx.reporter.stage("verify_quotes") as rec:
        payload = {
            "sources": [{"source_id": s["source_id"], "url": s["url"]} for s in sources],
            "claims": [{"claim_id": c["claim_id"], "source_id": c["source_id"], "supporting_quote": c["supporting_quote"]} for c in claims],
        }
        ver = await ctx.client.deterministic("quote_verifier", "verify_quotes", payload, "quote_verification")
        rec.add_calls(ver.records)
        verdicts = {v["claim_id"]: v for v in ver.data["claims"]}
        gate(set(verdicts) == set(claim_ids), "verify_quotes", "The verifier did not return a verdict for every claim.")
        verified = [c for c in claims if verdicts[c["claim_id"]]["verified"]]
        used_sources = {c["source_id"] for c in verified}
        failed_fetch = [s for s in ver.data["sources"] if not s["fetched"]]
        rec.detail = {
            "verified": len(verified),
            "rejected": [{"claim_id": cid, "reason": v["reason"]} for cid, v in verdicts.items() if not v["verified"]],
            "unfetched_sources": [s["url"] for s in failed_fetch],
        }
        rec.score("verified_claims", min(1.0, len(verified) / max(1, minimum)), 1.0, len(verified) >= minimum)
        gate(
            len(verified) >= minimum,
            "verify_quotes",
            f"Only {len(verified)} claims have quotes verified on the server; at least {minimum} are needed.",
        )
        ctx.claims = {c["claim_id"]: c for c in verified}
        ctx.sources = [s for s in sources if s["source_id"] in used_sources]
        return {"claims": ctx.claims, "sources": ctx.sources}

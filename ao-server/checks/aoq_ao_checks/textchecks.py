"""Structure and originality checks.

Structure: no H1 in the body, headings start at H2 and never skip a level, title
and SEO title lengths, meta description length.

Originality: share of the post's word n-grams (default 5) found in each reference
(existing posts given in the input, and source pages fetched here). The highest
share is reported; the worker gates on it.

Input:  {"language", "title", "seo_title", "seo_description", "content_html", "n",
         "source_urls": [...], "references": [{"id", "text"}]}
Output: contracts stages/text_checks.json
"""

from __future__ import annotations

import json
import re
from typing import Any, Callable

from .common import fetch_text, html_to_text, normalize, parse_input

TITLE_MAX = 70
SEO_TITLE_MAX = 60
DESC_MIN, DESC_MAX = 120, 160
_HEADING = re.compile(r"<h([1-6])\b", re.I)
_WORD = re.compile(r"\w+", re.UNICODE)


def structure(payload: dict[str, Any]) -> dict[str, Any]:
    content = str(payload.get("content_html") or "")
    levels = [int(x) for x in _HEADING.findall(content)]
    problems: list[str] = []
    h1 = levels.count(1)
    if h1:
        problems.append("The body contains an H1; the title is the only H1.")
    order_ok = True
    prev = 1
    for lvl in levels:
        if lvl > prev + 1:
            order_ok = False
        prev = lvl
    if levels and levels[0] != 2:
        order_ok = False
    if not order_ok:
        problems.append("Headings skip a level or do not start at H2.")
    title, seo_title, desc = (str(payload.get(k) or "") for k in ("title", "seo_title", "seo_description"))
    if len(title) > TITLE_MAX:
        problems.append(f"Title is {len(title)} characters; keep it at {TITLE_MAX} or fewer.")
    if len(seo_title) > SEO_TITLE_MAX:
        problems.append(f"SEO title is {len(seo_title)} characters; keep it at {SEO_TITLE_MAX} or fewer.")
    if not DESC_MIN <= len(desc) <= DESC_MAX:
        problems.append(f"Meta description is {len(desc)} characters; aim for {DESC_MIN} to {DESC_MAX}.")
    if not levels:
        problems.append("The body has no headings.")
    return {
        "title_chars": len(title),
        "seo_title_chars": len(seo_title),
        "seo_description_chars": len(desc),
        "h1_count": h1,
        "heading_order_ok": order_ok,
        "problems": problems,
        "score": max(0.0, round(1.0 - 0.2 * len(problems), 4)),
    }


def ngrams(text: str, n: int) -> set[tuple[str, ...]]:
    words = [w.lower() for w in _WORD.findall(normalize(text))]
    return {tuple(words[i : i + n]) for i in range(len(words) - n + 1)}


def originality(payload: dict[str, Any], fetch: Callable[[str], tuple[str, int]] = fetch_text) -> dict[str, Any]:
    n = int(payload.get("n") or 5)
    n = min(max(n, 3), 12)
    post = ngrams(html_to_text(str(payload.get("content_html") or "")), n)
    refs: list[tuple[str, str]] = [(str(r["id"]), html_to_text(str(r.get("text") or ""))) for r in payload.get("references", [])]
    errors: list[str] = []
    for url in payload.get("source_urls", []):
        try:
            text, _ = fetch(str(url))
            refs.append((str(url), text))
        except Exception as exc:  # noqa: BLE001 - reported, the worker fails the run on any
            errors.append(f"{url}: {str(exc)[:200]}")
    worst, worst_id = 0.0, None
    for ref_id, text in refs:
        if not post:
            break
        shared = len(post & ngrams(text, n))
        ratio = shared / len(post)
        if ratio > worst:
            worst, worst_id = ratio, ref_id
    return {
        "n": n,
        "max_overlap": round(worst, 4),
        "worst_reference": worst_id,
        "references_checked": len(refs),
        "fetch_errors": errors,
        "score": round(1.0 - worst, 4),
    }


def run(payload: dict[str, Any], fetch: Callable[[str], tuple[str, int]] = fetch_text) -> dict[str, Any]:
    return {"structure": structure(payload), "originality": originality(payload, fetch)}


def text_checks(text: str = "", context: str = "", mcp_tool_results_or_handles: Any = None) -> str:
    """AO deterministic entrypoint."""
    return json.dumps(run(parse_input(text, context)), ensure_ascii=False)

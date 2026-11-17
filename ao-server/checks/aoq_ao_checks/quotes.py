"""Quote verification: every supporting quote must be an exact substring of the text
of its source, fetched here on the AO server. Normalization covers whitespace, HTML
entities, typographic quotes and dashes, and markdown link or emphasis syntax in the
quote. Case and wording must match exactly.

Input:  {"sources": [{"source_id", "url"}], "claims": [{"claim_id", "source_id", "supporting_quote"}]}
Output: contracts stages/quote_verification.json
"""

from __future__ import annotations

import json
from typing import Any, Callable

from .common import fetch_text, normalize, parse_input, strip_markdown

MIN_QUOTE_CHARS = 10


def verify(payload: dict[str, Any], fetch: Callable[[str], tuple[str, int]] = fetch_text) -> dict[str, Any]:
    texts: dict[str, str] = {}
    sources_out = []
    for src in payload.get("sources", []):
        sid, url = str(src["source_id"]), str(src["url"])
        try:
            text, status = fetch(url)
            texts[sid] = normalize(text)
            sources_out.append({"source_id": sid, "url": url, "fetched": True, "http_status": status, "chars": len(texts[sid]), "error": None})
        except Exception as exc:  # noqa: BLE001 - recorded per source
            sources_out.append({"source_id": sid, "url": url, "fetched": False, "http_status": None, "chars": 0, "error": str(exc)[:1000]})
    claims_out = []
    for claim in payload.get("claims", []):
        cid, sid = str(claim["claim_id"]), str(claim["source_id"])
        quote = normalize(strip_markdown(str(claim.get("supporting_quote") or "")))
        if len(quote) < MIN_QUOTE_CHARS:
            claims_out.append({"claim_id": cid, "verified": False, "reason": "quote too short"})
        elif sid not in texts:
            claims_out.append({"claim_id": cid, "verified": False, "reason": "source not fetched"})
        elif quote in texts[sid]:
            claims_out.append({"claim_id": cid, "verified": True, "reason": "exact substring"})
        else:
            claims_out.append({"claim_id": cid, "verified": False, "reason": "quote not found in source text"})
    return {"sources": sources_out, "claims": claims_out}


def verify_quotes(text: str = "", context: str = "", mcp_tool_results_or_handles: Any = None) -> str:
    """AO deterministic entrypoint."""
    return json.dumps(verify(parse_input(text, context)), ensure_ascii=False)

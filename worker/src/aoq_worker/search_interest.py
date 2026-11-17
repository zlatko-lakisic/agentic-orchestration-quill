"""What people are searching right now, from two free Google sources (no account, no key):

- Google Trends' daily trending searches feed, per country (RSS).
- Google's search suggestions (autocomplete) for the site's seed terms: the questions visitors type.

Both are best effort: any failure just means fewer signals, never a failed run. Results are cached
for a few hours so runs do not ask Google again and again. The topics stage gives them to the
analyst as search interest; candidates that match them are marked, and novelty prefers those.
"""

from __future__ import annotations

import asyncio
import json
import logging
import re
import time
import xml.etree.ElementTree as ET
from pathlib import Path
from typing import Any
from urllib.parse import quote

LOG = logging.getLogger("aoq_worker")

TRENDS_URL = "https://trends.google.com/trending/rss?geo={geo}"
SUGGEST_URL = "https://suggestqueries.google.com/complete/search?client=firefox&hl=en&q={q}"
CACHE_SECONDS = 6 * 3600
MAX_BYTES = 512 * 1024
PAUSE = 1.0  # between Google requests
# Prefixes added to each seed term, so suggestions cover the questions visitors ask.
SUGGEST_PREFIXES = ("{seed}", "{seed} in", "things to do in {seed}", "best {seed}")
_WORD = re.compile(r"[^\W\d_]{4,}", re.UNICODE)
_STOP = {"with", "from", "that", "this", "your", "what", "when", "where", "which", "about", "best", "things", "near", "guide", "visit", "travel"}


def _words(text: str) -> set[str]:
    return {w.lower() for w in _WORD.findall(text or "")} - _STOP


class SearchInterest:
    def __init__(self, web: Any, cache_path: Path | None = None) -> None:
        self.web = web  # WebTools: its public session refuses private addresses
        self.cache_path = cache_path

    async def _get(self, url: str) -> bytes:
        body, _ctype, _final = await self.web._get_public(url, MAX_BYTES)
        return body

    async def trending(self, geo: str) -> list[dict[str, str]]:
        body = await self._get(TRENDS_URL.format(geo=quote(geo)))
        root = ET.fromstring(body)
        out = []
        for item in root.iter("item"):
            title = (item.findtext("title") or "").strip()
            traffic = ""
            for child in item:
                if child.tag.endswith("approx_traffic"):
                    traffic = (child.text or "").strip()
            if title:
                out.append({"query": title, "traffic": traffic, "geo": geo})
        return out

    async def suggestions(self, query: str) -> list[str]:
        body = await self._get(SUGGEST_URL.format(q=quote(query)))
        data = json.loads(body.decode("utf-8", errors="replace"))
        return [str(s) for s in (data[1] if isinstance(data, list) and len(data) > 1 else [])][:10]

    def _cached(self, key: str) -> Any | None:
        if self.cache_path is None:
            return None
        try:
            data = json.loads(self.cache_path.read_text(encoding="utf-8"))
            entry = data.get(key)
            if entry and time.time() - float(entry["at"]) < CACHE_SECONDS:
                return entry["value"]
        except (OSError, ValueError, KeyError, TypeError):
            pass
        return None

    def _store(self, key: str, value: Any) -> None:
        if self.cache_path is None:
            return
        try:
            data = json.loads(self.cache_path.read_text(encoding="utf-8")) if self.cache_path.exists() else {}
        except (OSError, ValueError):
            data = {}
        data[key] = {"at": time.time(), "value": value}
        try:
            self.cache_path.parent.mkdir(parents=True, exist_ok=True)
            self.cache_path.write_text(json.dumps(data, ensure_ascii=False), encoding="utf-8")
        except OSError:
            LOG.warning("could not write the search interest cache", exc_info=True)

    async def collect(self, seeds: list[str], geos: list[str]) -> dict[str, Any]:
        """Trending searches that touch the site's seed terms, and search suggestions for them."""
        key = json.dumps([sorted(seeds), sorted(geos)])
        cached = self._cached(key)
        if cached is not None:
            return cached
        seed_words = set().union(*(_words(s) for s in seeds)) if seeds else set()
        trending, errors = [], []
        for geo in geos:
            try:
                for t in await self.trending(geo):
                    # Only what touches the site's seed terms: a country's trending list is mostly
                    # sport and news (in BA: football, "outlook", "klix").
                    if _words(t["query"]) & seed_words:
                        trending.append(t)
            except Exception as exc:  # noqa: BLE001 - best effort
                errors.append(f"trends {geo}: {exc}")
            await asyncio.sleep(PAUSE)
        questions: list[str] = []
        for seed in seeds:
            for prefix in SUGGEST_PREFIXES:
                try:
                    for s in await self.suggestions(prefix.format(seed=seed)):
                        # The seed must appear as a whole word ("mostarda" is not about Mostar).
                        if s.lower() != seed.lower() and s not in questions and _words(s) & _words(seed):
                            questions.append(s)
                except Exception as exc:  # noqa: BLE001 - best effort
                    errors.append(f"suggest {seed!r}: {exc}")
                await asyncio.sleep(PAUSE)
        result = {"trending": trending[:40], "questions": questions[:80], "errors": errors[:10]}
        if trending or questions:
            self._store(key, result)
        return result


def matches(candidate: dict, interest: dict) -> list[str]:
    """Searches that share at least two distinctive words with a candidate's title and angle."""
    words = _words(f"{candidate.get('title', '')} {candidate.get('angle', '')}")
    found = []
    for q in [t["query"] for t in interest.get("trending", [])] + list(interest.get("questions", [])):
        if len(words & _words(q)) >= 2 and q not in found:
            found.append(q)
    return found[:5]

"""Web tools the worker serves to AO through the Reach reverse MCP tunnel (D14).

AO's agents call these tools; the worker runs them. Three tools:

* ``web_search``: queries the configured SearXNG instance (JSON API).
* ``fetch_url``: reads one public web page as plain text.
* ``image_search``: searches Openverse and Wikimedia Commons for images under
  licenses that allow reuse (CC0, public domain mark, CC BY, CC BY-SA).

Public fetches go through a resolver that refuses private, loopback, link-local,
multicast and reserved addresses, checked at connect time, so redirects and DNS
rebinding cannot reach the LAN. SearXNG is the one configured exception.

Every image result is kept in a ledger. The pipeline only accepts image candidates
that are in the ledger, and takes license, author and URLs from the ledger, never
from model output.
"""

from __future__ import annotations

import asyncio
import hashlib
import ipaddress
import json
import re
import socket
from dataclasses import dataclass, field
from html import unescape
from html.parser import HTMLParser
from typing import Any
from urllib.parse import urlencode, urlparse

import aiohttp
from aiohttp.abc import AbstractResolver
from aiohttp.resolver import DefaultResolver
from yarl import URL

from . import __version__

DEFAULT_USER_AGENT = f"ao-quill-worker/{__version__} (automated WordPress post research)"
PAGE_MAX_BYTES = 3 * 1024 * 1024
UNCHECKED_LICENSE = {
    "code": "unchecked",
    "name": "Not checked (found by web image search)",
    "url": None,
    "allows_download_and_reuse": False,
    "requires_attribution": True,
}
IMAGE_MAX_BYTES = 4 * 1024 * 1024
TEXT_MAX_CHARS = 40_000  # AO caps tunnel bodies (512 KiB by default); stay far below.
MAX_REDIRECTS = 5
IMAGE_MIMES = {"image/jpeg", "image/png", "image/webp", "image/gif"}
OPENVERSE_API = "https://api.openverse.org/v1/images/"
COMMONS_API = "https://commons.wikimedia.org/w/api.php"


class ToolError(Exception):
    """A tool call failed in a way the calling agent should see."""


# ------------------------------------------------------------------ address guard


def public_address(address: str) -> bool:
    try:
        ip = ipaddress.ip_address(address)
    except ValueError:
        return False
    if isinstance(ip, ipaddress.IPv6Address) and ip.ipv4_mapped is not None:
        ip = ip.ipv4_mapped
    return not (
        ip.is_private or ip.is_loopback or ip.is_link_local or ip.is_multicast or ip.is_reserved or ip.is_unspecified
    )


class PublicOnlyResolver(AbstractResolver):
    """Resolves like the default resolver, then drops every non-public address."""

    def __init__(self, inner: AbstractResolver | None = None) -> None:
        self._inner = inner or DefaultResolver()

    async def resolve(self, host: str, port: int = 0, family: int = socket.AF_INET) -> list[dict[str, Any]]:
        try:
            literal = ipaddress.ip_address(host)
        except ValueError:
            literal = None
        if literal is not None:
            if not public_address(host):
                raise OSError(f"refusing non-public address {host}")
            return [{"hostname": host, "host": host, "port": port, "family": family, "proto": 0, "flags": socket.AI_NUMERICHOST}]
        records = await self._inner.resolve(host, port, family)
        allowed = [r for r in records if public_address(str(r["host"]))]
        if not allowed:
            raise OSError(f"refusing {host}: it resolves only to non-public addresses")
        return allowed

    async def close(self) -> None:
        await self._inner.close()


def check_public_url(url: str) -> str:
    parsed = urlparse(url)
    if parsed.scheme not in ("http", "https") or not parsed.hostname:
        raise ToolError("Only http and https URLs can be fetched.")
    if parsed.username or parsed.password:
        raise ToolError("URLs with credentials are not allowed.")
    # aiohttp connects to IP-literal hosts without calling the resolver, so the
    # resolver guard never sees them. Check literals here, on every redirect hop too.
    host = parsed.hostname
    try:
        ipaddress.ip_address(host)
        literal = True
    except ValueError:
        literal = False
    if literal and not public_address(host):
        raise ToolError(f"Refusing non-public address {host}.")
    return url


# ------------------------------------------------------------------ html to text


class _Text(HTMLParser):
    SKIP = {"script", "style", "noscript", "template", "svg", "head", "nav", "footer", "form"}
    BLOCK = {"p", "div", "br", "li", "h1", "h2", "h3", "h4", "h5", "h6", "tr", "section", "article", "blockquote", "pre", "table"}

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.parts: list[str] = []
        self.title = ""
        self._skip = 0
        self._in_title = False

    def handle_starttag(self, tag: str, attrs: list) -> None:
        if tag == "title":
            self._in_title = True
        if tag in self.SKIP:
            self._skip += 1
        elif tag in self.BLOCK:
            self.parts.append("\n")

    def handle_endtag(self, tag: str) -> None:
        if tag == "title":
            self._in_title = False
        if tag in self.SKIP and self._skip:
            self._skip -= 1
        elif tag in self.BLOCK:
            self.parts.append("\n")

    def handle_data(self, data: str) -> None:
        if self._in_title and not self.title:
            self.title = data.strip()
        if not self._skip:
            self.parts.append(data)


def html_to_text(markup: str) -> tuple[str, str]:
    parser = _Text()
    parser.feed(markup)
    parser.close()
    text = "".join(parser.parts)
    lines = [re.sub(r"[ \t\r\f\v]+", " ", line).strip() for line in text.split("\n")]
    out = "\n".join(line for line in lines if line)
    return parser.title, out


def strip_tags(markup: str) -> str:
    return re.sub(r"\s+", " ", unescape(re.sub(r"<[^>]+>", " ", markup or ""))).strip()


# ------------------------------------------------------------------ licenses

_OPENVERSE_LICENSES = {"cc0": "cc0", "pdm": "pdm", "by": "cc-by", "by-sa": "cc-by-sa"}
_LICENSE_NAMES = {"cc0": "CC0 1.0", "pdm": "Public Domain Mark 1.0", "cc-by": "CC BY", "cc-by-sa": "CC BY-SA"}


def license_record(code: str, version: str = "", url: str | None = None) -> dict[str, Any]:
    name = _LICENSE_NAMES[code]
    if version and code in ("cc-by", "cc-by-sa"):
        name = f"{name} {version}"
    return {
        "code": code,
        "name": name,
        "url": url,
        "allows_download_and_reuse": True,
        "requires_attribution": code in ("cc-by", "cc-by-sa"),
    }


def commons_license_code(raw: str) -> tuple[str, str] | None:
    """Map Commons ``License`` metadata (``cc-by-sa-4.0``, ``cc0``, ``pd``) to a code and version."""
    value = (raw or "").strip().lower()
    match = re.fullmatch(r"cc-(by-sa|by)-(\d(?:\.\d)?)(?:-[a-z]+)?", value)
    if match:
        return ("cc-by-sa" if match.group(1) == "by-sa" else "cc-by", match.group(2))
    if value in ("cc0", "cc-zero"):
        return ("cc0", "")
    if value in ("pd", "public domain") or value.startswith("pd-"):
        return ("pdm", "")
    return None


# ------------------------------------------------------------------ ledger


@dataclass
class ImageCandidate:
    image_id: str
    provider: str
    title: str
    url: str
    thumbnail_url: str
    source_page_url: str
    width: int
    height: int
    license: dict[str, Any]
    author: str
    attribution: str

    def public(self) -> dict[str, Any]:
        return {
            "image_id": self.image_id,
            "provider": self.provider,
            "title": self.title,
            "width": self.width,
            "height": self.height,
            "license": self.license["code"],
            "author": self.author,
            "source_page_url": self.source_page_url,
        }


@dataclass
class ImageLedger:
    items: dict[str, ImageCandidate] = field(default_factory=dict)

    def add(self, cand: ImageCandidate) -> None:
        self.items.setdefault(cand.image_id, cand)

    def get(self, image_id: str) -> ImageCandidate | None:
        return self.items.get(image_id)


# ------------------------------------------------------------------ tools


@dataclass
class WebToolsConfig:
    searxng_url: str | None
    user_agent: str = DEFAULT_USER_AGENT
    timeout: float = 30.0
    searxng_ca_file: str | None = None


# SearXNG's upstream engines (DuckDuckGo, Google, ...) throttle bursts: a run sends a dozen or more
# searches within minutes, and an engine that answers with a CAPTCHA or "too many requests" is
# suspended for a while. Searches are therefore spaced out, repeated queries are answered from a
# per-run cache, and an empty answer while engines report being blocked is retried after a pause.
SEARCH_MIN_INTERVAL = 3.0
SEARCH_REQUEST_TIMEOUT = 15.0
# web_search through AO's tunnel must answer within its 60 s per tool call.
TOOL_SEARCH_BUDGET = 45.0
TOOL_RETRY_DELAYS = (8.0, 15.0)
PATIENT_RETRY_DELAYS = (20.0, 60.0)


class WebTools:
    def __init__(self, config: WebToolsConfig) -> None:
        self.config = config
        self.ledger = ImageLedger()
        # Every page fetch_url returned in this run, by requested and final URL. The research
        # stage checks claims against it before AO's own (independent) quote verification.
        self.fetched: dict[str, str] = {}
        self._public: aiohttp.ClientSession | None = None
        self._search: aiohttp.ClientSession | None = None
        self._search_lock = asyncio.Lock()
        self._last_search = 0.0
        self._search_cache: dict[tuple[str, str], dict[str, Any]] = {}
        self.sleep = asyncio.sleep  # replaced in tests

    async def __aenter__(self) -> WebTools:
        return self

    async def __aexit__(self, *exc: Any) -> None:
        await self.close()

    def _headers(self) -> dict[str, str]:
        return {"User-Agent": self.config.user_agent}

    def public_session(self) -> aiohttp.ClientSession:
        if self._public is None:
            connector = aiohttp.TCPConnector(resolver=PublicOnlyResolver(), limit=8)
            self._public = aiohttp.ClientSession(
                connector=connector,
                headers=self._headers(),
                timeout=aiohttp.ClientTimeout(total=self.config.timeout),
            )
        return self._public

    def search_session(self) -> aiohttp.ClientSession:
        if self._search is None:
            ssl_ctx: Any = None
            if self.config.searxng_ca_file:
                import ssl

                ssl_ctx = ssl.create_default_context(cafile=self.config.searxng_ca_file)
            self._search = aiohttp.ClientSession(
                connector=aiohttp.TCPConnector(ssl=ssl_ctx) if ssl_ctx else None,
                headers=self._headers(),
                timeout=aiohttp.ClientTimeout(total=self.config.timeout),
            )
        return self._search

    async def close(self) -> None:
        for sess in (self._public, self._search):
            if sess is not None:
                await sess.close()
        self._public = self._search = None

    # ---- web_search

    async def web_search(self, query: str, max_results: int = 8, language: str = "") -> dict[str, Any]:
        """web_search as AO calls it through the Reach tunnel: finishes within TOOL_SEARCH_BUDGET.

        AO's tunnel gives a tool call 60 seconds and answers 502 after that, which made CrewAI
        cancel a whole research call (test run 17), so retries here are short.
        """
        return await self._run_search(query, max_results, language, TOOL_RETRY_DELAYS, TOOL_SEARCH_BUDGET)

    async def search_patiently(self, query: str, max_results: int = 8, language: str = "") -> dict[str, Any]:
        """The worker's own searches (topic evidence): not behind the tunnel, so they can wait longer."""
        return await self._run_search(query, max_results, language, PATIENT_RETRY_DELAYS, None)

    async def _run_search(self, query: str, max_results: int, language: str, delays: tuple[float, ...], budget: float | None) -> dict[str, Any]:
        if not self.config.searxng_url:
            raise ToolError("Web search is not configured on the worker (AOQ_SEARXNG_URL).")
        query = (query or "").strip()
        if not query:
            raise ToolError("query is required.")
        max_results = max(1, min(20, int(max_results or 8)))
        key = (query.lower(), language)
        async with self._search_lock:
            cached = self._search_cache.get(key)
            if cached is None:
                cached = await self._search_with_retry(query, language, delays, budget)
                if cached["results"] or not cached["blocked"]:  # a blocked, empty answer may be retried later
                    self._search_cache[key] = cached
        out = {"query": query, "results": cached["results"][:max_results]}
        if not out["results"] and cached["blocked"]:
            out["note"] = "Search engines are rate-limiting right now; try a different query later or fetch a known page."
        return out

    async def _search_with_retry(self, query: str, language: str, delays: tuple[float, ...], budget: float | None) -> dict[str, Any]:
        loop = asyncio.get_running_loop()
        started = loop.time()
        results: list[dict[str, Any]] = []
        blocked: list = []
        for n, delay in enumerate((0.0, *delays)):
            if n:
                # Each request can take up to SEARCH_REQUEST_TIMEOUT; stop retrying when the next
                # wait plus a request would not fit in the budget.
                if budget is not None and loop.time() - started + delay + SEARCH_REQUEST_TIMEOUT > budget:
                    break
                await self.sleep(delay)
            results, blocked = await self._search_once(query, language)
            if results or not blocked:
                break
        return {"results": results, "blocked": blocked}

    async def _search_once(self, query: str, language: str) -> tuple[list[dict[str, Any]], list]:
        loop = asyncio.get_running_loop()
        wait = SEARCH_MIN_INTERVAL - (loop.time() - self._last_search)
        if self._last_search and wait > 0:
            await self.sleep(wait)
        params = {"q": query, "format": "json", "safesearch": "1"}
        if language:
            params["language"] = language
        url = self.config.searxng_url.rstrip("/") + "/search?" + urlencode(params)
        try:
            async with self.search_session().get(url, timeout=aiohttp.ClientTimeout(total=SEARCH_REQUEST_TIMEOUT)) as res:
                if res.status != 200:
                    raise ToolError(f"Search failed with HTTP {res.status}.")
                data = await res.json(content_type=None)
        except aiohttp.ClientError as exc:
            raise ToolError(f"Search failed: {exc}") from exc
        finally:
            self._last_search = loop.time()
        results = []
        for r in data.get("results") or []:
            link = str(r.get("url") or "")
            if not link.startswith(("http://", "https://")):
                continue
            results.append({"title": strip_tags(str(r.get("title") or ""))[:300], "url": link, "snippet": strip_tags(str(r.get("content") or ""))[:500]})
            if len(results) >= 20:
                break
        return results, list(data.get("unresponsive_engines") or [])

    # ---- fetch_url

    async def _get_public(self, url: str, max_bytes: int) -> tuple[bytes, str, str]:
        """GET with manual redirects; every hop is a public URL. Returns body, content type, final URL."""
        current = check_public_url(url)
        for _ in range(MAX_REDIRECTS + 1):
            try:
                async with self.public_session().get(current, allow_redirects=False) as res:
                    if res.status in (301, 302, 303, 307, 308):
                        target = res.headers.get("Location", "")
                        current = check_public_url(str(res.url.join(URL(target))))
                        continue
                    if res.status != 200:
                        raise ToolError(f"HTTP {res.status} for {current}")
                    declared = res.content_length
                    if declared is not None and declared > max_bytes:
                        raise ToolError(f"{current} is larger than {max_bytes} bytes.")
                    body = bytearray()
                    async for chunk in res.content.iter_chunked(65536):
                        body.extend(chunk)
                        if len(body) > max_bytes:
                            raise ToolError(f"{current} is larger than {max_bytes} bytes.")
                    ctype = (res.headers.get("Content-Type") or "").split(";")[0].strip().lower()
                    return bytes(body), ctype, current
            except (aiohttp.ClientError, OSError, asyncio.TimeoutError) as exc:
                raise ToolError(f"Could not fetch {current}: {exc}") from exc
        raise ToolError(f"Too many redirects for {url}")

    async def fetch_url(self, url: str, max_chars: int = 20_000) -> dict[str, Any]:
        max_chars = max(1000, min(TEXT_MAX_CHARS, int(max_chars or 20_000)))
        body, ctype, final = await self._get_public(url, PAGE_MAX_BYTES)
        text = body.decode("utf-8", errors="replace")
        title = ""
        if ctype in ("text/html", "application/xhtml+xml") or "<html" in text[:2000].lower():
            title, text = html_to_text(text)
        elif not (ctype.startswith("text/") or ctype in ("application/json", "application/xml")):
            raise ToolError(f"{final} is {ctype or 'of unknown type'}, not a text page.")
        truncated = len(text) > max_chars
        shown = text[:max_chars]
        for key in {url, final}:
            self.fetched[key] = shown
        return {"url": final, "title": title[:300], "text": shown, "truncated": truncated}

    # ---- image_search

    async def _openverse(self, query: str, limit: int) -> list[ImageCandidate]:
        params = {"q": query, "license": ",".join(_OPENVERSE_LICENSES), "page_size": str(limit), "mature": "false"}
        body, _, _ = await self._get_public(OPENVERSE_API + "?" + urlencode(params), PAGE_MAX_BYTES)
        out = []
        for r in json.loads(body).get("results") or []:
            code = _OPENVERSE_LICENSES.get(str(r.get("license") or "").lower())
            url = str(r.get("url") or "")
            page = str(r.get("foreign_landing_url") or "")
            if not code or not url.startswith("https://") or not page.startswith(("https://", "http://")):
                continue
            lic = license_record(code, str(r.get("license_version") or ""), r.get("license_url") or None)
            author = strip_tags(str(r.get("creator") or ""))[:200]
            attribution = strip_tags(str(r.get("attribution") or ""))[:500]
            if lic["requires_attribution"] and not attribution:
                continue
            out.append(
                ImageCandidate(
                    image_id=f"openverse:{r.get('id')}",
                    provider="openverse",
                    title=strip_tags(str(r.get("title") or ""))[:200],
                    url=url,
                    thumbnail_url=str(r.get("thumbnail") or url),
                    source_page_url=page,
                    width=int(r.get("width") or 0),
                    height=int(r.get("height") or 0),
                    license=lic,
                    author=author,
                    attribution=attribution,
                )
            )
        return out

    async def _commons(self, query: str, limit: int) -> list[ImageCandidate]:
        params = {
            "action": "query",
            "format": "json",
            "generator": "search",
            "gsrsearch": f"{query} filetype:bitmap",
            "gsrnamespace": "6",
            "gsrlimit": str(limit),
            "prop": "imageinfo",
            "iiprop": "url|extmetadata|mime|size",
            "iiurlwidth": "800",
        }
        body, _, _ = await self._get_public(COMMONS_API + "?" + urlencode(params), PAGE_MAX_BYTES)
        pages = (json.loads(body).get("query") or {}).get("pages") or {}
        out = []
        for page in pages.values():
            info = (page.get("imageinfo") or [{}])[0]
            meta = info.get("extmetadata") or {}

            def m(key: str) -> str:
                return str((meta.get(key) or {}).get("value") or "")

            mapped = commons_license_code(m("License"))
            url = str(info.get("url") or "")
            if mapped is None or info.get("mime") not in IMAGE_MIMES or not url.startswith("https://"):
                continue
            code, version = mapped
            lic = license_record(code, version, m("LicenseUrl") or None)
            author = strip_tags(m("Artist"))[:200]
            title = re.sub(r"^File:", "", str(page.get("title") or ""))
            page_url = str(info.get("descriptionurl") or "")
            if lic["requires_attribution"]:
                if not author:
                    continue
                attribution = f'"{title}" by {author}, {lic["name"]}, via Wikimedia Commons'
            else:
                attribution = ""
            out.append(
                ImageCandidate(
                    image_id=f"commons:{page.get('pageid')}",
                    provider="wikimedia_commons",
                    title=title[:200],
                    url=url,
                    thumbnail_url=str(info.get("thumburl") or url),
                    source_page_url=page_url,
                    width=int(info.get("width") or 0),
                    height=int(info.get("height") or 0),
                    license=lic,
                    author=author,
                    attribution=attribution[:500],
                )
            )
        return out

    async def image_search(self, query: str, max_results: int = 12) -> dict[str, Any]:
        query = (query or "").strip()
        if not query:
            raise ToolError("query is required.")
        max_results = max(1, min(30, int(max_results or 12)))
        per = max(4, max_results)
        found: list[ImageCandidate] = []
        errors: list[str] = []
        for name, fn in (("openverse", self._openverse), ("wikimedia_commons", self._commons)):
            try:
                found.extend(await fn(query, per))
            except (ToolError, ValueError, KeyError) as exc:
                errors.append(f"{name}: {exc}")
        if not found and errors:
            raise ToolError("Image search failed: " + "; ".join(errors))
        # Interleave providers so one cannot crowd out the other.
        by_provider: dict[str, list[ImageCandidate]] = {}
        for cand in found:
            by_provider.setdefault(cand.provider, []).append(cand)
        merged: list[ImageCandidate] = []
        while len(merged) < max_results and any(by_provider.values()):
            for items in by_provider.values():
                if items and len(merged) < max_results:
                    merged.append(items.pop(0))
        for cand in merged:
            self.ledger.add(cand)
        return {"query": query, "images": [c.public() for c in merged], "errors": errors}

    async def web_image_search(self, query: str, max_results: int = 30) -> list[ImageCandidate]:
        """SearXNG's image category (all engines). Results go in the ledger as ``web:`` IDs with the
        "unchecked" license: their license is not known (D28)."""
        if not self.config.searxng_url:
            raise ToolError("Web image search needs a SearXNG URL.")
        loop = asyncio.get_running_loop()
        wait = SEARCH_MIN_INTERVAL - (loop.time() - self._last_search)
        if self._last_search and wait > 0:
            await self.sleep(wait)
        params = {"q": query, "format": "json", "safesearch": "1", "categories": "images"}
        url = self.config.searxng_url.rstrip("/") + "/search?" + urlencode(params)
        try:
            async with self.search_session().get(url, timeout=aiohttp.ClientTimeout(total=SEARCH_REQUEST_TIMEOUT)) as res:
                if res.status != 200:
                    raise ToolError(f"Image search failed with HTTP {res.status}.")
                data = await res.json(content_type=None)
        except aiohttp.ClientError as exc:
            raise ToolError(f"Image search failed: {exc}") from exc
        finally:
            self._last_search = loop.time()
        out: list[ImageCandidate] = []
        for r in data.get("results") or []:
            img = str(r.get("img_src") or "")
            page = str(r.get("url") or "")
            if not img.startswith("https://") or not page.startswith(("http://", "https://")):
                continue
            size = re.match(r"\s*(\d+)\s*[x\u00d7]\s*(\d+)", str(r.get("resolution") or ""))
            thumb = str(r.get("thumbnail_src") or "")
            cand = ImageCandidate(
                image_id="web:" + hashlib.sha1(img.encode()).hexdigest()[:12],
                provider="web:" + str(r.get("engine") or ""),
                title=strip_tags(str(r.get("title") or ""))[:300],
                url=img,
                thumbnail_url=thumb if thumb.startswith("https://") else img,
                source_page_url=page,
                width=int(size.group(1)) if size else 0,
                height=int(size.group(2)) if size else 0,
                license=dict(UNCHECKED_LICENSE),
                author="",
                attribution="Source: " + (urlparse(page).hostname or page).removeprefix("www."),
            )
            self.ledger.add(cand)
            out.append(cand)
            if len(out) >= max_results:
                break
        return out

    async def fetch_image(self, image_id: str, *, thumbnail: bool = True) -> tuple[bytes, str]:
        """Bytes of a ledger image, for the vision judge. Only ledger URLs can be fetched."""
        cand = self.ledger.get(image_id)
        if cand is None:
            raise ToolError(f"{image_id} was not returned by image_search in this run.")
        body, ctype, _ = await self._get_public(cand.thumbnail_url if thumbnail else cand.url, IMAGE_MAX_BYTES)
        if ctype not in IMAGE_MIMES:
            raise ToolError(f"{image_id} is {ctype or 'of unknown type'}, not a supported image.")
        return body, ctype


# ------------------------------------------------------------------ MCP tool table

# CrewAI (inside AO) adds a "security_context" argument to every tool call and also
# rejects arguments a tool does not declare, so undeclared, every call fails before it
# leaves AO. Declaring it as an untyped, ignored argument lets the calls through.
SECURITY_CONTEXT = {"description": "Added by the agent framework to every call; ignored."}

TOOLS: dict[str, dict[str, Any]] = {
    "web_search": {
        "description": "Search the web. Returns titles, URLs and snippets. Open pages with fetch_url before citing them.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "security_context": SECURITY_CONTEXT,
                "query": {"type": "string", "description": "Search terms."},
                "max_results": {"type": "integer", "minimum": 1, "maximum": 20, "default": 8},
                "language": {"type": "string", "description": "Optional language code, for example en or bs."},
            },
            "required": ["query"],
        },
    },
    "fetch_url": {
        "description": "Fetch one public web page and return its readable text. Use it to read a source before quoting it.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "security_context": SECURITY_CONTEXT,
                "url": {"type": "string", "description": "An http or https URL."},
                "max_chars": {"type": "integer", "minimum": 1000, "maximum": TEXT_MAX_CHARS, "default": 20000},
            },
            "required": ["url"],
        },
    },
    "image_search": {
        "description": (
            "Search Openverse and Wikimedia Commons for photos under licenses that allow reuse "
            "(CC0, public domain, CC BY, CC BY-SA). Returns image IDs with title, size, license and author."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "security_context": SECURITY_CONTEXT,
                "query": {"type": "string", "description": "What the image should show, in English."},
                "max_results": {"type": "integer", "minimum": 1, "maximum": 30, "default": 12},
            },
            "required": ["query"],
        },
    },
}


async def call_tool(tools: WebTools, name: str, args: dict[str, Any]) -> dict[str, Any]:
    if name == "web_search":
        return await tools.web_search(str(args.get("query") or ""), args.get("max_results") or 8, str(args.get("language") or ""))
    if name == "fetch_url":
        return await tools.fetch_url(str(args.get("url") or ""), args.get("max_chars") or 20_000)
    if name == "image_search":
        return await tools.image_search(str(args.get("query") or ""), args.get("max_results") or 12)
    raise ToolError(f"Unknown tool {name}.")

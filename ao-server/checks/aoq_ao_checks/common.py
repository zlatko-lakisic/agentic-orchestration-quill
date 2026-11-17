"""Shared helpers: input parsing, safe fetching, HTML to text, normalization."""

from __future__ import annotations

import html
import ipaddress
import json
import re
import socket
import unicodedata
import urllib.request
from html.parser import HTMLParser
from typing import Any
from urllib.parse import urlparse

MAX_BYTES = 5 * 1024 * 1024
TIMEOUT = 20
USER_AGENT = "ao-quill-checks/0.1 (+deterministic verification)"


def parse_input(text: str, context: str = "") -> dict[str, Any]:
    """The worker sends a task header line followed by one JSON object."""
    for raw in (text, context):
        start = (raw or "").find("{")
        if start >= 0:
            return json.loads(raw[start:])
    raise ValueError("no JSON object in input")


class _TextExtractor(HTMLParser):
    SKIP = {"script", "style", "noscript", "template", "svg"}
    BLOCK = {"p", "div", "br", "li", "h1", "h2", "h3", "h4", "h5", "h6", "tr", "section", "article", "blockquote", "pre"}

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.parts: list[str] = []
        self._skip = 0

    def handle_starttag(self, tag: str, attrs: list) -> None:
        if tag in self.SKIP:
            self._skip += 1
        elif tag in self.BLOCK:
            self.parts.append("\n")

    def handle_endtag(self, tag: str) -> None:
        if tag in self.SKIP and self._skip:
            self._skip -= 1
        elif tag in self.BLOCK:
            self.parts.append("\n")

    def handle_data(self, data: str) -> None:
        if not self._skip:
            self.parts.append(data)


def html_to_text(markup: str) -> str:
    parser = _TextExtractor()
    parser.feed(markup)
    parser.close()
    return "".join(parser.parts)


_QUOTES = str.maketrans({"\u2018": "'", "\u2019": "'", "\u201a": "'", "\u201b": "'", "\u201c": '"', "\u201d": '"', "\u201e": '"',
                         "\u00ab": '"', "\u00bb": '"', "\u2013": "-", "\u2014": "-", "\u2212": "-", "\u00a0": " ", "\u2009": " ", "\u202f": " "})


def normalize(text: str) -> str:
    """Whitespace, entity, quote and dash normalization only. Case and words are kept."""
    text = unicodedata.normalize("NFKC", html.unescape(text)).translate(_QUOTES)
    return re.sub(r"\s+", " ", text).strip()


def strip_markdown(text: str) -> str:
    """Quotes copied from fetch_url output may carry markdown syntax."""
    text = re.sub(r"!?\[([^\]]*)\]\([^)]*\)", r"\1", text)
    text = re.sub(r"(\*\*|__|`)", "", text)
    text = re.sub(r"(^|\s)[*_](\S)", r"\1\2", text)
    text = re.sub(r"(\S)[*_](\s|$)", r"\1\2", text)
    return re.sub(r"^\s*(#+|>)\s*", "", text)


def _public_ip(text: str) -> bool:
    ip = ipaddress.ip_address(text.split("%", 1)[0])
    if isinstance(ip, ipaddress.IPv6Address) and ip.ipv4_mapped is not None:
        ip = ip.ipv4_mapped
    return not (ip.is_private or ip.is_loopback or ip.is_link_local or ip.is_reserved or ip.is_multicast or ip.is_unspecified)


def _public_host(host: str) -> bool:
    try:
        infos = socket.getaddrinfo(host, None)
    except socket.gaierror:
        return False
    return bool(infos) and all(_public_ip(info[4][0]) for info in infos)


def _check_url(url: str) -> None:
    parsed = urlparse(url)
    if parsed.scheme not in ("http", "https") or not parsed.hostname:
        raise ValueError("only public http and https URLs are fetched")
    if not _public_host(parsed.hostname):
        raise ValueError("host resolves to a non-public address")


class _CheckedRedirects(urllib.request.HTTPRedirectHandler):
    """Re-check every redirect target; urllib would otherwise follow it anywhere."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: D102
        _check_url(newurl)
        return super().redirect_request(req, fp, code, msg, headers, newurl)


_OPENER = urllib.request.build_opener(_CheckedRedirects)


def fetch_text(url: str) -> tuple[str, int]:
    """Fetch a public http(s) URL and return (text, status). Raises on failure."""
    _check_url(url)
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT, "Accept": "text/html,text/plain;q=0.9,*/*;q=0.1"})
    with _OPENER.open(req, timeout=TIMEOUT) as res:  # noqa: S310 - scheme and host checked, redirects re-checked
        status = getattr(res, "status", 200)
        ctype = res.headers.get("Content-Type", "")
        body = res.read(MAX_BYTES + 1)
    if len(body) > MAX_BYTES:
        raise ValueError("page larger than 5 MiB")
    charset = "utf-8"
    m = re.search(r"charset=([\w-]+)", ctype)
    if m:
        charset = m.group(1)
    raw = body.decode(charset, errors="replace")
    if "html" in ctype or raw.lstrip()[:15].lower().startswith(("<!doctype", "<html")):
        return html_to_text(raw), status
    if ctype.startswith("text/") or not ctype:
        return raw, status
    raise ValueError(f"unsupported content type {ctype}")

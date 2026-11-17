"""WordPress Abilities REST client. The only module that talks to WordPress.

Read-only abilities run with GET (input as ``input[key]=value`` query args), the
others with POST. Inputs and outputs are validated against the shared contracts
on both sides.
"""

from __future__ import annotations

import asyncio
import logging
import ssl
from typing import Any
from urllib.parse import urlparse

import aiohttp

from pathlib import Path

from . import contracts
from .errors import WordPressError
from .settings import WorkerSettings

READONLY = {"get-config", "get-inventory", "get-profiles"}
# Every ability with a shipped contract (contracts/abilities/<name>.input.json), so the
# list cannot fall behind the plugin.
ABILITIES = frozenset(
    p.name[: -len(".input.json")] for p in (Path(contracts.__file__).parent / "contracts" / "abilities").glob("*.input.json")
)
LOG = logging.getLogger("aoq_worker")
RETRY_DELAYS = (5, 15, 30, 60)  # seconds between attempts at a WordPress call
MAX_MEDIA_BYTES = 4 * 1024 * 1024  # AO limit per image.


def flatten_query(prefix: str, value: Any) -> list[tuple[str, str]]:
    """PHP style query args: {"a": {"b": 1}} -> [("input[a][b]", "1")]."""
    out: list[tuple[str, str]] = []
    if isinstance(value, dict):
        for k, v in value.items():
            out.extend(flatten_query(f"{prefix}[{k}]", v))
    elif isinstance(value, list):
        for i, v in enumerate(value):
            out.extend(flatten_query(f"{prefix}[{i}]", v))
    elif isinstance(value, bool):
        out.append((prefix, "1" if value else "0"))
    elif value is None:
        pass
    else:
        out.append((prefix, str(value)))
    return out


class WordPressClient:
    def __init__(self, settings: WorkerSettings, session: aiohttp.ClientSession | None = None) -> None:
        self._settings = settings
        self._session = session
        self._owned = session is None
        self.base = settings.wp_url.rstrip("/")

    async def __aenter__(self) -> WordPressClient:
        if self._session is None:
            ssl_ctx: ssl.SSLContext | bool = True
            if self._settings.wp_ca_pem:
                ssl_ctx = ssl.create_default_context(cadata=self._settings.wp_ca_pem)
            elif self._settings.wp_ca_file:
                ssl_ctx = ssl.create_default_context(cafile=self._settings.wp_ca_file)
            self._session = aiohttp.ClientSession(
                auth=aiohttp.BasicAuth(self._settings.wp_user, self._settings.wp_app_password),
                connector=aiohttp.TCPConnector(ssl=ssl_ctx),
                timeout=aiohttp.ClientTimeout(total=self._settings.http_timeout),
                headers={"User-Agent": "ao-quill-worker"},
            )
        return self

    async def __aexit__(self, *exc: object) -> None:
        if self._owned and self._session is not None:
            await self._session.close()
            self._session = None

    async def call(self, ability: str, payload: dict[str, Any]) -> dict[str, Any]:
        """Call an ability. Connection errors and 502/503/504 are retried with pauses (RETRY_DELAYS):
        a proxy restarting for a moment must not end a run that took half an hour (test run 0ecca7f1)."""
        if ability not in ABILITIES:
            raise ValueError(f"unknown ability {ability}")
        assert self._session is not None
        contracts.validate(payload, f"abilities/{ability}.input", stage="wordpress")
        for delay in (*RETRY_DELAYS, None):
            try:
                return await self._call_once(ability, payload)
            except (aiohttp.ClientConnectionError, asyncio.TimeoutError, WordPressError) as exc:
                transient = not isinstance(exc, WordPressError) or exc.status in (502, 503, 504)
                if not transient or delay is None:
                    raise
                LOG.warning("WordPress %s failed (%s); retrying in %s s", ability, exc, delay)
                await asyncio.sleep(delay)
        raise AssertionError("unreachable")

    async def _call_once(self, ability: str, payload: dict[str, Any]) -> dict[str, Any]:
        assert self._session is not None
        url = f"{self.base}/wp-json/wp-abilities/v1/abilities/aoq/{ability}/run"
        if ability in READONLY:
            # nocache=1: the site's nginx caches GET responses by URL, even authenticated ones; this
            # argument is on its bypass list, so the answer is fresh and is never stored for others.
            req = self._session.get(url, params=[*flatten_query("input", payload), ("nocache", "1")])
        else:
            req = self._session.post(url, json={"input": payload})
        async with req as res:
            try:
                body = await res.json(content_type=None)
            except Exception:  # noqa: BLE001
                body = {"code": "invalid_json", "message": (await res.text())[:500]}
            if res.status >= 400:
                raise WordPressError(ability, res.status, str(body.get("code", "")), str(body.get("message", "")))
        return contracts.validate(body, f"abilities/{ability}.output", stage="wordpress")

    async def fetch_media(self, url: str) -> tuple[bytes, str]:
        """Download a media library image from this WordPress host (for vision judging)."""
        assert self._session is not None
        if urlparse(url).netloc != self._settings.wp_host:
            raise ValueError(f"refusing to fetch media from another host: {url}")
        async with self._session.get(url) as res:
            if res.status != 200:
                raise WordPressError("media", res.status, "media_fetch", url)
            mime = res.headers.get("Content-Type", "").split(";")[0].strip()
            # content.read(n) returns what has arrived so far, not the whole body; that sent cut-off
            # JPEGs to the vision model ("invalid JPEG format: short Huffman data", test run 18).
            body = bytearray()
            async for chunk in res.content.iter_chunked(65536):
                body.extend(chunk)
                if len(body) > MAX_MEDIA_BYTES:
                    raise ValueError(f"media item larger than {MAX_MEDIA_BYTES} bytes: {url}")
            declared = res.content_length
        if declared is not None and len(body) != declared:
            raise ValueError(f"media item cut short ({len(body)} of {declared} bytes): {url}")
        return bytes(body), mime

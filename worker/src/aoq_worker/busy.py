"""Shared-worker busy presence: broadcast site + task to every configured WordPress site.

One worker serves main + sites.d; while run.lock is held for a long job, siblings stop
getting claim-run heartbeats. This module POSTs report-worker-status to all of them so
Status shows Busy instead of Not checking in (D34).
"""

from __future__ import annotations

import asyncio
import logging
import os
import re
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import aiohttp

LOG = logging.getLogger("aoq_worker")

# How often to re-broadcast while a job runs.
INTERVAL_SEC = 60

_ENV_LINE = re.compile(r"^([A-Za-z_][A-Za-z0-9_]*)=(.*)$")


def _unquote(value: str) -> str:
    value = value.strip()
    if len(value) >= 2 and value[0] == value[-1] and value[0] in ("'", '"'):
        return value[1:-1]
    return value


def _parse_env_file(path: Path) -> dict[str, str]:
    out: dict[str, str] = {}
    if not path.is_file():
        return out
    for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        m = _ENV_LINE.match(line)
        if not m:
            continue
        out[m.group(1)] = _unquote(m.group(2))
    return out


@dataclass(frozen=True)
class SiteEndpoint:
    name: str
    wp_url: str
    wp_user: str
    wp_app_password: str
    wp_ca_file: str | None = None


def site_label() -> str:
    return (os.environ.get("AOQ_SITE_LABEL") or "main").strip() or "main"


def load_sibling_endpoints() -> list[SiteEndpoint]:
    """Main worker.env plus every sites.d/*.env when AOQ_MAIN_ENV / AOQ_SITES_DIR are set."""
    main_env = os.environ.get("AOQ_MAIN_ENV") or ""
    sites_dir = os.environ.get("AOQ_SITES_DIR") or ""
    found: list[SiteEndpoint] = []
    seen_urls: set[str] = set()

    def add(name: str, env: dict[str, str]) -> None:
        url = (env.get("AOQ_WP_URL") or "").rstrip("/")
        user = env.get("AOQ_WP_USER") or ""
        password = env.get("AOQ_WP_APP_PASSWORD") or ""
        if not url or not user or not password:
            return
        key = url.lower()
        if key in seen_urls:
            return
        seen_urls.add(key)
        found.append(
            SiteEndpoint(
                name=name,
                wp_url=url,
                wp_user=user,
                wp_app_password=password,
                wp_ca_file=env.get("AOQ_WP_CA_FILE") or None,
            )
        )

    if main_env:
        add("main", _parse_env_file(Path(main_env)))
    if sites_dir:
        for path in sorted(Path(sites_dir).glob("*.env")):
            add(path.stem, _parse_env_file(path))

    # Always include the current process settings so a single-site install still works.
    cur_url = (os.environ.get("AOQ_WP_URL") or "").rstrip("/")
    cur_user = os.environ.get("AOQ_WP_USER") or ""
    cur_pass = os.environ.get("AOQ_WP_APP_PASSWORD") or ""
    if cur_url and cur_user and cur_pass and cur_url.lower() not in seen_urls:
        found.append(
            SiteEndpoint(
                name=site_label(),
                wp_url=cur_url,
                wp_user=cur_user,
                wp_app_password=cur_pass,
                wp_ca_file=os.environ.get("AOQ_WP_CA_FILE") or None,
            )
        )
    return found


def busy_payload(site: str, task: str, detail: str = "", started_at: float | None = None) -> dict[str, Any]:
    started = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(started_at or time.time()))
    now = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
    return {
        "site": site[:64],
        "task": task[:64],
        "detail": (detail or "")[:300],
        "started_at": started,
        "updated_at": now,
    }


async def _post_status(endpoint: SiteEndpoint, body: dict[str, Any], timeout: float = 30.0) -> None:
    url = f"{endpoint.wp_url}/wp-json/wp-abilities/v1/abilities/aoq/report-worker-status/run"
    ssl_arg: bool | str = True
    if endpoint.wp_ca_file and Path(endpoint.wp_ca_file).is_file():
        ssl_arg = endpoint.wp_ca_file
    try:
        timeout_cfg = aiohttp.ClientTimeout(total=timeout)
        async with aiohttp.ClientSession(
            auth=aiohttp.BasicAuth(endpoint.wp_user, endpoint.wp_app_password),
            timeout=timeout_cfg,
            headers={"User-Agent": "ao-quill-worker"},
        ) as session:
            async with session.post(url, json={"input": body}, ssl=ssl_arg) as res:
                if res.status >= 400:
                    text = (await res.text())[:200]
                    LOG.warning("busy status to %s failed: HTTP %s %s", endpoint.name, res.status, text)
    except Exception as exc:  # noqa: BLE001 - never fail the job for a status POST
        LOG.warning("busy status to %s failed: %s", endpoint.name, exc)


async def broadcast(busy: dict[str, Any] | None) -> None:
    """POST busy (or null to clear) to every configured WordPress endpoint."""
    endpoints = load_sibling_endpoints()
    if not endpoints:
        return
    body = {"busy": busy}
    await asyncio.gather(*[_post_status(ep, body) for ep in endpoints])


class BusyHeartbeat:
    """Broadcast busy status at start, every INTERVAL_SEC, and clear on exit."""

    def __init__(self, task: str, detail: str = "", site: str | None = None) -> None:
        self.site = site or site_label()
        self.task = task
        self.detail = detail
        self.started_at = time.time()
        self._stop = asyncio.Event()
        self._loop: asyncio.Task | None = None

    async def __aenter__(self) -> BusyHeartbeat:
        await broadcast(busy_payload(self.site, self.task, self.detail, self.started_at))
        self._loop = asyncio.create_task(self._tick())
        return self

    async def __aexit__(self, *exc: object) -> None:
        self._stop.set()
        if self._loop is not None:
            self._loop.cancel()
            try:
                await self._loop
            except asyncio.CancelledError:
                pass
        await broadcast(None)

    def update(self, detail: str) -> None:
        self.detail = detail

    async def _tick(self) -> None:
        while True:
            try:
                await asyncio.wait_for(self._stop.wait(), timeout=INTERVAL_SEC)
                return
            except asyncio.TimeoutError:
                await broadcast(busy_payload(self.site, self.task, self.detail, self.started_at))

"""A WordPress or proxy hiccup must not end or orphan a run (test run 0ecca7f1)."""

from __future__ import annotations

import aiohttp
import pytest

from aoq_worker import wp_client
from aoq_worker.errors import GateFailure, WordPressError
from aoq_worker.reporter import Reporter
from aoq_worker.wp_client import WordPressClient


class Settings:
    wp_url = "https://wordpress.example.com"
    wp_host = "wordpress.example.com"


async def test_wordpress_calls_retry_connection_errors(monkeypatch):
    monkeypatch.setattr(wp_client.asyncio, "sleep", lambda s: _noop())
    client = WordPressClient.__new__(WordPressClient)
    client._session = object()
    calls = []

    async def once(ability, payload):
        calls.append(ability)
        if len(calls) < 3:
            raise aiohttp.ClientConnectionError("Connect call failed")
        return {"ok": True}

    client._call_once = once
    monkeypatch.setattr(wp_client.contracts, "validate", lambda *a, **k: None)
    assert await client.call("report-progress", {}) == {"ok": True}
    assert len(calls) == 3


async def test_real_errors_are_not_retried(monkeypatch):
    client = WordPressClient.__new__(WordPressClient)
    client._session = object()
    calls = []

    async def once(ability, payload):
        calls.append(ability)
        raise WordPressError(ability, 409, "aoq_run_closed", "closed")

    client._call_once = once
    monkeypatch.setattr(wp_client.contracts, "validate", lambda *a, **k: None)
    with pytest.raises(WordPressError):
        await client.call("report-stage", {})
    assert len(calls) == 1


async def _noop():
    return None


async def test_a_failed_report_does_not_hide_the_stage_error():
    class DownWP:
        async def call(self, name, payload):
            raise aiohttp.ClientConnectionError("refused")

    reporter = Reporter("3f2b8c1e-9a4d-4c7e-8b1a-2d3e4f5a6b7c", DownWP(), dry_run=False)
    with pytest.raises(GateFailure) as err:
        async with reporter.stage("draft"):
            raise GateFailure("draft", "The post cites no verified claim.")
    assert "no verified claim" in err.value.reason

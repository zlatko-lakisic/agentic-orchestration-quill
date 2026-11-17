"""During a run the worker reports at least once a minute, even with nothing new to send."""

from __future__ import annotations

from aoq_worker import contracts, reporter


class WP:
    def __init__(self):
        self.calls = []

    async def call(self, name, payload):
        self.calls.append((name, payload))
        return {"ok": True}


async def test_a_quiet_run_still_sends_a_heartbeat(monkeypatch):
    wp = WP()
    rep = reporter.Reporter("11111111-2222-4333-8444-555555555555", wp, dry_run=False)
    await rep.flush()
    assert wp.calls == []  # within the minute: nothing new, nothing sent
    monkeypatch.setattr(reporter, "HEARTBEAT_SECONDS", 0.0)
    await rep.flush()
    name, payload = wp.calls[-1]
    assert name == "report-progress" and payload["output"] == [] and payload["heartbeat"] is True
    assert contracts.errors(payload, "abilities/report-progress.input") == []

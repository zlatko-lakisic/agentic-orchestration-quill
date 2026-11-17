"""Stage reporting: every stage attempt becomes one aoq/report-stage call (or, in a
dry run, one entry in the local stage log).

Live progress (step counts and AO output as it arrives) is batched and sent with
aoq/report-progress every few seconds; each report also extends the run lease, so a
slow AO never expires a run that is still moving."""

from __future__ import annotations

import asyncio
import contextlib
import logging
import time
from dataclasses import dataclass, field
from datetime import datetime, timezone
from typing import Any, AsyncIterator

from .errors import RunFailure, WordPressError

LOG = logging.getLogger("aoq_worker.progress")
FLUSH_SECONDS = 3.0
HEARTBEAT_SECONDS = 60.0  # at least one report a minute during a run (WordPress: a run silent for 10 minutes lost its worker)
MAX_TEXT = 20000  # per output entry (contract limit)
MAX_ENTRIES = 500  # per report (contract limit)


@dataclass
class StageRecord:
    stage: str
    language: str | None
    attempt: int
    started_at: str
    agents: list[dict[str, Any]] = field(default_factory=list)
    scores: list[dict[str, Any]] = field(default_factory=list)
    revision_count: int = 0
    detail: dict[str, Any] = field(default_factory=dict)
    # Set when an attempt failed but the run continues (a QA round that leads to a revision).
    soft_failure: str | None = None

    def add_calls(self, records: list[Any]) -> None:
        for r in records:
            entry = {"role": r.role, "agent_id": r.agent_id, "model": r.model}
            if entry not in self.agents:
                self.agents.append(entry)

    def score(self, check: str, score: float, threshold: float | None, passed: bool) -> None:
        self.scores.append({"check": check, "score": round(float(score), 4), "threshold": threshold, "passed": bool(passed)})


class Reporter:
    def __init__(self, run_id: str, wp: Any, *, dry_run: bool) -> None:
        self.run_id = run_id
        self.wp = wp
        self.dry_run = dry_run
        self.log: list[dict[str, Any]] = []
        self.step = 0
        self.steps = 1
        self.label = ""
        self._out: list[dict[str, str]] = []
        self._dirty = False
        self._stopped = False
        self._last_sent = time.monotonic()

    # ------------------------------------------------------------ live progress

    def progress(self, step: int, steps: int, label: str) -> None:
        self.step, self.steps, self.label = max(0, step), max(1, steps), label[:200]
        self._dirty = True
        LOG.info("step %d/%d %s", self.step, self.steps, self.label)

    def output(self, task: str, agent: str, text: str) -> None:
        if not text:
            return
        task, agent, step = task[:200], agent[:200], self.label[:64]
        last = self._out[-1] if self._out else None
        if last and last["step"] == step and last["task"] == task and last["agent"] == agent and len(last["text"]) + len(text) <= MAX_TEXT:
            last["text"] += text
        else:
            for i in range(0, len(text), MAX_TEXT):
                self._out.append({"step": step, "task": task, "agent": agent, "text": text[i : i + MAX_TEXT]})
        self._dirty = True

    async def flush(self) -> None:
        if self.dry_run:
            self._out.clear()
            self._dirty = False
            return
        if self._stopped:
            return
        # Nothing new: still report once a minute, so WordPress can tell a quiet AO call (often 5 to
        # 15 minutes without output) from a worker that stopped.
        if not self._dirty and time.monotonic() - self._last_sent < HEARTBEAT_SECONDS:
            return
        batch = self._out[:MAX_ENTRIES]
        payload = {"run_id": self.run_id, "step": self.step, "steps": self.steps, "label": self.label, "output": batch, "heartbeat": True}
        try:
            await self.wp.call("report-progress", payload)
        except WordPressError as exc:
            if exc.status == 409:  # run closed or lease gone: nothing more to report
                self._stopped = True
            LOG.warning("progress report failed: %s", exc)
            return
        except Exception:  # noqa: BLE001 - progress is best effort; the run goes on
            LOG.warning("progress report failed", exc_info=True)
            return
        del self._out[: len(batch)]
        self._dirty = bool(self._out)
        self._last_sent = time.monotonic()

    async def run_flusher(self) -> None:
        with contextlib.suppress(asyncio.CancelledError):
            while True:
                await asyncio.sleep(FLUSH_SECONDS)
                await self.flush()

    @contextlib.asynccontextmanager
    async def stage(self, stage: str, *, language: str | None = None, attempt: int = 1) -> AsyncIterator[StageRecord]:
        rec = StageRecord(stage, language, attempt, datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"))
        started = time.monotonic()
        try:
            yield rec
        except RunFailure as exc:
            await self._send_quietly(rec, started, "failed", exc.reason)
            raise
        except Exception as exc:  # noqa: BLE001
            await self._send_quietly(rec, started, "failed", f"{type(exc).__name__}: {exc}")
            raise
        if rec.soft_failure:
            await self._send(rec, started, "failed", rec.soft_failure)
        else:
            await self._send(rec, started, "ok", None)

    async def _send_quietly(self, rec: StageRecord, started: float, status: str, reason: str | None) -> None:
        """Report a failed stage; if WordPress cannot be reached, log it and let the stage's own error
        end the run (raising here hid the real reason, and the run never finished)."""
        try:
            await self._send(rec, started, status, reason)
        except Exception:  # noqa: BLE001
            LOG.warning("could not report the failed stage %s (%s)", rec.stage, reason, exc_info=True)

    async def _send(self, rec: StageRecord, started: float, status: str, reason: str | None) -> None:
        payload = {
            "run_id": self.run_id,
            "stage": rec.stage,
            "language": rec.language,
            "attempt": rec.attempt,
            "status": status,
            "started_at": rec.started_at,
            "duration_ms": int((time.monotonic() - started) * 1000),
            "agents": rec.agents,
            "scores": rec.scores,
            "revision_count": rec.revision_count,
            "failure_reason": (reason or "")[:4000] or None,
            "detail": rec.detail,
        }
        self.log.append(payload)
        if not self.dry_run:
            await self.wp.call("report-stage", payload)

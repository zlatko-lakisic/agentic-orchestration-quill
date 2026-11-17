"""Helpers shared by stages: gates and prompt formatting."""

from __future__ import annotations

import json
from datetime import datetime, timezone
from typing import Any

from ..errors import GateFailure


def gate(condition: bool, stage: str, reason: str) -> None:
    """Hard gate: a false condition ends the run."""
    if not condition:
        raise GateFailure(stage, reason)


def dumps(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, indent=1)


def clip(text: str, limit: int) -> str:
    text = " ".join(str(text or "").split())
    return text if len(text) <= limit else text[: limit - 1] + "…"


def now_iso() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def parse_iso(value: str) -> datetime:
    return datetime.fromisoformat(value.replace("Z", "+00:00"))


def change_ratio(old: list[str] | set[str], new: list[str] | set[str]) -> float:
    a, b = set(old), set(new)
    union = a | b
    return len(a ^ b) / len(union) if union else 0.0

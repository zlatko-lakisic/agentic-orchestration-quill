"""Errors that end a run. Every one of them fails the run with nothing written."""

from __future__ import annotations


class RunFailure(Exception):
    """Base class: the run stops, the reason goes to the run log."""

    def __init__(self, stage: str, reason: str) -> None:
        super().__init__(f"{stage}: {reason}")
        self.stage = stage
        self.reason = reason


class GateFailure(RunFailure):
    """A hard gate rejected a stage result."""


class ContractError(RunFailure):
    """Data did not match its JSON Schema contract."""


class AOError(RunFailure):
    """AO refused, timed out, or returned something unusable."""


class WordPressError(Exception):
    """The WordPress Abilities API returned an error."""

    def __init__(self, ability: str, status: int, code: str, message: str) -> None:
        super().__init__(f"{ability}: HTTP {status} {code}: {message}")
        self.ability = ability
        self.status = status
        self.code = code
        self.message = message

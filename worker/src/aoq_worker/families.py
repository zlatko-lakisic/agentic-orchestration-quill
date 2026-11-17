"""Provider family and vision detection from a catalog entry. Plain string rules;
the plugin uses the family to keep QA on a different family than the drafter."""

from __future__ import annotations

import re

_VISION = re.compile(r"(vl\b|vl[:\-]|vision|llava|moondream|minicpm-v|gemma[34]|gpt-4o|gpt-4\.1|gpt-5|claude-3|claude-(sonnet|opus|haiku)|pixtral|qwen.*vl)", re.I)


def provider_family(entry_type: str | None, model: str | None, agent_id: str = "") -> str:
    t = (entry_type or "").strip().lower()
    m = (model or "").strip().lower()
    if t == "deterministic":
        return "deterministic"
    if t == "anthropic" or m.startswith("claude") or "anthropic/" in m:
        return "anthropic"
    if t == "openai" or re.match(r"^(openai/)?(gpt|o[1-9])", m):
        return "openai"
    base = m.split("/")[-1].split(":")[0]
    match = re.match(r"[a-z]+", base)
    if match:
        return match.group(0)
    match = re.match(r"(?:client\.)?(?:ollama_|hf_)?([a-z]+)", agent_id.lower())
    return match.group(1) if match else "unknown"


def is_vision(model: str | None, entry_type: str | None = None) -> bool:
    if (entry_type or "").lower() == "deterministic":
        return False
    return bool(_VISION.search(model or ""))

"""A site written only by AO Quill borrows the writing style of the company's main site."""

from __future__ import annotations

from aoq_worker import contracts
from aoq_worker.pipeline import run_once

from .fakes import FakeBridge, FakeWP, deps, good_responses, inventory

MAIN_SITE = {
    "source": "main.example.com",
    "saved_at": "2026-11-16T03:30:00Z",
    "profiles": [
        {"language": "en", "data": {"language": "en", "voice": "Warm local guide (borrowed)", "person": "second", "formality": "neutral",
                                    "avg_sentence_words": 16, "typical_length_words": 900, "structure": "Intro, H2 sections",
                                    "heading_habits": "Short", "formatting_habits": "Few lists", "phrases_to_avoid": []}},
        {"language": "bs", "data": {"language": "bs", "voice": "Topli lokalni vodič (posuđeno)", "person": "second", "formality": "neutral",
                                    "avg_sentence_words": 15, "typical_length_words": 850, "structure": "Uvod",
                                    "heading_habits": "Kratki", "formatting_habits": "Malo listi", "phrases_to_avoid": []}},
    ],
}


def ai_only_inventory() -> dict:
    inv = inventory()
    for p in inv["posts"]:
        p["ai_generated"] = True  # no human posts at all: a fully AI-written site
    return inv


async def test_a_site_with_no_human_posts_uses_the_borrowed_style():
    wp = FakeWP(inv=ai_only_inventory(), profiles={"site_profile": None, "style_profiles": [], "borrowed_style": MAIN_SITE})
    assert contracts.errors(wp.profiles, "abilities/get-profiles.output") == []
    bridge = FakeBridge(good_responses())
    outcome = await run_once(deps(wp, bridge))
    assert outcome.status == "succeeded", outcome.reason
    assert not [c for c in bridge.calls if c["text"].startswith("AO Quill task: style_profile")]
    detail = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "style_profile"][0]["detail"]
    assert detail == {"en": "borrowed from main.example.com", "bs": "borrowed from main.example.com"}
    draft_call = next(c for c in bridge.calls if c["text"].startswith("AO Quill task: draft"))
    assert "Warm local guide (borrowed)" in draft_call["context"]


async def test_a_site_with_enough_human_posts_keeps_its_own_style():
    wp = FakeWP(profiles={"site_profile": None, "style_profiles": [], "borrowed_style": MAIN_SITE})
    bridge = FakeBridge(good_responses())
    outcome = await run_once(deps(wp, bridge))
    assert outcome.status == "succeeded", outcome.reason
    assert any(c["text"].startswith("AO Quill task: style_profile:en") for c in bridge.calls)
    detail = [p for n, p in wp.calls if n == "report-stage" and p["stage"] == "style_profile"][0]["detail"]
    assert "borrowed" not in str(detail)


async def test_no_human_posts_and_nothing_borrowed_still_stops_the_run():
    wp = FakeWP(inv=ai_only_inventory())
    outcome = await run_once(deps(wp, FakeBridge(good_responses())))
    assert outcome.status == "failed" and "borrowed" in outcome.reason

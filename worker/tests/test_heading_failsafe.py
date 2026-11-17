"""Mechanical last-chance after QA revision loops."""

from __future__ import annotations

import asyncio
from types import SimpleNamespace
from unittest.mock import AsyncMock

from aoq_worker.stages import draft as draft_stage
from aoq_worker.stages import qa as qa_stage


def test_mechanical_last_chance_applies_heading_fixes():
    async def _run():
        html = (
            "<h1>Bad</h1><p>Intro.</p>"
            "<h2>Tips</h2><p>One.</p>"
            "<h2>Tips</h2><p>Two.</p>"
            "<h2>My Title</h2><p>Fold me.</p>"
        )
        draft = {
            "title": "My Title",
            "excerpt": "x" * 40,
            "seo_title": "My Title",
            "seo_description": "d" * 140,
            "focus_keyword": "title",
            "slug": "my-title",
            "content_html": html,
            "images": [],
        }
        ctx = SimpleNamespace(
            draft=draft,
            inv=SimpleNamespace(primary="en", published_ids=lambda lang: set()),
            claims={"c1": {"id": "c1"}},
            inline_keys=lambda: [],
            config={"thresholds": {"structure": 0.8}, "originality_max_overlap": 0.3},
            sources=[],
            style_profiles={},
            site_profile={},
            scores=[],
        )
        # Avoid claim/link gates by using content that already has a claim marker after place_images.
        ctx.draft["content_html"] = draft_stage.apply_heading_fixes(
            "<p>Fact.{{{{c:c1}}}}</p><h2>Tips</h2><p>One.{{{{c:c1}}}}</p><h2>Tips</h2><p>Two.</p><h2>My Title</h2><p>Fold.</p>",
            title="My Title",
        )[0]
        rec = SimpleNamespace(detail={}, stage="qa", score=lambda *a, **k: None)
        draft_stage.fit_fields = AsyncMock(return_value=None)  # type: ignore[method-assign]
        qa_stage.primary_passes = AsyncMock(  # type: ignore[method-assign]
            return_value=[qa_stage.PassResult("structure", 1.0, 0.8, True, [])]
        )
        results = await qa_stage.mechanical_last_chance(ctx, rec)
        assert results[0].passed
        assert (rec.detail.get("revision") or {}).get("failsafe") == "mechanical_last_chance"
        assert ctx.draft["content_html"].lower().count("<h2") == 1
        assert "Fold" in ctx.draft["content_html"]

    asyncio.run(_run())

"""language-site: translations keep the markup exactly; AO is asked again, then text by text."""

from __future__ import annotations

import json
from types import SimpleNamespace

from aoq_worker.language_site import Progress, Translator, chunks, skeleton


class FakeClient:
    def __init__(self, html_answers):
        self.html_answers = list(html_answers)
        self.calls = []

    async def json_call(self, role, task, prompt, context, schema):
        self.calls.append((task, schema, json.loads(context)))
        if schema == "html_translation":
            return SimpleNamespace(data={"html": self.html_answers.pop(0)})
        items = json.loads(context)["items"]
        return SimpleNamespace(data={"items": [{"key": i["key"], "text": "DE " + i["text"].strip()} for i in items]})


def test_skeleton_and_chunks_keep_tags_whole():
    html = '<!-- wp:paragraph -->\n<p>Hello <strong>Mostar</strong> [contact-form-7 id="1"]</p>\n<!-- /wp:paragraph -->'
    assert skeleton(html) == ["<!-- wp:paragraph -->", "<p>", "<strong>", "</strong>", '[contact-form-7 id="1"]', "</p>", "<!-- /wp:paragraph -->"]
    long = "\n\n".join(["<p>" + "word " * 200 + "</p>"] * 8)
    parts = chunks(long, 3500)
    assert len(parts) > 1 and "".join(parts) == long and all(p.count("<p>") == p.count("</p>") for p in parts)


async def test_html_with_the_same_markup_is_taken_as_it_is():
    client = FakeClient(["<p>Hallo <strong>Mostar</strong></p>"])
    tr = Translator(client, "German", Progress(["translate"]))
    assert await tr.html("k", "<p>Hello <strong>Mostar</strong></p>", "x") == "<p>Hallo <strong>Mostar</strong></p>"


async def test_changed_markup_is_retried_then_translated_text_by_text():
    client = FakeClient(["<p>Hallo Mostar</p>", "<div>Hallo</div>"])
    tr = Translator(client, "German", Progress(["translate"]))
    out = await tr.html("k", "<p>Hello <strong>Mostar</strong> today</p>", "x")
    assert skeleton(out) == ["<p>", "<strong>", "</strong>", "</p>"]
    assert out == "<p>DE Hello <strong>DE Mostar</strong> DE today</p>"
    assert [c[1] for c in client.calls] == ["html_translation", "html_translation", "string_batch"]
    assert "previous_problem" in client.calls[1][2]


class DroppingClient:
    """TranslateGemma through a bridge that drops: ``drops`` calls fail before one gets through."""

    def __init__(self, drops):
        self.drops = drops
        self.agents = {"translator": "client.aoq_translator"}
        self.bridge = SimpleNamespace(is_active=False)
        self.calls = 0

    async def text_call(self, role, task, prompt, context=""):
        self.calls += 1
        if self.drops > 0:
            self.drops -= 1
            raise RuntimeError("translator: client.aoq_translator failed: Session bridge is not active — cannot run client.* agents")
        return "<aoq-text>{0}Hallo {1}Mostar{2}{3}</aoq-text>", None


async def test_dropped_bridge_is_reconnected_and_gemma_kept():
    client = DroppingClient(drops=2)
    reconnects = []

    async def recover():
        reconnects.append(1)
        client.bridge = SimpleNamespace(is_active=True)

    tr = Translator(client, "German", Progress(["translate"]), recover=recover, waits=(0, 0, 0))
    assert await tr.html("k", "<p>Hello <strong>Mostar</strong></p>", "x") == "<p>Hallo <strong>Mostar</strong></p>"
    assert len(reconnects) == 2 and tr.gemma


async def test_bridge_that_stays_down_raises_an_outage():
    import pytest

    from aoq_worker.language_site import AOOutage

    client = DroppingClient(drops=99)

    async def recover():
        raise RuntimeError("AO is not reachable")

    tr = Translator(client, "German", Progress(["translate"]), recover=recover, waits=(0, 0))
    with pytest.raises(AOOutage):
        await tr.html("k", "<p>Hello <strong>Mostar</strong></p>", "x")
    assert client.calls == 3 and tr.gemma

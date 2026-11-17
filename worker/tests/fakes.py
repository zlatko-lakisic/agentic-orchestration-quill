"""Fake WordPress and fake AO bridge with recorded responses for a two-language site."""

from __future__ import annotations

import copy
import json
import re
from typing import Any, Callable

from aoq_worker import contracts
from aoq_worker.pipeline import Dependencies

RUN_ID = "3f2b8c1e-9a4d-4c7e-8b1a-2d3e4f5a6b7c"
WRITE_ABILITIES = {"claim-run", "save-profiles", "report-stage", "commit-post", "finish-run", "save-checkpoint"}


def _post(blog: int, pid: int, lang: str, title: str, *, ai: bool = False, status: str = "publish") -> dict:
    return {
        "key": f"{blog}:{pid}",
        "blog_id": blog,
        "id": pid,
        "language": lang,
        "title": title,
        "excerpt": f"Excerpt of {title}",
        "content": f"<p>{title} body text written by a person about Riverton and the valley.</p>",
        "url": f"https://wordpress.example.com/{'' if lang == 'en' else lang + '/'}p{pid}/",
        "status": status,
        "categories": [3 if lang == "en" else 4],
        "tags": [],
        "date": f"2026-0{1 + pid % 9}-01T10:00:00Z",
        "modified": "2026-10-01T10:00:00Z",
        "ai_generated": ai,
        "translation_group": None,
    }


def inventory() -> dict:
    langs = [
        {"code": "en", "locale": "en_US", "name": "English", "blog_id": 1, "url": "https://wordpress.example.com/", "primary": True, "excluded": False},
        {"code": "bs", "locale": "bs_BA", "name": "Bosnian", "blog_id": 3, "url": "https://wordpress.example.com/ba/", "primary": False, "excluded": False},
        {"code": "de", "locale": "de_DE", "name": "German", "blog_id": 5, "url": "https://wordpress.example.com/de/", "primary": False, "excluded": True},
    ]
    posts = [_post(1, 10 + i, "en", f"English post {i}") for i in range(6)] + [_post(3, 20 + i, "bs", f"Bosanski post {i}") for i in range(5)]
    posts.append(_post(1, 99, "en", "Earlier AI post", ai=True))
    terms = [
        {"blog_id": 1, "language": "en", "taxonomy": "category", "id": 3, "name": "Travel", "slug": "travel", "parent": 0, "count": 6, "translations": []},
        {"blog_id": 1, "language": "en", "taxonomy": "post_tag", "id": 10, "name": "Stone Bridge", "slug": "stone-bridge", "parent": 0, "count": 4, "translations": []},
        {"blog_id": 1, "language": "en", "taxonomy": "post_tag", "id": 11, "name": "Silver", "slug": "silver", "parent": 0, "count": 2, "translations": []},
        {"blog_id": 3, "language": "bs", "taxonomy": "category", "id": 4, "name": "Putovanja", "slug": "putovanja", "parent": 0, "count": 5, "translations": []},
        {"blog_id": 3, "language": "bs", "taxonomy": "post_tag", "id": 12, "name": "Stari most", "slug": "stari-most", "parent": 0, "count": 3, "translations": []},
    ]
    media = [
        {
            "key": f"1:{mid}", "blog_id": 1, "id": mid, "language": "en",
            "url": f"https://wordpress.example.com/wp-content/uploads/2026/01/photo-{mid}.jpg",
            "preview_url": f"https://wordpress.example.com/wp-content/uploads/2026/01/photo-{mid}-768x512.jpg",
            "alt": f"Photo {mid}", "caption": "", "title": f"photo-{mid}", "mime": "image/jpeg",
            "filesize": 120000, "width": 1600, "height": 1067, "date": f"2026-01-0{mid - 500}T10:00:00Z",
        }
        for mid in (501, 502, 503)
    ]
    return {"languages": langs, "posts": posts, "terms": terms, "media": media}


def config() -> dict:
    agents = {
        "site_profile": "client.aoq_analyst", "style_profile": "client.aoq_analyst", "topics": "client.aoq_analyst",
        "novelty": "client.aoq_analyst", "research": "client.aoq_analyst", "structurer": "client.aoq_structurer",
        "outline": "client.aoq_analyst", "draft": "client.aoq_writer", "taxonomy": "client.aoq_analyst",
        "image_shortlist": "client.aoq_analyst", "image_vision": "client.aoq_vision", "qa_grammar": "client.aoq_editor",
        "qa_facts": "client.aoq_editor", "qa_style": "client.aoq_editor", "qa_seo": "client.aoq_editor",
        "translate": "client.aoq_writer", "back_translate": "client.aoq_editor", "translation_qa": "client.aoq_editor",
        "quote_verifier": "aoq_quote_verifier", "text_checks": "aoq_text_checks",
    }
    inv = inventory()
    return {
        "run_id": RUN_ID,
        "site_brief": "A travel guide to Riverton and the valley.",
        "publish_mode": "pending",
        "timezone": "Europe/Sarajevo",
        "languages": inv["languages"],
        "primary_language": "en",
        "agents": agents,
        "mcp": {"search": "search_searxng", "fetch": "fetch_url", "stock_images": None},
        "thresholds": {"grammar": 0.8, "facts": 0.9, "style": 0.7, "seo": 0.7, "structure": 0.8,
                       "translation_grammar": 0.8, "translation_fidelity": 0.85, "translation_style": 0.7},
        "originality_max_overlap": 0.15,
        "max_revision_loops": 2,
        "min_words": 300,
        "trend_seeds": [],
        "trend_geos": [],
        "min_verified_claims": 4,
        "min_human_posts_for_style": 5,
        "inline_image_count": 1,
        "image_sources": [{"id": "media_library", "enabled": True, "order": 1}, {"id": "stock", "enabled": False, "order": 2}, {"id": "ai_generated", "enabled": False, "order": 3}],
        "media_candidate_limit": 400,
        "image_min_score": 6,
        "featured_min_score": 8,
        "fallback_image": None,
        "instagram": {"enabled": False, "languages": [], "max_hashtags": 15, "link_hint": "Link in bio.", "caption_style": ""},
        "ao_priority": "background",
        "stage_timeout_seconds": 60,
    }


DRAFT_HTML = (
    "<p>Riverton's stone bridge is the city's best known sight.{{c:c1}} It crosses the Silver river.{{c:c2}}</p>"
    "<h2>When to go</h2><p>Early morning is quiet, and the light is soft on the stone.{{c:c3}}</p>"
    "<!-- aoq:image inline-1 -->"
    "<h2>Getting there</h2><p>Read our <a data-aoq-post=\"10\">walking guide</a> before you go. The bridge was rebuilt in 2004.{{c:c4}}</p>"
    # Practical, fact-free advice so the fixture reaches the length check's minimum (630 of 900 words).
    + "<h2>Tips for your visit</h2>" + "<p>" + " ".join(["Bring comfortable shoes, water and a light jacket, take your time, and plan a short break in a quiet place nearby."] * 30) + "</p>"
)
TRANSLATED_HTML = (
    "<p>Stari most je najpoznatija znamenitost grada.{{c:c1}} Prelazi rijeku Neretvu.{{c:c2}}</p>"
    "<h2>Kada ići</h2><p>Rano jutro je mirno, a svjetlo je meko na kamenu.{{c:c3}}</p>"
    "<!-- aoq:image inline-1 -->"
    "<h2>Kako doći</h2><p>Pročitajte naš <a data-aoq-post=\"20\">vodič</a> prije polaska. Most je obnovljen 2004.{{c:c4}}</p>"
)


def good_responses() -> dict[str, Any]:
    """Task ID (or prefix before ':') -> JSON response."""
    return {
        "site_profile": {"summary": "Travel guide to Riverton.", "audience": "Visitors to the valley.", "themes": ["Stone Bridge", "Day trips"], "content_gaps": ["Winter travel"], "avoid": ["Politics"]},
        "style_profile:en": {"language": "en", "voice": "Friendly local guide", "person": "second", "formality": "neutral", "avg_sentence_words": 16, "typical_length_words": 900, "structure": "Intro then H2 sections", "heading_habits": "Short H2s", "formatting_habits": "Few lists", "phrases_to_avoid": ["hidden gem"]},
        "style_profile:bs": {"language": "bs", "voice": "Prijateljski", "person": "second", "formality": "neutral", "avg_sentence_words": 15, "typical_length_words": 850, "structure": "Uvod i sekcije", "heading_habits": "Kratki naslovi", "formatting_habits": "Malo listi", "phrases_to_avoid": []},
        "topics": {"candidates": [{"id": f"t{i}", "title": f"Topic {i}", "angle": "Practical", "target_category_ids": [3], "evidence": [{"url": f"https://search.example.org/r{i}", "title": "Result", "note": "People ask about this."}]} for i in range(1, 11)]},
        "novelty": {"judgments": [{"candidate_id": f"t{i}", "novel": i == 1, "closest_post_keys": ["1:10"], "reason": "Checked."} for i in range(1, 11)], "chosen_candidate_id": "t1"},
        "research": {
            "sources": [{"source_id": "s1", "url": "https://source.example.org/bridge", "title": "Bridge history"}, {"source_id": "s2", "url": "https://source.example.org/river", "title": "River"}],
            "claims": [{"claim_id": f"c{i}", "claim": f"Claim {i}.", "source_id": "s1" if i % 2 else "s2", "supporting_quote": f"verbatim supporting quote {i}"} for i in range(1, 7)],
        },
        "verify_quotes": {
            "sources": [{"source_id": "s1", "url": "https://source.example.org/bridge", "fetched": True, "http_status": 200, "chars": 5000, "error": None},
                        {"source_id": "s2", "url": "https://source.example.org/river", "fetched": True, "http_status": 200, "chars": 4000, "error": None}],
            "claims": [{"claim_id": f"c{i}", "verified": True, "reason": "exact substring"} for i in range(1, 7)],
        },
        "outline": {"title": "Seeing the Stone Bridge at dawn", "sections": [{"heading": "When to go", "level": 2, "key_points": [{"point": "Morning light", "claim_ids": ["c3"]}]}, {"heading": "Getting there", "level": 2, "key_points": [{"point": "Walk", "claim_ids": ["c4"]}]}], "internal_links": [{"post_id": 10, "anchor_hint": "walking guide"}]},
        "draft": {"title": "Seeing the Stone Bridge at dawn", "slug": "stone-bridge-at-dawn", "excerpt": "When and how to see the Stone Bridge.", "seo_title": "Stone Bridge at dawn", "seo_description": "d" * 140, "focus_keyword": "stone bridge", "content_html": DRAFT_HTML},
        "taxonomy:en": {"category_ids": [3], "tag_ids": [10, 11]},
        "taxonomy:bs": {"category_ids": [4], "tag_ids": [12]},
        "images:shortlist": {"candidates": [{"media_key": "1:501", "reason": "Bridge"}, {"media_key": "1:502", "reason": "River"}, {"media_key": "1:503", "reason": "Town"}]},
        "images:subjects": {"subjects": ["stone bridge", "river at dawn"]},
        "draft:plan": {"edits": [{"check": "facts", "where": "Intro", "change": "Delete the unsupported sentence."}], "keep": ["The headings"]},
        "draft:fit": {"options": ["Stone Bridge at dawn", "A practical guide to the Stone Bridge at dawn: when to arrive, where to stand, and how the early light falls on the river and the old town."]},
        "draft:expand": {"html": "<h2>Planning your morning</h2><p>" + " ".join(["Arrive early, bring water and walk slowly along the river path to enjoy the quiet."] * 12) + "</p>"},
        "images:relevance": lambda n, kw: {"images": [
            {"image_key": i["image_key"], "score": 9, "shows": "stone bridge", "reason": "Shows the subject."} for i in json.loads(kw["context"])["images"]
        ]},
        "images:judge": {"images": [{"image_key": f"1:{m}", "relevant": True, "has_text": False, "has_logo": False, "has_watermark": False, "has_recognizable_people": False, "alt_text": f"View {m}", "caption": f"Caption {m}"} for m in (501, 502, 503)]},
        "qa": {"score": 0.95, "summary": "Good.", "issues": []},
        "qa:facts": {"score": 0.97, "summary": "Grounded.", "unsupported_sentences": [], "issues": []},
        "text_checks": {
            "structure": {"title_chars": 29, "seo_title_chars": 18, "seo_description_chars": 140, "h1_count": 0, "heading_order_ok": True, "problems": [], "score": 1.0},
            "originality": {"n": 5, "max_overlap": 0.02, "worst_reference": "1:10", "references_checked": 9, "fetch_errors": [], "score": 0.98},
        },
        "translate:bs": {"language": "bs", "title": "Stari most u zoru", "slug": "stari-most-u-zoru", "excerpt": "Kada i kako vidjeti Stari most.", "seo_title": "Stari most u zoru", "seo_description": "o" * 140, "focus_keyword": "stari most", "content_html": TRANSLATED_HTML,
                         "images": [{"key": "featured", "alt": "Pogled", "caption": ""}, {"key": "inline-1", "alt": "Rijeka", "caption": "Silver"}]},
        "back_translate": {"title": "The Stone Bridge at dawn", "content_text": "Back translated text."},
        "fidelity": {"score": 0.93, "summary": "Same meaning.", "issues": []},
    }


def lookup(responses: dict[str, Any], task: str) -> Any:
    """Most specific match: full task ID, then shorter prefixes split on ':'."""
    parts = task.split(":")
    for n in range(len(parts), 0, -1):
        key = ":".join(parts[:n])
        if key in responses:
            return responses[key]
    raise KeyError(task)


class FakeBridge:
    def __init__(self, responses: dict[str, Any]) -> None:
        self.responses = responses
        self.calls: list[dict[str, Any]] = []
        self.session_overlay = True
        self.mcp_tunnel = False
        self.engine_version = "2.12.1"
        self.stopped = False
        self.counts: dict[str, int] = {}

    async def direct_agent(self, **kw: Any) -> dict[str, Any]:
        self.calls.append(kw)
        task = kw["text"].split("\n", 1)[0].removeprefix("AO Quill task: ").strip()
        is_tool_call = ("mcp_provider_ids" in kw or "images" in kw) and "response_format" not in kw
        if is_tool_call:
            # Optional scripted agent answers: responses["prose:<task>"] is a list consumed in order.
            scripted = self.responses.get(f"prose:{task}")
            if scripted:
                return {"ok": True, "text": scripted.pop(0)}
            judge = re.match(r"^images:judge:[a-z_]+:(\d+)$", task)
            if judge and "images" in kw:
                # The vision model answers each check on its own line, from the scripted verdict.
                verdicts = (lookup(self.responses, task) or {}).get("images", [])
                n = int(judge.group(1))
                v = verdicts[n - 1] if len(verdicts) >= n else {}
                lines = [f"{k}: {'yes' if v.get(k) else 'no'}" for k in ("relevant", "has_text", "has_logo", "has_watermark", "has_recognizable_people")]
                return {"ok": True, "text": "\n".join(lines + [f"alt text: {v.get('alt_text', '')}", f"caption: {v.get('caption', '')}"])}
            return {"ok": True, "text": f"prose for {task}: " + "the agent's findings in full sentences. " * 3}
        task = re.sub(r":structure[0-9]*$", "", task)
        task = task.replace(":revise", "")
        if task.startswith("qa:"):
            check = task.split(":")[1]
            task = "qa:facts" if check == "facts" else f"qa:{check}"
        self.counts[task] = self.counts.get(task, 0) + 1
        value = lookup(self.responses, task)
        if callable(value):
            value = value(self.counts[task], kw)
        per_image = re.match(r"^images:judge:[a-z_]+:(\d+)$", task)
        if per_image and isinstance(value, dict) and isinstance(value.get("images"), list):
            # One vision call per image: image N gets the Nth verdict of the scripted list.
            n = int(per_image.group(1))
            value = {"images": value["images"][n - 1 : n]}
        if isinstance(value, Exception):
            raise value
        text = value if isinstance(value, str) else json.dumps(value)
        return {"ok": True, "text": text}

    async def stop(self, *, clear_remote: bool = True) -> None:
        self.stopped = True


class FakeCatalogEntry:
    def __init__(self, id: str, type: str | None = None, model: str | None = None) -> None:
        self.id, self.type, self.model = id, type, model
        self.role = self.description = self.planner_hint = None


class FakeCatalog:
    def __init__(self, agents: list[str] | None = None, mcps: list[str] | None = None) -> None:
        self.agents = [FakeCatalogEntry(a, "deterministic") for a in (agents if agents is not None else ["aoq_quote_verifier", "aoq_text_checks"])]
        self.mcps = [FakeCatalogEntry(m) for m in (mcps if mcps is not None else ["search_searxng", "fetch_url"])]
        self.generated_at = None


class FakeWP:
    def __init__(self, *, inv: dict | None = None, cfg: dict | None = None, profiles: dict | None = None, claim: bool = True, trigger: str = "scheduled", resume: dict | None = None) -> None:
        self.inv = inv or inventory()
        self.cfg = cfg or config()
        self.profiles = profiles or {"site_profile": None, "style_profiles": []}
        self.claim = claim
        self.trigger = trigger
        self.resume = resume
        self.calls: list[tuple[str, dict]] = []
        self.media_fetches: list[str] = []

    def names(self) -> list[str]:
        return [n for n, _ in self.calls]

    def writes(self) -> list[str]:
        return [n for n in self.names() if n in WRITE_ABILITIES]

    async def call(self, name: str, payload: dict) -> dict:
        contracts.validate(payload, f"abilities/{name}.input", stage="test")
        self.calls.append((name, copy.deepcopy(payload)))
        if name == "claim-run":
            run = {"run_id": RUN_ID, "run_date": "2026-11-12", "trigger": self.trigger, "lease_expires_at": "2026-11-12T11:00:00+00:00"}
            if self.resume:
                run["resume"] = self.resume
            out = {"run": run if self.claim else None}
            if getattr(self, "ig_job", None):
                out = {"run": None, "instagram": self.ig_job}
        elif name == "get-config":
            out = self.cfg
        elif name == "get-inventory":
            out = {"page": payload["page"], "per_page": payload["per_page"], "total_pages": 1, **(self.inv if payload["page"] == 1 else {"languages": [], "posts": [], "terms": [], "media": []})}
        elif name == "get-profiles":
            out = self.profiles
        elif name == "commit-post":
            out = {
                "translation_group": RUN_ID,
                "status": "pending",
                "posts": [{"language": p["language"], "blog_id": 1 if p["language"] == "en" else 3, "post_id": 700 + i, "url": f"https://wordpress.example.com/?p={700 + i}"} for i, p in enumerate(payload["posts"])],
                "attachments": [],
            }
        else:
            out = {"ok": True}
        return contracts.validate(out, f"abilities/{name}.output", stage="test")

    async def fetch_media(self, url: str) -> tuple[bytes, str]:
        self.media_fetches.append(url)
        return b"\xff\xd8\xff\xe0fakejpeg\xff\xd9", "image/jpeg"


class FakeWebTools:
    """Stands in for WebTools: a ledger as if image_search had run, and image bytes."""

    def __init__(self) -> None:
        from aoq_worker.web_tools import ImageCandidate, ImageLedger, license_record

        self.ledger = ImageLedger()
        self.fetched_images: list[str] = []
        specs = [
            ("openverse:0a1b2c3d", "cc0", "", ""),
            ("commons:4401", "cc-by-sa", "Jane Example", '"Stone bridge.jpg" by Jane Example, CC BY-SA 4.0, via Wikimedia Commons'),
            ("commons:4402", "cc-by", "Sam Example", '"River.jpg" by Sam Example, CC BY 4.0, via Wikimedia Commons'),
        ]
        for image_id, code, author, attribution in specs:
            n = image_id.split(":")[1]
            self.ledger.add(
                ImageCandidate(
                    image_id=image_id,
                    provider=image_id.split(":")[0],
                    title=f"Image {n}",
                    url=f"https://images.example.org/{n}.jpg",
                    thumbnail_url=f"https://images.example.org/{n}-thumb.jpg",
                    source_page_url=f"https://images.example.org/page/{n}",
                    width=2000,
                    height=1333,
                    license=license_record(code, "4.0" if code.startswith("cc-by") else "", None),
                    author=author,
                    attribution=attribution,
                )
            )

    async def web_image_search(self, query: str, max_results: int = 30) -> list:
        """As SearXNG's image tab: one page photo and one watermarked stock agency preview per query."""
        from aoq_worker.web_tools import UNCHECKED_LICENSE, ImageCandidate

        self.web_queries = getattr(self, "web_queries", []) + [query]
        out = []
        for n, host in ((1, "recipes.example.com"), (2, "www.shutterstock.com")):
            slug = query.replace(" ", "-")
            cand = ImageCandidate(
                image_id=f"web:{abs(hash((query, n))) % 16**12:012x}", provider="web:bing images", title=f"{query} {n}",
                url=f"https://{host}/img/{slug}-{n}.jpg", thumbnail_url=f"https://thumbs.example.net/{slug}-{n}.jpg",
                source_page_url=f"https://{host}/{slug}", width=1600, height=1000, license=dict(UNCHECKED_LICENSE),
                author="", attribution=f"Source: {host.removeprefix('www.')}",
            )
            self.ledger.add(cand)
            out.append(cand)
        return out

    async def fetch_image(self, image_id: str, *, thumbnail: bool = True) -> tuple[bytes, str]:
        if self.ledger.get(image_id) is None:
            raise ValueError(image_id)
        self.fetched_images.append(image_id)
        return b"\xff\xd8\xff\xe0stock\xff\xd9", "image/jpeg"


def deps(wp: FakeWP, bridge: FakeBridge, catalog: FakeCatalog | None = None, catalog_error: Exception | None = None, web_tools: Any = None) -> Dependencies:
    async def fetch_catalog():
        if catalog_error:
            raise catalog_error
        return catalog or FakeCatalog()

    async def open_bridge(cfg):
        return bridge

    overlay = [{"id": f"client.aoq_{n}", "type": "ollama", "model": m} for n, m in (("analyst", "qwen3"), ("writer", "qwen3"), ("editor", "gemma3"), ("structurer", "qwen3"), ("vision", "qwen2.5vl"))]
    return Dependencies(wp=wp, fetch_catalog=fetch_catalog, overlay_agents=lambda: overlay, open_bridge=open_bridge, web_tools=web_tools)


Mutator = Callable[[dict[str, Any]], None]

"""Generate every JSON Schema contract and copy it to the plugin and the worker.

Source of truth for the plugin/worker contract and for each stage's AO output.
Run `python scripts/build_contracts.py` after changing anything here, then commit
the generated files. `python scripts/build_contracts.py --check` exits 1 when the
committed files are out of date (used by tests and CI).

Schemas avoid `$ref`, `const` and `format` because the WordPress REST schema
validator (used by the Abilities API) does not support them.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TARGETS = [
    ROOT / "docs" / "contracts",
    ROOT / "wordpress-plugin" / "ao-quill" / "contracts",
    ROOT / "worker" / "src" / "aoq_worker" / "contracts",
]


# ---------------------------------------------------------------- fragments

def s(min_len: int = 0, max_len: int | None = None, pattern: str | None = None, enum: list | None = None) -> dict:
    out: dict = {"type": "string"}
    if min_len:
        out["minLength"] = min_len
    if max_len is not None:
        out["maxLength"] = max_len
    if pattern:
        out["pattern"] = pattern
    if enum:
        out["enum"] = enum
    return out


def i(minimum: int | None = None, maximum: int | None = None) -> dict:
    out: dict = {"type": "integer"}
    if minimum is not None:
        out["minimum"] = minimum
    if maximum is not None:
        out["maximum"] = maximum
    return out


def n(minimum: float | None = None, maximum: float | None = None) -> dict:
    out: dict = {"type": "number"}
    if minimum is not None:
        out["minimum"] = minimum
    if maximum is not None:
        out["maximum"] = maximum
    return out


B = {"type": "boolean"}


def arr(items: dict, min_items: int = 0, max_items: int | None = None) -> dict:
    out: dict = {"type": "array", "items": items}
    if min_items:
        out["minItems"] = min_items
    if max_items is not None:
        out["maxItems"] = max_items
    return out


def obj(props: dict, required: list[str] | None = None, extra: bool = False) -> dict:
    return {
        "type": "object",
        "properties": props,
        "required": list(props) if required is None else required,
        "additionalProperties": extra,
    }


def nullable(schema: dict) -> dict:
    out = dict(schema)
    t = out["type"]
    out["type"] = [t, "null"] if isinstance(t, str) else list(t) + ["null"]
    return out


FREE_OBJECT = {"type": "object", "additionalProperties": True}
UUID = s(pattern="^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$")
LANG = s(pattern="^[a-z]{2,3}$")
ID = i(1)
URL = s(1, 2048, "^https?://")
POST_KEY = s(pattern="^[0-9]+:[0-9]+$")  # "<blog_id>:<post_id>"
# Stock image IDs from the worker's image_search tool (D14).
STOCK_ID = s(pattern="^(openverse:[A-Za-z0-9-]{1,64}|commons:[0-9]{1,15})$")
# Image candidates in one vision call: media library keys or stock IDs.
IMAGE_KEY = s(pattern="^([0-9]+:[0-9]+|openverse:[A-Za-z0-9-]{1,64}|commons:[0-9]{1,15}|web:[a-f0-9]{12})$")
DATETIME = s(pattern="^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(Z|[+-][0-9]{2}:[0-9]{2})$")
DATE = s(pattern="^[0-9]{4}-[0-9]{2}-[0-9]{2}$")
STAGES = [
    "connect", "inventory", "site_profile", "style_profile", "topics", "novelty", "research",
    "verify_quotes", "outline", "draft", "taxonomy", "images", "qa", "translate",
    "translation_qa", "commit", "finish",
]
AGENT_ROLES = [
    "site_profile", "style_profile", "topics", "novelty", "research", "structurer", "outline",
    "draft", "taxonomy", "image_shortlist", "image_vision", "qa_grammar", "qa_facts",
    "qa_style", "qa_seo", "translate", "back_translate", "translation_qa",
    "quote_verifier", "text_checks",
]
QA_ROLES = ["qa_grammar", "qa_facts", "qa_style", "qa_seo", "back_translate", "translation_qa"]
DETERMINISTIC_ROLES = ["quote_verifier", "text_checks"]
THRESHOLDS = ["grammar", "facts", "style", "seo", "structure",
              "translation_grammar", "translation_fidelity", "translation_style"]
IMAGE_SOURCES = ["media_library", "stock", "web_search", "ai_generated"]
# "unchecked": found by SearXNG web image search; its license was not checked (D28).
LICENSE_CODES = ["site-library", "cc0", "pdm", "cc-by", "cc-by-sa", "pexels", "unchecked"]

LANGUAGE = obj({
    "code": LANG,
    "locale": s(2, 20),
    "name": s(1, 100),
    "blog_id": ID,
    "url": URL,
    "primary": B,
    "excluded": B,
})

CATALOG_AGENT = obj({
    "id": s(1, 200),
    "family": s(1, 64),
    "label": s(0, 300),
    "deterministic": B,
    "vision": B,
    "model": nullable(s(0, 200)),  # for the Settings page: model name and parameter size
}, required=["id", "family", "label", "deterministic", "vision"])

# ------------------------------------------------------- plugin <-> worker

CLAIM_RUN_INPUT = obj({
    "worker": obj({"version": s(1, 40), "ao_reach_version": s(1, 40)}),
    "ao": obj({
        "reachable": B,
        "error": nullable(s(0, 1000)),
        "catalog": nullable(obj({
            "agents": arr(CATALOG_AGENT, max_items=2000),
            "mcps": arr(s(1, 200), max_items=500),
            "generated_at": nullable(s(0, 64)),
        })),
    }),
    # True when the worker holds its run lock, so no run of this worker is in progress.
    "idle": B,
}, required=["worker", "ao"])

CLAIMED_RUN = obj({
    "run_id": UUID,
    "run_date": DATE,
    "trigger": s(enum=["scheduled", "manual", "test"]),  # test: full pipeline, never committed
    "lease_expires_at": DATETIME,
    # A test run that resumes an earlier run: its checkpoint and the step to start from.
    # state is JSON text: PHP would turn empty objects into lists if it decoded it.
    "resume": obj({"resumed_from": UUID, "from_step": s(1, 64), "state": s(2, 8_000_000)}),
}, required=["run_id", "run_date", "trigger", "lease_expires_at"])

# The run's state after a step (topic, claims, outline, draft, images, ...), so a failed
# test run can be resumed from that step and a finished one published later.
SAVE_CHECKPOINT_INPUT = obj({
    "run_id": UUID,
    "step": s(1, 64),
    "completed": arr(s(1, 64), 0, 40),
    "state": s(2, 8_000_000),  # JSON text, stored and returned as is
})

# An Instagram caption job for one WordPress post (Instagram generation tab, D30). test: caption
# and image preview only; post: then posted to Instagram.
INSTAGRAM_JOB = obj({
    "job_id": UUID,
    "mode": s(enum=["test", "post"]),
    "language": LANG,
    "title": s(1, 300),
    "excerpt": s(0, 2000),
    "text": s(0, 20000),
})
CLAIM_RUN_OUTPUT = obj({"run": nullable(CLAIMED_RUN), "instagram": nullable(INSTAGRAM_JOB)}, required=["run"])
REPORT_INSTAGRAM_INPUT = obj({
    "job_id": UUID,
    "status": s(enum=["ok", "failed"]),
    "caption": s(0, 2200),
    "hashtags": arr(s(1, 100), 0, 30),
    "error": s(0, 2000),
}, required=["job_id", "status"])

CONFIG = obj({
    "run_id": nullable(UUID),
    "site_brief": s(0, 10000),
    "trend_seeds": arr(s(1, 80), 0, 30),  # search interest: seed terms for Google suggestions and trend filtering
    "trend_geos": arr(s(pattern="^[A-Z]{2}$"), 0, 10),  # Google Trends countries, the site's own first
    "publish_mode": s(enum=["pending", "publish"]),
    "timezone": s(1, 64),
    "languages": arr(LANGUAGE, 1),
    "primary_language": LANG,
    "agents": obj({role: s(1, 200) for role in AGENT_ROLES}),
    "mcp": obj({
        "search": s(1, 200),
        "fetch": s(1, 200),
        "stock_images": nullable(s(1, 200)),
    }),
    "thresholds": obj({k: n(0, 1) for k in THRESHOLDS}),
    "originality_max_overlap": n(0, 1),
    "max_revision_loops": i(1, 10),
    "min_words": i(50, 5000),  # the draft and every revision must reach it; checked in QA
    "min_verified_claims": i(1, 50),
    "min_human_posts_for_style": i(1, 100),
    "inline_image_count": i(0, 6),
    "image_sources": arr(obj({"id": s(enum=IMAGE_SOURCES), "enabled": B, "order": i(1, 10)}), 1, 4),
    "media_candidate_limit": i(10, 2000),
    "image_min_score": n(0, 10),  # in-body images must reach it (D27)
    "featured_min_score": n(0, 10),  # the featured image should reach it; weaker ones are a fallback
    "fallback_image": nullable(POST_KEY),
    "instagram": obj({
        "enabled": B,
        "languages": arr(LANG, 0, 20),
        "max_hashtags": i(0, 30),
        "link_hint": s(0, 300),
        "caption_style": s(0, 500),
    }),  # media library "blog:id" used when nothing usable is found
    "ao_priority": s(enum=["background", "low", "normal"]),
    "stage_timeout_seconds": i(30, 14400),
})

GET_CONFIG_INPUT = obj({"run_id": UUID}, required=[])

INV_POST = obj({
    "key": POST_KEY,
    "blog_id": ID,
    "id": ID,
    "language": LANG,
    "title": s(0, 1000),
    "excerpt": s(0, 5000),
    "content": s(0, 500000),
    "url": URL,
    "status": s(enum=["publish", "future", "draft", "pending", "private"]),
    "categories": arr(ID),
    "tags": arr(ID),
    "date": DATETIME,
    "modified": DATETIME,
    "ai_generated": B,
    "translation_group": nullable(UUID),
})

INV_TERM = obj({
    "blog_id": ID,
    "language": LANG,
    "taxonomy": s(enum=["category", "post_tag"]),
    "id": ID,
    "name": s(1, 400),
    "slug": s(1, 400),
    "parent": i(0),
    "count": i(0),
    "translations": arr(obj({"language": LANG, "id": ID})),
})

INV_MEDIA = obj({
    "key": POST_KEY,
    "blog_id": ID,
    "id": ID,
    "language": LANG,
    "url": URL,
    "preview_url": URL,
    "alt": s(0, 2000),
    "caption": s(0, 5000),
    "title": s(0, 1000),
    "mime": s(1, 100),
    "filesize": i(0),
    "width": i(0),
    "height": i(0),
    "date": DATETIME,
})

GET_INVENTORY_INPUT = obj({"run_id": UUID, "page": i(1), "per_page": i(1, 200)}, required=["page"])

GET_INVENTORY_OUTPUT = obj({
    "page": i(1),
    "per_page": i(1, 200),
    "total_pages": i(0),
    "languages": arr(LANGUAGE),
    "posts": arr(INV_POST),
    "terms": arr(INV_TERM),
    "media": arr(INV_MEDIA),
})

PROFILE_FINGERPRINT = arr(POST_KEY, max_items=100000)

SITE_PROFILE_RECORD = obj({
    "fingerprint": PROFILE_FINGERPRINT,
    "built_at": DATETIME,
    "data": FREE_OBJECT,
})
STYLE_PROFILE_RECORD = obj({
    "language": LANG,
    "fingerprint": PROFILE_FINGERPRINT,
    "built_at": DATETIME,
    "data": FREE_OBJECT,
})

GET_PROFILES_INPUT = obj({"run_id": UUID}, required=[])
# Writing style borrowed from another AO Quill site (its style profiles from human posts), for a site
# with too few human posts of its own in a language (a fully AI-written site).
BORROWED_STYLE = obj({
    "source": s(1, 200),
    "saved_at": DATETIME,
    "profiles": arr(obj({"language": LANG, "data": FREE_OBJECT}), 0, 50),
})
GET_PROFILES_OUTPUT = obj({
    "site_profile": nullable(SITE_PROFILE_RECORD),
    "style_profiles": arr(STYLE_PROFILE_RECORD),
    "borrowed_style": BORROWED_STYLE,
}, required=["site_profile", "style_profiles"])
SAVE_PROFILES_INPUT = obj({
    "run_id": UUID,
    "site_profile": nullable(SITE_PROFILE_RECORD),
    "style_profiles": arr(STYLE_PROFILE_RECORD),
})
OK_OUTPUT = obj({"ok": B})

# AO connection kept in WordPress (D16). The worker reads it at the start of every poll.
PEM_CERT = s(1, 20000, "-----BEGIN CERTIFICATE-----")
PEM_KEY = s(1, 20000, "-----BEGIN [A-Z ]*PRIVATE KEY-----")
MTLS = obj({"cert_pem": PEM_CERT, "key_pem": PEM_KEY, "ca_pem": PEM_CERT})
GET_AO_CONNECTION_INPUT = obj({}, required=[])
GET_AO_CONNECTION_OUTPUT = obj({
    "ao_url": nullable(s(1, 2048, "^https://")),
    "ao_ipv4_only": B,
    "searxng_url": nullable(URL),
    "user_agent": nullable(s(1, 300)),
    "mtls": nullable(MTLS),
})
SAVE_AO_CERTIFICATE_OUTPUT = obj({"ok": B, "subject": s(0, 500), "expires_at": s(0, 40)})

REPORT_STAGE_INPUT = obj({
    "run_id": UUID,
    "stage": s(enum=STAGES),
    "language": nullable(LANG),
    "attempt": i(1, 50),
    "status": s(enum=["ok", "failed"]),
    "started_at": DATETIME,
    "duration_ms": i(0),
    "agents": arr(obj({"role": s(1, 64), "agent_id": s(1, 200), "model": nullable(s(0, 200))}), max_items=50),
    "scores": arr(obj({"check": s(1, 64), "score": n(0, 1), "threshold": nullable(n(0, 1)), "passed": B}), max_items=50),
    "revision_count": i(0, 50),
    "failure_reason": nullable(s(0, 4000)),
    "detail": FREE_OBJECT,
})

LICENSE = obj({
    "code": s(enum=LICENSE_CODES),
    "name": s(1, 200),
    "url": nullable(URL),
    "allows_download_and_reuse": B,  # false only for "unchecked" (D28)
    "requires_attribution": B,
})

COMMIT_IMAGE = obj({
    "key": s(pattern="^(featured|inline-[0-9]+)$"),
    "source": s(enum=["media_library", "remote"]),
    "media": nullable(obj({"blog_id": ID, "id": ID})),
    "url": nullable(URL),
    "source_page_url": nullable(URL),
    "license": LICENSE,
    "author": s(0, 400),
    "attribution": s(0, 2000),
})

COMMIT_POST = obj({
    "language": LANG,
    "title": s(1, 200),
    "slug": s(0, 200, "^[a-z0-9-]*$"),
    "excerpt": s(0, 1000),
    "content": s(1, 200000),
    "category_ids": arr(ID, 1, 20),
    "tag_ids": arr(ID, 0, 40),
    "seo": obj({"title": s(0, 200), "description": s(0, 400), "focus_keyword": s(0, 200)}),
    "images": arr(obj({"key": s(pattern="^(featured|inline-[0-9]+)$"), "alt": s(0, 400), "caption": s(0, 1000)}), 1, 7),
    # Instagram caption and hashtags (no #) written by the worker when Instagram is on (D29).
    "instagram": obj({"caption": s(1, 2200), "hashtags": arr(s(1, 100), 0, 30)}),
}, required=["language", "title", "slug", "excerpt", "content", "category_ids", "tag_ids", "seo", "images"])

COMMIT_INPUT = obj({
    "run_id": UUID,
    "posts": arr(COMMIT_POST, 1, 20),
    "images": arr(COMMIT_IMAGE, 1, 7),
})

COMMIT_OUTPUT = obj({
    "translation_group": UUID,
    "status": s(enum=["pending", "publish"]),
    "posts": arr(obj({"language": LANG, "blog_id": ID, "post_id": ID, "url": URL}), 1),
    "attachments": arr(obj({"blog_id": ID, "id": ID, "key": s(1, 40)})),
})

# Test runs: what would have been committed, shown in the Test run tab.
PREVIEW = obj({
    "posts": arr(obj({"language": LANG, "title": s(1, 200), "excerpt": s(0, 1000), "content": s(1, 200000)}), 0, 20),
})

# Live progress for the Test run tab (and the Run log): pipeline step counts and AO
# output as the worker receives it. Each call also extends the run lease.
REPORT_PROGRESS_INPUT = obj({
    "run_id": UUID,
    "step": i(0, 100),
    "steps": i(1, 100),
    "label": s(0, 200),
    "output": arr(obj({"step": s(0, 64), "task": s(0, 200), "agent": s(0, 200), "text": s(0, 20000)}, required=["task", "agent", "text"]), 0, 500),
    # The worker reports at least once a minute during a run (output may be empty), so a run that
    # goes quiet longer has lost its worker. Optional for older workers.
    "heartbeat": B,
}, required=["run_id", "step", "steps", "label", "output"])

FINISH_INPUT = obj({
    "run_id": UUID,
    "status": s(enum=["succeeded", "failed"]),
    "failure_reason": nullable(s(0, 4000)),
    "result": obj({
        "posts": arr(obj({"language": LANG, "blog_id": ID, "post_id": ID, "url": URL})),
        "scores": arr(obj({"language": nullable(LANG), "check": s(1, 64), "score": n(0, 1)})),
        "agents": arr(obj({"role": s(1, 64), "agent_id": s(1, 200), "model": nullable(s(0, 200))})),
        "sources": arr(obj({"source_id": s(1, 40), "url": URL, "title": s(0, 500)})),
        "usage": nullable(FREE_OBJECT),
        "ao": obj({
            "engine_version": nullable(s(0, 40)),
            "capabilities": arr(obj({"id": s(1, 64), "advertised": B})),
        }),
        "preview": nullable(PREVIEW),
    }),
})

# Shared-worker busy presence: broadcast to every WordPress site while run.lock is held (D34).
# busy null clears the banner so sibling Status tabs return to Running / idle poll.
REPORT_WORKER_STATUS_INPUT = obj({
    "busy": nullable(obj({
        "site": s(1, 64),
        "task": s(1, 64),
        "detail": s(0, 300),
        "started_at": s(0, 64),
        "updated_at": s(0, 64),
    }, required=["site", "task"])),
})

# ------------------------------------------------------------ stage outputs

QA_ISSUE = obj({
    "severity": s(enum=["minor", "major", "critical"]),
    "location": s(0, 500),
    "problem": s(1, 2000),
    "fix": s(1, 2000),
})

STAGE_SCHEMAS = {
    "site_profile": obj({
        "summary": s(1, 4000),
        "audience": s(1, 2000),
        "themes": arr(s(1, 300), 1, 40),
        "content_gaps": arr(s(1, 500), 0, 40),
        "avoid": arr(s(1, 300), 0, 40),
    }),
    "style_profile": obj({
        "language": LANG,
        "voice": s(1, 1000),
        "person": s(enum=["first", "second", "third", "mixed"]),
        "formality": s(enum=["formal", "neutral", "informal"]),
        "avg_sentence_words": n(1, 100),
        "typical_length_words": i(50, 20000),
        "structure": s(1, 2000),
        "heading_habits": s(0, 1000),
        "formatting_habits": s(0, 1000),
        "phrases_to_avoid": arr(s(1, 200), 0, 50),
    }),
    "topic_candidates": obj({
        "candidates": arr(obj({
            "id": s(pattern="^t[0-9]{1,2}$"),
            "title": s(1, 200),
            "angle": s(1, 1000),
            "target_category_ids": arr(ID, 0, 5),
            "evidence": arr(obj({"url": URL, "title": s(0, 300), "note": s(1, 1000)}), 0, 10),
        }, required=["title", "angle"]), 3, 10),
    }),
    "novelty": obj({
        "judgments": arr(obj({
            "candidate_id": s(pattern="^t[0-9]{1,2}$"),
            "novel": B,
            "closest_post_keys": arr(POST_KEY, 0, 5),
            "reason": s(1, 1000),
        }), 1, 10),
        "chosen_candidate_id": nullable(s(pattern="^t[0-9]{1,2}$")),
    }),
    "research": obj({
        "sources": arr(obj({"source_id": s(pattern="^s[0-9]{1,3}$"), "url": URL, "title": s(0, 500)}), 0, 30),
        "claims": arr(obj({
            "claim_id": s(pattern="^c[0-9]{1,3}$"),
            "claim": s(1, 1000),
            "source_id": s(pattern="^s[0-9]{1,3}$"),
            "supporting_quote": s(10, 1000),
        }, required=["claim", "supporting_quote"]), 1, 80),
    }, required=["claims"]),
    "quote_verification": obj({
        "sources": arr(obj({
            "source_id": s(1, 10),
            "url": URL,
            "fetched": B,
            "http_status": nullable(i(0, 999)),
            "chars": i(0),
            "error": nullable(s(0, 1000)),
        })),
        "claims": arr(obj({"claim_id": s(1, 10), "verified": B, "reason": s(0, 500)})),
    }),
    "outline": obj({
        "title": s(1, 200),
        "sections": arr(obj({
            "heading": s(1, 200),
            "level": i(2, 4),
            "key_points": arr(obj({"point": s(1, 1000), "claim_ids": arr(s(pattern="^c[0-9]{1,3}$"), 0, 10)}), 1, 12),
        }), 2, 15),
        "internal_links": arr(obj({"post_id": ID, "anchor_hint": s(1, 200)}), 0, 8),
    }),
    "draft": obj({
        "title": s(1, 200),
        "slug": s(1, 200, "^[a-z0-9-]+$"),
        "excerpt": s(1, 1000),
        "seo_title": s(1, 200),
        "seo_description": s(1, 400),
        "focus_keyword": s(1, 200),
        "content_html": s(200, 200000),
    }),
    "taxonomy": obj({"category_ids": arr(ID, 1, 5), "tag_ids": arr(ID, 0, 15)}),
    # Text model's verdict on the vision model's descriptions: does the image show the post's subject?
    # Text model's score (0-10) of the vision model's descriptions against the post's subjects (D27).
    "image_relevance": obj({
        "images": arr(obj({"image_key": IMAGE_KEY, "score": n(0, 10), "shows": s(0, 200), "reason": s(1, 300)}), 0, 12),
    }),
    # What a photo for the post should show: concrete things, most important first (D27).
    # place: the town or site the post is about; web and stock photos whose page names it rank higher.
    "image_subjects": obj({"subjects": arr(s(1, 80), 1, 6), "place": s(0, 100)}, required=["subjects"]),
    # Revision round (D31): the editor's plan, field length options, and new sections for the word count.
    "revision_plan": obj({
        "edits": arr(obj({"check": s(1, 40), "where": s(0, 300), "change": s(1, 800)}), 1, 15),
        "keep": arr(s(1, 300), 0, 10),
    }),
    "field_options": obj({"options": arr(s(1, 400), 1, 8)}),
    # language-site (docs/plans/new-language-site.md): short texts by key, and one HTML text.
    "string_batch": obj({"items": arr(obj({"key": s(1, 200), "text": s(0, 4000)}), 0, 60)}),
    "html_translation": obj({"html": s(1, 40000)}),
    # under_heading: the existing H2 the new H3 subsections belong under; empty for a new H2 section.
    "expansion": obj({"html": s(1, 30000), "under_heading": s(0, 300)}, required=["html"]),
    # An Instagram caption for a post (D29); hashtags without the #.
    "instagram_caption": obj({"caption": s(1, 2000), "hashtags": arr(s(1, 60), 0, 30)}),
    "image_shortlist": obj({
        "candidates": arr(obj({"media_key": POST_KEY, "reason": s(1, 500)}), 0, 12),
    }),
    "stock_shortlist": obj({
        "candidates": arr(obj({"image_id": STOCK_ID, "reason": s(1, 500)}), 0, 12),  # none is a valid answer
    }),
    "image_judgement": obj({
        "images": arr(obj({
            "image_key": IMAGE_KEY,
            "relevant": B,
            "has_text": B,
            "has_logo": B,
            "has_watermark": B,
            "has_recognizable_people": B,
            "alt_text": s(1, 300),
            "caption": s(0, 500),
        }, required=["relevant", "has_text", "has_logo", "has_watermark", "has_recognizable_people", "alt_text", "caption"]), 1, 12),
    }),
    "qa": obj({
        "score": n(0, 1),
        "summary": s(1, 2000),
        "issues": arr(QA_ISSUE, 0, 50),
    }),
    "qa_facts": obj({
        "score": n(0, 1),
        "summary": s(1, 2000),
        "unsupported_sentences": arr(obj({"sentence": s(1, 2000), "why": s(1, 500)}), 0, 50),
        "issues": arr(QA_ISSUE, 0, 50),
    }),
    "text_checks": obj({
        "structure": obj({
            "title_chars": i(0),
            "seo_title_chars": i(0),
            "seo_description_chars": i(0),
            "h1_count": i(0),
            "heading_order_ok": B,
            "problems": arr(s(1, 500)),
            "score": n(0, 1),
        }),
        "originality": obj({
            "n": i(3, 12),
            "max_overlap": n(0, 1),
            "worst_reference": nullable(s(0, 2048)),
            "references_checked": i(0),
            "fetch_errors": arr(s(0, 2048)),
            "score": n(0, 1),
        }),
    }),
    "translation": obj({
        "language": LANG,
        "title": s(1, 200),
        "slug": s(0, 200, "^[a-z0-9-]*$"),  # empty: WordPress makes it from the title (Arabic)
        "excerpt": s(1, 1000),
        "seo_title": s(1, 200),
        "seo_description": s(1, 400),
        "focus_keyword": s(1, 200),
        "content_html": s(200, 200000),
        "images": arr(obj({"key": s(pattern="^(featured|inline-[0-9]+)$"), "alt": s(1, 300), "caption": s(0, 500)}), 1, 7),
    }),
    "back_translation": obj({"title": s(1, 300), "content_text": s(1, 200000)}),
}

PLUGIN_SCHEMAS = {
    "claim-run.input": CLAIM_RUN_INPUT,
    "claim-run.output": CLAIM_RUN_OUTPUT,
    "get-config.input": GET_CONFIG_INPUT,
    "get-config.output": CONFIG,
    "get-inventory.input": GET_INVENTORY_INPUT,
    "get-inventory.output": GET_INVENTORY_OUTPUT,
    "get-profiles.input": GET_PROFILES_INPUT,
    "get-profiles.output": GET_PROFILES_OUTPUT,
    "save-profiles.input": SAVE_PROFILES_INPUT,
    "save-profiles.output": OK_OUTPUT,
    "report-stage.input": REPORT_STAGE_INPUT,
    "report-stage.output": OK_OUTPUT,
    "commit-post.input": COMMIT_INPUT,
    "commit-post.output": COMMIT_OUTPUT,
    "finish-run.input": FINISH_INPUT,
    "finish-run.output": OK_OUTPUT,
    "report-progress.input": REPORT_PROGRESS_INPUT,
    "report-progress.output": OK_OUTPUT,
    "save-checkpoint.input": SAVE_CHECKPOINT_INPUT,
    "save-checkpoint.output": OK_OUTPUT,
    "report-instagram.input": REPORT_INSTAGRAM_INPUT,
    "report-instagram.output": OK_OUTPUT,
    "report-worker-status.input": REPORT_WORKER_STATUS_INPUT,
    "report-worker-status.output": OK_OUTPUT,
    "get-ao-connection.input": GET_AO_CONNECTION_INPUT,
    "get-ao-connection.output": GET_AO_CONNECTION_OUTPUT,
    "save-ao-certificate.input": MTLS,
    "save-ao-certificate.output": SAVE_AO_CERTIFICATE_OUTPUT,
}

ENUMS = {
    "agent_roles": AGENT_ROLES,
    "qa_roles": QA_ROLES,
    "deterministic_roles": DETERMINISTIC_ROLES,
    "thresholds": THRESHOLDS,
    "image_sources": IMAGE_SOURCES,
    "license_codes": LICENSE_CODES,
    "stages": STAGES,
}


def render() -> dict[str, str]:
    files: dict[str, str] = {}
    for name, schema in PLUGIN_SCHEMAS.items():
        doc = {"$schema": "http://json-schema.org/draft-07/schema#", "title": f"aoq/{name}", **schema}
        files[f"abilities/{name}.json"] = json.dumps(doc, indent=2) + "\n"
    for name, schema in STAGE_SCHEMAS.items():
        doc = {"$schema": "http://json-schema.org/draft-07/schema#", "title": f"stage/{name}", **schema}
        files[f"stages/{name}.json"] = json.dumps(doc, indent=2) + "\n"
    files["enums.json"] = json.dumps(ENUMS, indent=2) + "\n"
    return files


def main(argv: list[str]) -> int:
    check = "--check" in argv
    files = render()
    stale: list[str] = []
    for target in TARGETS:
        for rel, text in files.items():
            path = target / rel
            current = path.read_text(encoding="utf-8") if path.is_file() else None
            if current == text:
                continue
            if check:
                stale.append(str(path.relative_to(ROOT)))
                continue
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(text, encoding="utf-8", newline="\n")
        for existing in target.rglob("*.json"):
            rel = existing.relative_to(target).as_posix()
            if rel not in files and not rel.startswith("fixtures/"):
                if check:
                    stale.append(str(existing.relative_to(ROOT)))
                else:
                    existing.unlink()
    if stale:
        print("Contracts out of date, run scripts/build_contracts.py:\n  " + "\n  ".join(stale))
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))

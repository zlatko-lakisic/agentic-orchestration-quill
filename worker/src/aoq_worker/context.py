"""Run state held in memory for one run. Nothing here outlives the process."""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

from .ao_client import AOClient
from .reporter import Reporter


@dataclass
class Inventory:
    languages: list[dict[str, Any]]
    posts: list[dict[str, Any]]
    terms: list[dict[str, Any]]
    media: list[dict[str, Any]]

    @property
    def active_languages(self) -> list[dict[str, Any]]:
        return [lang for lang in self.languages if not lang["excluded"]]

    @property
    def primary(self) -> str:
        return next(lang["code"] for lang in self.languages if lang["primary"])

    def posts_in(self, lang: str, *, published_only: bool = False, human_only: bool = False) -> list[dict[str, Any]]:
        return [
            p
            for p in self.posts
            if p["language"] == lang
            and (not published_only or p["status"] == "publish")
            and (not human_only or not p["ai_generated"])
        ]

    def published_ids(self, lang: str) -> set[int]:
        return {p["id"] for p in self.posts_in(lang, published_only=True)}

    def terms_in(self, lang: str, taxonomy: str) -> list[dict[str, Any]]:
        return [t for t in self.terms if t["language"] == lang and t["taxonomy"] == taxonomy]

    def term_ids(self, lang: str, taxonomy: str) -> set[int]:
        return {t["id"] for t in self.terms_in(lang, taxonomy)}

    def post_keys(self) -> set[str]:
        return {p["key"] for p in self.posts}

    def media_by_key(self) -> dict[str, dict[str, Any]]:
        return {m["key"]: m for m in self.media}


@dataclass
class RunContext:
    run_id: str
    dry_run: bool
    config: dict[str, Any]
    wp: Any
    reporter: Reporter
    ao: AOClient | None = None
    web: Any = None  # WebTools-like: ledger and fetch_image for stock images (D14)
    test: bool = False  # test run from the Generation tab: full pipeline, never committed
    preview: dict[str, Any] | None = None  # what would have been committed (test and dry runs)
    inventory: Inventory | None = None
    profiles: dict[str, Any] = field(default_factory=dict)
    site_profile: dict[str, Any] | None = None
    style_profiles: dict[str, dict[str, Any]] = field(default_factory=dict)
    candidates: list[dict[str, Any]] = field(default_factory=list)
    topic: dict[str, Any] | None = None
    topic_round: int = 1  # topics -> novelty rounds; a round with no novel topic goes back to topics
    rejected_topics: list[dict[str, str]] = field(default_factory=list)  # title and why, from earlier rounds
    sources: list[dict[str, Any]] = field(default_factory=list)
    claims: dict[str, dict[str, Any]] = field(default_factory=dict)
    outline: dict[str, Any] | None = None
    draft: dict[str, Any] | None = None
    taxonomy: dict[str, dict[str, Any]] = field(default_factory=dict)
    taxonomy_removed: dict[str, dict[str, Any]] = field(default_factory=dict)
    images: list[dict[str, Any]] = field(default_factory=list)
    inline_count: int | None = None  # in-body images actually used, when fewer were found than the setting asks
    image_texts: dict[str, dict[str, dict[str, str]]] = field(default_factory=dict)
    translations: dict[str, dict[str, Any]] = field(default_factory=dict)
    # Languages finished in the translate step (checks passed, terms chosen), with a hash of the
    # draft they were translated from: a resumed run keeps them while the draft is the same.
    translated: dict[str, str] = field(default_factory=dict)
    # Languages whose translation passed its checks (categories may still be missing), same hash.
    checked: dict[str, str] = field(default_factory=dict)
    # Called after each finished language to save the checkpoint (set by the pipeline).
    save_progress: Any = None
    instagram: dict[str, dict[str, Any]] = field(default_factory=dict)  # caption and hashtags per language (D29)
    scores: list[dict[str, Any]] = field(default_factory=list)
    bundle: dict[str, Any] | None = None
    commit_result: dict[str, Any] | None = None
    ao_info: dict[str, Any] = field(default_factory=lambda: {"engine_version": None, "capabilities": []})

    @property
    def inv(self) -> Inventory:
        assert self.inventory is not None
        return self.inventory

    @property
    def client(self) -> AOClient:
        assert self.ao is not None
        return self.ao

    def inline_keys(self) -> list[str]:
        """In-body image placeholders the post must have: the setting, or fewer when the images step
        found fewer relevant images."""
        from . import markup

        count = self.config["inline_image_count"] if self.inline_count is None else self.inline_count
        return markup.inline_keys(count)

    def target_languages(self) -> list[str]:
        """Active languages other than the primary."""
        return [lang["code"] for lang in self.inv.active_languages if not lang["primary"]]

"""Content markup conventions shared with the plugin (see class-aoq-commit.php).

- ``{{c:c1,c3}}`` after a factual sentence: the verified claims it rests on.
  Kept through QA and translation, stripped before commit.
- ``<!-- aoq:image inline-1 -->``: where an in-body image goes.
- ``<a data-aoq-post="123">``: internal link, resolved to a permalink by the plugin.

Only regular expression matching and set checks happen here.
"""

from __future__ import annotations

import html
import re

CLAIM_MARKER = re.compile(r"\{\{c:\s*([c0-9,\s]+?)\s*\}\}")
IMAGE_PLACEHOLDER = re.compile(r"<!--\s*aoq:image\s+(featured|inline-[0-9]+)\s*-->")
POST_LINK = re.compile(r'<a\s+data-aoq-post="([0-9]+)"')


def claim_ids(content: str) -> set[str]:
    out: set[str] = set()
    for group in CLAIM_MARKER.findall(content):
        out.update(x.strip() for x in group.split(",") if x.strip())
    return out


def claim_marker_count(content: str) -> int:
    return len(CLAIM_MARKER.findall(content))


def strip_claims(content: str) -> str:
    return CLAIM_MARKER.sub("", content)


def image_keys(content: str) -> list[str]:
    return IMAGE_PLACEHOLDER.findall(content)


def place_images(content: str, keys: list[str]) -> str:
    """Make the body carry exactly these in-body image placeholders, each once.

    Writers sometimes leave one out (test run 1659ebd2) or add one that was not asked for. Extra and
    repeated ones are removed; missing ones are put before a section heading spread through the post
    (or after a paragraph when there are too few headings), never before the first section."""
    seen: set[str] = set()

    def keep(m: re.Match) -> str:
        key = m.group(1)
        if key in keys and key not in seen:
            seen.add(key)
            return m.group(0)
        return ""

    content = IMAGE_PLACEHOLDER.sub(keep, content)
    missing = [k for k in keys if k not in seen]
    if not missing:
        return content
    spots = [m.start() for m in re.finditer(r"<h2\b", content, re.I)][1:]  # section starts, not the first
    if len(spots) < len(missing):
        spots = [m.end() for m in re.finditer(r"</p>", content, re.I)][:-1] or [len(content)]
    for n, key in enumerate(missing):
        # Evenly spread: the (n+1)/(k+1) point of the available spots.
        pos = spots[min(len(spots) - 1, (n + 1) * len(spots) // (len(missing) + 1))]
        placeholder = f"\n<!-- aoq:image {key} -->\n"
        content = content[:pos] + placeholder + content[pos:]
        spots = [p + len(placeholder) if p >= pos else p for p in spots]
    return content


BLOCK_END = re.compile(r"</(?:p|li|h[1-6]|blockquote|td|figcaption)\s*>", re.I)


def place_claims(source: str, content: str) -> tuple[str, list[str]]:
    """Make a translation cite exactly the source's claims; returns the body and the claim IDs put back.

    Translators sometimes drop a marker or change an ID (run e883d4d1, Bosnian revision). IDs the source
    does not cite are removed. A missing ID goes at the end of the block that matches the source block
    citing it: the same block when both have as many blocks, otherwise the one at the same relative place.
    Markers are stripped before commit, so a marker one sentence off does no harm to the published post."""
    wanted = claim_ids(source)

    def keep(m: re.Match) -> str:
        ids = [x.strip() for x in m.group(1).split(",") if x.strip() in wanted]
        return "{{c:" + ",".join(ids) + "}}" if ids else ""

    content = CLAIM_MARKER.sub(keep, content)
    missing = wanted - claim_ids(content)
    if not missing:
        return content, []
    src_ends = [m.start() for m in BLOCK_END.finditer(source)]
    ends = [m.start() for m in BLOCK_END.finditer(content)] or [len(content)]
    inserts: dict[int, list[str]] = {}
    for cid in sorted(missing, key=lambda c: source.find(c)):
        at = next((m.start() for m in CLAIM_MARKER.finditer(source) if cid in claim_ids(m.group(0))), len(source))
        block = next((i for i, e in enumerate(src_ends) if e >= at), max(len(src_ends) - 1, 0))
        if len(src_ends) != len(ends):
            block = round(block * (len(ends) - 1) / max(len(src_ends) - 1, 1))
        inserts.setdefault(min(block, len(ends) - 1), []).append(cid)
    for block in sorted(inserts, reverse=True):
        pos = ends[block]
        content = content[:pos] + "{{c:" + ",".join(inserts[block]) + "}}" + content[pos:]
    return content, sorted(missing)


# Writing system of each language the sites use; any other is Latin.
SCRIPT_OF = {"ar": "Arabic", "fa": "Arabic", "ur": "Arabic", "he": "Hebrew", "ru": "Cyrillic", "uk": "Cyrillic", "sr": "Cyrillic",
             "mk": "Cyrillic", "bg": "Cyrillic", "el": "Greek", "zh": "Han", "ja": "Han", "ko": "Hangul"}
_SCRIPTS = {
    "Latin": re.compile(r"[A-Za-zÀ-ɏḀ-ỿ]"),
    "Arabic": re.compile(r"[؀-ۿݐ-ݿࢠ-ࣿﭐ-﷿ﹰ-﻿]"),
    "Hebrew": re.compile(r"[֐-׿]"),
    "Cyrillic": re.compile(r"[Ѐ-ӿ]"),
    "Greek": re.compile(r"[Ͱ-Ͽ]"),
    "Han": re.compile(r"[぀-ヿ㐀-䶿一-鿿豈-﫿]"),
    "Hangul": re.compile(r"[가-힯ᄀ-ᇿ]"),
}


def foreign_script(text: str, lang: str) -> str:
    """Why a translation is not all in its language's writing system, or "" when it is.

    qwen2.5 switched to Chinese halfway through an Arabic post (run 8140dae6) and grammar QA scored
    it 1.0. Names and terms in Latin letters are normal in an Arabic post, so Latin is allowed up
    to a share there; letters of any third script (Chinese in Arabic, Cyrillic in German) are not."""
    plain = html.unescape(re.sub(r"<[^>]+>|\{\{c:[^}]*\}\}|https?://\S+", " ", text))
    want = SCRIPT_OF.get(lang.split("_")[0].lower(), "Latin")
    counts = {name: len(rx.findall(plain)) for name, rx in _SCRIPTS.items()}
    letters = sum(counts.values())
    if letters < 20:
        return ""
    others = {k: v for k, v in counts.items() if k not in (want, "Latin") and v}
    if sum(others.values()) > max(3, letters // 200):
        found = ", ".join(f"{k} ({v} letters)" for k, v in sorted(others.items(), key=lambda x: -x[1]))
        return f"part of it is written in another script: {found} of {letters} letters"
    if want != "Latin" and counts[want] < 0.6 * letters:
        return f"only {counts[want]} of {letters} letters are {want}"
    return ""


def link_ids(content: str) -> set[int]:
    return {int(x) for x in POST_LINK.findall(content)}


def inline_keys(count: int) -> list[str]:
    return [f"inline-{i}" for i in range(1, count + 1)]


def preview_html(content: str, claims: dict[str, dict]) -> str:
    """Dry-run preview: claim markers become footnote links with the quote as a tooltip."""

    def marker(m: re.Match[str]) -> str:
        ids = [x.strip() for x in m.group(1).split(",") if x.strip()]
        parts = []
        for cid in ids:
            c = claims.get(cid, {})
            title = html.escape(f"{c.get('claim', '')}\n\"{c.get('supporting_quote', '')}\"", quote=True)
            parts.append(f'<a class="claim" href="#{cid}" title="{title}">{html.escape(cid)}</a>')
        return "<sup>" + " ".join(parts) + "</sup>"

    return CLAIM_MARKER.sub(marker, content)

"""Stage 10: images (D27). Every image is scored against what the post's photos should show,
and the best one becomes the featured image.

* ``subjects``: a text agent names two to four concrete things a photo for this post should show.
* ``media_library``: the worker searches the whole library for those things (file name, title, alt
  text, caption), leaves out logos and maps, and a text agent shortlists from the best matches.
* ``stock``: an agent searches with the worker's ``image_search`` MCP (Openverse and Wikimedia
  Commons, served through the Reach tunnel, D14) and shortlists by image ID. Only IDs the tool
  returned in this run are accepted; license, author and URLs come from the tool's own record.

A vision agent describes each image (one call per image) and answers the text, logo, watermark and
people checks; a text agent scores each description 0-10 against the subjects. Images with text,
logos, watermarks or faces are never used. Stock searches repeat until there is a featured image
(landscape, at least 1200 px wide, featured pass mark) and enough in-body images (image pass mark).
When searching is exhausted the step falls back instead of failing: weaker images, then the
configured fallback image, and fewer in-body images. AI generated images remain an AO gap."""

from __future__ import annotations

import re

import base64
from pathlib import PurePosixPath
from typing import Any
from urllib.parse import urlparse

from .. import markup
from ..context import RunContext
from .common import clip, dumps, gate

SHIPPED_SOURCES = {"media_library", "stock", "web_search"}
MAX_SHORTLIST = 12
ALLOWED_MIMES = {"image/jpeg", "image/png", "image/webp", "image/gif"}
CHECK_FIELDS = ("relevant", "has_text", "has_logo", "has_watermark", "has_recognizable_people")

SUBJECTS = """Name two to four things a photo for this post should show, most important first. Each is a
short concrete phrase in English for something a camera can see (for example "snowy ski slope",
"Jahorina mountain", "Christmas market stalls", "cup of herbal tea"), not an idea or a mood. Name the
post's own subject, not the country or region in general.
When the post is about a place, put the place's name in every subject ("Old Bridge in Mostar", "Old Mosque in
Mostar", not "Ottoman-era mosque"), so a photo of the same kind of thing somewhere else does not match, and set
"place" to the town or site the post is about (one or two words, for example "Mostar"); else leave it empty.
The photos must also suit the site described in the site brief and its readers: follow what the brief says
about images and what the site is about (a pilgrimage site's photos show its churches, pilgrims and
landscapes, not the buildings of another faith)."""

SHORTLIST = """A photo for this post should show one of these: {subjects}. Pick up to {max} images from the
media library list that show one of them, best first. Judge from file name, title, alt text and caption. A
general view of the region is not enough. Pick none if none fit: stock photos are searched next."""

STOCK = """Find photos that show one of these: {subjects}. Use the image_search tool with two to four short
English queries naming those things (not the post title and not the region in general).
Then pick up to {max} images, best first, and list each by the exact image_id the tool returned, with a short
reason. Prefer landscape photos at least 1200 pixels wide. Avoid images whose titles suggest text, logos,
maps, posters or identifiable people. Pick at least {need} if that many could fit."""

JUDGE = """You see one image. First write one line "subject: ..." naming what the post is about, and one line
"shows: ..." naming what the image shows. Then answer each question about this image only, each on its own
line as "name: yes" or "name: no":
relevant: does the image show one of the post's subjects ({subjects})? A nice view of the region is not enough.
has_text: is any readable text, sign or lettering visible?
has_logo: is any logo, emblem, coat of arms or brand mark visible?
has_watermark: is any watermark or overlaid copyright visible?
has_recognizable_people: is any person's face visible, even partly or in profile?
Then write two more lines: "alt text: ..." (what the image shows, under 125 characters) and "caption: ...",
in language "{lang}"."""

RELEVANCE = """The post in the context is about: {title}. A photo for it should show one of: {subjects}.
Score each image description in the context from 0 to 10 for how well the image shows that:
10: clearly shows the first item. 8-9: clearly shows one of the items.
5-7: shows something closely related (the same place or activity in another season, a related thing).
1-4: only the country or region in general, a generic landscape, or other sights.
0: unrelated, a logo, a portrait, a room.
Give each image key with its score, "shows" (the listed item it shows, or "none") and a one-line reason."""

_ANSWER = re.compile(r"^\W*(relevant|has_text|has_logo|has_watermark|has_recognizable_people)\W*[:=-]\s*\W*(yes|no|true|false)\b", re.I | re.M)
# What an unanswered question counts as: always against the image.
_UNANSWERED = {"relevant": False, "has_text": True, "has_logo": True, "has_watermark": True, "has_recognizable_people": True}


def vision_answers(prose: str) -> tuple[dict[str, bool], list[str]]:
    """The yes/no answers the vision model itself gave, read from its own reply.

    The structurer must not decide these: in test run a0100eed the vision model answered only alt text and
    caption, and the structurer filled the checks with permissive defaults, passing a close-up face.
    Unanswered questions count against the image. Returns (answers, unanswered names).
    """
    found: dict[str, bool] = {}
    for name, value in _ANSWER.findall(prose or ""):
        found.setdefault(name.lower(), value.lower() in ("yes", "true"))
    missing = [k for k in CHECK_FIELDS if k not in found]
    return {k: found.get(k, _UNANSWERED[k]) for k in CHECK_FIELDS}, missing


SITE_LIBRARY = {
    "code": "site-library",
    "name": "Site media library",
    "url": None,
    "allows_download_and_reuse": True,
    "requires_attribution": False,
}


LIBRARY_OFFER_PAD = 10  # recent images added when few library items match the subjects
NOT_PHOTOS = re.compile(r"logo|icon|favicon|banner|\bmap\b|-map|map-|screenshot|\bqr\b|badge|button|placeholder|avatar|signature", re.I)
_STOP = {"the", "and", "for", "with", "from", "into", "near", "view", "photo", "image", "picture", "bosnia", "herzegovina"}


def _words(text: str) -> list[str]:
    return [w for w in re.findall(r"[a-z0-9\u00c0-\u024f]+", text.lower()) if len(w) >= 3 and w not in _STOP]


def library_matches(media: list[dict], subjects: list[str], limit: int) -> tuple[list[dict], int]:
    """The library items to offer the shortlist: those whose file name, title, alt text or caption
    mention the subjects, best match first (test run 41137d8d offered only the newest uploads and
    missed five ski resort photos). Logos, maps and other non-photos are left out. When fewer than
    LIBRARY_OFFER_PAD items match, recent photos fill up to that many, as a fallback pool.
    Returns (items, how many matched)."""
    terms = [_words(sub) for sub in subjects]
    scored: list[tuple[float, str, dict]] = []
    rest: list[dict] = []
    for m in media:
        file = PurePosixPath(urlparse(m["url"]).path).name
        if NOT_PHOTOS.search(file) or NOT_PHOTOS.search(m["title"]):
            continue
        hay = _words(" ".join((file.replace("-", " ").replace("_", " "), m["title"], m["alt"], m["caption"])))
        score = 0.0
        for rank, words in enumerate(terms):
            hits = sum(1 for w in words if any(h.startswith(w[:5]) for h in hay))
            if hits:
                # Earlier subjects matter more; a full phrase match counts double.
                score += (hits + (hits == len(words))) * (1.0 - 0.15 * rank)
        if score > 0:
            scored.append((score, m["date"], m))
        else:
            rest.append(m)
    scored.sort(key=lambda x: (x[0], x[1]), reverse=True)
    out = [m for _, _, m in scored[:limit]]
    matched = len(out)
    if len(out) < LIBRARY_OFFER_PAD:
        out += sorted(rest, key=lambda m: m["date"], reverse=True)[: LIBRARY_OFFER_PAD - len(out)]
    return out, matched


async def _media_library(ctx: RunContext, post: dict, need: int, detail: dict) -> tuple[list[dict], dict[str, dict]]:
    """Search, shortlist and load media library candidates. Returns vision images and records by key."""
    media = [m for m in ctx.inv.media if m["mime"] in ALLOWED_MIMES]
    media, matched = library_matches(media, post["subjects"], ctx.config["media_candidate_limit"])
    if not media:
        detail["media_library"] = {"skipped": "no photos in the media library"}
        return [], {}
    library = [
        {
            "media_key": m["key"],
            "file": PurePosixPath(urlparse(m["url"]).path).name,
            "title": clip(m["title"], 120),
            "alt": clip(m["alt"], 160),
            "caption": clip(m["caption"], 160),
        }
        for m in media
    ]
    res = await ctx.client.json_call(
        "image_shortlist",
        "images:shortlist",
        SHORTLIST.format(max=MAX_SHORTLIST, subjects=_list(post["subjects"])),
        dumps({"post": post, "media_library": library}),
        "image_shortlist",
    )
    detail.setdefault("_calls", []).extend(res.records)
    by_key = ctx.inv.media_by_key()
    allowed = {m["key"] for m in media}
    shortlist: list[str] = []
    for cand in res.data["candidates"]:
        if cand["media_key"] not in allowed:  # not in the library that was offered: ignore it
            detail.setdefault("ignored_media_keys", []).append(cand["media_key"])
            continue
        if cand["media_key"] not in shortlist:
            shortlist.append(cand["media_key"])

    images: list[dict] = []
    records: dict[str, dict] = {}
    errors: list[str] = []
    for key in shortlist:
        item = by_key[key]
        try:
            data, mime = await ctx.wp.fetch_media(item["preview_url"])
        except Exception as exc:  # noqa: BLE001 - recorded, candidate dropped
            errors.append(f"{key}: {exc}")
            continue
        if mime not in ALLOWED_MIMES:
            errors.append(f"{key}: unexpected type {mime}")
            continue
        problem = image_problem(data, mime)
        if problem:
            errors.append(f"{key}: {problem}")
            continue
        images.append({"mimeType": mime, "dataBase64": base64.b64encode(data).decode("ascii"), "name": key})
        records[key] = library_record(item)
    detail["media_library"] = {"offered": len(media), "matched_subjects": matched, "shortlisted": len(shortlist), "loaded": len(images), "fetch_errors": errors}
    return images, records


def library_record(item: dict) -> dict:
    return {
        "source": "media_library",
        "media": {"blog_id": item["blog_id"], "id": item["id"]},
        "url": None,
        "source_page_url": None,
        "license": dict(SITE_LIBRARY),
        "author": "",
        "attribution": "",
        "preview_url": item["preview_url"],
        "width": int(item.get("width") or 0),
        "height": int(item.get("height") or 0),
        "file": PurePosixPath(urlparse(item["url"]).path).name,
    }


def _list(subjects: list[str]) -> str:
    return "; ".join(f'"{x}"' for x in subjects)


STOCK_RETRIES = 4  # extra stock searches when too few images passed, each with new search terms
STOCK_RETRY = """

Earlier searches in this run gave too few good photos ({have} of {need} found). Search again with
different terms than before: closer views of the subject itself, its ingredients or parts, people doing the
activity, or a wider scene in which the subject is clearly visible. Do not pick these image_ids again:
{tried}."""


async def _stock(ctx: RunContext, post: dict, need: int, detail: dict, attempt: int = 1, tried: set | None = None, have: int = 0) -> tuple[list[dict], dict[str, dict]]:
    """Search and load stock candidates through the worker-hosted image_search MCP."""
    tried = tried or set()
    mcp_id = ctx.config["mcp"].get("stock_images")
    gate(bool(mcp_id), "images", "Stock images are enabled but no image search MCP is configured.")
    gate(ctx.web is not None, "images", "Stock images need the worker's web tools, which are not running.")
    assert ctx.web is not None
    prompt = STOCK.format(max=MAX_SHORTLIST, need=need, subjects=_list(post["subjects"]))
    if attempt > 1:
        prompt += STOCK_RETRY.format(have=have, need=have + need, tried=", ".join(sorted(tried)) or "none")
    res = await ctx.client.json_call(
        "image_shortlist",
        "images:stock" if attempt == 1 else f"images:stock:retry{attempt}",
        prompt,
        dumps({"post": post}),
        "stock_shortlist",
        mcp_ids=[mcp_id],
    )
    detail.setdefault("_calls", []).extend(res.records)
    shortlist: list[str] = []
    unknown: list[str] = []
    for cand in res.data["candidates"]:
        image_id = cand["image_id"]
        if image_id in tried:
            continue  # already judged in an earlier round
        if ctx.web.ledger.get(image_id) is None:
            unknown.append(image_id)  # not returned by the tool in this run: never trusted
            continue
        if image_id not in shortlist:
            shortlist.append(image_id)

    images: list[dict] = []
    records: dict[str, dict] = {}
    errors: list[str] = []
    for image_id in shortlist:
        cand = ctx.web.ledger.get(image_id)
        try:
            data, mime = await ctx.web.fetch_image(image_id)
        except Exception as exc:  # noqa: BLE001 - recorded, candidate dropped
            errors.append(f"{image_id}: {exc}")
            continue
        problem = image_problem(data, mime)
        if problem:
            errors.append(f"{image_id}: {problem}")
            continue
        images.append({"mimeType": mime, "dataBase64": base64.b64encode(data).decode("ascii"), "name": image_id})
        records[image_id] = {
            "source": "remote",
            "media": None,
            "url": cand.url,
            "source_page_url": cand.source_page_url,
            "license": dict(cand.license),
            "author": cand.author,
            "attribution": cand.attribution,
            "preview_url": cand.thumbnail_url,
            "width": cand.width,
            "height": cand.height,
            "file": PurePosixPath(urlparse(cand.url).path).name,
        }
    detail["stock" if attempt == 1 else f"stock_retry{attempt}"] = {"shortlisted": len(shortlist), "loaded": len(images), "unknown_ids": unknown, "fetch_errors": errors}
    return images, records


WEB_PER_ROUND = 8  # web search images sent to the vision check per search
# Paid stock agencies: their previews are watermarked, so the vision check rejects them anyway.
WATERMARKED = re.compile(r"shutterstock|dreamstime|alamy|gettyimages|istockphoto|depositphotos|123rf|stock\.adobe|bigstockphoto|canstockphoto|agefotostock", re.I)


async def _web(ctx: RunContext, post: dict, detail: dict, tried: set) -> tuple[list[dict], dict[str, dict]]:
    """SearXNG image search (all engines) for each subject, as short queries (D28). Each image keeps
    a link to the page it was found on; its license is "unchecked". The full-size image is loaded
    (WordPress downloads the same URL when committing), so an image that cannot be fetched is dropped."""
    gate(ctx.web is not None, "images", "Web image search needs the worker's web tools, which are not running.")
    assert ctx.web is not None
    per_query: list[list] = []
    errors: list[str] = []
    skipped = {"watermarked_stock": 0, "small": 0}
    for query in post["subjects"][:4]:
        try:
            found = await ctx.web.web_image_search(query)
        except Exception as exc:  # noqa: BLE001 - recorded, other queries still count
            errors.append(f"{query}: {exc}")
            continue
        keep = []
        for cand in found:
            if cand.image_id in tried:
                continue
            if WATERMARKED.search(cand.source_page_url) or WATERMARKED.search(cand.url):
                skipped["watermarked_stock"] += 1
                continue
            if cand.width and cand.width < 800:
                skipped["small"] += 1
                continue
            keep.append(cand)
        per_query.append(keep)
    picked: list = []
    seen: set[str] = set()
    while len(picked) < WEB_PER_ROUND and any(per_query):
        for items in per_query:  # interleave: the best results of every subject first
            while items:
                cand = items.pop(0)
                if cand.image_id not in seen:
                    seen.add(cand.image_id)
                    picked.append(cand)
                    break
            if len(picked) >= WEB_PER_ROUND:
                break
    images: list[dict] = []
    records: dict[str, dict] = {}
    for cand in picked:
        try:
            data, mime = await ctx.web.fetch_image(cand.image_id, thumbnail=False)
        except Exception as exc:  # noqa: BLE001 - recorded, candidate dropped
            errors.append(f"{cand.image_id}: {exc}")
            continue
        problem = image_problem(data, mime)
        if problem:
            errors.append(f"{cand.image_id}: {problem}")
            continue
        images.append({"mimeType": mime, "dataBase64": base64.b64encode(data).decode("ascii"), "name": cand.image_id})
        records[cand.image_id] = {
            "source": "remote",
            "media": None,
            "url": cand.url,
            "source_page_url": cand.source_page_url,
            "license": dict(cand.license),
            "author": cand.author,
            "attribution": cand.attribution,
            "preview_url": cand.thumbnail_url,
            "width": cand.width,
            "height": cand.height,
            "file": PurePosixPath(urlparse(cand.url).path).name,
        }
    detail["web_search"] = {"queries": post["subjects"][:4], "picked": len(picked), "loaded": len(images), "skipped": skipped, "errors": errors}
    return images, records


def image_problem(data: bytes, mime: str) -> str | None:
    """Why an image would be rejected by the vision model, or None when it looks complete.

    One broken image makes Ollama refuse the whole vision call, so incomplete files are dropped
    here: a JPEG must start with SOI and end with EOI, a PNG must end with its IEND chunk, and a
    WebP's RIFF size must match its length.
    """
    if mime == "image/jpeg":
        if not data.startswith(b"\xff\xd8"):
            return "not a JPEG"
        if b"\xff\xd9" not in data[-16:]:
            return "JPEG is cut short"
    elif mime == "image/png":
        if not data.startswith(b"\x89PNG\r\n\x1a\n"):
            return "not a PNG"
        if b"IEND" not in data[-16:]:
            return "PNG is cut short"
    elif mime == "image/webp":
        if data[:4] != b"RIFF" or data[8:12] != b"WEBP":
            return "not a WebP"
        if int.from_bytes(data[4:8], "little") + 8 > len(data):
            return "WebP is cut short"
    return None


async def _judge(ctx: RunContext, post: dict, lang: str, images: list[dict], detail: dict, source: str) -> list[dict]:
    """Describe, check and score each image. Returns the verdicts of usable images (no text, logo,
    watermark or face), each with a 0-10 ``score`` against the post's subjects.

    One vision call per image: judging several in one call let the model shift its verdicts between
    them (test run df73a0e9). The score comes from a text model reading the vision model's
    descriptions: the small vision model judged "relevant" against the image, not the post (test run
    a4e81d36), and a yes/no let a photo of ruins pass for a winter post (test run 41137d8d)."""
    sent = [img["name"] for img in images]
    verdicts: dict[str, dict] = {}
    for n, img in enumerate(images, 1):
        judged = await ctx.client.json_call(
            "image_vision",
            f"images:judge:{source}:{n}",
            JUDGE.format(lang=lang, subjects=_list(post["subjects"])),
            dumps({"post": post}),
            "image_judgement",
            images=[img],
        )
        detail.setdefault("_calls", []).extend(judged.records)
        if judged.data["images"]:
            answers, missing = vision_answers(judged.prose)
            verdicts[img["name"]] = dict(judged.data["images"][0], image_key=img["name"], **answers)
            if missing:
                detail.setdefault(source, {}).setdefault("unanswered", {})[img["name"]] = missing
    info = detail.setdefault(source, {})
    scores: dict[str, dict] = {}
    if verdicts:
        rel = await ctx.client.json_call(
            "image_shortlist",
            f"images:relevance:{source}",
            RELEVANCE.format(title=post["title"], subjects=_list(post["subjects"])),
            dumps({"post": post, "images": [{"image_key": k, "alt_text": v["alt_text"], "caption": v["caption"]} for k, v in verdicts.items()]}),
            "image_relevance",
        )
        detail.setdefault("_calls", []).extend(rel.records)
        scores = {x["image_key"]: x for x in rel.data["images"]}
    usable: list[dict] = []
    info["judged"] = len(verdicts)
    info["scores"] = {}
    info["rejected"] = []
    for k in sent:
        v = verdicts.get(k)
        if v is None:
            continue
        sc = scores.get(k)
        score = float(sc["score"]) if sc else 0.0
        if sc and not v["relevant"]:
            score = max(0.0, score - 2)  # the vision model did not see the subject either
        v["score"] = round(score, 1)
        v["shows"] = sc["shows"] if sc else "none"
        info["scores"][k] = {"score": v["score"], "shows": v["shows"], "reason": sc["reason"] if sc else "not scored"}
        flags = [f for f in CHECK_FIELDS[1:] if v[f]]
        if flags:
            info["rejected"].append({"image_key": k, "because": flags})
            continue
        usable.append(v)
    info["usable"] = len(usable)
    return usable


FEATURED_MIN_WIDTH = 1200  # the theme shows the featured image as a wide banner (1290 x 540)
FALLBACK_FLOOR = 3  # below this a judged image is unrelated; the fallback image is used first


def _stem(name: str) -> str:
    stem = re.sub(r"\.[a-z0-9]+$", "", name.lower())
    stem = re.sub(r"(-\d+x\d+|-scaled|-min|-opt|-e\d+|-\d{1,2})+$", "", stem)
    return re.sub(r"[^a-z0-9]+", "", stem)


def _similar(a: dict, b: dict) -> bool:
    """Near-duplicates: the same file (sizes and copies included) or nearly the same description."""
    ra, rb = a["record"], b["record"]
    if ra.get("media") and ra["media"] == rb.get("media"):
        return True
    if ra.get("url") and ra["url"] == rb.get("url"):
        return True
    if ra.get("file") and rb.get("file") and _stem(ra["file"]) == _stem(rb["file"]):
        return True
    wa, wb = set(_words(a["verdict"]["alt_text"])), set(_words(b["verdict"]["alt_text"]))
    return bool(wa and wb) and len(wa & wb) / len(wa | wb) >= 0.6


def _plain(text: str) -> str:
    """Lowercase ASCII letters and digits, words joined by spaces: "Mostar" in "Stari_Most-Mostar.jpg"."""
    import unicodedata

    text = unicodedata.normalize("NFKD", text).encode("ascii", "ignore").decode().lower()
    return " " + re.sub(r"[^a-z0-9]+", " ", text) + " "


def trust(record: dict, place: str) -> int:
    """How sure we are that a photo shows the post's place: 2 for the site's own media library,
    1 for a stock or web photo whose image or page address names the place, 0 otherwise (Medjugorje
    run 205ad21e put the Blue Mosque in Istanbul, from a Reddit post, on a Mostar article: the vision
    model scored it 10 for "Ottoman-era mosque" and it outranked the site's own Stari Most photos)."""
    if record.get("source") == "media_library":
        return 2
    words = [w for w in _plain(place).split() if len(w) >= 4]
    where = _plain(f"{record.get('source_page_url') or ''} {record.get('url') or ''} {record.get('file') or ''}")
    return 1 if words and any(f" {w} " in where for w in words) else 0


def featured_fit(c: dict) -> bool:
    r = c["record"]
    return r.get("width", 0) >= FEATURED_MIN_WIDTH and r.get("width", 0) > r.get("height", 0)


def assign(pool: list[dict], inline_needed: int, image_min: float, featured_min: float) -> tuple[dict | None, list[dict], str]:
    """Pick the featured image and the in-body images from the pool of usable candidates.

    The best candidate goes to the featured slot, never the first that passed. Preference for the
    featured image: a landscape photo at the featured pass mark; else the best at the image pass
    mark (landscape first); else the best above FALLBACK_FLOOR. In-body images must reach the image
    pass mark, and no two chosen images may be near-duplicates. Returns (featured, inline, how the
    featured image was found).

    Among the images that reach the image pass mark, trust comes before score: the site's own photos
    first, then stock or web photos whose page names the post's place, then the rest."""
    ranked = sorted(
        pool,
        key=lambda c: (c["verdict"]["score"] >= image_min, c.get("trust", 0), c["verdict"]["score"], featured_fit(c)),
        reverse=True,
    )
    tiers = [
        ("best", lambda c: c["verdict"]["score"] >= featured_min and featured_fit(c)),
        ("below the featured pass mark", lambda c: c["verdict"]["score"] >= image_min and featured_fit(c)),
        ("not landscape or under 1200 px", lambda c: c["verdict"]["score"] >= image_min),
        ("weak match", lambda c: c["verdict"]["score"] >= FALLBACK_FLOOR),
    ]
    featured, how = None, "none"
    for name, ok in tiers:
        featured = next((c for c in ranked if ok(c)), None)
        if featured:
            how = name
            break
    inline: list[dict] = []
    for c in ranked:
        if len(inline) >= inline_needed:
            break
        if c is featured or c["verdict"]["score"] < image_min:
            continue
        if any(_similar(c, o) for o in [*([featured] if featured else []), *inline]):
            continue
        inline.append(c)
    return featured, inline, how


def satisfied(pool: list[dict], inline_needed: int, image_min: float, featured_min: float) -> bool:
    featured, inline, how = assign(pool, inline_needed, image_min, featured_min)
    return how == "best" and len(inline) >= inline_needed


def _fallback(ctx: RunContext, pool: list[dict]) -> dict | None:
    """The configured fallback image, or, when none is set, the best usable image whatever its score."""
    key = ctx.config.get("fallback_image")
    item = ctx.inv.media_by_key().get(key) if key else None
    if item:
        return {
            "verdict": {"score": 0.0, "shows": "fallback image", "alt_text": item["alt"] or item["title"] or "", "caption": ""},
            "record": library_record(item),
            "key": key,
        }
    return max(pool, key=lambda c: c["verdict"]["score"], default=None)


async def run(ctx: RunContext) -> list[dict]:
    assert ctx.draft is not None
    lang = ctx.inv.primary
    inline_needed = int(ctx.config["inline_image_count"])
    need = 1 + inline_needed
    image_min = float(ctx.config.get("image_min_score", 6))
    featured_min = float(ctx.config.get("featured_min_score", 8))
    async with ctx.reporter.stage("images") as rec:
        enabled = [s["id"] for s in sorted(ctx.config["image_sources"], key=lambda s: s["order"]) if s["enabled"]]
        gate(bool(enabled), "images", "No image source is enabled.")
        for source in enabled:
            gate(source in SHIPPED_SOURCES, "images", f"Image source {source} depends on an AO gap and is not available.")

        post: dict[str, Any] = {"title": ctx.draft["title"], "excerpt": ctx.draft["excerpt"], "sections": [s["heading"] for s in (ctx.outline or {}).get("sections", [])]}
        detail: dict[str, Any] = {"sources": enabled, "pass_marks": {"image": image_min, "featured": featured_min}}
        pool: list[dict] = []  # usable candidates: {"verdict", "record", "key"}
        judged: set[str] = set()  # image keys already judged, never offered again
        try:
            subj = await ctx.client.json_call("image_shortlist", "images:subjects", SUBJECTS, dumps({"post": post, "site_brief": ctx.config.get("site_brief") or ""}), "image_subjects")
            detail.setdefault("_calls", []).extend(subj.records)
            post["subjects"] = subj.data["subjects"][:4]
            post["place"] = str(subj.data.get("place") or "").strip()
            detail["subjects"] = post["subjects"]
            if post["place"]:
                detail["place"] = post["place"]

            async def take(loader_images: list[dict], records: dict[str, dict], source: str) -> None:
                judged.update(img["name"] for img in loader_images)
                for verdict in await _judge(ctx, post, lang, loader_images, detail, source):
                    record = records[verdict["image_key"]]
                    pool.append({"verdict": verdict, "record": record, "key": verdict["image_key"], "trust": trust(record, post["place"])})

            for source in enabled:
                if satisfied(pool, inline_needed, image_min, featured_min):
                    break
                if source == "media_library":
                    images, records = await _media_library(ctx, post, need, detail)
                elif source == "web_search":
                    images, records = await _web(ctx, post, detail, judged)
                else:
                    images, records = await _stock(ctx, post, need, detail, tried=judged, have=0)
                if images:
                    await take(images, records, source)
            # Not good enough yet: search stock photos again with new terms (at most STOCK_RETRIES
            # more rounds), until there is a featured image and enough in-body images.
            attempt = 1
            while "stock" in enabled and attempt <= STOCK_RETRIES and not satisfied(pool, inline_needed, image_min, featured_min):
                attempt += 1
                have = len([c for c in pool if c["verdict"]["score"] >= image_min])
                images, records = await _stock(ctx, post, max(1, need - have), detail, attempt=attempt, tried=judged, have=have)
                detail["stock_rounds"] = attempt
                if images:
                    await take(images, records, f"stock_retry{attempt}")
        finally:
            rec.add_calls(detail.pop("_calls", []))
            rec.detail = detail

        featured, inline, how = assign(pool, inline_needed, image_min, featured_min)
        if featured is None:
            # Nothing above the floor: the configured fallback image, else the best usable image.
            featured = _fallback(ctx, pool)
            how = "fallback image" if featured and featured.get("key") == ctx.config.get("fallback_image") else "best available"
            inline = [c for c in inline if c is not featured]
        gate(
            featured is not None,
            "images",
            "No usable image was found and no fallback image is set (Settings, Images).",
        )
        assert featured is not None
        detail["featured_choice"] = how
        if len(inline) < inline_needed:
            # Fewer in-body images rather than a failed run: the draft's extra placeholders go.
            detail["inline_short"] = {"wanted": inline_needed, "used": len(inline)}
            ctx.inline_count = len(inline)
            ctx.draft["content_html"] = markup.place_images(ctx.draft["content_html"], ctx.inline_keys())
        chosen = [("featured", featured), *zip(ctx.inline_keys(), inline)]
        ctx.images = []
        ctx.image_texts[lang] = {}
        for key, c in chosen:
            record = {k: v for k, v in c["record"].items() if k not in ("width", "height", "file")}
            ctx.images.append({"key": key, **record})
            ctx.image_texts[lang][key] = {"alt": c["verdict"]["alt_text"], "caption": c["verdict"]["caption"]}
        detail["ranking"] = [
            {"image_key": c["key"], "score": c["verdict"]["score"], "shows": c["verdict"]["shows"], "featured_fit": featured_fit(c)}
            for c in sorted(pool, key=lambda c: c["verdict"]["score"], reverse=True)
        ]
        # For the Generation tab's gallery (rec.detail is this same dict).
        detail["chosen"] = [
            {
                "key": img["key"],
                "source": img["source"],
                "preview_url": img.get("preview_url") or img.get("url"),
                "full_url": img.get("url") or img.get("preview_url"),
                "alt": ctx.image_texts[lang][img["key"]]["alt"],
                "caption": ctx.image_texts[lang][img["key"]]["caption"],
                "license": (img.get("license") or {}).get("name") or (img.get("license") or {}).get("code") or "",
                "attribution": img.get("attribution") or "",
                "source_page_url": img.get("source_page_url") or "",
                "score": c["verdict"]["score"],
            }
            for img, (_, c) in zip(ctx.images, chosen)
        ]
        for img in ctx.images:
            # Web search images have an unchecked license by design (D28); others must allow reuse.
            if img["license"]["code"] != "unchecked":
                gate(img["license"]["allows_download_and_reuse"], "images", f"Image {img['key']} has no license that allows reuse.")
            if img["license"]["requires_attribution"]:
                gate(bool(img["attribution"].strip()), "images", f"Image {img['key']} needs attribution text and has none.")
        return ctx.images

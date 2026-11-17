"""Dry-run output: the bundle as JSON plus an HTML preview per language, written to
./out/<run_id>/. Output only; nothing reads these files back."""

from __future__ import annotations

import html
import json
from pathlib import Path
from typing import Any

from . import markup
from .context import RunContext

STYLE = """body{font:16px/1.6 system-ui,sans-serif;max-width:860px;margin:2rem auto;padding:0 16px;color:#1d1d1f;background:#fff}
h1{font-size:1.8rem}figure{margin:1.5rem 0}img{max-width:100%;height:auto}
sup a.claim{font-size:.75rem;text-decoration:none;background:#eef;padding:0 .25em;border-radius:3px;margin-left:2px}
table{border-collapse:collapse;width:100%;font-size:.9rem}td,th{border:1px solid #ddd;padding:4px 8px;text-align:left}
.meta{color:#555;font-size:.9rem}.fail{color:#b00}section{margin-top:2rem}"""


def _image_html(ctx: RunContext, lang: str, key: str) -> str:
    img = next((i for i in ctx.images if i["key"] == key), None)
    text = ctx.image_texts.get(lang, {}).get(key, {})
    if img is None:
        return f"<p class='fail'>Missing image {html.escape(key)}</p>"
    src = img.get("preview_url") or img.get("url") or ""
    cap = html.escape(text.get("caption", ""))
    lic = html.escape(f"{img['license']['name']} {img['attribution']}".strip())
    return f"<figure><img src='{html.escape(src)}' alt='{html.escape(text.get('alt', ''))}'><figcaption>{cap} <span class='meta'>[{lic}]</span></figcaption></figure>"


def preview(ctx: RunContext, lang: str, doc: dict[str, Any]) -> str:
    body = markup.preview_html(doc["content_html"], ctx.claims)
    body = markup.IMAGE_PLACEHOLDER.sub(lambda m: _image_html(ctx, lang, m.group(1)), body)
    body = markup.POST_LINK.sub(lambda m: f'<a href="#post-{m.group(1)}" title="internal link to post {m.group(1)}"', body)
    scores = "".join(
        f"<tr><td>{html.escape(s['check'])}</td><td>{s['score']:.2f}</td></tr>" for s in ctx.scores if s["language"] == lang
    )
    claims = "".join(
        f"<tr id='{html.escape(cid)}'><td>{html.escape(cid)}</td><td>{html.escape(c['claim'])}</td><td>“{html.escape(c['supporting_quote'])}”</td><td>{html.escape(c['source_id'])}</td></tr>"
        for cid, c in ctx.claims.items()
    )
    sources = "".join(
        f"<li>{html.escape(s['source_id'])}: <a href='{html.escape(s['url'])}'>{html.escape(s.get('title') or s['url'])}</a></li>" for s in ctx.sources
    )
    tax = ctx.taxonomy.get(lang, {})
    return f"""<!doctype html><html lang="{html.escape(lang)}"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>{html.escape(doc['title'])}</title><style>{STYLE}</style></head><body>
<p class="meta">Dry run {html.escape(ctx.run_id)}, language {html.escape(lang)}. SEO title: {html.escape(doc['seo_title'])}.
Description: {html.escape(doc['seo_description'])}. Focus keyword: {html.escape(doc['focus_keyword'])}.
Category IDs {tax.get('category_ids')}, tag IDs {tax.get('tag_ids')}.</p>
{_image_html(ctx, lang, 'featured')}
<h1>{html.escape(doc['title'])}</h1>
{body}
<section><h2>QA scores</h2><table><tr><th>Check</th><th>Score</th></tr>{scores}</table></section>
<section><h2>Verified claims</h2><table><tr><th>ID</th><th>Claim</th><th>Quote verified on the server</th><th>Source</th></tr>{claims}</table></section>
<section><h2>Sources</h2><ul>{sources}</ul></section>
</body></html>"""


def write(ctx: RunContext, outcome: Any, out_dir: str | Path = "out") -> Path:
    target = Path(out_dir) / ctx.run_id
    target.mkdir(parents=True, exist_ok=True)
    summary = {
        "run_id": ctx.run_id,
        "status": outcome.status,
        "failure_reason": outcome.reason,
        "topic": ctx.topic,
        "scores": ctx.scores,
        "ao": ctx.ao_info,
        "stages": ctx.reporter.log,
    }
    (target / "run.json").write_text(json.dumps(summary, indent=2, ensure_ascii=False), encoding="utf-8")
    if ctx.bundle is not None:
        (target / "bundle.json").write_text(json.dumps(ctx.bundle, indent=2, ensure_ascii=False), encoding="utf-8")
    docs = {}
    if ctx.draft is not None and ctx.inventory is not None:
        docs[ctx.inv.primary] = ctx.draft
    docs.update(ctx.translations)
    for lang, doc in docs.items():
        (target / f"preview-{lang}.html").write_text(preview(ctx, lang, doc), encoding="utf-8")
    return target

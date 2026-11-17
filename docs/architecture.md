# Architecture

AO Quill writes one original post a day for a multilingual WordPress network and saves it in every site language, linked as translations, marked as AI generated in a way visitors never see.

## Thin client, thick server

```
                 HTTPS, Application Password                 WebSocket + REST (optional mTLS)
 +--------------------------+   abilities only   +----------------------+   direct_agent only   +-------------------------------+
 | WordPress (plugin)       | <----------------- | Worker (thin client) | --------------------> | AO engine (thick server)      |
 |                          |                    |                      |                       |                               |
 | settings, schedule,      |   claim-run        | sequences 15 stages  |   one call per stage  | models (drafting, QA, vision) |
 | run log, profile cache,  |   get-config       | validates JSON shape |   with json_schema    | MCP tools via Reach tunnel    |
 | all writes to the site:  |   get-inventory    | applies gates to     |                       | deterministic agents:         |
 |  image sideload, posts,  |   get/save-profiles|  structured results  |                       |  aoq_quote_verifier           |
 |  terms, SEO, links,      |   report-stage     | no state between     |                       |  aoq_text_checks              |
 |  status, AI marking,     |   commit-post      |  runs, no GPU        |                       | overlay agents (client.aoq_*) |
 |  rollback                |   finish-run       |                      |                       |                               |
 +--------------------------+                    +----------------------+                       +-------------------------------+
```

- **AO** does every piece of model work: drafting, tool use, vision, QA scoring, and deterministic verification. This repository ships the server-side pieces as files in `/ao-server/`; they are installed by hand.
- **The worker** (`/worker`) is a Python app on AO Reach. It drives the pipeline against the WordPress Abilities REST API and the AO engine. It also serves three MCP tools to AO through the Reach reverse tunnel: web search (SearXNG), page fetch and licensed image search (`docs/decisions.md` D14). AO's agents decide when to call them; the requests come back over the worker's own WebSocket to loopback-only servers. It computes nothing that needs a model: JSON Schema validation, set membership checks, sequencing, retries, timeouts, reporting and those tool calls. A test fails if any module other than `wp_client.py`, `ao_client.py`, `web_tools.py` and `mcp_host.py` imports a network library, and another runs a whole pipeline with sockets disabled.
- **The plugin** (`/wordpress-plugin/ao-quill`) owns configuration, the schedule and every write. The worker never touches the database, never decides post status, and its WordPress user holds a single custom capability.

## Multisite per language

The site is a subdirectory multisite with one site per language and no multilingual plugin (`docs/decisions.md` D4). The plugin is network activated:

- Languages come from each site's language setting; the main site is the primary language. Two active sites with the same language block runs until one is excluded.
- Abilities are registered on the main site and switch sites internally.
- `commit-post` writes one post per site and links them in a network table (`aoq_translations`). Existing human posts have no links; nothing in the network recorded them.
- Terms are per site and have no known translations, so each language's terms are chosen from that site's own list.

`AOQ_Translation_Adapter` keeps the door open for WPML, Polylang or TranslatePress. Only the multisite adapter exists.

## Pipeline

Fixed order, no dynamic planning. Each AO step is one `direct_agent` call with a JSON Schema from `/docs/contracts/stages`. When a step needs MCP tools or images, AO's JSON mode ignores them, so it takes two calls: the tool or vision call returns prose, then `client.aoq_structurer` returns JSON under the schema (`docs/decisions.md` D3). Nothing verifiable is trusted from the prose; quotes are checked again on the server.

| # | Stage | Runs on | Gate (code, not instructions) |
|---|---|---|---|
| 1 | Inventory | WordPress | At least one active language, the primary present, no duplicate languages, a category in every language |
| 2 | Site profile | AO, cached in WordPress | Schema. Reused unless more than 5% of post keys changed or older than 7 days |
| 3 | Style profiles | AO, cached | Built only from posts without `_aoq_generated`. Primary always; other languages with enough human posts. Rebuilt when a new human post appears |
| 4 | Topics | AO with search MCP | Ten candidates, unique IDs, every target category exists |
| 5 | Novelty | AO model judge (partial) | A chosen candidate exists, was judged novel, cited posts exist |
| 6 | Research | AO with search and fetch MCPs | Unique IDs, every claim cites a listed source |
| 6b | Quote verification | AO deterministic `aoq_quote_verifier` | Each quote is an exact substring of the source text fetched on the server; at least the configured number verified; unverified claims are dropped |
| 7 | Outline | AO | Internal link targets are published posts in the primary language; claim IDs are verified |
| 8 | Draft | AO drafting agent | Claim markers cite only verified claims, at least one; links valid; image placeholders exactly as configured; no H1 |
| 9 | Taxonomy | AO | Every term ID exists in that language, at least one category, never new terms |
| 10 | Images | AO text and vision agents | Enabled sources are shipped ones; every chosen image passed vision checks (relevant, no text, logo, watermark or recognizable person); every image has a license that allows reuse |
| 11 | QA loop | AO QA agents and `aoq_text_checks` | Every pass at or above threshold within the loop limit |
| 12 | Translate | AO, per language | Claim markers and image placeholders unchanged; links point to posts in that language; image text for every image |
| 13 | Per-language QA and terms | AO | Same loop rules; terms exist in that language |
| 14 | Commit | WordPress | Schema plus plugin checks: every language exactly once, terms exist, links are published posts, no claim markers left, license and attribution rules; rollback on any failure |
| 15 | Finish | WordPress | A run cannot succeed without a commit |

### QA mechanisms

| Pass | Mechanism |
|---|---|
| Grammar and spelling | QA agent, JSON score and fixes. Provider family must differ from the drafter (checked by the plugin on save) |
| Factual grounding | QA agent with the verified claims; any unsupported sentence fails the pass. Code checks that every claim marker is a verified claim |
| Style | QA agent with the style profile |
| Structure and SEO | QA agent for judgment, plus `aoq_text_checks` for heading order, H1, title, SEO title and description lengths |
| Originality | `aoq_text_checks`: share of the post's 5-word sequences found in any source page (fetched on the server) or any existing post in that language |
| Translation fidelity | Back translation by one agent, comparison by another |

AO harness profiles and impartial QA do not apply to `direct_agent` JSON calls, so they are not used yet (`qa_harness`, planned).

### Content conventions

| Marker | Meaning | Handled by |
|---|---|---|
| `{{c:c1,c3}}` after a sentence | Claims the sentence rests on | Kept through QA and translation, stripped by the worker before commit; the plugin rejects any left over |
| `<!-- aoq:image inline-1 -->` | In-body image position | Replaced by the plugin with the image for that site |
| `<a data-aoq-post="123">` | Internal link | Resolved by the plugin to the permalink on that site |

## Contracts

`scripts/build_contracts.py` generates every JSON Schema into `/docs/contracts` and copies it to the plugin and the worker. Schemas avoid `$ref`, `const` and `format` because the WordPress REST validator does not support them. Shared fixtures in `/docs/contracts/fixtures` are validated by both the PHP and the Python tests.

| Ability | Method | Purpose |
|---|---|---|
| `aoq/claim-run` | POST | Heartbeat and AO catalog in; a run with a lease out, or nothing |
| `aoq/get-config` | GET | Config for the run (or current config for a dry run) |
| `aoq/get-inventory` | GET | Languages, posts, terms and media of every active site, paginated |
| `aoq/get-profiles` | GET | Cached site and style profiles |
| `aoq/save-profiles` | POST | Store profiles with their fingerprints |
| `aoq/report-stage` | POST | One row per stage attempt |
| `aoq/commit-post` | POST | Whole multilingual bundle in one call, all or nothing |
| `aoq/finish-run` | POST | Close the run with its result |

Read-only abilities carry `meta.mcp.public = true` for the MCP Adapter; write abilities do not.

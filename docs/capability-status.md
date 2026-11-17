# Capability status

Every capability is labeled shipped, partial, or planned. The plugin status panel reads the same list from `wordpress-plugin/ao-quill/capabilities.json`, and a test fails when this page and that file disagree.

"Shipped" means the code is in this repository and covered by unit tests against fakes. Nothing has been verified end to end against the real AO engine or a staging copy of the site yet; the last column says so.

| ID | Capability | Status | Notes | End to end |
|---|---|---|---|---|
| `schedule` | Daily schedule, run now, lease | shipped |  | | not yet |
| `ai_marking` | Private AI marking (meta and non-public taxonomy) | shipped |  | not yet |
| `multisite_languages` | Multisite per language adapter | shipped |  | | not yet |
| `translation_links` | Translation links for posts this plugin creates | shipped | Stored by the plugin. Existing human posts have no links. | not yet |
| `term_glossary` | Glossary from the site's term translations | partial | The network stores no term translations. Translators get the target site's own term names instead. | not yet |
| `seo_aioseo` | All in One SEO title, description and focus keyword | shipped |  | not yet |
| `site_profile` | Site profile, cached in WordPress | shipped |  | | not yet |
| `style_profile` | Style profiles per language from human posts | shipped | Passed as call context, not as a session skill. | not yet |
| `topic_search` | Topic candidates from web search | shipped | Served by the worker (client.aoq_search, SearXNG) over the AO Reach tunnel, or a search MCP on the AO server. | not yet |
| `novelty_retrieval` | Novelty check with embedding retrieval | planned | AO cannot index client documents for embedding retrieval. See docs/ao-gaps.md item 3. | no |
| `novelty_judge` | Novelty check by model judge over titles and excerpts | partial | Fallback until embedding retrieval exists. | not yet |
| `research_grounding` | Quotes verified as exact substrings on the server | shipped | Needs the aoq_quote_verifier deterministic agent installed on the AO server. | not yet |
| `originality_check` | Word n-gram originality on the server | shipped | Needs the aoq_text_checks deterministic agent installed on the AO server. | not yet |
| `images_media_library` | Image reuse from the media library, judged by a vision agent | shipped |  | | not yet |
| `images_stock` | Stock image search (Openverse, Wikimedia Commons) | shipped | Served by the worker (client.aoq_images) over the AO Reach tunnel. Licenses limited to CC0, public domain, CC BY and CC BY-SA; license and attribution come from the provider, not the model. | no |
| `images_web_search` | Web image search through SearXNG (all engines) | shipped | Served by the worker. Licenses are not checked: each image is linked to the page it was found on and marked license unchecked (D28). | no |
| `images_ai_generated` | AI generated images | planned | AO has no image generation, and no generation service is available to serve from the worker. See docs/ao-gaps.md item 5. | no |
| `qa_harness` | AO harness profiles and impartial QA on QA passes | planned | AO JSON mode does not apply harness profiles or impartial QA to direct_agent calls. | no |
| `usage_reporting` | Token usage and cost in the run log | planned | AO records usage server side but does not return it to Reach clients. | no |
| `mcp_adapter` | Read-only abilities over the WordPress MCP Adapter | partial | Read-only abilities are flagged public for MCP. The MCP Adapter is not installed on the site. | no |

## What depends on the AO server

| Needed on the AO server | For | How the worker checks it |
|---|---|---|
| Session overlays (`AGENTIC_SERVE_SESSION_OVERLAY=1`) | Overlay agents | `hello` frame on connect |
| MCP tunnel (`AGENTIC_SERVE_MCP_TUNNEL=1`) | Worker-hosted search, fetch and image search (`client.aoq_*`, D14) | `hello` frame on connect |
| `aoq_quote_verifier` deterministic agent | Research gate | AO catalog |
| `aoq_text_checks` deterministic agent | Structure and originality gates | AO catalog |
| A vision model reachable from `client.aoq_vision` | Image judging | First image call |

If the plugin settings name server MCP providers instead of the `client.aoq_*` ones, those must be in the AO catalog. A run that finds any of the first four missing fails at the `connect` stage, before any model call, and the status panel shows which one.

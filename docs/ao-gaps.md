# AO gaps

What this project needs from AO, and whether the engine (2.12.1) and AO Reach (0.19.0) provide it. Status is one of available, partial, missing. Server-side config that this repository can provide is in `/ao-server/`; see `ao-server/INSTALL.md`.

## 1. Search MCP and `fetch_url` on a `direct_agent` call: partial

- Available: `mcp_provider_ids` on `direct_agent` attaches MCP providers to the call, including `client.*` MCPs a Reach client serves through the reverse tunnel (`AGENTIC_SERVE_MCP_TUNNEL=1`).
- Used (D14): the worker serves `client.aoq_search` (SearXNG), `client.aoq_fetch` and `client.aoq_images` itself over the tunnel. AO dials nothing on the worker's network; requests travel back over the worker's own WebSocket.
- Still possible: server providers instead (`fetch_url` with `AGENTIC_MCP_FETCH_ENABLED=1`, or `ao-server/mcp_providers/search_searxng.yaml`). Set their IDs in the plugin settings.
- Limit: tunnel request and response bodies are capped by `AGENTIC_SERVE_SESSION_OVERLAY_MAX_BYTES` (512 KiB by default). `fetch_url` returns at most 40,000 characters of text.
- Gap: with `response_format` or `json_schema` set, the engine runs a plain model call and ignores `mcp_provider_ids` and `images`. The worker therefore makes a tool call that returns prose, then a JSON call over that prose (D3). Proposed engine change: honor `mcp_provider_ids` and `images` in JSON mode (tool loop or vision call, then native structured output under the schema).

## 2. Deterministic checks the model cannot skip: available, needs install

- Harness assertions only inspect output text (length, regex, bullets, JSON parse). They cannot see sources.
- Impartial QA runs after dynamic and society runs, is advisory by default, and is not wired to `direct_agent`.
- Custom tools are MCP tools; a model chooses whether to call them, so they cannot be gates.
- Used: `type: deterministic` agent providers run a fixed `module:callable` on the server with no model. The worker calls them as fixed stages. `ao-server/checks` ships the module and `ao-server/agent_providers` the two providers.
- Engine concern: overlay registration does not restrict agent `type`, so any client could register a deterministic agent pointing at any importable module on the engine host. Proposed: an allowlist of deterministic entrypoints, or reject `type: deterministic` in session overlays.
- Verifier fetch guard: refuses non-public addresses for the URL and for every redirect hop. It resolves the name before `urllib` connects, so a host that changes its DNS answer between the two lookups is not caught. Running the engine host without a route to internal networks closes that.
- Small gap: the research fetch (worker) and the verifier (AO host) fetch pages separately, so a page that changes between the two fetches can fail verification. The run then fails hard, which is the safe direction.

## 3. Client document set for retrieval: partial

- Available: `POST /api/v1/kb/upsert` and `/api/v1/kb/ingest` accept documents with a `source_id` and scope; `GET /api/v1/kb/search` does full text search (12 results at most).
- Missing: embedding and hybrid retrieval exist only as server `rag_sources` YAML. A client cannot attach its own embedding index or scope one to a session.
- Used now: a model judge with every post's title and excerpt in context (`novelty_judge`, partial). Proposed: an embedding `rag_source` whose documents a Reach client can upsert by scope, and a `rag_source_ids` field on `direct_agent`.

## 4. Style profiles as session skills: available, not used

- Overlay skills are injected into the agent backstory. Overlay payload limit is 512 KiB and 64 entries (`AGENTIC_SERVE_SESSION_OVERLAY_MAX_BYTES`, `AGENTIC_SERVE_SESSION_OVERLAY_MAX_ENTRIES`).
- Not used: the engine's JSON-mode path sends a fixed system message and a user prompt built from the goal and context only. Agent role, goal, backstory and skills do not reach JSON calls. Style profiles go in the call context instead (D5), and every instruction the pipeline relies on is in the worker's prompt text. Overlay agent instructions only apply to the prose calls (tools and vision).
- Proposed engine change: include the agent's role, backstory and skills as the system message in JSON mode.

## 5. Image search and image generation: search served by the worker, generation missing

- The engine has no image search and no image generation.
- Stock search is served by the worker over the tunnel (D14): `client.aoq_images` searches Openverse and Wikimedia Commons and keeps only CC0, public domain, CC BY and CC BY-SA. The worker keeps a ledger of every result; the pipeline accepts only image IDs the tool returned in that run and takes license, author, attribution and URLs from the ledger, never from model output.
- Not confirmed: Openverse's terms of use (the terms page refuses automated reads) and its anonymous rate limits. Wikimedia asks automated clients to send a User-Agent with contact details; set `AOQ_HTTP_USER_AGENT`, or Commons and Wikipedia answer 403.
- Unsplash is excluded: its API guidelines require hotlinking the returned image URLs, and this project downloads images into WordPress. Pexels needs an API key and is not wired.
- Image generation, proposed shape: an engine capability (`type: image_generation` agent or an MCP provider) that returns an image URL or bytes plus model and prompt metadata, so the plugin can store provenance as attachment meta. It stays planned and cannot be enabled.
- Vision is available: `images` on `direct_agent` (16 images, 4 MiB each, 20 MiB total), through the two-call pattern from item 1.

## Other

- Usage: the engine records token usage per app and agent but does not return it to Reach clients. Run log cost is planned. Proposed: usage totals on the `run_end` frame.
- Engine version: sent on the `hello` frame; AO Reach drops it, so the worker reads it with a small `SessionBridge` subclass. Proposed: keep it on `SessionBridge`.
- Queue priority: the worker sends the named tier `background` (0). Realtime clients (100) always go first.

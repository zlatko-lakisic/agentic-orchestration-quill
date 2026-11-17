# AO server setup for AO Quill

Everything here is installed by hand on the AO engine host. Nothing in this repository changes the engine on its own.

## 1. Engine settings

| Setting | Value | Why |
|---|---|---|
| `AGENTIC_SERVE_SESSION_OVERLAY` | `1` | The worker registers its `client.aoq_*` overlay agents |
| `AGENTIC_SERVE_MCP_TUNNEL` | `1` | The worker serves search, fetch and image search (`client.aoq_search`, `client.aoq_fetch`, `client.aoq_images`) through the Reach tunnel |

With the default plugin settings, the engine needs no search provider, no `fetch_url` and no Node.js: the worker serves those tools (`docs/decisions.md` D14). Section 3 is only for running them on the engine instead.

## 2. Deterministic checks

The research and originality gates run as `type: deterministic` agent providers. Install the package into the engine's Python environment (the same one that runs `main.py`):

```bash
pip install ./ao-server/checks
```

It has no dependencies beyond the standard library. Then copy the two provider files into the engine's agent provider catalog (`config/agent_providers/`, or the directory set by your extra catalog setting):

- `agent_providers/aoq_quote_verifier.yaml` (entrypoint `aoq_ao_checks.quotes:verify_quotes`)
- `agent_providers/aoq_text_checks.yaml` (entrypoint `aoq_ao_checks.textchecks:text_checks`)

The checks fetch source pages from the engine host. They refuse non-http(s) URLs and hosts that resolve to private, loopback or link-local addresses.

Check from the engine host:

```bash
python -c "import json, aoq_ao_checks.textchecks as t; print(t.text_checks('x ' + json.dumps({'content_html': '<h2>A</h2><p>one two three four five six</p>', 'title': 'T', 'seo_title': 'T', 'seo_description': 'd', 'n': 5, 'references': [], 'source_urls': []})))"
```

## 3. Optional: search and fetch on the engine

Only if you set server provider IDs in the plugin settings instead of `client.aoq_search` and `client.aoq_fetch`. For fetch, set `AGENTIC_MCP_FETCH_ENABLED=1` and `pip install mcp-server-fetch`. For search, copy `mcp_providers/search_searxng.yaml` into the engine's MCP provider catalog (`config/mcp_providers/`). It runs the open-source `mcp-searxng` server with `npx`, so the engine host needs Node.js. SearXNG must have the JSON format enabled (`search.formats: [html, json]` in its settings). Replace `@latest` with the version you tested.

If you prefer an existing provider (`search_brave`, `search_exa`, `search_tavily`), set its ID as the search MCP provider in the plugin settings instead.

## 4. Models

The overlay agents in `/overlays` reference models by name (`qwen3.5:27b`, `qwen3.5:9b`, `gemma4:26b`, `gemma4:e4b`). Use models the engine host has, and keep the editor (QA) agent on a different provider family than the writer. Any agent in the AO catalog can be chosen per stage in the plugin settings instead.

## 5. Verify

After restarting the engine, `GET /api/v1/catalog` should list `aoq_quote_verifier` and `aoq_text_checks`. The worker reads the overlay and tunnel flags from the `hello` frame when it connects. A run fails at the `connect` stage, before any model call, while any of these is missing.

## Not provided here

- Image generation: see `docs/ao-gaps.md` item 5. Stock image search is served by the worker.
- Engine changes proposed in `docs/ao-gaps.md` (JSON mode with tools and images, agent instructions in JSON mode, client RAG sources, usage on `run_end`, deterministic entrypoint allowlist).

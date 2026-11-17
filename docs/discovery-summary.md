# Discovery summary

Sanitized summary of Step 0. Hostnames, addresses, users and paths are kept out of this file on purpose.

## WordPress

- WordPress 7.1.3, PHP 8.2, WP-CLI available.
- Abilities API available. Application Passwords available.
- Multilingual setup: **subdirectory multisite, one site per language**. No multilingual plugin.
  - Primary site: English, about 450 published posts, 25 categories, about 2,900 tags.
  - Bosnian site: about 440 published posts, 13 categories, about 2,800 tags.
  - German and Arabic sites exist but have no posts, and their locale is still set to English.
  - Posts and terms are not linked across sites. Nothing in the database records which English post is the translation of which Bosnian post.
- SEO plugin: All in One SEO. Its sitemap is on; core sitemaps are off. Tags are not in the sitemap.
- MCP Adapter: not installed.
- Uploads: PHP allows 8 MB request bodies; the web server allows 64 MB. PHP can make outbound HTTP requests, so sideloading images works.
- No rules block or rate limit the REST API. Only the login page is rate limited.
- WP cron runs on page load; there is no system cron.
- No security plugin that would block an external REST client.
- A staging copy exists on the same host, reachable only locally.

## AO

- Engine 2.12.1. AO Reach repo version 0.19.0; its Python `pyproject.toml` still says 0.15.0. The package's own `__version__` is 0.19.0. Pin tag `v0.19.0`.
- JSON mode on `direct_agent` is a separate engine path that ignores `mcp_provider_ids` and `images`. A stage that needs tools or vision plus structured output takes two calls.
- `type: deterministic` agent providers run a fixed Python function on the server with no model. This is where the grounding and originality gates go.
- Search providers: Brave, Exa, Tavily. No SearXNG provider. No image search or image generation.
- Knowledge base REST endpoints accept client documents (full text search). Embedding retrieval needs a server-side RAG source.

## External services

- Unsplash: API guidelines require hotlinking the returned image URLs. Excluded, because this project downloads images.
- Pexels: API documentation allows use with photographer credit and a link to Pexels; 200 requests per hour, 20,000 per month. The full API terms page could not be read.
- Openverse: the terms of use could not be confirmed from the official pages. The license filter and key requirements need checking against the live API before building on it.
- Open-source MCP servers exist for both Pexels and Openverse, but none was verified enough to ship a provider file. Stock search stays planned.

## Blocking, resolved

The site is multisite per language, which the build prompt treats as a stop. The operator approved building for it with plugin-owned translation links (`docs/decisions.md` D4).

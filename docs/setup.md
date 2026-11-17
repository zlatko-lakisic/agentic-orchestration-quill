# Setup

Order matters: AO server first, then the plugin, then the worker.

## 1. AO server

Follow `ao-server/INSTALL.md`: session overlays and the MCP tunnel on, the deterministic checks installed. Search, fetch and image search are served by the worker (D14).

## 2. WordPress plugin

Requirements: WordPress 6.9 or later (Abilities API), PHP 8.1 or later, a multisite with one site per language.

1. Copy `wordpress-plugin/ao-quill` to `wp-content/plugins/ao-quill`.
2. Network activate it (Network Admin, Plugins). Activation creates four network tables, the `aoq_worker` role on the main site, and the `aoq-worker` user.
3. As a network admin, edit the `aoq-worker` user on the main site and create an Application Password. Copy it for the worker.
4. Open Network Admin, Settings, AO Quill:
   - Write the site brief.
   - Set the run time and timezone, and the publishing mode (pending review by default).
   - Choose the post author. The worker user cannot be the author.
   - Check the languages table. Sites whose language setting duplicates another site's language must either get the right site language or be excluded. WordPress ignores a site language whose language pack is not installed and keeps the old value, so install the pack first (Network Admin, Updates, or choose the language once in Network Admin, Settings, Default language and switch back), then set Network Admin, Sites, Edit, Settings, Site language.
5. Agent choices are checked against the AO catalog the worker reports. Until the worker has polled once, the defaults stay in place and runs fail with "not reported the AO catalog yet".

## 3. Worker

The worker ships with the plugin in `wp-content/plugins/ao-quill/worker/` and is installed by hand (D15), on the WordPress server or any server that reaches WordPress and AO. As a normal user on that server (Ubuntu needs `python3-venv` once):

First set the AO engine URL (and SearXNG URL, User-Agent, IPv4 only if needed) in Network Admin, AO Quill, AO connection (D16). Then:

```bash
wp --path=/path/to/wordpress user application-password create aoq-worker "ao-quill worker" --porcelain
bash /path/to/wordpress/wp-content/plugins/ao-quill/worker/install.sh --wp-url https://wordpress.example.com
~/.local/share/ao-quill-worker/run.sh enroll --ca-file /path/to/ao-ca.pem   # only if AO requires client certificates and none is stored
~/.local/share/ao-quill-worker/run.sh check
~/.local/share/ao-quill-worker/run.sh run --dry-run --out ~/aoq-dry-run
bash /path/to/wordpress/wp-content/plugins/ao-quill/worker/install.sh --cron
```

The Generation tab (Network Admin, AO Quill) shows every run as it happens: the 14 steps with what each does, progress, readable AO answers, per-step and overall time estimates from past runs, and the raw log. It starts a real run (Generate now) or a test run that writes nothing and ends with a preview of every language (D17). Run a test before enabling the schedule.

The installer asks only for the Application Password. `enroll` asks for a one-time AO token (mint it on the AO host with `python3 -m orchestration.serve.mtls mint-token --client-name ao-quill-worker --ttl 600`) and stores the issued certificate in WordPress; a certificate can also be pasted in the AO connection settings. Run the installer again after a plugin update to upgrade the worker. The plugin's Status tab shows whether the worker checks in. Containers and Kubernetes are described in `deploy/README.md`.

Without the installer (development), check connectivity and do a dry run like this:

```bash
cd worker
python -m venv .venv
.venv/bin/pip install -e .
export AOQ_WP_URL=https://wordpress.example.com AOQ_WP_USER=aoq-worker AOQ_WP_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx' AOQ_AO_URL=https://ao.example.internal:8765
export AOQ_SEARXNG_URL=https://search.example.internal AOQ_HTTP_USER_AGENT='ao-quill-worker/0.1.0 (+https://www.example.org/contact)'
.venv/bin/aoq-worker check
.venv/bin/aoq-worker run --dry-run --out ./out
```

The dry run reads the real site and uses real AO, writes nothing to WordPress, and leaves `out/<run_id>/` with `run.json` (every stage), `bundle.json` (what would be committed) and `preview-<language>.html` per language with images, QA scores, sources and the verified quote behind every factual sentence.

The worker needs outbound access to SearXNG (`AOQ_SEARXNG_URL`, JSON format enabled), to the pages it researches, and to `api.openverse.org`, `commons.wikimedia.org` and `upload.wikimedia.org` for stock images. Set `AOQ_HTTP_USER_AGENT` to a value with a contact URL or email; Wikimedia refuses automated requests without one. `aoq-worker check` reports whether search answers.

To use stock images, enable "Stock image search" in the plugin's Images settings and set its order against media library reuse. Sources are tried in order until enough images pass the vision checks.

## 4. Staging check before production

1. Run against the staging copy with the publishing mode on pending.
2. Confirm one post per language, linked (Run log shows the translation group), with categories, tags, featured and in-body images, and license meta on copied images (`_aoq_license_code`).
3. As an anonymous visitor, confirm no trace of the marking: view source of the post, `/wp-json/wp/v2/posts`, `/feed/`, and the AIOSEO sitemap (`/sitemap.xml`). Search for `aoq` and `ai-generated`.
4. Force a commit failure (for example, temporarily delete a chosen category between runs) and confirm no post or attachment is left.

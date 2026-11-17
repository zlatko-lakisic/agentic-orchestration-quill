# Decisions

## D1. Pin AO Reach `v0.19.0`

- Reason: the newest tag; has `direct_agent(json_schema=...)` (0.17.0) and `images` (0.10.0). The repo `VERSION` and package `__version__` say 0.19.0, `python/pyproject.toml` still says 0.15.0, so the pin is by tag.
- Alternatives: `v0.17.0`, the oldest with JSON mode; it lacks the agent state fixes.

## D2. Research grounding gate runs as a deterministic AO agent

- Reason: harness assertions cannot see sources, custom tools are optional for a model, and the worker must not fetch pages. A `type: deterministic` provider fetches each source on the server and checks exact substrings, and the worker calls it as a fixed stage.
- Alternatives: harness assertion (cannot reach sources), engine hook (engine change), ship research as partial.
- Needs: the operator installs `ao-server/checks` on the engine host.

## D3. Tool and vision stages use two calls

- Reason: engine JSON mode ignores `mcp_provider_ids` and `images`. Call 1 uses tools or vision and returns prose; call 2 (`client.aoq_structurer`) turns it into JSON under the schema. Anything verifiable is checked again later (quotes by D2, term and post IDs by set checks).
- Alternatives: wait for the engine change in `docs/ao-gaps.md` item 1.

## D4. Multisite per language with plugin-owned translation links

- Finding: subdirectory multisite, one site per language, no multilingual plugin, no stored links between posts or terms.
- Decision (approved): network-activated plugin; abilities on the main site; a multisite `AOQ_Translation_Adapter`; translation groups for posts this plugin creates stored in a network table; terms chosen per site.
- Alternatives: MultilingualPress (a production change, more moving parts), linking existing human posts by heuristics (out of scope: human content is never modified).

## D5. Style profiles go in call context, not session skills

- Reason: the engine's JSON-mode prompt is built from goal and context only; overlay skills land in the agent backstory and would not reach JSON calls. Context also lets each call get only the profile for its language.
- Alternatives: one overlay skill per language profile (does not reach JSON calls today).

## D6. Worker dependencies

- `ao-reach` (pinned tag): the AO client, required by the brief.
- `aiohttp`: the HTTP client for WordPress. Already a dependency of `ao-reach`, so it adds nothing.
- `jsonschema`: contract and stage output validation.
- Nothing else. A test fails if the dependency list changes.

## D7. Rollback deletes, not trashes

- Reason: a failed commit must leave no posts or attachments behind (acceptance 8). Trashed posts still exist. The plugin force-deletes only the posts, attachments, SEO rows and links it created in that same call.
- Alternatives: trash (leaves rows behind).

## D8. One run per day, including manual runs

- Reason: the scope is at most one post a day. "Run now" starts a run only when today has no run that is claimed, running or succeeded. A failed run is not retried by the schedule; "Run now" can retry it.
- Alternatives: allow extra manual runs (would break the one post per day limit).

## D9. Media library images carry a `site-library` license

- Reason: images already in the media library belong to the site. They are reused on their own site (no copy) and copied to other language sites with license metadata. Remote images need an external license code from an allowlist of licenses that permit download and modification (CC0, public domain mark, CC BY, CC BY-SA, Pexels).
- Alternatives: re-checking provenance of existing media (no data for it).

## D10. Unsplash excluded; stock from Openverse and Wikimedia Commons

- Reason: Unsplash API guidelines require hotlinking; this project downloads images. Stock search now runs in the worker (D14) against Openverse and Wikimedia Commons, which need no API key and return license metadata. Pexels needs a key and is not wired. Openverse's terms of use are still to be confirmed by a person (`docs/ao-gaps.md` item 5).

## D11. Novelty by model judge for now

- Reason: AO cannot run embedding retrieval over client-supplied documents. The judge sees every post's title and excerpt in all languages. Recorded as partial.
- Alternatives: KB upsert plus full text search (keyword only, adds AO-side state per run).

## D12. Post author is a setting

- Reason: the author is public. The worker user must not appear as author, or the AI marking would show through the author archive. The plugin refuses the worker user as author.

## D13. Server-side quote normalization

- The verifier normalizes whitespace, HTML entities, typographic quotes and dashes, and strips markdown link and emphasis syntax from the quote (quotes copied from `fetch_url` output can carry it). Case and words must match exactly.

## D14. Search, fetch and image search served by the worker over the Reach tunnel

- Decision: the worker serves three MCP providers, `client.aoq_search` (SearXNG), `client.aoq_fetch` and `client.aoq_images` (Openverse, Wikimedia Commons). AO Reach registers them in the session overlay as `tunnel://session-mcp/<alias>`; AO relays each MCP request back over the worker's WebSocket to a loopback-only server in the worker (`mcp_host.py`). AO needs `AGENTIC_SERVE_MCP_TUNNEL=1`.
- Reason: overlay MCPs must be tunneled (AO refuses stdio and remote URLs in overlays), and AO has no image search. This keeps the AO host free of Node.js and provider files and makes stock images possible. Models still run only on AO.
- Cost: the worker now reaches the public web and SearXNG, not only WordPress and AO. Only `web_tools.py` and `mcp_host.py` may do that (test enforced). Public fetches go through a resolver that refuses private, loopback, link-local, multicast and reserved addresses at connect time, so redirects and DNS rebinding cannot reach the LAN; SearXNG is the one configured exception.
- Integrity: image search results go into a per-run ledger. The pipeline accepts only ledger IDs and takes license, author, attribution and URLs from the ledger. Quote verification still fetches sources independently on the AO host (D6), so the worker's fetch cannot vouch for its own quotes.
- No new dependency: the MCP server is a small JSON-RPC subset on `aiohttp`, which the worker already uses.
- Alternatives: server MCP providers (still supported by setting their IDs); the official `mcp` SDK in the worker (heavier dependency tree); `LocalMcpHost` stdio servers (needs Node.js and `npx` in the image).

## D15. The worker ships inside the plugin, installed by hand

- Decision: the plugin zip carries the worker in `ao-quill/worker/` (Python source, overlays, `install.sh`), built by `scripts/build_plugin.py`. Installing it is a separate manual step on a server: `install.sh` creates a virtual environment in `~/.local/share/ao-quill-worker`, keeps settings and the AO client certificate in `~/.config/aoq` (mode 600/700), asks for the Application Password and the AO enroll token with hidden prompts, and with `--cron` runs the worker every 15 minutes through a locked `run.sh`.
- Reason: one artifact to version and upgrade. WordPress cannot run the worker itself: runs last minutes to an hour and keep an AO Reach WebSocket open, which PHP requests and WP-Cron cannot do, and AO Reach is a Python library.
- Detection: WordPress cannot see the worker's server, so it judges the worker by check-ins. Every poll calls `claim-run`, which records a heartbeat even when no run is due. The Status tab shows Not installed (never checked in), Running (checked in within 30 minutes) or Not checking in, the installed and bundled versions, and the install commands; network admin shows a warning while the worker is not checking in.
- Nothing in `worker/` is executed by the web server, and no secret is stored under the web root.

## D16. AO connection and certificate kept in WordPress

- Decision: the plugin stores the AO URL, the IPv4-only switch, the SearXNG URL, the User-Agent and the worker's mTLS client certificate (Network Admin, AO Quill, AO connection). The worker reads them with `aoq/get-ao-connection` at the start of every poll and writes the certificate to a private cache folder (0700/0600) for AO Reach. `aoq-worker enroll` stores a newly issued certificate with `aoq/save-ao-certificate`. A worker server then only needs the WordPress URL and an Application Password.
- Protection: the private key is encrypted at rest with libsodium secretbox under a key derived from the site's `SECURE_AUTH` salt, never printed in the admin screens, and only returned to users with `aoq_run_worker`. Both abilities are not readonly, so they are never public over MCP and always use POST. The plugin checks that the key matches the certificate and that AO's CA issued it.
- Trade-off: whoever controls WordPress (a super admin, or anyone who can read the database and wp-config.php) can obtain the AO client key. That is the same trust the worker user already has, and it can be revoked on AO.
- Environment variables (`AOQ_AO_URL`, `AOQ_AO_MTLS_DIR`, ...) still win, so containers can pin them. `install.sh` removes them from its own settings file so WordPress stays the source.
- Changing the site's salts makes the stored key unreadable; the status panel says so and the certificate has to be stored again.

## D17. Test runs, live progress, and no time limits that a slow AO can hit

- Test runs: the Generation tab asks for a run with trigger `test`. The worker runs every stage against AO and the live site but builds a preview instead of committing; `commit-post` refuses test runs, and `finish-run` may succeed without a commit only for them. Test runs do not count as the day's post. One run (of any kind) is active at a time.
- Live progress: the worker sends `report-progress` every 3 seconds while anything changed: the pipeline step (connect plus the 13 stages, 14 steps), and AO output as it arrives (chunks AO streams during a call, then each call's answer). The tab polls `admin-ajax.php?action=aoq_live` every 3 seconds and fetches only output it has not shown. The plugin keeps the newest 300 KB of output per run, for the last 10 runs.
- Time limits: every `report-stage` and `report-progress` extends the run lease by `lease_minutes` (default 720), so a progressing run never expires. The per-call limit `stage_timeout_seconds` defaults to 3600 and goes up to 14400. The AO Reach session lives 24 hours.
- Polling: cron runs the worker every minute so a test starts quickly. Idle polls are one `claim-run` request; the AO catalog in the heartbeat is cached for 15 minutes, and a claimed run always fetches it fresh. The worker counts as not checking in after 15 quiet minutes.

## D18. Research pre-check against the pages actually read

- Observed: the research agent cited pages it never fetched and wrote quotes from search snippets; AO's verifier rejected every claim, which is the gate working, but the run then failed.
- Decision: the worker records every page AO's agent reads through its `fetch_url` tool. After research, claims whose source was not fetched in this run, or whose quote is not on that page (same normalization as the verifier), are dropped. If fewer than `min_verified_claims` remain, research runs once more with the reasons and the list of pages really read. The research prompt also says that only pages fetch_url returned count as sources.
- The pre-check only filters. AO's deterministic verifier still fetches every remaining source itself and decides each quote (D6); a page that changes between the two fetches fails there, which is the safe direction.

## D19. Loose schemas for models, strict pruning in the worker

- Observed: test runs failed whole steps over one bad item. A topic with an empty evidence list or an empty URL, or a category ID that does not exist, made AO reject the full answer ("unexpected format"), after minutes of agent and tool work on slow hardware. Asking the model for every field also invited invention: one JSON retry filled evidence with example.com URLs.
- Worker fields: models are not asked for what the worker can get itself. Topic evidence comes from the worker's own search of each working title (`contracts.WORKER_FIELDS`); stray IDs (categories, tags, posts, media, claims, candidates) are removed in the stages that check them.
- Loose to AO, strict in the worker: the schema sent to AO keeps types, required fields and enums but leaves out `minLength`, `maxLength`, `pattern`, `minItems`, `maxItems`, `minimum` and `maximum` (`contracts.model_schema`). The worker then prunes the answer against the strict contract (`contracts.prune`): invalid array items and optional fields are dropped, over-long free text is cut, surplus items are trimmed, and only an unsalvageable required field or an array below its minimum fails the call. What was removed is shown in the run's live output. Deterministic agents stay strictly validated.
- Retry the JSON step only, with the error: when AO or the worker rejects the JSON, only the call that produced it is repeated once (the structurer for tool-using steps), with the exact error (AO's `ReachRunError.detail` for `direct_agent_format`) and the instruction to leave items out rather than invent values. The agent and tool phase is not rerun.
- AO side: raw answer and repair retry tracked in the AO repository (issue 54).

## D20. No new topic goes back to topics

- When novelty judges none of the candidates new, the run is not failed. It goes back to topics (the Generation tab shows step 5 again, as attempt 2), and the topics prompt lists every title rejected so far with the judge's reason, asking for different subjects. The novelty step of that round is recorded as failed with the rejected titles.
- After `TOPIC_ROUNDS` (3) rounds with nothing new, the run fails at novelty as before.
- When the judge chooses a candidate it did not itself judge new, the first candidate it did judge new is used instead.

## D21. No thinking models in the pipeline

- Observed on the Jetson: models that "think" before answering (qwen3.5, gemma4) spend most of a call on hidden reasoning that AO neither streams nor can switch off for JSON calls (AO issue 58). qwen3.5:9b never answered novelty in 25+ minutes; qwen3.5:27b took about 14 minutes per draft; gemma4:26b reasoned for over 30 minutes on one facts check. Replaying that facts check directly: gemma4:26b with thinking produced 24,000 characters of reasoning and no answer in 4 minutes, and 5 seconds with thinking off.
- Decision: every pipeline agent uses a non-thinking model. Analyst, structurer and writer: `qwen2.5:14b-instruct`. Editor: `phi4:latest` (family "phi", so QA stays a different family from the writer). In the replay, phi4 answered the facts check in 93 s with valid JSON and flagged sentences the claims only partly support; mistral-nemo answered in 20 s but passed everything, which is too lenient for QA.
- Vision stays `gemma4:e4b` for now; its image passes finished in about 2 minutes.
- Revisit when AO can send `think: false` for JSON calls (issue 58).

## D22. The facts score decides, not single flagged sentences

- Before: the facts check failed whenever the editor listed any unsupported sentence, whatever its score. With phi4 flagging borderline sentences every round, runs failed even at a score of 0.8 against a pass mark of 0.6.
- Now: the facts check passes when its score reaches the pass mark in Settings. Sentences the editor lists as unsupported are still shown in the step details and still go to the writer whenever a revision runs, as major issues.

## D23. Post length, image relevance and research breadth

- Length: a "Minimum word count" setting (default 300) is a hard rule in the draft prompt and every revision prompt, and QA has a length check that fails below it. The writer aims for the site's typical post length from its style profile (at most 1,500 words), never below the minimum. Revisions that cut unsupported sentences must make up the length with practical, fact-free advice or verified claims.
- Images: the media library shortlist only picks images that show the post's subject itself and may pick none, so stock search fills the gap instead of forcing loosely related library photos. Stock queries name the subject. The vision model names the post's subject and what the image shows before its yes/no answers.
- Research: if every kept claim comes from one site, research runs its second attempt asking for pages from at least two sites (thin, single-source posts failed originality).

## D24. Search interest for topics (no accounts)

- The topics step takes into account what people search now, from two free Google sources that need no account or key: Google Trends' daily trending searches per country (RSS) and Google's search suggestions for the site's seed terms (autocomplete).
- Seed terms and countries are plugin settings (Search interest). Trending searches are kept only when they touch a seed term; suggestions only when a seed appears as a whole word. Results are cached for 6 hours; any failure means fewer signals, never a failed run.
- Each candidate lists the searches it matches (two shared distinctive words); novelty prefers candidates with real search interest. Instagram and X were left out: both need accounts (and X a paid API).

## D25. Stalled and orphaned runs are halted by WordPress

- Observed (test run 0ecca7f1): the proxy in front of WordPress refused connections for a moment; the worker crashed while reporting a failed draft and never called finish-run, so the run stayed "running" and would have blocked every run, including the daily one, until its 12-hour lease ended.
- Worker: WordPress calls are retried on connection errors and 502/503/504 (after 5, 15, 30 and 60 seconds). If reporting a failed stage itself fails, the stage's own error still ends the run.
- WordPress: a worker checks in as idle only while it holds its run lock, so an active run seen at an idle check-in has lost its worker and is failed at once ("The worker stopped without finishing the run"). A run that reports nothing for longer than one AO call may take (stage_timeout_seconds) plus 10 minutes is halted as stalled; checked on every worker check-in and every Generation tab refresh.

## D26. The daily run has no cap, and the preview matches the post

- Observed (2026-11-14): two failed "Generate now" runs before 07:00 counted as the day's post, so the scheduled run never started.
- The scheduled run happens once a day, at the set time, whatever other runs or posts were made that day. Only an earlier scheduled run that day (in any outcome) stops it; a failed one is not retried automatically. "Generate now" has no daily cap either; runs still go one at a time.
- The test-run preview lays the post out as the theme shows it: title, then the featured image as a wide banner, then the body with its in-body images. The excerpt appears apart, since only post lists show it.

## D27. Images are scored and ranked; the step falls back instead of failing

- Observed (test run 41137d8d, "Bosnian Winter Wonders"): the featured image was a photo of stone ruins. Only the newest 400 library uploads were offered (five ski resort photos further back were never seen), the text relevance check was a lenient yes/no ("an outdoor historical site in Bosnia" passed), and the first image to pass became featured while the better ski slope photo went in the body.
- A text call names two to four concrete things the post's photos should show (subjects). The whole media library is searched for them by file name, title, alt text and caption; logos, maps and other non-photos are left out; the best matches (up to the candidate setting, default 40) are offered to the shortlist, padded with recent photos when few match.
- The text model scores each vision description 0-10 against the subjects (8-10 shows one clearly, 5-7 closely related, 1-4 only the region). A "no" from the vision model costs 2 points. Text, logos, watermarks and faces still exclude an image.
- All usable images from every source are ranked. The best becomes featured: a landscape photo at least 1200 px wide at the featured pass mark (default 8); in-body images need the image pass mark (default 6) and may not be near-duplicates (same file or nearly the same description). Stock searches repeat (up to 4 more rounds) until both are met.
- Never a failed run for images: the featured image falls back to the best image under the featured mark, then any image scoring 3 or more, then the fallback image setting (a main-site media ID), then the best usable image. In-body images that cannot be found are dropped from the post. Only a run with no usable image at all and no fallback image set fails.

## D28. Web image search through SearXNG (owner's decision)

- The site owner asked for SearXNG's image search (all engines: Brave, Bing, Google, Pinterest, Wikimedia Commons) as an image source, with a link to the page each image was found on, and takes responsibility for the licensing. It is an image source in Settings ("web_search"), searched with one short query per subject.
- Images from it carry the license code "unchecked" (allows_download_and_reuse false): their license is not known. In the post, an in-body image's caption ends with "Source: <a>site</a>" linking the page it was found on; for a featured image the same line goes under the post. The attachment keeps the image URL and source page in its meta (_aoq_source_url, _aoq_source_page_url) and _aoq_license_code "unchecked", so these images can be found later.
- Results from paid stock agencies (Shutterstock, Dreamstime, Alamy, Getty and similar) are skipped: their previews are watermarked and fail the vision check anyway. Images under 800 px wide are skipped. The full-size file is fetched before judging, so an image WordPress could not download is never chosen.
- They are judged, scored and ranked like every other source (D27).

## D29. Instagram: posting, captions and the bio link

- Instagram tab in AO Quill. The connection uses the Instagram API with Unified Login (Facebook Login for Business; no Facebook Page needed): a Meta app ID and secret (App settings → Basic), a Unified login configuration ID, then Connect Instagram (OAuth via facebook.com/dialog/oauth with that config_id; redirect URL shown on the tab), or a pasted access token. The shared Instagram account is read from /me/assigned_instagram_accounts (exactly one). Graph calls use graph.facebook.com. When Meta returns an expiry, WP-Cron tries to extend the token when it has under 20 days left; non-expiring tokens are left alone. The app secret and token are never shown again. Permissions in the Meta configuration: instagram_basic, instagram_content_publish.
- When Instagram is on, the worker writes an Instagram caption and hashtags for each post language that posts to Instagram, in the commit step (writer model): a hook line, a short summary using only the post's facts, and the call to action ("link in bio"; links in captions do not work). They travel in the commit bundle (posts[].instagram) and are stored as post meta. A caption that cannot be written never fails the run; WordPress then builds one from the title and excerpt.
- A post goes to Instagram about a minute after it goes live (WP-Cron): at once in Publish mode, or when an editor publishes a post saved for review. Only AO Quill posts, only in the chosen languages, once (Post again on the tab re-posts). The featured image is cropped from the centre to 4:5 (or 1:1), 1080 px wide, JPEG, stored in uploads/aoq-instagram/; Instagram fetches it from there. Hashtags: the post's own, then the fixed ones, at most the set maximum (30).
- Bio link: on every site, /ig-latest/ (configurable) redirects with a 302 to that site's newest published AO Quill post (tourguidemostar.com/ig-latest/ for English, tourguidemostar.com/ba/ig-latest/ for Bosnian), or to the home page when there is none. It works whether or not posting is on, and is not cached or indexed.

## D30. Instagram generation tab

- The Generation tab is now "Post generation". A new "Instagram generation" tab picks any post (human or AO Quill, published or pending) on the sites that post to Instagram and offers Test run (caption with AO and the cropped image, nothing posted) and Generate and post (the same, then posted; published posts only, and Instagram must be connected).
- One Instagram job at a time, stored as a network option. claim-run returns it ({"run": null, "instagram": job}) when no post run is active, so it never competes with a post run for AO; it goes before a due post run because it is one short call. The worker writes the caption with the same prompt as the post pipeline (D29) and reports it with report-instagram; WordPress stores it on the post, makes the image, and for Generate and post posts it through WP-Cron at once.
- A job still running at an idle worker check-in, or after 15 minutes, lost its worker and fails. The tab follows the job live (polling, no page reloads while it runs) and keeps the last 15 jobs.

## D31. Revision rounds: an editor's plan, and the worker fixes what can be counted

- Observed (test runs c092e9a2 and 8065d509): structure stayed at 0.60 through three revisions (a 71-character title, a 174-205 character meta description) and length crept from 46% to 94% of the minimum. The writer got the raw QA issues and rewrote the whole post; models cannot count characters or words, and the prompt said both "at least N words" and "a shorter accurate post is better".
- A revision round now: (1) when a check other than length (or title and description lengths) fails, the editor (analyst model) turns the QA results into a numbered plan of concrete edits plus what to keep, and the writer applies the plan, changing nothing else; (2) when the post is under the minimum word count, the writer writes one or two new sections that the worker cleans (verified claim markers only, no images or H1, real links only), inserts before the last H2 and counts, up to two tries; (3) when the title, SEO title or meta description is outside the structure check's limits (70, 60, 120-160 characters), the writer proposes options and the worker keeps the first that fits by counting, else cuts at a sentence end or word.
- The contradictory "shorter is better" line is gone. What the round did is in the QA step details (revision: plan, expanded, fitted).
- (D29, addition) The Instagram link of every post is kept: the latest in _aoq_ig_permalink and all of them (Post again adds one) in _aoq_ig_posts with media ID and time. When Instagram does not return the link at once it is asked three times, then again by WP-Cron every 5 minutes (three more tries). The link shows in an Instagram column on the Posts list, in an Instagram box in the post editor, on the Instagram tab and in the Instagram generation history.

## D32. Language links between the sites (hreflang and the language switcher)

- Every page of every language site gets hreflang alternates (and x-default, the main site's language) for the same page in the other languages, and the menu's language items (custom links to another site's home page) go to that same page instead of the home page.
- Counterparts: the front page and the posts page of each site; posts, pages and tours linked in aoq_translations (AO Quill's own posts are linked when committed; other content with AOQ_Language_Links::link(), stored as run "manual"); taxonomy terms with the same slug. Only sites with a language of their own count (a site left on the main site's language is not an alternate). Tour and tour-taxonomy URLs on another site are built from that site's WP Travel Engine permalink bases, because WordPress builds cross-site links with the current site's rewrite rules.
- A page without a counterpart has no alternates, and its language item keeps going to the other site's home page.

## D33. Heading hierarchy

- Observed (post 5647): nine H2 headings, one per dish, and almost no H3; three of them were added by the word-count expansion, which was told to add H2 sections.
- The outline uses levels 2 to 4: level 2 only for the 3 to 6 main parts of the article, several things of the same kind (dishes, places, stops, tips) as level 3 under one level 2, level 4 only inside a level 3, never skipping a level. The draft rules say the same and allow <h4>.
- The word-count expansion prefers H3 subsections under an existing H2 (it names the H2, and the worker inserts the new material at the end of that section); a new H2 only for a new main part.
- A worker QA check, "headings", fails a post with more than 6 H2 or with 5 or more H2 and no H3; the revision plan then regroups the sections.
- Deterministic cleaners (after draft and every revision): `normalize_heading_levels` demotes H1 and fills skipped levels (max H4); `dedupe_h2_sections` merges duplicate H2 titles and folds an H2 that repeats the article title into the previous section. Expansion that reuses an existing H2 title is demoted to H3. Heading-order structure problems are mechanical (like title/meta lengths), not a model rewrite.
- Failsafe: after the configured revision loops still fail, one mechanical last chance (cleaners + `fit_fields`, no model) re-runs primary passes. If a **scheduled** run still fails on QA (not stall / orphan / originality fetch / config), WordPress queues **one** auto-resume from `qa` or `draft` on the next idle `claim-run`; that run_id cannot auto-resume again.

## D34. Shared-worker busy presence

- One worker serves several WordPress sites (`worker.env` + `sites.d/*.env`) under one `run.lock`. While a long job runs (post run, Instagram caption, language-site), siblings stop getting `claim-run` heartbeats and Status used to show **Not checking in**.
- While busy, the worker broadcasts `{ site, task, detail, started_at, updated_at }` every ~60 s to **all** configured sites via `aoq/report-worker-status` (Application Password). Idle polls clear busy (`busy: null`). Receiving a report refreshes `CONTACT_OPTION` so age does not trip stopped.
- Plugin state: `busy` when the last busy payload is recent and this site has no local current run (siblings); the site being worked still shows `running` from local progress. Status / Generation: **Busy — tgme: post_run (qa)**, not a red not-checking-in notice.

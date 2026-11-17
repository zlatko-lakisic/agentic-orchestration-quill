# New language site from the base site

A repeatable procedure for turning one site of the network into a full copy of the base (English)
site in another language. Input is only:

- **site**: the site's URL (for example `https://tourguidemostar.com/de/`)
- **language**: the WordPress locale it should be (for example `de_DE`)

The procedure switches the site to that language if it is not already, copies everything from the
base site, translates it, links every page to its English counterpart, and leaves the site looking
exactly like the base site. The Bosnian site (`/ba/`, 2026-11-14) was the first one done this way,
by hand-written scripts; this plan turns that into one tool and records what it taught.

First use: **German** (`/de/`, `de_DE`). The site is empty today: language unset (so English), one
page, no tours, posts, menus or theme settings. WordPress core German files are already installed.

## What "everything" means

Copied from the base site and translated (in this order, each a step of its own):

| Area | What | Translated |
|---|---|---|
| Site settings | title, tagline, front page and posts page, posts per page, permalinks, date format | title, tagline |
| Theme settings (Customizer) | all theme mods: home sections and their order, colours, header, banner, footer, social links, counters, partners, contact details, Analytics | every text field |
| Media | every image the settings, tours, pages and testimonials use (each site has its own library) | alt text, titles |
| Tour taxonomies | destinations, activities, trip types, difficulty, tags (same slugs) | names |
| Tours | every published tour: text, itinerary, details, facts, gallery, categories, and its price packages (Adult/Child categories remapped) | title, description, tabs, details |
| WP Travel Engine settings | booking pages, tabs, trip facts, enquiry, WhatsApp, related trips | labels |
| Pages | every published page, same template; contact forms and shortcodes kept | title, content |
| Testimonials | all, with their trip and date | text written in English (quotes in other languages stay as written) |
| Menus | main menu with the same structure; the language item | item titles |
| Widgets | sidebar and footer, same widgets in the same places | titles, text |
| Plugin and theme words | WP Travel Engine and theme interface words (buttons, search bar, labels) | yes, as a language file |
| SEO | AIOSEO title and description per page and tour | yes |
| Language links | every page, tour and category linked to its base counterpart (hreflang, switcher) | n/a |
| Blog posts | optional, a batch of its own (see step 9) | title, content, excerpt, SEO |

Not copied: users (shared by the network), orders and enquiries, the base site's AO Quill posts
(see step 9), and anything that is the site's own (its logo if it has one, its WhatsApp number).

## Running it

On the WordPress server, as the worker's user:

```bash
~/.local/share/ao-quill-worker/run.sh language-site --url https://tourguidemostar.com/de/ --locale de_DE
```

It shows what the target holds, asks once before changing anything (`--yes` skips the question),
and then prints its progress as a percentage, with a "waiting on AO (n s)" line while AO is
translating. It waits for a post run in progress to finish (the worker lock), and it can be stopped
and run again at any time: what was already copied is updated and translations come from the cache
(`~/.local/share/ao-quill-worker/language-sites/`), so only new or changed English texts go to AO.

Options: `--step check|backup|language|export|translate|apply|words|link|verify` runs one step;
`--posts N` also translates the N newest blog posts; `--force` continues when the target has many
pages of its own; `--wp-path` overrides the WordPress folder (set by the worker install as
`AOQ_WP_PATH`). Backups go to `~/aoq-backups/`.

## The tool

One WP-CLI command in the AO Quill plugin, so it runs where the data is and can be re-run safely:

Built as the worker command `aoq-worker language-site` (Python: steps, AO translation, progress,
verify) with the plugin's `tools/language-site.php` (WP-CLI: check, export, apply, link, language
file). The WordPress side has no screens; it only runs from the command line.

- **Idempotent:** everything it creates carries `_aoq_source` (base site and ID), so a second run
  updates what the first one made instead of duplicating it. Each step can be run alone (`--step`).
- **Translation file in between:** the copy step writes every text to translate into a JSON file
  keyed by source (`trip:260:tab`, `mod:popular_title`, `page:410:content`). Translation fills the
  same file; the apply step reads it. A human can edit the file before applying, and a later run
  only translates texts that changed in the base site (each entry keeps a hash of its source).
- **Translation engine:** the AO Quill worker, as a job type of its own (like the Instagram caption
  jobs, D30): it claims a batch of texts, translates them with the translate agent and checks them
  with back-translation (the fidelity check of D10), and reports them back. HTML, block comments,
  shortcodes, URLs and placeholders pass through untouched; only text nodes are translated. It runs
  only when no post run is active.
- **Backup first:** the command exports the target site's tables to `~/tgm-backups/` before writing
  anything, and refuses to continue if the export fails.

## Steps

1. **Check the target.** The site exists, is not the base site, and is not archived. Show what is
   there now (counts of posts, pages, tours, menus, theme settings) so nothing is overwritten by
   surprise. Stop if the site already has content of its own that the base site does not have,
   unless `--force` is given.
2. **Back up** the target site's tables.
3. **Switch the language** if the site is not already in it: set the site's `WPLANG` to the locale,
   install the WordPress core, theme and plugin language packs for it (`wp language core install`,
   `wp language plugin install --all`, `wp language theme install --all`), and set the site title and
   tagline (translated).
4. **Copy** settings, media, taxonomies, tours with packages, WP Travel Engine settings, pages,
   testimonials, menus and widgets from the base site, still in English, and write the translation
   file. After this step the site already matches the base site in layout.
5. **Translate** the translation file through the worker (step above). Progress shows on the Post
   generation tab as a run of its own ("Language site: de"), with a step per area.
6. **Apply** the translations to everything copied in step 4.
7. **Plugin and theme words:** for every plugin and theme text domain the site uses that has no
   language pack in this language, generate a `.mo` file from the strings the site actually shows
   (collected from the rendered pages), translated like the rest, into `wp-content/languages/`.
8. **Link** every page, tour and category to its base counterpart (D32), so hreflang and the
   language switcher work, and add the language to the base site's language menu
   (Languages > Deutsch).
9. **Blog posts (optional, `--step=posts`).** The base site has about 455 posts. Translate them in
   batches through the same worker job (newest first, a set number per night so the daily run is
   never delayed), each linked to its original. Then include the site in AO Quill (remove it from
   the excluded languages) so every new daily post is translated into it by the normal translate
   step.
10. **Verify.** For every page, tour, category and the home page: render the base page and the new
    page and compare their structure (sections, headings count, menu items, footer widgets, images,
    prices, booking widget) and look for leftover English (a language check on the visible text).
    Report differences; the run fails if a page is missing or a section is missing. Screenshots of
    the home page and one tour, desktop and mobile, go into the report.

## Defaults (decided for Bosnian, kept unless told otherwise)

- Tours get slugs in the new language; existing pages keep their slugs (no broken links).
- Prices stay as on the base site (same currency).
- The site keeps its own WhatsApp number and logo if it has them; otherwise the base site's.
- Theme demo content the base site does not show (demo counters, placeholder partner logos, demo
  menus) is removed, not copied.
- The base site's pages that are theme demo text (the "Our Team" page) are translated as they are
  and reported, so someone can replace them on both sites.
- Blog posts are not part of the first run; step 9 is started separately.

## Lessons from the Bosnian site

- **Each site has its own media library and its own term and package IDs.** Images, price
  categories and taxonomy terms must be copied and remapped, never referenced by ID.
- **WordPress builds cross-site links with the current site's rewrite rules.** Tour and tour
  taxonomy URLs on another site come from that site's WP Travel Engine permalink bases (D32).
- **Terms may already exist under other slugs** (Bosnian had "group" where the base site has
  "group-tour"). Match by slug, and remove the target site's empty duplicates.
- **WP Travel Engine has no language packs** for most languages: its interface words need the
  generated `.mo` file (step 7), including strings with a context (`taxonomy general name`).
- **Text pasted from ChatGPT** carries `data-start`/`data-end` attributes and conversation wrappers;
  the copy step strips them.
- **The nginx FastCGI cache** serves visitors the old pages for up to 30 minutes after a run; verify
  with `?nocache=1`. Purging needs sudo.
- **The must-use plugins folder belongs to root**, so nothing can be added there; everything lives
  in the AO Quill plugin or in `wp-content/languages/`.

## German: what the first run will do

- Set `/de/` to `de_DE` and install the German theme and plugin packs that exist.
- Copy and translate everything in the table above (the site has none of it today).
- Generate `wp-travel-engine-de_DE.mo` if WP Travel Engine has no German pack.
- Link all pages, tours and categories to English; add "Deutsch" to the English and Bosnian
  language menus.
- Leave blog posts for step 9, and the Arabic site (`/ar/`, also still English and empty) for the
  next run of the same command with `--locale=ar` (right-to-left: check the theme's RTL styles in the
  verify step).

## Open questions

- Should the German and Arabic sites get blog posts at all, or only the pages and tours?
- Translation quality: the worker's translate agent is a 14B local model. Good enough for German
  without review, or should step 6 wait for a review of the translation file?
- Slugs for German pages: keep the English slugs (as on Bosnian) or translate them?

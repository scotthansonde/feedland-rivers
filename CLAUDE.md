# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress plugin (`feedland-rivers`) that displays a FeedLand river — a stream of news items from feeds a FeedLand user subscribes to — on a WordPress page via the `[feedland-rivers]` shortcode. Pure PHP, no build step, no JavaScript tooling. Targets PHP 7.4+, WordPress 6.1+ (tested up to 7.1).

## Commands

```bash
composer install       # pulls PHPCS + WordPress Coding Standards (dev-only, never shipped)
composer lint           # runs phpcs against .phpcs.xml.dist
composer lint:fix       # runs phpcbf to auto-fix what it safely can
php -l <file>            # syntax-check a single file
```

There is no automated test suite (no PHPUnit, no `tests/` directory). Verify changes by actually running the plugin — see "Manual verification" below.

### Packaging a release

`.github/workflows/release.yml` builds a distributable zip named `feedland-rivers-X.Y.Z.zip` (version from the pushed tag) via `git archive`, attaches it to the GitHub Release, and sets a release body note pointing at that asset — because GitHub also auto-attaches its own unrelated "Source code (zip/tar.gz)" archives to every release, and those contain the raw repo checkout (wrong root folder name, dev-only files included) rather than the installable plugin package. To build the zip locally:

```bash
git archive --format=zip --prefix=feedland-rivers/ HEAD \
  -o feedland-rivers-X.Y.Z.zip \
  -- . ':!composer.json' ':!composer.lock' ':!.phpcs.xml.dist' ':!README.md' ':!.gitignore' ':!.github' ':!CLAUDE.md'
```

The plugin header `Version:` (`feedland-rivers.php`) and `Stable tag:` (`readme.txt`) must be bumped together — nothing enforces this automatically.

When checking a release with the WordPress Plugin Check plugin, download the `feedland-rivers.zip` release *asset* specifically. GitHub also auto-attaches a "Source code (zip)" archive to every release, containing the entire unfiltered repo (`.git`-tracked dotfiles, `CLAUDE.md`, composer files, everything) under a version-suffixed folder name (`feedland-rivers-0.2.1/` rather than `feedland-rivers/`) — checking that one instead produces a wall of spurious `TextDomainMismatch` errors (it infers the expected text domain from the folder name) plus `hidden_files`/`application_detected`/`github_directory` warnings for files the real release zip never ships.

### Manual verification

Because output renders inside a sandboxed `<iframe srcdoc="...">`, the page's raw HTML source is one extra layer of attribute-encoding removed from what actually gets parsed — inspecting it directly is misleading. To see the real rendered document, extract the `srcdoc` attribute value and run it through `html_entity_decode()`.

A disposable Docker WordPress install (matching the plugin's "Tested up to" version) is the fastest way to reproduce and verify behavior against real WordPress core functions (`wp_kses()`, `esc_attr()`, etc.) rather than guessing: `wordpress:latest` + `mysql:8.0` + `wordpress:cli` via docker-compose, plugin directory bind-mounted into `wp-content/plugins/feedland-rivers`, `wp option update feedland_rivers_options --format=json` to configure it, `wp post create --post_content='[feedland-rivers]'` for a test page, then `curl` the page and decode the `srcdoc` attribute as above. This caught a real bug (see "The srcdoc double-encoding gotcha" below) that a synthetic PHP harness alone missed.

## Architecture

Three PHP files, tied together by one trust boundary: **feed content is untrusted** (arbitrary third-party RSS, aggregated by FeedLand) and must only ever reach output through this plugin's own fixed, escaped code paths — never through the admin-supplied/fetched template, and never through a less-obvious channel like attribute double-encoding.

- **`feedland-rivers.php`** — bootstrap: constants (`FEEDLAND_RIVERS_DEFAULT_*`, `FEEDLAND_RIVERS_MAX_ITEMS`, `FEEDLAND_RIVERS_CACHE_TTL`/`ERROR_CACHE_TTL`), hook registration, and `feedland_rivers_shortcode()` — the shortcode callback and main entry point. Resolves `server`/`username`/`category` from shortcode attributes first, falling back to the Settings page option for each (so a site can embed several different rivers via multiple shortcode instances, while a bare `[feedland-rivers]` uses the configured default). Calls into `render.php` to fetch/cache the river JSON and render the full HTML document, then wraps it in a sandboxed iframe.

- **`includes/settings.php`** — registers the Settings > FeedLand Rivers admin page and validates/sanitizes on save (`feedland_rivers_validate_options()`), including live pings to FeedLand's `isuserindatabase`/`getriverfromcategory` endpoints to verify username/category. Two principles apply consistently: malformed input is rejected and falls back to the previously-saved value (never the plugin default, so a typo can't wipe a working config); input that merely couldn't be *verified* (e.g. FeedLand unreachable) is kept as entered, with a warning. `feedland_rivers_clean_url()` is a shared URL-validation helper used both here and by the shortcode's `server` attribute override.

- **`includes/render.php`** — the bulk of the logic:
  - `feedland_rivers_get_river()` fetches FeedLand's `getriver`/`getriverfromcategory` JSON API server-side, cached in a WP transient keyed on `server|username|category|max_items` (`FEEDLAND_RIVERS_CACHE_TTL`, 3 min default). Failures are cached too, for a shorter TTL, as a sentinel array (since `get_transient()` can't distinguish "not cached" from "cached the value false").
  - `feedland_rivers_build_sections()` preserves FeedLand's own reverse-chronological, interleaved section order verbatim — this is **not** grouped by feed; the same feed can legitimately reappear in a later section if other feeds' items land between them chronologically.
  - `feedland_rivers_render_item()`/`render_section()` render each item/section as HTML. `feedland_rivers_sanitize_description()` runs feed-supplied description HTML through `wp_kses()` with a small allowlist (`p`, `br`, `a[href]`, `strong`, `b`, `em`, `i`); links get `target="_blank" rel="noopener noreferrer"` forced on afterward rather than allowed through kses, so a feed can't supply its own `rel` and enable reverse tabnabbing.
  - `feedland_rivers_get_template()`/`default_template()`/`apply_template()` fetch an admin-configured Template URL (cached) or fall back to the bundled `assets/default-template.html`, substituting `[%pageTitle%]`/`[%pageDescription%]`/`[%pageImage%]`/`[%fontFaceCss%]` tokens via `strtr()` (matching FeedLand's own template convention: raw, unescaped, single-pass). Only admin-authored values ever reach template substitution — feed content is injected separately at the template's `id="idRiverContent"` mount point by `render_iframe_document()`, so the sanitization boundary holds regardless of which template is active.
  - The plugin self-hosts the Ubuntu webfont (`feedland_rivers_maybe_serve_font()`, CORS-enabled since the iframe is opaque-origin) and inlines Font Awesome Free 5 SVG icons for item footers — no third-party stylesheets/fonts/scripts in the plugin's *own* generated markup. This doesn't extend to a custom Template URL, which is fetched and used verbatim as the page shell and can load whatever it wants. Feed favicons come from DuckDuckGo's icon service (`feedland_rivers_favicon_url` filter to override or disable) — the one deliberate remaining third-party request.

- **`uninstall.php`** — deletes the options row and sweeps all `feedland_rivers_*` transients via a direct query (transient keys embed an md5 hash and can't otherwise be enumerated), looping over every site on multisite.

### The srcdoc double-encoding gotcha

`feedland_rivers_shortcode()` serializes the *entire* rendered document into one iframe `srcdoc` attribute. `esc_attr()` alone is not safe for this: it defaults to `$double_encode = false`, so a pre-existing HTML entity already in the sanitized content (e.g. a feed's own escaped `&lt;img&gt;` sample text, left inert by `wp_kses()`) survives `esc_attr()` untouched — and the browser's one normal attribute-decode pass then fully unwraps it back into a live tag inside the iframe, silently defeating the sanitizer (and, since the iframe runs with `allow-scripts`, could let a feed smuggle a real `<script>` tag through). The fix is `htmlspecialchars( wp_check_invalid_utf8( $srcdoc ), ENT_QUOTES, 'UTF-8', true )` — force `$double_encode = true` — whenever serializing a whole sub-document into an attribute, not `esc_attr()`.

## Comment conventions in this codebase

Comments here explain non-obvious *why* (a hidden constraint, a subtle invariant, a workaround, a security rationale) — not *what* the code does. They describe **current** behavior and rationale directly; they don't narrate history ("previously X was broken", "Y used to work differently", "Z is now different") since a reader has no earlier version to compare against.

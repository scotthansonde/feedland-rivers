=== FeedLand Rivers ===
Contributors: scotthansonde
Tags: feedland, rss, river, news, feeds
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show a FeedLand river — a constantly-updated stream of news from the feeds a FeedLand user subscribes to — on a WordPress page or post.

== Description ==

Fetches the river JSON from FeedLand's API server-side (cached), renders it with its own template, and embeds the result in an isolated iframe. No FeedLand front-end scripts are involved, and no third-party stylesheets, fonts or scripts are loaded — the webfont and item icons ship with the plugin.

Add the river anywhere with the `[feedland-rivers]` shortcode.

= What it talks to =

* Your configured FeedLand server, server-side only, for the river JSON and feed titles.
* DuckDuckGo's icon service, from the visitor's browser, for each feed's favicon. This is the only third-party request, and it can be turned off:

`add_filter( 'feedland_rivers_favicon_url', '__return_empty_string' );`

= Filters =

* `feedland_rivers_max_items` — total item cap across all sections (default 20).
* `feedland_rivers_cache_ttl` — how long a fetched river stays cached, in seconds (default 600).
* `feedland_rivers_favicon_url` — the favicon URL for a feed, or '' for none.

== Installation ==

1. Install and activate the plugin.
2. Go to **Settings > FeedLand Rivers** and enter the FeedLand username whose river you want to show. Optionally limit it to one category.
3. Add the `[feedland-rivers]` shortcode to a page, post or widget.

== Frequently Asked Questions ==

= Can I show more than one river? =

Yes. The `username`, `category` and `server` shortcode attributes override the corresponding setting, e.g. `[feedland-rivers username="alice" category="tech"]`. A bare `[feedland-rivers]` uses the username/category/server configured at **Settings > FeedLand Rivers**.

= Why is the river in an iframe? =

For genuine two-way CSS and JavaScript isolation from the theme. The iframe is sandboxed without `allow-same-origin`, so feed content can't reach the surrounding page. It sizes itself to its content via `postMessage`.

= Can I change how it looks? =

Yes. Point the Template URL setting at your own HTML template, which is fetched and filled in the same way FeedLand fills its own News Product templates. It needs an element with `id="idRiverContent"` for the river to be injected into, and it can use the `[%pageTitle%]`, `[%pageDescription%]`, `[%pageImage%]` and `[%fontFaceCss%]` tokens.

= Nothing shows up. =

Check that the username is correct at **Settings > FeedLand Rivers**, and that the account is subscribed to at least one feed — FeedLand only polls feeds that have active subscribers.

== Changelog ==

= 0.2.2 =
* The release zip no longer includes the developer-only CLAUDE.md file.
* Fixed unprefixed global variables in uninstall.php flagged by the Plugin Check tool.

= 0.2.1 =
* Fixed a bug that let a feed's own pre-escaped sample markup (e.g. a tutorial post showing `<img>` as literal text) survive as a live tag inside the river's sandboxed iframe, bypassing the description sanitizer.

= 0.2.0 =
* Item ages and section timestamps are rendered in the site's timezone instead of being shifted by its UTC offset.
* An empty river is distinguished from a failed fetch; failed fetches are briefly cached so an outage no longer costs a blocking request per page view.
* Malformed API responses and templates without a mount point no longer break the river silently.
* Settings validation no longer discards good configuration when the server is briefly unreachable; URLs must be http or https.
* The webfont and item icons are bundled instead of loaded from Google Fonts and a third-party CDN.
* The river is usable by keyboard and screen reader.
* Added `uninstall.php`, translations support, and filters for the item cap and cache lifetime.

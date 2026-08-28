=== FeedLand Rivers ===
Contributors: scotthansonde
Tags: feedland, rss, river, news, feeds
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show a FeedLand river — a constantly-updated stream of news from the feeds a FeedLand user subscribes to — on a WordPress page or post.

== Description ==

Fetches the river JSON from FeedLand's API server-side (cached), renders it with its own template, and embeds the result in an isolated iframe. No FeedLand front-end scripts are involved, and no third-party stylesheets, fonts or scripts are loaded — the webfont and item icons ship with the plugin.

Add the river anywhere with the `[feedland-rivers]` shortcode.

= What it talks to =

* Your configured FeedLand server, server-side only, for the river JSON and feed titles.
* Your configured Template URL, if you set one, server-side only — fetched once and cached, to build the page shell. Leave it blank to use the plugin's built-in template instead.
* DuckDuckGo's icon service, from the visitor's browser, for each feed's favicon. This can be turned off:

`add_filter( 'feedland_rivers_favicon_url', '__return_empty_string' );`

* Your configured FeedLand server's homepage, server-side only, once a day — reads its advertised WebSocket address so live updates (below) reach the right host even on a self-hosted instance whose socket runs on a different subdomain than its API. Falls back to guessing from the server's own host if this can't be read.
* A FeedLand WebSocket address (usually, but not always, the same host as your configured server — see above), from the visitor's browser, while polling (below) is enabled — listens for FeedLand's live new-item/updated-item notifications so a poll can be triggered immediately instead of waiting for the next timed check. Nothing from this connection is ever rendered; it's only compared against the feed URLs already shown before deciding whether to poll early. Set `feedland_rivers_live_updates_enabled` to `__return_false` to turn this off and rely on timed polling alone:

`add_filter( 'feedland_rivers_live_updates_enabled', '__return_false' );`

* This site's own REST API (not a third party), from the visitor's browser, every few minutes — checks for fresh river content and updates it in place without a full page reload. Each request carries a token proving the specific river shown was actually configured on this site, generated when the page itself was rendered; requests for any other username/category/server are rejected. Set `feedland_rivers_poll_interval` to `0` to turn this off entirely and fall back to only refreshing on a full page reload.

= Filters =

* `feedland_rivers_max_items` — total item cap across all sections (default 20).
* `feedland_rivers_cache_ttl` — how long a fetched river stays cached, in seconds (default 180).
* `feedland_rivers_poll_interval` — how often the browser checks for fresh content and updates the river in place, in seconds (default 180, 0 disables). Kept equal to `feedland_rivers_cache_ttl` by default: polling faster than the river cache expires mostly just adds requests that land on cache hits, without showing anything sooner.
* `feedland_rivers_feed_list_cache_ttl` — how long the subscribed-feeds list used to filter live updates stays cached, in seconds (default 1800).
* `feedland_rivers_live_updates_enabled` — whether the browser opens a WebSocket connection to trigger an early poll on a live update (default true).
* `feedland_rivers_live_updates_socket_url` — the WebSocket address to connect to for a given server, or override it if the server's own homepage doesn't advertise the right one.
* `feedland_rivers_socket_discovery_cache_ttl` — how long a discovered WebSocket address stays cached, in seconds (default 86400).
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

= 0.4.0 =
* The river now also listens for FeedLand's live-update notifications over a WebSocket connection and polls immediately when a subscribed feed changes, instead of always waiting for the next timed check. Disable with `add_filter( 'feedland_rivers_live_updates_enabled', '__return_false' );`.
* The watched feed set comes from FeedLand's own OPML subscription list, not just the feeds currently visible in the displayed window, so a quiet feed's next post still triggers a refresh.
* The WebSocket address is discovered from the configured server's own homepage once a day, since a self-hosted instance can run it on a completely different host than its REST API; falls back to a same-host guess if that fails.
* Added the `feedland_rivers_feed_list_cache_ttl`, `feedland_rivers_feed_list_error_cache_ttl`, `feedland_rivers_live_updates_enabled`, `feedland_rivers_live_updates_socket_url`, `feedland_rivers_socket_discovery_cache_ttl` and `feedland_rivers_socket_discovery_error_cache_ttl` filters.

= 0.3.0 =
* The river now checks for fresh content every few minutes and updates itself in place, so a page left open picks up new items without a full reload. Updates fade in rather than causing the flash a full document swap would otherwise show. Disable with `add_filter( 'feedland_rivers_poll_interval', '__return_zero' );`.
* Added the `feedland_rivers_poll_interval` filter; the default river cache lifetime is now 3 minutes (previously 10), matched to the new poll interval so the two don't drift apart.
* The polling requests are same-origin, not third-party, and are cryptographically scoped to only the rivers this site actually renders — see "What it talks to" above. Outbound FeedLand requests can no longer be pointed at an internal/private address.

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

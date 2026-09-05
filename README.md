# River Embed for FeedLand

## What is this?

This is a WordPress plugin that displays a FeedLand river — a constantly-updated stream of news items from feeds a FeedLand user subscribes to — on a WordPress page or post.

FeedLand also has its own [News Product](https://docs.feedland.com/newsproducts.md) feature, a whole standalone HTML page you deploy on your own server. This plugin doesn't use that; instead it fetches the same underlying river JSON directly from FeedLand's API on the server side (cached), renders it with its own template, and embeds the result on the page via an isolated `iframe`.

## How to Use

1. Install and activate the plugin.
2. Adjust the plugin settings at **Settings > River Embed**: enter your FeedLand username and, optionally, a category to limit the river to feeds in that category (leave it blank to show everything the user subscribes to). Description, Image URL, and Template URL are optional — see "Customizing the template" below.
3. Add the `[feedland-rivers]` shortcode anywhere on your site to display the river.

### Adding the Shortcode

Whether selecting a widget or a block, you will choose "Shortcode", then copy/paste this into the input:
```PHP
[feedland-rivers]
```

You can also output the shortcode in your PHP templates like this:
```PHP
echo do_shortcode( '[feedland-rivers]' );
```

### Multiple rivers

The `username`, `category` and `server` attributes override the corresponding setting, so you can embed different rivers on different pages:
```PHP
[feedland-rivers username="alice" category="tech"]
[feedland-rivers username="bob" server="https://myfeedland.example.com/"]
```
A bare `[feedland-rivers]` still uses the username/category/server configured at **Settings > River Embed**.

## How it works

FeedLand doesn't offer a lightweight "just the river, sized to content" embed URL — its News Product pages (`/newsproduct`) are always full standalone HTML documents pulling in ~25 scripts/stylesheets (legacy jQuery, Bootstrap, a WebSocket connection, etc.), meant to be deployed as their own page rather than embedded inside another site's layout.

Instead, on each shortcode render this plugin:

1. Calls FeedLand's own JSON river API directly — `getriverfromcategory` (or `getriver` for "everything the user subscribes to") — server-side via `wp_safe_remote_get()`, cached in a transient (`FEEDLAND_RIVERS_CACHE_TTL`, 3 minutes by default) so we're not hitting FeedLand on every page view.
2. Renders items as sections in FeedLand's own reverse-chronological order (`includes/render.php`) — one section per batch the API returns, without merging or re-sorting, so the same feed can reappear further down if other feeds' items interleave chronologically — styled to match feedland.com's own river page (`?river=true&...`), no FeedLand front-end scripts involved at all.
3. Fetches a **document template** (see below) and drops that rendered content into it, then embeds the result via `<iframe srcdoc="...">`. A small script watches the document's height with `ResizeObserver` and reports it to the parent page via `postMessage`, which a listener uses to size the iframe to its actual content — so there's no fixed/guessed height.

This gives real two-way CSS/JS isolation from the WordPress theme (a genuine iframe, not a same-page reset hack) and drops the dependency on FeedLand's undocumented internal front-end scripts entirely. Item titles/descriptions are untrusted, since they originate from whatever external RSS feeds the FeedLand user subscribes to — this holds regardless of which document template is in use, since only admin-authored values (title/description/image) ever reach the template's substitution; feed content is rendered separately, by the plugin's own fixed code. Titles are stripped to plain text; descriptions allow a small set of formatting tags (paragraphs, links, bold/italic) via `wp_kses()` — independently of whatever FeedLand itself does. FeedLand does sanitize descriptions server-side, at feed-ingestion time (`sanitize-html`, restricted to `p`/`br` — confirmed from `database.js` in its own source, and actually stricter than this plugin's own allowlist), but that's a property of one particular upstream deployment's ingestion pipeline, not a guarantee this plugin can assume holds for whichever FeedLand server happens to be configured, including a self-hosted one.

## Customizing the template

FeedLand itself builds its News Product pages by fetching an HTML template from a configured URL and filling in `[%token%]` placeholders (confirmed directly from FeedLand's own templates, e.g. `http://scripting.com/code/riverclient/index.html` — the lean "just a river page" variant of its News Product page, as opposed to the full FeedLand app shell). This plugin works the same way:

- **Template URL** (Settings > River Embed) — an HTML template fetched and filled in the same way. Leave it blank to use the plugin's built-in template (`assets/default-template.html`), modeled on FeedLand's own riverclient template but without its ~25-script dependency chain. Point it at your own hosted template, or at FeedLand's own riverclient URL if you want that exact look, to override it.
- Available tokens: `[%pageTitle%]`, `[%pageDescription%]`, `[%pageImage%]` — filled from the Title/Description/Image URL settings. The built-in template doesn't display Description/Image (so leaving them blank changes nothing); they're there for compatibility with templates that do.
- Whatever template is used must contain an element with `id="idRiverContent"` — the plugin injects the rendered river content right after that element's opening tag, the same mount-point convention FeedLand's own templates use (their client-side JS fills that div in after the page loads; this plugin fills it in server-side, since it renders items in PHP rather than loading FeedLand's front-end scripts).
- The plugin also injects a small resize-reporting script before `</body>` regardless of which template is in use, since a template not built for this plugin won't already have it.

## Item footer icons

Each item shows all four icons FeedLand's own `riverviewer.js` shows anonymous visitors (same Font Awesome glyphs and `.sp*Button` class names), matching how FeedLand itself always renders all four and just greys out whichever isn't usable rather than omitting it:

- **Doc** — links to the item's own page on FeedLand (`{server}?item={id}`). Always active.
- **Enclosure** (headphones) — links to the item's enclosure when it has one (whatever type — FeedLand opens it the same way regardless of whether it's audio, an image, etc.). Greyed out when the item has none.
- **Share** and **Data** — always greyed out. Share opens a "forward to your linkblog" dialog that requires a FeedLand login to complete; data opens a client-side debug-JSON dialog. Neither has a plain URL, so neither is usable by an anonymous visitor on someone else's WordPress site.

## Bundled assets and credits

Everything the plugin itself generates — the built-in template and every
item/section — is either inline or served from your own site: no third-party
stylesheets, fonts or scripts, so no visitor's browser contacts a third party
just to view a river (hotlinked Google Fonts has been held to breach the GDPR
in German courts), and nothing breaks if some other site's CDN goes down.
This doesn't extend to a custom Template URL: that template is fetched and
used as the page shell verbatim, so it can load whatever it wants, including
its own third-party fonts/scripts/stylesheets — see "Customizing the
template" above.

- **Ubuntu** (`assets/fonts/`) — weights 400 and 700, `latin` and `latin-ext`
  subsets only. Licensed under the [Ubuntu Font Licence 1.0](assets/fonts/LICENCE.txt).
  Applied only by the built-in template's own CSS (`body`, `.river-item-title`,
  etc. in `assets/default-template.html`) — the item/section HTML the plugin
  generates carries no font styling of its own, so a custom template is free
  to use its own fonts instead. Served through the plugin rather than as a
  plain file URL because the river renders in a sandboxed, opaque-origin
  iframe, which makes even a same-server font a CORS-gated cross-origin
  request; see `feedland_rivers_maybe_serve_font()`.
- **Item footer icons** — the four glyphs are inlined as SVG from
  [Font Awesome Free 5](https://fontawesome.com) (`retweet`, `file-alt`,
  `code`, `headphones`) — version 5 specifically, since 6 redrew `retweet`
  and `headphones` lighter. Font Awesome Free icons are licensed
  [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).
- **Feed favicons** are fetched from DuckDuckGo's icon service — they can't be
  bundled, since they belong to whatever sites the river happens to link to.
  Filter them to your own proxy, or off entirely:

```PHP
add_filter( 'feedland_rivers_favicon_url', '__return_empty_string' );
```

- **Live updates**: while polling is enabled, the visitor's browser also opens
  a WebSocket connection (see `feedland_rivers_live_updates_script()`),
  listening for FeedLand's live new-item/updated-item notifications so a poll
  can fire immediately instead of waiting for the next timed check. Nothing
  from that connection is ever rendered — each notification is only compared,
  as a plain string, against the feed URLs already shown, to decide whether to
  poll early. That watched set comes from FeedLand's own OPML subscription
  list (`feedland_rivers_get_category_feed_urls()`), not just the feeds
  currently visible in the displayed window, so a quiet feed's next post still
  triggers a refresh once it has one. The socket address usually matches the
  configured server, but not always — a self-hosted instance can run its
  socket on an entirely different host, so the server-side fetches that
  host's own homepage once a day and reads its advertised address
  (`feedland_rivers_discover_socket_url()`), falling back to a same-host guess
  if that fails; both are overridable per-server with
  `feedland_rivers_live_updates_socket_url`. Turn live updates off entirely to
  fall back to timed polling alone:

```PHP
add_filter( 'feedland_rivers_live_updates_enabled', '__return_false' );
```

## Notes / known limitations

- Only a single category (or "everything the user subscribes to") is supported per shortcode instance — items appear in one section per batch of items the API returns, in the server's own order (matching feedland.com's own river page, which does not merge or re-sort them either, so the same feed can reappear further down), capped at `FEEDLAND_RIVERS_MAX_ITEMS` (20 by default) total items across all sections. For more than one category on a page, use multiple shortcode instances — see "Multiple rivers" above. FeedLand News Products also support multiple tabs of categories within a single instance; that could be added as a future enhancement if needed.
- The feed's owner must be subscribed to a feed in FeedLand for it to show up in the river — FeedLand only checks feeds that have active subscribers.
- No interactive share/like/bookmark icons — those require being logged into FeedLand as the account owner, which isn't meaningful for an anonymous visitor on someone else's WordPress site.

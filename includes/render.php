<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Resolves the server/username/category to use for a river, applying the
 * same shortcode-attribute-overrides-option-fallback rule regardless of
 * caller: the shortcode's own atts (guaranteed strings by shortcode_atts())
 * and a REST request's params (which, without a declared string schema,
 * could be null or an array) both pass through here, so each value is
 * coerced defensively rather than trusted to already be a string.
 *
 * @param array $atts    {server?: mixed, username?: mixed, category?: mixed}.
 * @param array $options The feedland_rivers_options array.
 *
 * @return array {server: string, username: string, category: string}.
 */
function feedland_rivers_resolve_atts( array $atts, array $options ): array {
	$server = is_string( $atts['server'] ?? null ) ? trim( $atts['server'] ) : '';
	$server = '' !== $server ? feedland_rivers_clean_url( $server ) : '';

	if ( '' === $server ) {
		$server = $options['feedland_rivers_server'] ?? FEEDLAND_RIVERS_DEFAULT_SERVER;
	}

	$server = trailingslashit( $server );

	$username = is_string( $atts['username'] ?? null ) ? trim( $atts['username'] ) : '';
	$username = '' !== $username ? sanitize_text_field( $username ) : trim( $options['feedland_rivers_username'] ?? '' );

	$category = is_string( $atts['category'] ?? null ) ? trim( $atts['category'] ) : '';
	$category = '' !== $category ? sanitize_text_field( $category ) : trim( $options['feedland_rivers_category'] ?? '' );

	return array(
		'server'   => $server,
		'username' => $username,
		'category' => $category,
	);
}

/**
 * Fetches and renders a river's full iframe srcdoc document for an already-
 * resolved server/username/category, or false when there's no username to
 * fetch with or the fetch itself failed. Shared by the shortcode and the
 * REST poll endpoint so both render exactly the same way.
 *
 * @param string $server   FeedLand server base URL, trailing slash included.
 * @param string $username FeedLand screenname.
 * @param string $category Optional category name.
 * @param array  $options  The feedland_rivers_options array.
 *
 * @return string|false
 */
function feedland_rivers_render_srcdoc( string $server, string $username, string $category, array $options ) {
	if ( '' === $username ) {
		return false;
	}

	$river = feedland_rivers_get_river( $server, $username, $category, feedland_rivers_max_items() );

	if ( false === $river ) {
		return false;
	}

	return feedland_rivers_render_iframe_document( $river, $server, $options );
}

/**
 * A token proving a resolved server/username/category triple was actually
 * rendered by this site, shared by the shortcode (which creates one for the
 * poll script to send back) and the REST poll endpoint (which verifies it
 * before doing anything else).
 *
 * The REST route is public and, per its own docs, deliberately accepts
 * server/username/category from the client -- but only a value this plugin
 * itself already rendered into a page should be able to produce a valid
 * token for that exact triple, which is what actually closes off "hit the
 * endpoint directly with arbitrary params": the plain server/username/
 * category matching the shortcode's own validation logic (resolve_atts()
 * above) was never itself a barrier to that.
 *
 * Deliberately wp_hash(), not wp_create_nonce()/wp_verify_nonce(): both are
 * an HMAC over the site's secret salts, but a real nonce also mixes in the
 * *current request's* user ID and session token, which is wrong for this
 * value specifically. The river a given triple renders is identical for
 * every visitor regardless of who's logged in, the token sits in HTML a page
 * cache or CDN may serve unchanged to a mix of logged-in and anonymous
 * visitors, and the token needs to keep validating when the poll script
 * calls back minutes after the page loaded, from whatever session that
 * browser tab happens to carry by then -- none of which is the same
 * requester identity a nonce implicitly binds to. wp_hash() alone gives the
 * same "only a combination this site actually rendered" guarantee without
 * that binding, at the cost of the token not expiring on its own; an
 * acceptable tradeoff here, since it grants a caller nothing beyond viewing
 * the exact content the site already serves publicly at that combination.
 *
 * @param string $server   FeedLand server base URL, trailing slash included.
 * @param string $username FeedLand screenname.
 * @param string $category Optional category name.
 *
 * @return string
 */
function feedland_rivers_river_token( string $server, string $username, string $category ): string {
	return wp_hash( 'feedland_rivers_river|' . $server . '|' . $username . '|' . $category, 'nonce' );
}

/**
 * Fetches the river JSON for a username/category from FeedLand, cached in a
 * transient so we're not hitting FeedLand on every page view.
 *
 * Distinguishes three outcomes rather than two. A river that fetched and
 * parsed cleanly but happens to contain no feeds is a *success* carrying
 * nothing -- it returns normally and renders as the "no news items yet"
 * message, distinct from an actual fetch failure.
 *
 * Failures are cached too, for a much shorter time
 * (FEEDLAND_RIVERS_ERROR_CACHE_TTL), so a FeedLand outage doesn't cost
 * *every single page view* a fresh 8-second blocking request.
 * get_transient() can't distinguish "not cached" from "cached the value
 * false", so the negative entry is a sentinel array rather than a literal
 * false.
 *
 * The cache key covers server/username/category, so an admin correcting a
 * bad setting gets a different key and never waits out a stale negative
 * entry from the old one.
 *
 * @param string $server   FeedLand server base URL, trailing slash included.
 * @param string $username FeedLand screenname.
 * @param string $category Optional category name.
 *
 * @return array|false Decoded river data (possibly with an empty feeds
 *                     list), or false if it could not be fetched.
 */
function feedland_rivers_get_river( string $server, string $username, string $category, int $max_items = FEEDLAND_RIVERS_MAX_ITEMS ) {
	$cache_key = 'feedland_rivers_' . md5( $server . '|' . $username . '|' . $category . '|' . $max_items );
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) && isset( $cached['feedlandRiversError'] ) ) {
		return false; // Negative cache hit -- don't re-hit a server we just failed against.
	}

	if ( false !== $cached ) {
		return $cached;
	}

	if ( '' !== $category ) {
		$endpoint = add_query_arg(
			array(
				'screenname' => $username,
				'catname'    => $category,
			),
			$server . 'getriverfromcategory'
		);
	} else {
		$endpoint = add_query_arg(
			array( 'screenname' => $username ),
			$server . 'getriver'
		);
	}

	// wp_safe_remote_get(), not wp_remote_get(): $server reaches here from the
	// anonymous-accessible REST poll endpoint (includes/rest.php) as well as
	// the shortcode, so it's no longer only ever a value a trusted content
	// author configured. wp_safe_remote_get() runs wp_http_validate_url(),
	// which rejects a $server that resolves to a loopback/private/link-local/
	// cloud-metadata address (filterable via http_request_host_is_external if
	// a site genuinely needs to point this at an internal FeedLand instance).
	$request = wp_safe_remote_get( $endpoint, array( 'timeout' => 8 ) );

	if ( is_wp_error( $request ) || 200 !== wp_remote_retrieve_response_code( $request ) ) {
		feedland_rivers_cache_river_error( $cache_key );
		return false;
	}

	$data = json_decode( wp_remote_retrieve_body( $request ), true );

	// FeedLand reports a lookup it couldn't satisfy as a {"message": "..."}
	// object with a 200 status (the same convention the category check in
	// feedland_rivers_validate_options() keys off), so a 200 on its own
	// doesn't mean we were handed a river. Anything without a usable feeds
	// list is a failure; an empty-but-present list is not.
	if ( ! is_array( $data ) || isset( $data['message'] ) || ! isset( $data['feeds'] ) || ! is_array( $data['feeds'] ) ) {
		feedland_rivers_cache_river_error( $cache_key );
		return false;
	}

	// Cache only what can actually be rendered. FeedLand returns the
	// account's whole river -- 129 feeds and 164 items, ~320KB serialized,
	// for the account this was measured against -- of which at most
	// $max_items are ever shown. Trimming first took the stored transient
	// from 323KB to 95KB, which matters for object caches with a per-value
	// size limit. build_sections() output is already in the
	// feeds shape, and re-running it over trimmed data is a no-op, so the
	// render path is unchanged. $max_items is part of the cache key, so
	// changing the cap invalidates rather than silently reusing an old trim.
	$data['feeds'] = feedland_rivers_build_sections( $data, $max_items );

	set_transient( $cache_key, $data, feedland_rivers_cache_ttl() );

	return $data;
}

/**
 * Fetches the set of feed URLs a username (optionally scoped to a category)
 * subscribes to on FeedLand, via its OPML subscription-list endpoint.
 *
 * Deliberately separate from feedland_rivers_get_river(): that returns only
 * the feeds represented in the currently-cached top $max_items window, which
 * is too narrow for the WebSocket live-update filter in
 * feedland_rivers_poll_listener_script() -- a feed that hasn't posted
 * recently enough to be in that window still needs to be watched, so a new
 * item from it can trigger an immediate poll instead of only ever matching
 * feeds already on screen.
 *
 * Omitting catname (confirmed against feedland.com) returns everything the
 * user subscribes to, the same "blank means everything" convention
 * getriver/getriverfromcategory already use.
 *
 * Cached far longer than the river itself
 * (FEEDLAND_RIVERS_FEED_LIST_CACHE_TTL vs. FEEDLAND_RIVERS_CACHE_TTL): a
 * subscription list changes far less often than which items are newest, so
 * there's no freshness reason to refetch it every few minutes.
 *
 * @param string $server   FeedLand server base URL, trailing slash included.
 * @param string $username FeedLand screenname.
 * @param string $category Optional category name.
 *
 * @return string[]|false List of feed URLs, or false if the list could not be fetched.
 */
function feedland_rivers_get_category_feed_urls( string $server, string $username, string $category ) {
	if ( '' === $username ) {
		return false;
	}

	$cache_key = 'feedland_rivers_feeds_' . md5( $server . '|' . $username . '|' . $category );
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) && isset( $cached['feedlandRiversError'] ) ) {
		return false; // Negative cache hit -- don't re-hit a server we just failed against.
	}

	if ( false !== $cached ) {
		return $cached;
	}

	$query = array( 'screenname' => $username );
	if ( '' !== $category ) {
		$query['catname'] = $category;
	}

	// wp_safe_remote_get(), not wp_remote_get() -- see the matching comment in
	// feedland_rivers_get_river(). $server reaches here from the same two
	// callers (the shortcode and the anonymous-accessible REST poll endpoint).
	$request = wp_safe_remote_get( add_query_arg( $query, $server . 'opml' ), array( 'timeout' => 8 ) );

	if ( is_wp_error( $request ) || 200 !== wp_remote_retrieve_response_code( $request ) ) {
		set_transient( $cache_key, array( 'feedlandRiversError' => true ), feedland_rivers_feed_list_error_cache_ttl() );
		return false;
	}

	// libxml_use_internal_errors() just keeps a malformed response from
	// emitting a PHP warning on a public page -- simplexml_load_string()
	// already returns false on failure regardless. The parsed xmlUrl values
	// are only ever compared as plain strings against WebSocket feedUrl
	// fields, never output, so there's no separate sanitization boundary to
	// enforce here the way there is for feed item content.
	$previous_setting = libxml_use_internal_errors( true );
	$xml              = simplexml_load_string( wp_remote_retrieve_body( $request ) );
	libxml_use_internal_errors( $previous_setting );

	if ( false === $xml ) {
		set_transient( $cache_key, array( 'feedlandRiversError' => true ), feedland_rivers_feed_list_error_cache_ttl() );
		return false;
	}

	$outlines = $xml->xpath( '//outline[@xmlUrl]' );

	// trim() -- confirmed against a real account's OPML response, at least one
	// xmlUrl value comes back with stray leading whitespace. Matching against
	// a WebSocket feedUrl is exact string comparison (see
	// feedland_rivers_live_updates_script()'s handleSocketMessage()), so an
	// untrimmed value here would silently and permanently exclude that feed
	// from ever matching.
	$feed_urls = array();
	foreach ( is_array( $outlines ) ? $outlines : array() as $outline ) {
		$feed_url = trim( (string) $outline['xmlUrl'] );
		if ( '' !== $feed_url ) {
			$feed_urls[] = $feed_url;
		}
	}
	$feed_urls = array_values( array_unique( $feed_urls ) );

	set_transient( $cache_key, $feed_urls, feedland_rivers_feed_list_cache_ttl() );

	return $feed_urls;
}

/**
 * Discovers a FeedLand server's own advertised WebSocket URL by fetching its
 * homepage and reading the urlSocketServer value out of its inline
 * `var appConsts = {...}` config block -- the same value FeedLand's own
 * front-end connects to. Confirmed present, under that exact key, on both
 * feedland.com and a self-hosted instance whose socket runs on a completely
 * different host from its REST API -- i.e. this is how a self-hosted
 * instance's actual socket host gets found automatically, without requiring
 * every such site to configure the feedland_rivers_live_updates_socket_url
 * filter by hand.
 *
 * See feedland_rivers_live_updates_socket_url(), which actually falls back
 * when this returns false -- to a guessed convention, and after that to the
 * filter -- so a homepage that doesn't expose this (a redesigned template, a
 * server that's down) degrades to a reasonable guess rather than leaving
 * live updates entirely unresolved.
 *
 * @param string $server FeedLand server base URL, trailing slash included.
 *
 * @return string|false The discovered socket URL, or false if it couldn't be found.
 */
function feedland_rivers_discover_socket_url( string $server ) {
	$cache_key = 'feedland_rivers_socket_' . md5( $server );
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) && isset( $cached['feedlandRiversError'] ) ) {
		return false; // Negative cache hit -- don't re-hit a server we just failed against.
	}

	if ( false !== $cached ) {
		return $cached;
	}

	// wp_safe_remote_get() -- see the matching comment in
	// feedland_rivers_get_river(). $server reaches here the same way.
	$request = wp_safe_remote_get( $server, array( 'timeout' => 5 ) );

	if ( is_wp_error( $request ) || 200 !== wp_remote_retrieve_response_code( $request ) ) {
		set_transient( $cache_key, array( 'feedlandRiversError' => true ), feedland_rivers_socket_discovery_error_cache_ttl() );
		return false;
	}

	// Double quotes only, matching the two confirmed real examples
	// (`urlSocketServer: "wss://...",`) -- deliberately narrow, since a wrong
	// match here would be cached as fact for a day; missing an unusual
	// quoting/formatting style just falls back to the guessed convention
	// instead, which is a much smaller cost than a bad cached match.
	if ( ! preg_match( '/urlSocketServer\s*:\s*"(wss?:\/\/[^"]+)"/', wp_remote_retrieve_body( $request ), $matches ) ) {
		set_transient( $cache_key, array( 'feedlandRiversError' => true ), feedland_rivers_socket_discovery_error_cache_ttl() );
		return false;
	}

	set_transient( $cache_key, $matches[1], feedland_rivers_socket_discovery_cache_ttl() );

	return $matches[1];
}

/**
 * Records a short-lived "this fetch failed" marker for a river cache key.
 *
 * A sentinel array rather than a literal false, since get_transient()
 * returns false for a missing entry and there'd be no way to tell the two
 * apart.
 *
 * @param string $cache_key The river transient key.
 *
 * @return void
 */
function feedland_rivers_cache_river_error( string $cache_key ): void {
	set_transient( $cache_key, array( 'feedlandRiversError' => true ), FEEDLAND_RIVERS_ERROR_CACHE_TTL );
}

/**
 * Fetches a feed's display title/link from FeedLand (e.g. "NYT > Top
 * Stories" for the raw HomePage.xml URL), cached for a day since feed
 * metadata rarely changes -- this is what lets FeedLand's own river page
 * (feedland.com/?river=true&...) show a friendly feed name instead of the
 * raw feed URL, and it's cheap here because a river's many item-batches
 * usually collapse to a small number of distinct feed URLs.
 *
 * @param string $server   FeedLand server base URL, trailing slash included.
 * @param string $feed_url The feed's XML URL.
 *
 * @return array {title: string, link: string}
 */
function feedland_rivers_get_feed_info( string $server, string $feed_url ): array {
	$fallback = array(
		'title' => wp_parse_url( $feed_url, PHP_URL_HOST ) ?: $feed_url,
		'link'  => $feed_url,
	);

	$cache_key = 'feedland_rivers_feed_' . md5( $server . '|' . $feed_url );
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return $cached;
	}

	// wp_safe_remote_get() here too -- see the matching comment in
	// feedland_rivers_get_river(). $server is the same client-reachable value
	// in both places.
	$request = wp_safe_remote_get(
		add_query_arg( array( 'url' => $feed_url ), $server . 'getfeed' ),
		array( 'timeout' => 5 )
	);

	if ( is_wp_error( $request ) || 200 !== wp_remote_retrieve_response_code( $request ) ) {
		set_transient( $cache_key, $fallback, feedland_rivers_cache_ttl() );
		return $fallback;
	}

	$data = json_decode( wp_remote_retrieve_body( $request ), true );

	// The getfeed response sits behind the same trust boundary as a river
	// item: title and link are whatever the server returned, and both are
	// handed straight to esc_html()/esc_url(), which are fatal on a non-string.
	$title = feedland_rivers_text( is_array( $data ) ? ( $data['title'] ?? '' ) : '' );
	$link  = feedland_rivers_text( is_array( $data ) ? ( $data['link'] ?? '' ) : '' );

	$info = array(
		'title' => '' !== $title ? $title : $fallback['title'],
		'link'  => '' !== $link ? $link : $fallback['link'],
	);

	set_transient( $cache_key, $info, DAY_IN_SECONDS );

	return $info;
}

/**
 * Coerces a server-supplied value to a string, or '' when it isn't one.
 *
 * Every value inside a river item comes from whatever JSON the configured
 * server chose to return, so any field can be an array or object where a
 * string was expected. A plain (string) cast isn't enough: on an array it
 * emits an "Array to string conversion" warning and yields the literal
 * "Array", and handing one to strtotime() or to a string-typed parameter is
 * a TypeError -- a fatal on a public page, which is exactly what the rest of
 * this file's shape-checking exists to prevent.
 *
 * @param mixed $value The raw value.
 *
 * @return string
 */
function feedland_rivers_text( $value ): string {
	return is_scalar( $value ) ? (string) $value : '';
}

/**
 * An item's pubDate as a Unix timestamp, or 0 when it has none we can read.
 *
 * @param array $item One item from a feedland_rivers_build_sections() section.
 *
 * @return int
 */
function feedland_rivers_item_time( array $item ): int {
	$pub_date = feedland_rivers_text( $item['pubDate'] ?? '' );

	if ( '' === $pub_date ) {
		return 0;
	}

	return (int) strtotime( $pub_date );
}

/**
 * Converts the raw feeds-of-items shape FeedLand returns into render-ready
 * sections, preserving the server's own ordering -- matching exactly how
 * FeedLand's own client does it (theRiver.feeds.forEach in riverviewer.js's
 * displayTraditionalRiver(), scripting.com/code/feedland/home/
 * riverviewer.js): one section per raw array entry, in the order the API
 * returned them. No merging of entries that share a feed URL, no
 * client-side re-sorting -- the API already returns feeds chronologically
 * interleaved (confirmed against feedland.com/?river=true&...), so this is
 * a true chronological river, not a feed-grouped one: the same feed can
 * legitimately reappear later, in its own section, if other feeds' items
 * land in between chronologically. Item count is capped at $max_items
 * across all sections combined.
 *
 * @param array $river     Decoded river data from feedland_rivers_get_river().
 * @param int   $max_items Maximum total number of items to return.
 *
 * @return array List of {feedUrl: string, items: array[]}.
 */
function feedland_rivers_build_sections( array $river, int $max_items ): array {
	$sections  = array();
	$remaining = $max_items;

	if ( empty( $river['feeds'] ) || ! is_array( $river['feeds'] ) ) {
		return $sections;
	}

	foreach ( $river['feeds'] as $feed ) {
		if ( $remaining <= 0 ) {
			break;
		}

		if ( ! is_array( $feed ) || empty( $feed['feedUrl'] ) || ! is_string( $feed['feedUrl'] ) ) {
			continue;
		}

		if ( empty( $feed['items'] ) || ! is_array( $feed['items'] ) ) {
			continue;
		}

		// Drop anything that isn't an item object before slicing, so junk
		// entries don't eat into the item budget, and so a scalar can never
		// reach feedland_rivers_render_item()'s array parameter -- that's a
		// TypeError, i.e. a fatal on a public page, and every value here
		// comes from whatever JSON the configured server chose to return.
		$items = array_values( array_filter( $feed['items'], 'is_array' ) );
		$items = array_slice( $items, 0, $remaining );

		if ( ! $items ) {
			continue;
		}

		$sections[] = array(
			'feedUrl' => $feed['feedUrl'],
			'items'   => $items,
		);

		$remaining -= count( $items );
	}

	return $sections;
}

/**
 * The self-hosted Ubuntu webfont files, keyed by the slug used in the
 * font-serving URL.
 *
 * Only weights 400 and 700 are shipped: those are the only two the bundled
 * template's CSS asks for. (The old Google Fonts request also pulled 500
 * italic, which nothing referenced.) Only the latin and latin-ext subsets
 * are shipped too -- enough for Western and Central European text,
 * including German umlauts and the eszett -- rather than also carrying
 * Cyrillic, Greek and Vietnamese for a river unlikely to need them.
 *
 * Ubuntu is licensed under the Ubuntu Font Licence 1.0, which permits
 * redistribution; see assets/fonts/LICENCE.txt.
 *
 * @return array Map of slug => {weight: int, range: string}.
 */
function feedland_rivers_font_files(): array {
	return array(
		'ubuntu-400-latin'     => array(
			'weight' => 400,
			'range'  => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD',
		),
		'ubuntu-400-latin-ext' => array(
			'weight' => 400,
			'range'  => 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF',
		),
		'ubuntu-700-latin'     => array(
			'weight' => 700,
			'range'  => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD',
		),
		'ubuntu-700-latin-ext' => array(
			'weight' => 700,
			'range'  => 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF',
		),
	);
}

/**
 * Serves one of the bundled woff2 files, with the CORS header that makes it
 * loadable from inside the river iframe.
 *
 * This route exists because of an interaction that isn't obvious. The river
 * renders in a sandboxed iframe with allow-same-origin deliberately
 * omitted, so its document has an *opaque* origin. Every subresource it
 * requests is therefore cross-origin -- including a font sitting in this
 * plugin's own directory on this very server -- and @font-face fetches are
 * CORS-gated. Referencing assets/fonts/*.woff2 by plain URL fails with a
 * NetworkError (verified in Chrome). fonts.googleapis.com only ever worked
 * here because gstatic sends Access-Control-Allow-Origin: *; serving the
 * files ourselves means sending it ourselves.
 *
 * The slug is matched against a fixed allowlist before any filesystem
 * access, so there is no path to traverse. Responses are immutable and
 * cached for a year, so a visitor fetches each file once.
 *
 * @return void
 */
function feedland_rivers_maybe_serve_font(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only static asset.
	$slug = isset( $_GET['feedland_rivers_font'] ) ? sanitize_key( wp_unslash( $_GET['feedland_rivers_font'] ) ) : '';

	if ( '' === $slug ) {
		return;
	}

	$files = feedland_rivers_font_files();
	$path  = FEEDLAND_RIVERS_PATH . 'assets/fonts/' . $slug . '.woff2';

	if ( ! isset( $files[ $slug ] ) || ! is_readable( $path ) ) {
		status_header( 404 );
		exit;
	}

	header( 'Content-Type: font/woff2' );
	header( 'Content-Length: ' . filesize( $path ) );
	header( 'Access-Control-Allow-Origin: *' );
	header( 'Cache-Control: public, max-age=31536000, immutable' );
	header( 'X-Content-Type-Options: nosniff' );

	// phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming a bundled binary asset, not site content.
	readfile( $path );
	exit;
}

/**
 * Builds the @font-face block for the bundled Ubuntu files, for templates
 * carrying the [%fontFaceCss%] token.
 *
 * @return string
 */
function feedland_rivers_font_face_css(): string {
	$css = '';

	foreach ( feedland_rivers_font_files() as $slug => $meta ) {
		$url = add_query_arg( 'feedland_rivers_font', $slug, home_url( '/' ) );

		$css .= sprintf(
			'@font-face{font-family:Ubuntu;font-style:normal;font-weight:%1$d;font-display:swap;src:url("%2$s") format("woff2");unicode-range:%3$s;}',
			(int) $meta['weight'],
			esc_url_raw( $url ),
			$meta['range']
		);
	}

	return '<style>' . $css . '</style>';
}

/**
 * Wraps text so it is available to assistive tech but not visible.
 *
 * Styled inline rather than via a class because the document's CSS comes
 * from a swappable template, which may not define a helper class -- an
 * unstyled helper class would leave this text visible on the page.
 *
 * @param string $text The text to hide visually.
 *
 * @return string
 */
function feedland_rivers_screen_reader_text( string $text ): string {
	return '<span class="river-sr-only" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0">' . esc_html( $text ) . '</span>';
}

/**
 * The four footer glyphs, inlined as SVG path data.
 *
 * Inlined as SVG rather than loaded from a stylesheet, so viewing a river
 * doesn't contact a third-party origin for four glyphs out of a library of
 * thousands, and the footer keeps working if that origin goes down. They're
 * the same four icons FeedLand's own riverviewer.js shows, so the footer
 * still looks like FeedLand's.
 *
 * Icons: Font Awesome Free 5 (https://fontawesome.com) -- retweet,
 * file-alt, code and headphones. Version 5 specifically: 6 redrew retweet
 * and headphones lighter, which would change the footer's weight. Font
 * Awesome Free icon paths are
 * licensed CC BY 4.0 (https://creativecommons.org/licenses/by/4.0/); see
 * the credit in README.md.
 *
 * @return array Map of icon key => {width: int, height: int, path: string}.
 */
function feedland_rivers_icons(): array {
	return array(
		'share' => array(
			'width'   => 640,
			'height'  => 512,
			'path'    => 'M629.657 343.598L528.971 444.284c-9.373 9.372-24.568 9.372-33.941 0L394.343 343.598c-9.373-9.373-9.373-24.569 0-33.941l10.823-10.823c9.562-9.562 25.133-9.34 34.419.492L480 342.118V160H292.451a24.005 24.005 0 0 1-16.971-7.029l-16-16C244.361 121.851 255.069 96 276.451 96H520c13.255 0 24 10.745 24 24v222.118l40.416-42.792c9.285-9.831 24.856-10.054 34.419-.492l10.823 10.823c9.372 9.372 9.372 24.569-.001 33.941zm-265.138 15.431A23.999 23.999 0 0 0 347.548 352H160V169.881l40.416 42.792c9.286 9.831 24.856 10.054 34.419.491l10.822-10.822c9.373-9.373 9.373-24.569 0-33.941L144.971 67.716c-9.373-9.373-24.569-9.373-33.941 0L10.343 168.402c-9.373 9.373-9.373 24.569 0 33.941l10.822 10.822c9.562 9.562 25.133 9.34 34.419-.491L96 169.881V392c0 13.255 10.745 24 24 24h243.549c21.382 0 32.09-25.851 16.971-40.971l-16.001-16z',
		),
		'doc' => array(
			'width'   => 384,
			'height'  => 512,
			'path'    => 'M288 248v28c0 6.6-5.4 12-12 12H108c-6.6 0-12-5.4-12-12v-28c0-6.6 5.4-12 12-12h168c6.6 0 12 5.4 12 12zm-12 72H108c-6.6 0-12 5.4-12 12v28c0 6.6 5.4 12 12 12h168c6.6 0 12-5.4 12-12v-28c0-6.6-5.4-12-12-12zm108-188.1V464c0 26.5-21.5 48-48 48H48c-26.5 0-48-21.5-48-48V48C0 21.5 21.5 0 48 0h204.1C264.8 0 277 5.1 286 14.1L369.9 98c9 8.9 14.1 21.2 14.1 33.9zm-128-80V128h76.1L256 51.9zM336 464V176H232c-13.3 0-24-10.7-24-24V48H48v416h288z',
		),
		'data' => array(
			'width'   => 640,
			'height'  => 512,
			'path'    => 'M278.9 511.5l-61-17.7c-6.4-1.8-10-8.5-8.2-14.9L346.2 8.7c1.8-6.4 8.5-10 14.9-8.2l61 17.7c6.4 1.8 10 8.5 8.2 14.9L293.8 503.3c-1.9 6.4-8.5 10.1-14.9 8.2zm-114-112.2l43.5-46.4c4.6-4.9 4.3-12.7-.8-17.2L117 256l90.6-79.7c5.1-4.5 5.5-12.3.8-17.2l-43.5-46.4c-4.5-4.8-12.1-5.1-17-.5L3.8 247.2c-5.1 4.7-5.1 12.8 0 17.5l144.1 135.1c4.9 4.6 12.5 4.4 17-.5zm327.2.6l144.1-135.1c5.1-4.7 5.1-12.8 0-17.5L492.1 112.1c-4.8-4.5-12.4-4.3-17 .5L431.6 159c-4.6 4.9-4.3 12.7.8 17.2L523 256l-90.6 79.7c-5.1 4.5-5.5 12.3-.8 17.2l43.5 46.4c4.5 4.9 12.1 5.1 17 .6z',
		),
		'enclosure' => array(
			'width'   => 512,
			'height'  => 512,
			'path'    => 'M256 32C114.52 32 0 146.496 0 288v48a32 32 0 0 0 17.689 28.622l14.383 7.191C34.083 431.903 83.421 480 144 480h24c13.255 0 24-10.745 24-24V280c0-13.255-10.745-24-24-24h-24c-31.342 0-59.671 12.879-80 33.627V288c0-105.869 86.131-192 192-192s192 86.131 192 192v1.627C427.671 268.879 399.342 256 368 256h-24c-13.255 0-24 10.745-24 24v176c0 13.255 10.745 24 24 24h24c60.579 0 109.917-48.098 111.928-108.187l14.382-7.191A32 32 0 0 0 512 336v-48c0-141.479-114.496-256-256-256z',
		),
	);
}

/**
 * Renders one footer icon: an active link when $url is non-empty, or a
 * greyed-out, non-clickable placeholder (matching FeedLand's own
 * .spEnclosureButtonDisabled treatment of an item with no enclosure --
 * riverviewer.js always renders that icon, just disabled) when it isn't.
 *
 * @param string $class          The FeedLand-matching wrapper class (e.g. spDocButton).
 * @param string $icon           Icon key from feedland_rivers_icons().
 * @param string $url            Destination URL, or '' to render disabled.
 * @param string $title          Tooltip when active.
 * @param string $disabled_title Tooltip when disabled.
 *
 * @return string
 */
function feedland_rivers_render_footer_icon( string $class, string $icon, string $url, string $title, string $disabled_title = '' ): string {
	$icons = feedland_rivers_icons();

	if ( ! isset( $icons[ $icon ] ) ) {
		return '';
	}

	$width  = (int) $icons[ $icon ]['width'];
	$height = (int) $icons[ $icon ]['height'];

	/*
	 * Height is locked to 1em and width follows the glyph's own aspect ratio,
	 * which is how a webfont sizes an icon: the em box fixes the height, and
	 * the advance width is whatever the glyph needs. Giving both axes 1em
	 * instead letterboxes anything wider than it is tall -- SVG's default
	 * preserveAspectRatio shrank both retweet and code (640x512) to 0.80em
	 * to make them fit a 1em square, which read as visibly smaller and
	 * thinner than the Font Awesome stylesheet these replaced.
	 */
	$svg = sprintf(
		'<svg class="river-icon" viewBox="0 0 %1$d %2$d" width="%3$sem" height="1em" role="img" aria-hidden="true" focusable="false"><path d="%4$s" fill="currentColor"></path></svg>',
		$width,
		$height,
		esc_attr( rtrim( rtrim( number_format( $width / $height, 4, '.', '' ), '0' ), '.' ) ),
		esc_attr( $icons[ $icon ]['path'] )
	);

	if ( $url ) {
		// The glyph is aria-hidden, so the link needs a real accessible name:
		// visually-hidden text, not a title attribute, which screen readers
		// expose inconsistently. The styles are inline because the CSS lives
		// in a swappable template that might not define a helper class.
		return '<span class="' . esc_attr( $class ) . '"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr( $title ) . '">'
			. $svg
			. feedland_rivers_screen_reader_text( $title )
			. '</a></span>';
	}

	// The permanently-disabled icons (share, data) exist only for visual
	// parity with FeedLand, which always draws all four and greys out
	// whichever isn't usable. They aren't controls and do nothing, so they're
	// hidden from assistive tech entirely rather than announced as four
	// buttons of which two are inert.
	return '<span class="' . esc_attr( $class ) . ' river-icon-disabled" title="' . esc_attr( $disabled_title ) . '" aria-hidden="true">' . $svg . '</span>';
}

/**
 * Sanitizes feed-supplied description HTML for display, allowing a small,
 * deliberately curated set of formatting tags rather than stripping
 * everything (wp_strip_all_tags() alone drops </p>/<br> with no
 * replacement, running adjacent paragraphs together into one unreadable
 * block -- seen on real feed content: a Bulwark item whose description was
 * several distinct <p> paragraphs).
 *
 * FeedLand's own river *rendering* applies no sanitization to this text:
 * getDescriptionText() (riverviewer.js) returns feedItem.description
 * unmodified, and setBodytext() inserts it via jQuery's .append(), which
 * parses and renders it as raw HTML -- confirmed by reading the source, and
 * by removeScriptTags()/neuterMarkup() (misc.js) never being called anywhere
 * in that path. That's safe on FeedLand's own end because it sanitizes
 * earlier, at feed-ingestion time: getItemDescription()/stripMarkup()
 * (database/database.js in FeedLand's own source) run every incoming item's
 * description through sanitize-html with an allowlist of exactly `p`/`br`
 * -- no attributes, no links -- before it's ever written to FeedLand's
 * database, and the getriver/getriverfromcategory API this plugin calls
 * serves that already-sanitized stored value back out unchanged. This
 * plugin sanitizes independently anyway rather than trusting that: it's a
 * property of one particular upstream deployment's ingestion pipeline, not
 * something a WordPress site embedding feed content from *whichever*
 * FeedLand server happens to be configured -- including a self-hosted one
 * -- can assume holds. wp_kses() runs here with its own intentionally
 * small allowlist: paragraphs/line breaks plus basic inline emphasis and
 * links -- broader than FeedLand's own p/br-only allowlist specifically
 * because this plugin also renders links, which raises a concern FeedLand's
 * allowlist never has to: `target`/`rel` are deliberately not in the
 * allowed attribute list (so a feed can't set target="_blank" without rel=
 * "noopener", inviting reverse tabnabbing); they're forced consistently
 * on every allowed link afterward instead, matching how every other link
 * this plugin renders is already handled.
 *
 * The obvious tidy-up here -- allow target/rel through kses and normalise
 * with wp_targeted_link_rel() -- is deliberately *not* taken. It would give
 * up the property above (a feed could then supply its own rel), and
 * wp_targeted_link_rel() adds only `noopener`, dropping the `noreferrer`
 * that keeps the visitor's page URL from leaking to whatever the feed links
 * to. Instead the rewrite below doesn't assume kses's exact output
 * formatting: it matches any anchor open tag and rebuilds it from the href
 * it finds, so it can't be quietly broken by a change in how kses quotes or
 * orders attributes.
 *
 * @param string $html Raw feed-supplied HTML.
 *
 * @return string Sanitized HTML, safe to output without further escaping.
 */
function feedland_rivers_sanitize_description( string $html ): string {
	$allowed = array(
		'p'      => array(),
		'br'     => array(),
		'a'      => array( 'href' => true ),
		'strong' => array(),
		'b'      => array(),
		'em'     => array(),
		'i'      => array(),
	);

	$safe = wp_kses( $html, $allowed );

	$rewritten = preg_replace_callback(
		'#<a\b[^>]*>#i',
		static function ( array $matches ): string {
			if ( ! preg_match( '#\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $matches[0], $href ) ) {
				return '<a>'; // kses dropped the href; nowhere to send anyone.
			}

			$url = $href[1] ?: ( $href[2] ?? '' ) ?: ( $href[3] ?? '' );

			return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">';
		},
		$safe
	);

	// preg_* returns null on a PCRE-level failure; keeping the kses output is
	// safe (it just wouldn't carry target/rel), returning null would not.
	return trim( null !== $rewritten ? $rewritten : (string) $safe );
}

/**
 * Renders a single river item as an HTML fragment.
 *
 * Feed-supplied title/description text is untrusted (it comes from
 * whatever RSS feed the FeedLand user subscribes to). Title is plain text
 * (stripped and escaped); description allows a small set of formatting
 * tags via feedland_rivers_sanitize_description() rather than being
 * escaped outright.
 *
 * All four footer icons FeedLand's own riverviewer.js shows anonymous
 * visitors are shown (same Font Awesome glyphs and .sp*Button class names,
 * scripting.com/code/feedland/home/riverviewer.js), but two are always
 * greyed out rather than active: share opens a "forward to your linkblog"
 * dialog that requires being signed into FeedLand as the account owner to
 * complete, and data opens a client-side debug-JSON dialog -- neither is
 * something a WP site's anonymous visitor can use, and FeedLand itself has
 * no plain URL for either. Doc and enclosure are real links when available
 * (doc always is; enclosure only when the item has one, greyed out
 * otherwise -- same as FeedLand's own disabled treatment).
 *
 * @param array  $item   One item from a feedland_rivers_build_sections() section.
 * @param string $server FeedLand server base URL, for the doc-page link.
 *
 * @return string
 */
function feedland_rivers_render_item( array $item, string $server ): string {
	$title       = trim( wp_strip_all_tags( feedland_rivers_text( $item['title'] ?? '' ) ) );
	$link        = feedland_rivers_text( $item['link'] ?? '' );
	$description = feedland_rivers_sanitize_description( feedland_rivers_text( $item['description'] ?? '' ) );

	$when      = '';
	$timestamp = feedland_rivers_item_time( $item );

	if ( $timestamp ) {
		$when = human_time_diff( $timestamp, time() );
	}

	$item_id       = feedland_rivers_text( $item['id'] ?? '' );
	$enclosure     = is_array( $item['enclosure'] ?? null ) ? $item['enclosure'] : array();
	$doc_url       = '' !== $item_id ? add_query_arg( array( 'item' => $item_id ), $server ) : '';
	$enclosure_url = feedland_rivers_text( $enclosure['url'] ?? '' );

	ob_start();
	?>
	<div class="river-item">
		<?php if ( '' !== $title ) : ?>
			<div class="river-item-title">
				<?php if ( '' !== $link ) : ?>
					<a href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $title ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $title ); ?>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php if ( $description ) : ?><div class="river-item-body river-item-body-clamped"><?php echo $description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-sanitized by feedland_rivers_sanitize_description(). ?></div><?php endif; ?>
		<div class="river-item-footer">
			<?php echo feedland_rivers_render_footer_icon( 'spShareButton', 'share', '', '', __( "Sharing isn't available here.", 'feedland-rivers' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr()/esc_url() above. ?>
			<?php echo feedland_rivers_render_footer_icon( 'spDocButton', 'doc', $doc_url, __( 'View this item on its own page.', 'feedland-rivers' ), __( 'No link available for this item.', 'feedland-rivers' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo feedland_rivers_render_footer_icon( 'spDataButton', 'data', '', '', __( "Technical data view isn't available here.", 'feedland-rivers' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo feedland_rivers_render_footer_icon( 'spEnclosureButton', 'enclosure', $enclosure_url, __( 'Listen to or view the enclosure.', 'feedland-rivers' ), __( 'No enclosure attached to this item.', 'feedland-rivers' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( $when ) : ?><span class="river-item-when"><?php echo esc_html( $when ); ?></span><?php endif; ?>
		</div>
	</div>
	<?php
	return trim( (string) ob_get_clean() );
}

/**
 * Formats a section-header timestamp the way FeedLand's own riverviewer.js
 * does (getFeedPubdateString()): "Today, 3:33 PM" for the current day,
 * otherwise "Aug 26, 3:33 PM".
 *
 * Uses wp_date() rather than date_i18n() deliberately. date_i18n()'s second
 * parameter is documented as "a sum of Unix timestamp and timezone offset in
 * seconds", and when handed an explicit numeric value core takes its
 * "reverse that operation" branch -- gmdate() to wall-clock digits, then
 * re-reads those digits as site-local. Our $timestamp comes from
 * strtotime( $item['pubDate'] ), which is a true UTC epoch, so that branch
 * relabels UTC as local and shifts every header by the site's offset (and
 * flips the $is_today test across midnight). wp_date() takes a real Unix
 * timestamp and renders it in the site timezone, which is what we want.
 * current_time( 'Ymd' ) is correct as-is: for non-timestamp formats core
 * builds a DateTime in wp_timezone() rather than adding an offset.
 *
 * @param int $timestamp Unix timestamp.
 *
 * @return string
 */
function feedland_rivers_format_section_date( int $timestamp ): string {
	$is_today = wp_date( 'Ymd', $timestamp ) === current_time( 'Ymd' );

	if ( $is_today ) {
		/* translators: %s: time, e.g. "3:33 pm" */
		return sprintf( __( 'Today, %s', 'feedland-rivers' ), wp_date( 'g:i A', $timestamp ) );
	}

	return wp_date( 'M j, g:i A', $timestamp );
}

/**
 * Renders one feed's section: the favicon + feed name + pubdate header
 * (styled after .divRiverSection/.divSectionHeader in FeedLand's own
 * riverviewer.css) followed by its items.
 *
 * @param array  $section One section from feedland_rivers_build_sections().
 * @param string $server  FeedLand server base URL, for the feed-info lookup.
 *
 * @return string
 */
function feedland_rivers_render_section( array $section, string $server ): string {
	$feed_info = feedland_rivers_get_feed_info( $server, $section['feedUrl'] );
	$host      = wp_parse_url( $section['feedUrl'], PHP_URL_HOST );

	/**
	 * Filters the favicon URL shown beside a feed's name in the section header.
	 *
	 * Defaults to DuckDuckGo's icon service, which is what makes this the one
	 * remaining third-party origin a visitor's browser contacts. Return an
	 * empty string to drop favicons entirely, or point it at your own proxy.
	 *
	 * @param string $favicon_url The favicon URL, or '' for none.
	 * @param string $host        The feed's hostname.
	 */
	$favicon_url = apply_filters(
		'feedland_rivers_favicon_url',
		$host ? 'https://icons.duckduckgo.com/ip3/' . rawurlencode( $host ) . '.ico' : '',
		(string) $host
	);

	$newest_time = 0;
	foreach ( $section['items'] as $item ) {
		$newest_time = max( $newest_time, feedland_rivers_item_time( $item ) );
	}

	$items_html = '';
	foreach ( $section['items'] as $item ) {
		$items_html .= feedland_rivers_render_item( $item, $server );
	}

	ob_start();
	?>
	<div class="river-section">
		<div class="river-section-header">
			<span class="river-section-name">
				<?php if ( $host && $favicon_url ) : ?><img class="river-section-favicon" src="<?php echo esc_url( $favicon_url ); ?>" width="16" height="16" alt="" loading="lazy"><?php endif; ?>
				<a href="<?php echo esc_url( $feed_info['link'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $feed_info['title'] ); ?></a>
			</span>
			<?php if ( $newest_time ) : ?><span class="river-section-date"><?php echo esc_html( feedland_rivers_format_section_date( $newest_time ) ); ?></span><?php endif; ?>
		</div>
		<?php echo $items_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped fragments in feedland_rivers_render_item(). ?>
	</div>
	<?php
	return trim( (string) ob_get_clean() );
}

/**
 * Fetches the document template used for the embed iframe's srcdoc, cached
 * in a transient. Mirrors how FeedLand's own News Product route fetches
 * config.urlNewsProductSource (feedland.js:441, renderUserNewsproduct()):
 * an admin-configured URL, fetched fresh (we add caching, FeedLand doesn't)
 * and substituted with [%token%] placeholders. Falls back to the plugin's
 * bundled default template -- modeled on FeedLand's own lean
 * riverclient/index.html, without its ~25-script dependency chain -- both
 * when no URL is configured and when a configured URL fails to fetch.
 *
 * @param string $template_url Admin-configured template URL, or ''.
 *
 * @return string
 */
function feedland_rivers_get_template( string $template_url ): string {
	if ( '' === $template_url ) {
		return feedland_rivers_default_template();
	}

	$cache_key = 'feedland_rivers_template_' . md5( $template_url );
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return $cached;
	}

	$request = wp_remote_get( $template_url, array( 'timeout' => 8 ) );

	if ( is_wp_error( $request ) || 200 !== wp_remote_retrieve_response_code( $request ) ) {
		return feedland_rivers_default_template();
	}

	// A document template is a document. If the server tells us it handed
	// back something else -- JSON, an image, an octet-stream -- don't paste
	// it into the page as though it were markup. A server that declares
	// nothing gets the benefit of the doubt.
	$content_type = wp_remote_retrieve_header( $request, 'content-type' );
	$content_type = is_array( $content_type ) ? (string) reset( $content_type ) : (string) $content_type;

	if ( '' !== $content_type && ! preg_match( '#^\s*(?:text/html|application/xhtml\+xml|text/plain)\b#i', $content_type ) ) {
		return feedland_rivers_default_template();
	}

	$template = wp_remote_retrieve_body( $request );

	if ( '' === trim( $template ) ) {
		return feedland_rivers_default_template();
	}

	set_transient( $cache_key, $template, HOUR_IN_SECONDS );

	return $template;
}

/**
 * Reads the bundled default template from disk, once per request.
 *
 * Cached in a static so the common path -- serving a cached remote
 * template instead -- never touches the filesystem.
 *
 * @return string
 */
function feedland_rivers_default_template(): string {
	static $template = null;

	if ( null === $template ) {
		$path     = FEEDLAND_RIVERS_PATH . 'assets/default-template.html';
		$contents = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions -- reading a bundled asset, not site content.
		$template = false === $contents ? '' : $contents;
	}

	return $template;
}

/**
 * Substitutes [%token%] placeholders in a template, matching FeedLand's own
 * template convention (confirmed from scripting.com/code/riverclient/
 * index.html and .../feedland/home/index.html: raw, unescaped, single-pass
 * substitution -- e.g. "flEnableLogin: [%flEnableLogin%]," drops a raw
 * value straight into JS with no escaping). We rely on strtr()'s
 * simultaneous replacement, same practical effect as FeedLand's
 * multipleReplaceAll(): a substituted value can never be re-matched by a
 * later token. Callers are responsible for escaping each value for its own
 * context before it's passed in here, exactly as FeedLand's server code
 * does before calling multipleReplaceAll().
 *
 * @param string $template The template text.
 * @param array  $tokens   Map of '[%token%]' => already-escaped replacement value.
 *
 * @return string
 */
function feedland_rivers_apply_template( string $template, array $tokens ): string {
	return strtr( $template, $tokens );
}

/**
 * The iframe self-sizing script: watches the document's height with
 * ResizeObserver and reports it to the parent page via postMessage. Needed
 * regardless of which template is in use (ours or an admin-configured one),
 * since an arbitrary fetched template won't already have it.
 *
 * @return string
 */
function feedland_rivers_resize_script(): string {
	return '<script>'
		. '(function () {'
		. 'var lastHeight = 0;'
		. 'function report() {'
		. 'var h = document.body.scrollHeight;'
		. 'if (h !== lastHeight) {'
		. 'lastHeight = h;'
		. 'window.parent.postMessage({ feedlandRiversHeight: h }, "*");'
		. '}'
		. '}'
		. 'new ResizeObserver(report).observe(document.body);'
		. 'report();'
		. '})();'
		. '</script>';
}

/**
 * The click-to-expand script for clamped item bodies.
 *
 * FeedLand's own river page has no separate "more" button -- clicking the
 * (visibly truncated) item text itself toggles it. That's kept for mouse
 * users, but it can't be the only way in: a bare div with a click handler is
 * invisible to the keyboard, and giving the div role="button" would be worse,
 * since item descriptions can contain links and nesting interactive content
 * inside a button is both invalid and a keyboard trap. So overflowing items
 * also get a real <button> after the text, which is focusable, announced, and
 * carries aria-expanded.
 *
 * Measurement waits for document.fonts.ready. The script runs at parse time,
 * before the webfont resolves, so measuring scrollHeight/clientHeight
 * without waiting would compare against fallback-font metrics -- an item
 * that only overflows after the font swaps in would never get a handler,
 * and one that only overflowed before the swap would get a useless one.
 * Self-hosting the font (see feedland_rivers_maybe_serve_font()) doesn't
 * avoid this: local fonts still load asynchronously.
 *
 * Expanding changes document.body's height, which the resize script's
 * ResizeObserver already reports, so the iframe grows on its own.
 *
 * @return string
 */
function feedland_rivers_expand_body_script(): string {
	return '<script>'
		. '(function () {'
		. 'var style = document.createElement("style");'
		. 'style.textContent = ".river-item-body-overflowing{position:relative}"'
		. '+ ".river-item-body-clamped.river-item-body-overflowing{cursor:pointer}"'
		. '+ ".river-item-body-clamped.river-item-body-overflowing::after{content:\'\';position:absolute;left:0;right:0;bottom:0;height:3em;pointer-events:none;background:linear-gradient(to bottom,transparent,var(--river-fade,#f5f5f5))}"'
		. '+ ".river-item-more{display:inline-block;margin:2px 0 0;padding:0;border:0;background:none;font:inherit;font-size:13px;color:#1E68A6;cursor:pointer;text-decoration:underline}"'
		. '+ ".river-item-more:focus-visible{outline:2px solid #1E68A6;outline-offset:2px}";'
		. 'document.head.appendChild(style);'
		. 'function ready() {'
		. 'var n = 0;'
		. 'document.querySelectorAll(".river-item-body-clamped").forEach(function (body) {'
		. 'if (body.scrollHeight <= body.clientHeight + 1) return;'
		. 'body.classList.add("river-item-body-overflowing");'
		. 'var id = body.id || ("idRiverBody" + (++n));'
		. 'body.id = id;'
		. 'var btn = document.createElement("button");'
		. 'btn.type = "button";'
		. 'btn.className = "river-item-more";'
		. 'btn.setAttribute("aria-controls", id);'
		. 'btn.setAttribute("aria-expanded", "false");'
		. 'btn.textContent = ' . wp_json_encode( __( 'Show more', 'feedland-rivers' ) ) . ';'
		. 'function toggle() {'
		. 'var open = body.classList.toggle("river-item-body-clamped") === false;'
		. 'btn.setAttribute("aria-expanded", open ? "true" : "false");'
		. 'btn.textContent = open ? ' . wp_json_encode( __( 'Show less', 'feedland-rivers' ) ) . ' : ' . wp_json_encode( __( 'Show more', 'feedland-rivers' ) ) . ';'
		. '}'
		. 'btn.addEventListener("click", toggle);'
		. 'body.addEventListener("click", function (e) {'
		// Don't hijack a click on a link inside the description.
		. 'if (e.target.closest && e.target.closest("a")) return;'
		. 'toggle();'
		. '});'
		. 'body.parentNode.insertBefore(btn, body.nextSibling);'
		. '});'
		. '}'
		. 'if (document.fonts && document.fonts.ready) { document.fonts.ready.then(ready); } else { ready(); }'
		. '})();'
		. '</script>';
}

/**
 * Renders the full standalone HTML document used as the embed iframe's
 * srcdoc: fetches the configured (or bundled default) template, substitutes
 * the admin-authored page-header tokens, injects the server-rendered river
 * content at the template's id="idRiverContent" mount point (the same
 * convention FeedLand's own templates use), and injects the resize script.
 *
 * Only admin-authored values (title/description/image, from settings) ever
 * reach the *template's* substitution -- feed-supplied item/section content
 * is rendered separately, by feedland_rivers_render_item()/render_section()
 * and their own fixed escaping. This holds regardless of which template is
 * active: untrusted feed content (from whatever RSS feeds the FeedLand user
 * subscribes to) only ever reaches markup through this plugin's own fixed,
 * audited escaping code, never through a fetched/admin-supplied template.
 *
 * @param array  $river   River data from feedland_rivers_get_river().
 * @param string $server  FeedLand server base URL, for feed-info lookups.
 * @param array  $options The feedland_rivers_options array.
 *
 * @return string
 */
function feedland_rivers_render_iframe_document( array $river, string $server, array $options ): string {
	$sections = feedland_rivers_build_sections( $river, feedland_rivers_max_items() );

	$sections_html = '';
	foreach ( $sections as $section ) {
		$sections_html .= feedland_rivers_render_section( $section, $server );
	}

	if ( '' === $sections_html ) {
		$sections_html = '<p class="river-empty">' . esc_html__( 'No news items to show yet.', 'feedland-rivers' ) . '</p>';
	}

	$template = feedland_rivers_get_template( trim( $options['feedland_rivers_template_url'] ?? '' ) );

	$image = trim( $options['feedland_rivers_image'] ?? '' );

	$template = feedland_rivers_apply_template(
		$template,
		array(
			'[%pageTitle%]'       => esc_html( trim( $options['feedland_rivers_title'] ?? '' ) ),
			'[%pageDescription%]' => esc_html( trim( $options['feedland_rivers_description'] ?? '' ) ),
			'[%pageImage%]'       => $image ? '<img src="' . esc_url( $image ) . '" alt="">' : '',
			'[%fontFaceCss%]'     => feedland_rivers_font_face_css(),
		)
	);

	// Inject the river content right after the mount point's opening tag
	// closes. preg_replace_callback (not preg_replace) so nothing in
	// $sections_html can be misread as a backreference. preg_* returns null
	// on a PCRE-level failure, so fall back to the un-injected template
	// rather than propagating null.
	//
	// The pattern accepts every spelling of the attribute HTML allows --
	// single or double quotes, no quotes at all, whitespace around the "="
	// -- because missing it is silent and total: the header renders and the
	// river simply isn't there. The lookbehind for whitespace keeps it from
	// matching a "data-id" (or any other *-id) attribute, and the value is
	// anchored so "idRiverContentSomething" doesn't count.
	$count    = 0;
	$injected = preg_replace_callback(
		'/(?<=\s)id\s*=\s*(?:(["\'])idRiverContent\1|idRiverContent(?=[\s>]))[^>]*>/i',
		static function ( array $matches ) use ( $sections_html ) {
			return $matches[0] . $sections_html;
		},
		$template,
		1,
		$count
	);

	if ( null !== $injected && $count > 0 ) {
		$template = $injected;
		feedland_rivers_clear_template_warning();
	} else {
		// No mount point (or a PCRE failure). Rather than serve a template
		// with no river in it, put the content at the end of the body and
		// leave a note for whoever configured the template.
		$template = feedland_rivers_append_to_body( $template, $sections_html );
		feedland_rivers_flag_template_warning( trim( $options['feedland_rivers_template_url'] ?? '' ) );
	}

	$scripts  = feedland_rivers_resize_script() . feedland_rivers_expand_body_script();
	$template = feedland_rivers_append_to_body( $template, $scripts );

	return $template;
}

/**
 * Appends a fragment just before a document's last </body>, or at the very
 * end when the template has no </body> to anchor to.
 *
 * Anchored to the last occurrence, and inserted exactly once: str_ireplace()
 * would replace *every* match, so a template that mentions the string a
 * second time (a code sample, an escaped example, a comment) would get two
 * copies of whatever's being appended -- two ResizeObservers and a
 * duplicate "Show more" button per item, or on the no-mount-point path, the
 * whole river twice.
 *
 * @param string $document The document text.
 * @param string $fragment The fragment to append.
 *
 * @return string
 */
function feedland_rivers_append_to_body( string $document, string $fragment ): string {
	$position = strripos( $document, '</body>' );

	if ( false === $position ) {
		return $document . $fragment;
	}

	return substr_replace( $document, $fragment, $position, 0 );
}

/**
 * Records that the configured template had no id="idRiverContent" mount
 * point, so feedland_rivers_admin_notices() can tell the admin. Stored
 * rather than displayed inline because this is discovered while rendering
 * the front end, where the person who can fix it usually isn't looking.
 *
 * @param string $template_url The configured template URL, or '' for the bundled one.
 *
 * @return void
 */
function feedland_rivers_flag_template_warning( string $template_url ): void {
	set_transient( 'feedland_rivers_template_warning', $template_url, DAY_IN_SECONDS );
}

/**
 * Clears the mount-point warning once a template injects cleanly again.
 *
 * @return void
 */
function feedland_rivers_clear_template_warning(): void {
	if ( false !== get_transient( 'feedland_rivers_template_warning' ) ) {
		delete_transient( 'feedland_rivers_template_warning' );
	}
}

<?php
/**
 * The public REST endpoint the parent-page poll script calls to check for
 * and fetch fresh river content, letting an already-rendered iframe update
 * in place without a full page reload.
 *
 * @package river-embed-for-feedland
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

add_action( 'rest_api_init', 'feedland_rivers_register_rest_routes' );

/**
 * Registers the public, read-only endpoint the parent page's poll script
 * (feedland_rivers_poll_listener_js()) calls to check for and fetch
 * fresh river content. Deliberately open to anonymous requests, same as the
 * river itself already is via the shortcode -- but unlike the shortcode,
 * server/username/category arrive here as plain request params anyone could
 * set to anything, so feedland_rivers_rest_get_river() additionally requires
 * a token proving this exact triple was actually rendered by this site (see
 * feedland_rivers_river_token()) before it does anything with them.
 * That's what keeps this matching every shortcode variant (the point of
 * accepting server/username/category from the client at all) without also
 * becoming an open "fetch any URL this site's server can reach" endpoint for
 * combinations nothing on the site ever rendered.
 *
 * @return void
 */
function feedland_rivers_register_rest_routes(): void {
	register_rest_route(
		'feedland-rivers/v1',
		'/river',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'feedland_rivers_rest_get_river',
			'permission_callback' => '__return_true',
			'args'                => array(
				'server'   => array(
					'type'    => 'string',
					'default' => '',
				),
				'username' => array(
					'type'    => 'string',
					'default' => '',
				),
				'category' => array(
					'type'    => 'string',
					'default' => '',
				),
				'token'    => array(
					'type'    => 'string',
					'default' => '',
				),
			),
		)
	);
}

/**
 * Re-renders a river's srcdoc for the poll script and reports whether it
 * changed since the page was last rendered/polled.
 *
 * Returns the *raw* srcdoc (not the htmlspecialchars()-encoded variant the
 * shortcode embeds as an HTML attribute) -- the client assigns it directly to
 * an iframe's .srcdoc IDL property, which needs no such encoding, and double
 * -encoding it here would just show escaped entities as literal text.
 *
 * @param WP_REST_Request $request The REST request.
 *
 * @return WP_REST_Response|WP_Error
 */
function feedland_rivers_rest_get_river( WP_REST_Request $request ) {
	$options = get_option( 'feedland_rivers_options' );

	$resolved = feedland_rivers_resolve_atts(
		array(
			'server'   => $request->get_param( 'server' ),
			'username' => $request->get_param( 'username' ),
			'category' => $request->get_param( 'category' ),
		),
		$options
	);

	// Checked against the *resolved* triple, matching exactly what
	// feedland_rivers_shortcode() created a token for at render time -- an
	// unresolved override that happens to resolve to the same values as
	// another already-rendered river gets the same token, which is fine,
	// it's still a combination this site actually shows. Checked before any
	// fetch happens, not just before the response is built. hash_equals(),
	// not ===: this is a secret-derived token comparison, not incidental
	// string equality, so it needs to run in constant time regardless of
	// where the strings first differ.
	if ( ! hash_equals( feedland_rivers_river_token( $resolved['server'], $resolved['username'], $resolved['category'] ), (string) $request->get_param( 'token' ) ) ) {
		return new WP_Error(
			'feedland_rivers_invalid_token',
			__( 'This river was not rendered by this site.', 'river-embed-for-feedland' ),
			array( 'status' => 403 )
		);
	}

	$srcdoc = feedland_rivers_render_srcdoc( $resolved['server'], $resolved['username'], $resolved['category'], $options );

	if ( false === $srcdoc ) {
		return new WP_Error(
			'feedland_rivers_river_unavailable',
			__( 'Unable to load the river right now.', 'river-embed-for-feedland' ),
			array( 'status' => 502 )
		);
	}

	// Refreshed on every poll, same as $srcdoc -- the client carries it onto
	// the replacement iframe's dataset (see swap() in
	// feedland_rivers_poll_listener_js()) so the WebSocket filter keeps
	// watching the right feed set as it drifts. A failed fetch here doesn't
	// fail the whole poll -- an empty list just means the WebSocket trigger
	// sits idle until the next successful poll re-populates it; the interval
	// poll itself is unaffected.
	$feed_urls = feedland_rivers_get_category_feed_urls( $resolved['server'], $resolved['username'], $resolved['category'] );

	$response = rest_ensure_response(
		array(
			'srcdoc'   => $srcdoc,
			'hash'     => md5( $srcdoc ),
			'feedUrls' => false === $feed_urls ? array() : $feed_urls,
		)
	);

	// Lets any HTTP cache/CDN in front of WP collapse repeated identical-
	// params polls (e.g. many visitors' tabs polling the same popular river)
	// to one origin hit. Doesn't touch the accepted tradeoff above: it does
	// nothing for a request that varies server/username/category every time.
	$response->header( 'Cache-Control', 'public, max-age=60' );

	return $response;
}

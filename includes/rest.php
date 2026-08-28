<?php
/**
 * The public REST endpoint the parent-page poll script calls to check for
 * and fetch fresh river content, letting an already-rendered iframe update
 * in place without a full page reload.
 *
 * @package feedland-rivers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

add_action( 'rest_api_init', 'feedland_rivers_register_rest_routes' );

/**
 * Registers the public, read-only endpoint the parent page's poll script
 * (feedland_rivers_poll_listener_script()) calls to check for and fetch
 * fresh river content. Deliberately open to anonymous requests, same as the
 * river itself already is via the shortcode -- and, since server/username/
 * category are accepted from the client exactly like the shortcode's own
 * attributes, this makes the same outbound-FeedLand-fetch capability a page
 * author already has (via [feedland-rivers server="..."]) reachable by any
 * visitor rather than only whoever can edit page content. That's an accepted
 * tradeoff for matching every shortcode variant, not an oversight.
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

	$srcdoc = feedland_rivers_render_srcdoc( $resolved['server'], $resolved['username'], $resolved['category'], $options );

	if ( false === $srcdoc ) {
		return new WP_Error(
			'feedland_rivers_river_unavailable',
			__( 'Unable to load the river right now.', 'feedland-rivers' ),
			array( 'status' => 502 )
		);
	}

	$response = rest_ensure_response(
		array(
			'srcdoc' => $srcdoc,
			'hash'   => md5( $srcdoc ),
		)
	);

	// Lets any HTTP cache/CDN in front of WP collapse repeated identical-
	// params polls (e.g. many visitors' tabs polling the same popular river)
	// to one origin hit. Doesn't touch the accepted tradeoff above: it does
	// nothing for a request that varies server/username/category every time.
	$response->header( 'Cache-Control', 'public, max-age=60' );

	return $response;
}

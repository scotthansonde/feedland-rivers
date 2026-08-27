<?php
/**
 * Plugin Name:       FeedLand Rivers
 * Description:       Show a FeedLand river on your site.
 * Requires at least: 6.1
 * Tested up to:      7.1
 * Requires PHP:      7.4
 * Version:           0.2.1
 * Author:            Scott Hanson
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       feedland-rivers
 * Domain Path:       /languages
 *
 * @package           feedland-rivers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( defined( 'FEEDLAND_RIVERS_PATH' ) ) {
	return; // Return if another copy of the plugin is activated.
}

define( 'FEEDLAND_RIVERS_PATH', plugin_dir_path( __FILE__ ) );

define( 'FEEDLAND_RIVERS_DEFAULT_SERVER', 'https://feedland.com/' );
define( 'FEEDLAND_RIVERS_DEFAULT_USERNAME', '' );
define( 'FEEDLAND_RIVERS_DEFAULT_CATEGORY', '' );
define( 'FEEDLAND_RIVERS_DEFAULT_TITLE', '' );
define( 'FEEDLAND_RIVERS_DEFAULT_DESCRIPTION', '' );
define( 'FEEDLAND_RIVERS_DEFAULT_IMAGE', '' );
define( 'FEEDLAND_RIVERS_DEFAULT_TEMPLATE_URL', '' );
define( 'FEEDLAND_RIVERS_MAX_ITEMS', 20 );
define( 'FEEDLAND_RIVERS_CACHE_TTL', 10 * MINUTE_IN_SECONDS );
define( 'FEEDLAND_RIVERS_ERROR_CACHE_TTL', 2 * MINUTE_IN_SECONDS );

require_once 'includes/settings.php';
require_once 'includes/render.php';

add_shortcode( 'feedland-rivers', 'feedland_rivers_shortcode' );
add_action( 'admin_menu', 'feedland_rivers_add_admin_menu' );
add_action( 'admin_init', 'feedland_rivers_settings_init' );
register_activation_hook( __FILE__, 'feedland_rivers_default_options' );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'feedland_rivers_add_action_links' );
add_action( 'admin_notices', 'feedland_rivers_admin_notices' );
add_action( 'init', 'feedland_rivers_maybe_serve_font' );
add_action( 'init', 'feedland_rivers_load_textdomain' );

/**
 * Outputs the river: fetches the river JSON server-side (cached), renders
 * it with our own template, and embeds the result via an `iframe srcdoc`
 * -- fully isolated from the site's theme CSS/JS, with no dependency on
 * FeedLand's own front-end scripts. The iframe carries a small
 * ResizeObserver that reports its content height back to this page via
 * postMessage so it can size itself instead of using a fixed height.
 *
 * The `username`, `category` and `server` shortcode attributes override the
 * corresponding setting, so a site can embed several different rivers on
 * different pages -- e.g. [feedland-rivers username="alice" category="tech"]
 * alongside [feedland-rivers username="bob"] -- while the settings page
 * keeps its role as the default for a bare [feedland-rivers]. An invalid
 * server override is silently ignored in favour of the configured one,
 * same as an invalid Server Settings field falls back rather than breaking
 * the page; an invalid username is left to fail the same way a bad
 * Settings-page username already does, since both go through the same
 * fetch below.
 *
 * @param array|string $atts Shortcode attributes.
 *
 * @return string
 */
function feedland_rivers_shortcode( $atts ): string {
	$options = get_option( 'feedland_rivers_options' );

	$atts = shortcode_atts(
		array(
			'username' => '',
			'category' => '',
			'server'   => '',
		),
		$atts,
		'feedland-rivers'
	);

	$server = trim( $atts['server'] );
	$server = '' !== $server ? feedland_rivers_clean_url( $server ) : '';

	if ( '' === $server ) {
		$server = $options['feedland_rivers_server'] ?? FEEDLAND_RIVERS_DEFAULT_SERVER;
	}

	$server = trailingslashit( $server );

	$username = trim( $atts['username'] );
	$username = '' !== $username ? sanitize_text_field( $username ) : trim( $options['feedland_rivers_username'] ?? '' );

	$category = trim( $atts['category'] );
	$category = '' !== $category ? sanitize_text_field( $category ) : trim( $options['feedland_rivers_category'] ?? '' );

	if ( '' === $username ) {
		return '';
	}

	$river = feedland_rivers_get_river( $server, $username, $category, feedland_rivers_max_items() );

	if ( false === $river ) {
		return '<p class="feedlandRiversError">' . esc_html__( 'Unable to load the river right now.', 'feedland-rivers' ) . '</p>';
	}

	$srcdoc    = feedland_rivers_render_iframe_document( $river, $server, $options );
	$iframe_id = 'idFeedlandRivers' . wp_unique_id();

	$listener = feedland_rivers_resize_listener_script();

	// allow-popups (+ allow-popups-to-escape-sandbox, so the opened tab
	// isn't itself sandboxed) is required for the item/doc/enclosure links'
	// target="_blank" to do anything at all -- without it the sandbox
	// silently blocks every popup, so every link in the river was a dead
	// click. Doesn't loosen the iframe's own isolation: allow-same-origin
	// is still omitted, so the river document itself stays opaque-origin.
	//
	// The inline height is a starting *default*, not a floor: an iframe with
	// no height at all is zero pixels tall, so until the first postMessage
	// arrives -- or forever, if scripts are blocked, ResizeObserver is
	// missing, or a script-src CSP drops the listener below -- the river was
	// an invisible empty box. min-height would be the wrong tool here, since
	// it can't be shrunk back below by the resize listener; a plain height
	// is simply overwritten by it once the real content height is known.
	// scrolling is deliberately left at its default rather than "no" for the
	// same reason: if nothing ever resizes this, the visitor can still reach
	// content past the default height instead of having it silently clipped.
	// Once the listener has sized the frame there's no overflow to scroll.
	// esc_attr() alone is not enough here: it defaults to $double_encode =
	// false, so a pre-existing entity already in $srcdoc (e.g. the literal
	// "&lt;img...&gt;" that feedland_rivers_sanitize_description() correctly
	// leaves as inert text for a feed demonstrating markup) survives esc_attr()
	// untouched. The browser then does its one normal decode pass on the
	// srcdoc attribute value and fully unwraps that entity back into a real,
	// live tag before handing the document to the iframe's own parser --
	// silently defeating every tag the sanitizer stripped or neutralized.
	// Forcing double_encode re-escapes that leading "&", so the one browser
	// decode lands back on inert entity text instead of a live tag.
	//
	// wp_check_invalid_utf8() first, matching what esc_attr() does
	// internally: htmlspecialchars() silently returns '' on malformed
	// multi-byte input, which would blank the entire river rather than just
	// the offending fragment.
	$srcdoc_attr = htmlspecialchars( wp_check_invalid_utf8( $srcdoc ), ENT_QUOTES, 'UTF-8', true );

	return '<iframe id="' . esc_attr( $iframe_id ) . '" class="feedlandRiversIframe" title="' . esc_attr__( 'FeedLand river', 'feedland-rivers' ) . '" sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox" style="width:100%;height:600px;border:0;display:block;" srcdoc="' . $srcdoc_attr . '"></iframe>'
		. $listener;
}

/**
 * Prints the parent-page listener that resizes river iframes based on the
 * postMessage sent by feedland_rivers_render_iframe_document(). Only
 * printed once per page even if the shortcode is used multiple times.
 *
 * @return string
 */
function feedland_rivers_resize_listener_script(): string {
	static $printed = false;

	if ( $printed ) {
		return '';
	}
	$printed = true;

	return '<script>window.addEventListener("message",function(e){if(!e.data||typeof e.data.feedlandRiversHeight!=="number")return;document.querySelectorAll(".feedlandRiversIframe").forEach(function(f){if(f.contentWindow===e.source){f.style.height=e.data.feedlandRiversHeight+"px";}});});</script>';
}

/**
 * Sets default options for the plugin upon activation.
 */
function feedland_rivers_default_options(): void {
	$defaults = array(
		'feedland_rivers_title'        => FEEDLAND_RIVERS_DEFAULT_TITLE,
		'feedland_rivers_category'     => FEEDLAND_RIVERS_DEFAULT_CATEGORY,
		'feedland_rivers_server'       => FEEDLAND_RIVERS_DEFAULT_SERVER,
		'feedland_rivers_username'     => FEEDLAND_RIVERS_DEFAULT_USERNAME,
		'feedland_rivers_description'  => FEEDLAND_RIVERS_DEFAULT_DESCRIPTION,
		'feedland_rivers_image'        => FEEDLAND_RIVERS_DEFAULT_IMAGE,
		'feedland_rivers_template_url' => FEEDLAND_RIVERS_DEFAULT_TEMPLATE_URL,
	);

	$options = get_option( 'feedland_rivers_options' );

	if ( false === $options ) {
		update_option( 'feedland_rivers_options', $defaults );
	} else {
		$options = wp_parse_args( $options, $defaults );
		update_option( 'feedland_rivers_options', $options );
	}
}

/**
 * Adds a settings link to the plugin action links on the plugins page.
 *
 * @param array $links An array of plugin action links.
 *
 * @return array An array of plugin action links with the new "Settings" link.
 */
function feedland_rivers_add_action_links( array $links ): array {
	$settings_slug = 'feedland_rivers_settings';
	$settings_link = '<a href="' . esc_url( get_admin_url( null, 'options-general.php?page=' . $settings_slug ) ) . '">' . esc_html__( 'Settings', 'feedland-rivers' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}

/**
 * Warns an administrator when the document template has no
 * id="idRiverContent" mount point for the river to be injected into.
 *
 * This is detected while rendering the front end, where nobody who can fix
 * it is likely to be looking, so the render records a transient and we
 * surface it in wp-admin instead. The river still appears -- it's appended
 * to the end of the body as a fallback -- but probably not where the
 * template intended it, which is worth saying out loud rather than leaving
 * someone to wonder why their layout looks wrong.
 *
 * @return void
 */
function feedland_rivers_admin_notices(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$template_url = get_transient( 'feedland_rivers_template_warning' );

	if ( false === $template_url ) {
		return;
	}

	$source = '' === $template_url
		? __( 'the built-in template', 'feedland-rivers' )
		/* translators: %s: the configured template URL. */
		: sprintf( __( 'the template at %s', 'feedland-rivers' ), '<code>' . esc_html( $template_url ) . '</code>' );

	printf(
		'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
		esc_html__( 'FeedLand Rivers:', 'feedland-rivers' ),
		wp_kses(
			sprintf(
				/* translators: %1$s: description of which template, %2$s: the required id attribute. */
				__( 'No mount point was found in %1$s, so the river was appended to the end of the page instead of where the template expects it. Add an element with %2$s to the template.', 'feedland-rivers' ),
				$source,
				'<code>id="idRiverContent"</code>'
			),
			array( 'code' => array() )
		)
	);
}

/**
 * Loads translations.
 *
 * Required for the __()/esc_html__() calls throughout the plugin to
 * actually translate when installed outside the wordpress.org directory,
 * which loads translations for hosted plugins itself.
 *
 * @return void
 */
function feedland_rivers_load_textdomain(): void {
	load_plugin_textdomain( 'feedland-rivers', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

/**
 * The maximum number of items to show across all sections.
 *
 * @return int
 */
function feedland_rivers_max_items(): int {
	/**
	 * Filters the total item cap for a river.
	 *
	 * @param int $max_items Default FEEDLAND_RIVERS_MAX_ITEMS.
	 */
	return max( 1, (int) apply_filters( 'feedland_rivers_max_items', FEEDLAND_RIVERS_MAX_ITEMS ) );
}

/**
 * How long a successfully fetched river stays cached.
 *
 * @return int Seconds.
 */
function feedland_rivers_cache_ttl(): int {
	/**
	 * Filters the river cache lifetime.
	 *
	 * @param int $ttl Default FEEDLAND_RIVERS_CACHE_TTL, in seconds.
	 */
	return max( 0, (int) apply_filters( 'feedland_rivers_cache_ttl', FEEDLAND_RIVERS_CACHE_TTL ) );
}

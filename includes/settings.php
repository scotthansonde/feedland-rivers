<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Add menu item and page for the FeedLand Rivers settings
 *
 * @return void
 */
function feedland_rivers_add_admin_menu(): void {
	add_options_page(
		__( 'FeedLand Rivers Settings', 'feedland-rivers' ),
		__( 'FeedLand Rivers', 'feedland-rivers' ),
		'manage_options',
		'feedland_rivers_settings',
		'feedland_rivers_settings_page'
	);
}

/**
 * Display settings page content
 *
 * @return void
 */
function feedland_rivers_settings_page(): void {
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<form action="options.php" method="POST">
			<?php
			settings_fields( 'feedland_rivers_settings' );
			do_settings_sections( 'feedland_rivers_settings' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

/**
 * Renders settings page on admin_init
 *
 * @return void
 */
function feedland_rivers_settings_init(): void {
	register_setting(
		'feedland_rivers_settings',
		'feedland_rivers_options',
		array(
			'sanitize_callback' => 'feedland_rivers_validate_options',
		)
	);

	add_settings_section(
		'feedland_rivers_settings_section',
		__( 'FeedLand Rivers Settings', 'feedland-rivers' ),
		'feedland_rivers_settings_section_callback',
		'feedland_rivers_settings'
	);

	add_settings_field(
		'feedland_rivers_title',
		__( 'Title', 'feedland-rivers' ),
		'feedland_rivers_settings_field_callback',
		'feedland_rivers_settings',
		'feedland_rivers_settings_section',
		array(
			'label_for'   => 'feedland_rivers_title',
			'type'        => 'text',
			'name'        => 'feedland_rivers_title',
			'class'       => 'regular-text',
			'description' => esc_html__( 'The heading shown above the river. Leave blank for no heading.', 'feedland-rivers' ),
			// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- documentation, not code.
			// Rendered inside the template via [%pageTitle%] (the built-in template shows it), not as a separate element outside the iframe.
		)
	);

	add_settings_field(
		'feedland_rivers_username',
		__( 'FeedLand username', 'feedland-rivers' ),
		'feedland_rivers_settings_field_callback',
		'feedland_rivers_settings',
		'feedland_rivers_settings_section',
		array(
			'label_for'   => 'feedland_rivers_username',
			'type'        => 'text',
			'name'        => 'feedland_rivers_username',
			'class'       => 'regular-text',
			'description' => esc_html__( 'The username of the FeedLand account whose river you want shown. (Required)', 'feedland-rivers' ),
		)
	);

	add_settings_field(
		'feedland_rivers_server',
		__( 'FeedLand server', 'feedland-rivers' ),
		'feedland_rivers_settings_field_callback',
		'feedland_rivers_settings',
		'feedland_rivers_settings_section',
		array(
			'label_for'   => 'feedland_rivers_server',
			'type'        => 'url',
			'name'        => 'feedland_rivers_server',
			'class'       => 'regular-text',
			'placeholder' => FEEDLAND_RIVERS_DEFAULT_SERVER,
			'description' => esc_html__( 'The server that account is on. (Defaults to feedland.com, required)', 'feedland-rivers' ),
		)
	);

	add_settings_field(
		'feedland_rivers_category',
		__( 'Category (optional)', 'feedland-rivers' ),
		'feedland_rivers_settings_field_callback',
		'feedland_rivers_settings',
		'feedland_rivers_settings_section',
		array(
			'label_for'   => 'feedland_rivers_category',
			'type'        => 'text',
			'name'        => 'feedland_rivers_category',
			'class'       => 'regular-text',
			'description' => esc_html__( 'Show news only from feeds in this category. Leave blank to show news from everything the user subscribes to.', 'feedland-rivers' ),
		)
	);

	add_settings_field(
		'feedland_rivers_description',
		__( 'Description (optional)', 'feedland-rivers' ),
		'feedland_rivers_settings_field_callback',
		'feedland_rivers_settings',
		'feedland_rivers_settings_section',
		array(
			'label_for'   => 'feedland_rivers_description',
			'type'        => 'text',
			'name'        => 'feedland_rivers_description',
			'class'       => 'regular-text',
			'description' => esc_html__( 'Used if your template shows a description (the built-in template does not).', 'feedland-rivers' ),
		)
	);

	add_settings_field(
		'feedland_rivers_image',
		__( 'Image URL (optional)', 'feedland-rivers' ),
		'feedland_rivers_settings_field_callback',
		'feedland_rivers_settings',
		'feedland_rivers_settings_section',
		array(
			'label_for'   => 'feedland_rivers_image',
			'type'        => 'url',
			'name'        => 'feedland_rivers_image',
			'class'       => 'regular-text',
			'description' => esc_html__( 'Used if your template shows an image (the built-in template does not).', 'feedland-rivers' ),
		)
	);

	add_settings_field(
		'feedland_rivers_template_url',
		__( 'Template URL (optional)', 'feedland-rivers' ),
		'feedland_rivers_settings_field_callback',
		'feedland_rivers_settings',
		'feedland_rivers_settings_section',
		array(
			'label_for'   => 'feedland_rivers_template_url',
			'type'        => 'url',
			'name'        => 'feedland_rivers_template_url',
			'class'       => 'regular-text',
			'description' => esc_html__( 'The HTML template used for the river page, fetched and filled in the same way FeedLand fills in its own News Product templates. Leave blank to use the built-in template.', 'feedland-rivers' ),
		)
	);
}

/**
 * Settings section callback, can optionally add descriptions here
 *
 * @return void
 */
function feedland_rivers_settings_section_callback(): void {
	echo '<p>' . esc_html__( 'Customize the FeedLand Rivers settings.', 'feedland-rivers' ) . '</p>';
}

/**
 * Settings field callback
 *
 * @param array $args Arguments passed from the settings field
 *
 * @return void
 */
function feedland_rivers_settings_field_callback( array $args ): void {
	$options = get_option( 'feedland_rivers_options' );

	$value = $options[ $args['name'] ] ?? '';

	switch ( $args['type'] ) {
		case 'text':
		case 'url':
			printf(
				'<input type="%1$s" id="%2$s" name="feedland_rivers_options[%2$s]" value="%3$s" class="%4$s" placeholder="%5$s" />',
				esc_attr( $args['type'] ),
				esc_attr( $args['name'] ),
				esc_attr( $value ),
				esc_attr( $args['class'] ),
				esc_attr( $args['placeholder'] ?? '' )
			);
			break;
	}

	if ( ! empty( $args['description'] ) ) {
		printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
	}
}

/**
 * Validates a URL: it must be a syntactically valid http(s) URL.
 *
 * filter_var( FILTER_VALIDATE_URL ) on its own isn't enough -- it happily
 * accepts javascript://x%0aalert(1), feed://example.com and other schemes
 * we have no business fetching or emitting. esc_url_raw() would reduce a
 * disallowed protocol to an empty string, which then becomes a silently
 * useless setting rather than a reported one (an empty server URL leaves
 * trailingslashit() returning "/"), so the scheme is checked explicitly and
 * the result re-checked for emptiness.
 *
 * Deliberately does *not* reject loopback or private addresses. Pointing
 * this plugin at a self-hosted FeedLand on localhost or a LAN address is a
 * supported setup, so wp_http_validate_url()/wp_safe_remote_get() would do
 * more harm than good here.
 *
 * @param string $url The submitted URL.
 *
 * @return string The cleaned URL, or '' if it isn't usable.
 */
function feedland_rivers_clean_url( string $url ): string {
	$url = trim( $url );

	if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
		return '';
	}

	$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return '';
	}

	return esc_url_raw( $url );
}

/**
 * Validate options before saving.
 *
 * Two principles, applied consistently to every field:
 *
 * 1. Malformed input is rejected, and the field falls back to whatever was
 *    already saved before falling back to the plugin default -- a typo in
 *    one field shouldn't wipe a working configuration.
 * 2. Input we merely couldn't *verify* is kept, with a warning -- a momentary
 *    network blip while checking the username must not reset the server URL
 *    to feedland.com and blank the username, silently discarding a perfectly
 *    good self-hosted configuration the admin just typed.
 *
 * Note that this runs on any update_option() of feedland_rivers_options once
 * register_setting() has been called for the request, not just on a settings
 * POST, hence the defensive handling of a partial or non-array $input.
 *
 * @param mixed $input Options to validate.
 *
 * @return array Validated options.
 */
function feedland_rivers_validate_options( $input ): array {
	$input    = is_array( $input ) ? $input : array();
	$existing = get_option( 'feedland_rivers_options' );
	$existing = is_array( $existing ) ? $existing : array();

	$group = 'feedland_rivers_settings';

	/**
	 * The previously-saved value for a key, or the plugin default.
	 *
	 * @param string $key     Option key.
	 * @param string $default Plugin default.
	 *
	 * @return string
	 */
	$keep = static function ( string $key, string $default ) use ( $existing ): string {
		$value = $existing[ $key ] ?? $default;

		return is_string( $value ) ? $value : $default;
	};

	$submitted = static function ( string $key ) use ( $input ): string {
		$value = $input[ $key ] ?? '';

		return is_string( $value ) ? trim( $value ) : '';
	};

	// Server URL. Blank means "use the default"; malformed is an error.
	$server = feedland_rivers_clean_url( $submitted( 'feedland_rivers_server' ) );

	if ( '' === $server ) {
		if ( '' === $submitted( 'feedland_rivers_server' ) ) {
			$server = FEEDLAND_RIVERS_DEFAULT_SERVER;
		} else {
			add_settings_error(
				$group,
				'feedland_rivers_server',
				esc_html__( 'The FeedLand server URL is not valid — it must be an http:// or https:// address.', 'feedland-rivers' )
			);
			$server = $keep( 'feedland_rivers_server', FEEDLAND_RIVERS_DEFAULT_SERVER );
		}
	}

	$input['feedland_rivers_server'] = $server;

	// Username.
	$username = trim( sanitize_text_field( $submitted( 'feedland_rivers_username' ) ) );

	if ( '' === $username ) {
		add_settings_error(
			$group,
			'feedland_rivers_username',
			esc_html__( 'The username cannot be empty.', 'feedland-rivers' )
		);
		$username = $keep( 'feedland_rivers_username', FEEDLAND_RIVERS_DEFAULT_USERNAME );
	}

	$input['feedland_rivers_username'] = $username;

	// Confirm the username exists on the server -- but only when there's
	// actually a username to check, only when it (or the server) has changed
	// since the last save, and never at the cost of the admin's other
	// settings if the server can't be reached. Re-verifying an unchanged
	// username on every save meant editing an unrelated field like the Title
	// cost two blocking round-trips to FeedLand for no information.
	$server_changed   = $server !== ( $existing['feedland_rivers_server'] ?? '' );
	$username_changed = $username !== ( $existing['feedland_rivers_username'] ?? '' );

	if ( '' !== $username && ( $username_changed || $server_changed ) ) {
		$request = wp_remote_get(
			add_query_arg(
				array( 'screenname' => $username ),
				trailingslashit( $server ) . 'isuserindatabase'
			),
			array( 'timeout' => 5 )
		);

		if ( is_wp_error( $request ) ) {
			add_settings_error(
				$group,
				'feedland_rivers_server',
				esc_html__( 'Could not reach the FeedLand server to verify the username, so it was saved as entered.', 'feedland-rivers' ),
				'warning'
			);
		} else {
			$response = json_decode( wp_remote_retrieve_body( $request ), true );

			if ( ! is_array( $response ) ) {
				add_settings_error(
					$group,
					'feedland_rivers_server',
					esc_html__( 'The FeedLand server did not return a usable response when verifying the username, so it was saved as entered.', 'feedland-rivers' ),
					'warning'
				);
			} elseif ( empty( $response['flInDatabase'] ) ) {
				add_settings_error(
					$group,
					'feedland_rivers_username',
					esc_html__( 'The username provided is not associated with a FeedLand account.', 'feedland-rivers' )
				);
				$input['feedland_rivers_username'] = $keep( 'feedland_rivers_username', FEEDLAND_RIVERS_DEFAULT_USERNAME );
			}
		}
	}

	// Category, now that username and server are settled.
	$category = trim( sanitize_text_field( $submitted( 'feedland_rivers_category' ) ) );

	$category_changed = $category !== ( $existing['feedland_rivers_category'] ?? '' );

	if ( '' !== $category && '' !== $input['feedland_rivers_username'] && ( $category_changed || $username_changed || $server_changed ) ) {
		$request = wp_remote_get(
			add_query_arg(
				array(
					'screenname' => $input['feedland_rivers_username'],
					'catname'    => $category,
				),
				trailingslashit( $server ) . 'getriverfromcategory'
			),
			array( 'timeout' => 5 )
		);

		if ( is_wp_error( $request ) ) {
			add_settings_error(
				$group,
				'feedland_rivers_category',
				esc_html__( 'Could not reach the FeedLand server to verify the category, so it was saved as entered.', 'feedland-rivers' ),
				'warning'
			);
		} else {
			$response = json_decode( wp_remote_retrieve_body( $request ), true );

			// A "message" key means the server couldn't find that category for that user.
			if ( is_array( $response ) && array_key_exists( 'message', $response ) ) {
				add_settings_error(
					$group,
					'feedland_rivers_category',
					esc_html__( 'That category was not found for this user.', 'feedland-rivers' )
				);
				$category = $keep( 'feedland_rivers_category', FEEDLAND_RIVERS_DEFAULT_CATEGORY );
			}
		}
	}

	$input['feedland_rivers_category'] = $category;

	// Title and description are free text.
	$input['feedland_rivers_title']       = sanitize_text_field( $submitted( 'feedland_rivers_title' ) );
	$input['feedland_rivers_description'] = sanitize_text_field( $submitted( 'feedland_rivers_description' ) );

	// Image URL and template URL. Both optional; blank clears them.
	foreach ( array(
		'feedland_rivers_image'        => esc_html__( 'The image URL is not valid — it must be an http:// or https:// address.', 'feedland-rivers' ),
		'feedland_rivers_template_url' => esc_html__( 'The template URL is not valid — it must be an http:// or https:// address.', 'feedland-rivers' ),
	) as $key => $message ) {
		$clean = feedland_rivers_clean_url( $submitted( $key ) );

		if ( '' === $clean && '' !== $submitted( $key ) ) {
			add_settings_error( $group, $key, $message );
			$clean = $keep( $key, '' );
		}

		$input[ $key ] = $clean;
	}

	// The template URL is deliberately not fetched here -- a template being
	// temporarily unreachable shouldn't block saving settings, since
	// feedland_rivers_get_template() already falls back to the bundled
	// default template on fetch failure.

	return $input;
}

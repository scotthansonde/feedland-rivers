<?php
/**
 * Removes everything this plugin stored, on uninstall.
 *
 * Transients are swept with a direct query rather than delete_transient()
 * because their keys embed an md5 of the server/username/category/cap (and
 * of each feed URL and template URL), so there is no way to enumerate them
 * after the settings are gone.
 *
 * @package feedland-rivers
 */

// Only ever run as WordPress's uninstall handler.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Deletes this plugin's option and transients for the current site.
 *
 * @return void
 */
function feedland_rivers_uninstall_site(): void {
	global $wpdb;

	delete_option( 'feedland_rivers_options' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- transient keys are hashed, so they can't be enumerated; no caching concern during uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_feedland_rivers_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_feedland_rivers_' ) . '%'
		)
	);
}

/**
 * Runs the uninstall across every site, on multisite.
 *
 * @return void
 */
function feedland_rivers_run_uninstall(): void {
	if ( ! is_multisite() ) {
		feedland_rivers_uninstall_site();
		return;
	}

	// Site-scoped options and transients live in each site's own tables, so
	// every site on the network needs sweeping. 'number' => 0 is required:
	// get_sites() defaults to returning only the first 100 sites, which would
	// have quietly left everything behind on a larger network.
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		feedland_rivers_uninstall_site();
		restore_current_blog();
	}
}

feedland_rivers_run_uninstall();

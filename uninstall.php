<?php
/**
 * Removes everything Web of D Form Locator stored: the results table and options on
 * every site, and the per-user screen option. Self-contained: does not load
 * the Composer autoloader or any plugin class.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Drops the results table and deletes the options for the current site.
 *
 * @return void
 */
function gffl_uninstall_site(): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping the plugin's own table; the name is internal.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gffl_results" );

	foreach ( array( 'gffl_db_version', 'gffl_last_scan', 'gffl_scan_state' ) as $option ) {
		delete_option( $option );
	}
}

/**
 * Runs the site cleanup on every site of every network, 100 sites per query.
 *
 * @return void
 */
function gffl_uninstall_all_sites(): void {
	$offset = 0;

	do {
		$site_ids    = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $offset,
			)
		);
		$batch_count = count( $site_ids );

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			gffl_uninstall_site();
			restore_current_blog();
		}

		$offset += 100;
	} while ( 100 === $batch_count );
}

if ( is_multisite() ) {
	gffl_uninstall_all_sites();
} else {
	gffl_uninstall_site();
}

// The "Embeds per page" screen option is user meta, shared by all sites.
delete_metadata( 'user', 0, 'gffl_per_page', '', true );

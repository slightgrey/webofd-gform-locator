<?php
/**
 * Creates and upgrades the plugin's database table.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator;

/**
 * Owns the gffl_results table schema and the plugin's lifecycle hooks.
 */
class Installer {

	/**
	 * Option holding the installed schema version.
	 */
	public const DB_VERSION_OPTION = 'gffl_db_version';

	/**
	 * Option holding an in-progress scan's queue and lock.
	 */
	public const SCAN_STATE_OPTION = 'gffl_scan_state';

	/**
	 * Returns the results table name for the current site.
	 *
	 * Resolved on every call so it stays correct after switch_to_blog().
	 *
	 * @return string Prefixed table name.
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'gffl_results';
	}

	/**
	 * Activation hook callback.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 *
	 * @return void
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			self::for_each_site( array( self::class, 'install' ) );
			return;
		}

		self::install();
	}

	/**
	 * Deactivation hook callback. Clears any stuck scan lock but keeps results.
	 *
	 * @param bool $network_wide Whether the plugin is being network-deactivated.
	 *
	 * @return void
	 */
	public static function deactivate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			self::for_each_site( array( self::class, 'clear_scan_state' ) );
			return;
		}

		self::clear_scan_state();
	}

	/**
	 * Installs the table and options for one site of a network.
	 *
	 * @param int $blog_id Site ID.
	 *
	 * @return void
	 */
	public static function install_for_site( int $blog_id ): void {
		switch_to_blog( $blog_id );
		self::install();
		restore_current_blog();
	}

	/**
	 * Runs a callback on every site of the current network, 100 sites per query.
	 *
	 * @param callable $callback Called with no arguments while switched to each site.
	 *
	 * @return void
	 */
	public static function for_each_site( callable $callback ): void {
		$offset = 0;

		do {
			$site_ids = get_sites(
				array(
					'fields'     => 'ids',
					'number'     => 100,
					'offset'     => $offset,
					'network_id' => get_current_network_id(),
				)
			);

			$batch_count = count( $site_ids );

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				$callback();
				restore_current_blog();
			}

			$offset += 100;
		} while ( 100 === $batch_count );
	}

	/**
	 * Deletes the in-progress scan state for the current site.
	 *
	 * @return void
	 */
	public static function clear_scan_state(): void {
		delete_option( self::SCAN_STATE_OPTION );
	}

	/**
	 * Re-runs the installer when the stored schema version is older than the plugin.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$installed = (string) get_option( self::DB_VERSION_OPTION, '0' );

		if ( version_compare( $installed, GFFL_VERSION, '>=' ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Creates or updates the results table and records the schema version.
	 *
	 * @return void
	 */
	public static function install(): void {
		self::create_table();
		update_option( self::DB_VERSION_OPTION, GFFL_VERSION, false );
	}

	/**
	 * Runs dbDelta for the results table.
	 *
	 * @return void
	 */
	private static function create_table(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( self::table_schema() );
	}

	/**
	 * Builds the CREATE TABLE statement in the format dbDelta expects.
	 *
	 * @return string SQL statement.
	 */
	private static function table_schema(): string {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned DEFAULT NULL,
			form_ref varchar(191) NOT NULL DEFAULT '',
			source_type varchar(20) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_ref varchar(191) NOT NULL DEFAULT '',
			post_type varchar(20) NOT NULL DEFAULT '',
			post_status varchar(20) NOT NULL DEFAULT '',
			embed_method varchar(30) NOT NULL DEFAULT '',
			context varchar(191) NOT NULL DEFAULT '',
			scanned_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY object_id (object_id),
			KEY embed_method (embed_method)
		) {$charset_collate};";
	}
}

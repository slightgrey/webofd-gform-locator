<?php
/**
 * Keeps per-site tables in step with sites being created and deleted.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator\Network;

use GFFormLocator\Installer;
use WP_Site;

/**
 * Multisite lifecycle: install on new sites when network-active, and drop the
 * site's table when core deletes the site.
 */
class NetworkHooks {

	/**
	 * Installs the plugin table on a newly created site if the plugin is network-active.
	 *
	 * Hooked at priority 200 so core has already created the site's own tables.
	 *
	 * @param WP_Site $site New site.
	 *
	 * @return void
	 */
	public function initialize_site( WP_Site $site ): void {
		if ( ! $this->is_network_active() ) {
			return;
		}

		Installer::install_for_site( (int) $site->blog_id );
	}

	/**
	 * Adds the site's results table to the list core drops when deleting a site.
	 *
	 * @param array<int, string> $tables  Tables core will drop.
	 * @param int                $site_id Site being deleted.
	 *
	 * @return array<int, string> Tables including ours.
	 */
	public function drop_tables( array $tables, int $site_id ): array {
		global $wpdb;

		$tables[] = $wpdb->get_blog_prefix( $site_id ) . 'gffl_results';

		return $tables;
	}

	/**
	 * Tells whether the plugin is network-activated.
	 *
	 * @return bool True if network-active.
	 */
	private function is_network_active(): bool {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active_for_network( plugin_basename( GFFL_FILE ) );
	}
}

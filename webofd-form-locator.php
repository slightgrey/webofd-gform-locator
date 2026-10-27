<?php
/**
 * Plugin Name:       Web of D Form Locator
 * Description:       Finds every page, template, widget and field where a Gravity Forms form is embedded. Works without Gravity Forms installed.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Web of D
 * Author URI:        https://vincelayte.me/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       webofd-form-locator
 * Domain Path:       /languages
 * Update URI:        false
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GFFL_VERSION', '1.0.1' );
define( 'GFFL_FILE', __FILE__ );
define( 'GFFL_DIR', plugin_dir_path( __FILE__ ) );
define( 'GFFL_URL', plugin_dir_url( __FILE__ ) );

/**
 * Prints an admin notice telling the site owner to install Composer dependencies.
 *
 * @return void
 */
function gffl_missing_vendor_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
		esc_html__( 'Web of D Form Locator:', 'webofd-form-locator' ),
		wp_kses(
			__( 'Run <code>composer install</code> in the plugin directory before use.', 'webofd-form-locator' ),
			array( 'code' => array() )
		)
	);
}

if ( ! file_exists( GFFL_DIR . 'vendor/autoload.php' ) ) {
	add_action( 'admin_notices', 'gffl_missing_vendor_notice' );
	return;
}

require_once GFFL_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( 'GFFormLocator\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GFFormLocator\Installer', 'deactivate' ) );

GFFormLocator\Plugin::run();

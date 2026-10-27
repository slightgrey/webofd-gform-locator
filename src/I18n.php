<?php
/**
 * Loads the plugin text domain.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator;

/**
 * Makes the plugin's strings translatable.
 */
class I18n {

	/**
	 * Loads translations from the plugin's languages directory.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'webofd-form-locator', false, dirname( plugin_basename( GFFL_FILE ) ) . '/languages' );
	}
}

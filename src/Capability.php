<?php
/**
 * The capability required to use Web of D Form Locator.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator;

/**
 * Single source for the gffl_capability filter, shared by admin screens and REST routes.
 */
final class Capability {

	/**
	 * Returns the capability needed to view the report and run scans.
	 *
	 * @return string Capability name (default manage_options).
	 */
	public static function required(): string {
		$capability = apply_filters( 'gffl_capability', 'manage_options' );

		return is_string( $capability ) && '' !== $capability ? $capability : 'manage_options';
	}

	/**
	 * Tells whether the current user may use the plugin.
	 *
	 * @return bool True if allowed.
	 */
	public static function current_user_can(): bool {
		return current_user_can( self::required() );
	}
}

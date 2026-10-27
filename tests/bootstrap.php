<?php
/**
 * PHPUnit bootstrap for Web of D Form Locator unit tests.
 *
 * Unit tests run without a database. WordPress hook functions are replaced by
 * stubs that record calls in $GLOBALS['gffl_test_hooks'], and WordPress core's
 * shortcode and block parsers are loaded straight from wp-includes.
 *
 * Set GFFL_WP_INCLUDES to point at a different wp-includes directory.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );
}

$GLOBALS['gffl_test_hooks'] = array();

/**
 * Filters registered by tests: hook name => callback.
 *
 * @var array<string, callable> $GLOBALS['gffl_test_filters']
 */
$GLOBALS['gffl_test_filters'] = array();

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Runs a test-registered filter, or returns the value unchanged.
	 *
	 * @param string $hook  Hook name.
	 * @param mixed  $value Value to filter.
	 * @param mixed  ...$args Extra arguments.
	 *
	 * @return mixed Filtered value.
	 */
	function apply_filters( $hook, $value, ...$args ) {
		if ( isset( $GLOBALS['gffl_test_filters'][ $hook ] ) ) {
			return $GLOBALS['gffl_test_filters'][ $hook ]( $value, ...$args );
		}

		return $value;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Records an action registration.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted args.
	 *
	 * @return bool Always true.
	 */
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['gffl_test_hooks'][] = array( 'action', $hook, $callback, $priority, $accepted_args );
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Records a filter registration.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted args.
	 *
	 * @return bool Always true.
	 */
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['gffl_test_hooks'][] = array( 'filter', $hook, $callback, $priority, $accepted_args );
		return true;
	}
}

$gffl_wp_includes = getenv( 'GFFL_WP_INCLUDES' ) ? rtrim( (string) getenv( 'GFFL_WP_INCLUDES' ), '/\\' ) . '/' : ABSPATH . 'wp-includes/';

require_once $gffl_wp_includes . 'shortcodes.php';
require_once $gffl_wp_includes . 'class-wp-block-parser-block.php';
require_once $gffl_wp_includes . 'class-wp-block-parser-frame.php';
require_once $gffl_wp_includes . 'class-wp-block-parser.php';
require_once $gffl_wp_includes . 'blocks.php';

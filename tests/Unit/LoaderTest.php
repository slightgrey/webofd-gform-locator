<?php
/**
 * Tests for the hook Loader.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator\Tests\Unit;

use GFFormLocator\Loader;
use PHPUnit\Framework\TestCase;

/**
 * Loader queues hooks and registers each one exactly once on run().
 */
final class LoaderTest extends TestCase {

	/**
	 * Clears recorded hooks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['gffl_test_hooks'] = array();
	}

	/**
	 * Nothing is registered until run() is called.
	 *
	 * @return void
	 */
	public function test_hooks_are_not_registered_before_run(): void {
		$loader = new Loader();
		$loader->add_action( 'init', 'strlen' );

		$this->assertSame( array(), $GLOBALS['gffl_test_hooks'] );
	}

	/**
	 * Each queued action and filter is registered once with its priority and arg count.
	 *
	 * @return void
	 */
	public function test_run_registers_each_hook_once(): void {
		$loader = new Loader();
		$loader->add_action( 'init', 'strlen' );
		$loader->add_action( 'admin_menu', 'trim', 20 );
		$loader->add_filter( 'wpmu_drop_tables', 'array_values', 10, 2 );

		$loader->run();

		$this->assertSame(
			array(
				array( 'action', 'init', 'strlen', 10, 1 ),
				array( 'action', 'admin_menu', 'trim', 20, 1 ),
				array( 'filter', 'wpmu_drop_tables', 'array_values', 10, 2 ),
			),
			$GLOBALS['gffl_test_hooks']
		);
	}
}

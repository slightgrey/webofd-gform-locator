<?php
/**
 * Smoke test for the unit-test bootstrap.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * WordPress parser functions are available without a database.
 */
final class BootstrapTest extends TestCase {

	/**
	 * Both core parsers load and work.
	 *
	 * @return void
	 */
	public function test_wordpress_parsers_are_loaded(): void {
		$this->assertTrue( function_exists( 'parse_blocks' ) );
		$this->assertTrue( function_exists( 'shortcode_parse_atts' ) );

		$blocks = parse_blocks( '<!-- wp:gravityforms/form {"formId":"7"} /-->' );
		$this->assertSame( 'gravityforms/form', $blocks[0]['blockName'] );
		$this->assertSame( array( 'id' => '3' ), shortcode_parse_atts( ' id="3"' ) );
	}
}

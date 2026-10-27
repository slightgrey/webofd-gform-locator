<?php
/**
 * Tests for the EmbedMatch value object.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator\Tests\Unit;

use GFFormLocator\Detector\EmbedMatch;
use PHPUnit\Framework\TestCase;

/**
 * Match identity and de-duplication.
 */
final class EmbedMatchTest extends TestCase {

	/**
	 * Equal values give equal keys; any differing value changes the key.
	 *
	 * @return void
	 */
	public function test_key_reflects_all_identity_fields(): void {
		$a = new EmbedMatch( 3, '3', 'shortcode', '' );

		$this->assertSame( $a->key(), ( new EmbedMatch( 3, '3', 'shortcode', '' ) )->key() );
		$this->assertNotSame( $a->key(), ( new EmbedMatch( 4, '4', 'shortcode', '' ) )->key() );
		$this->assertNotSame( $a->key(), ( new EmbedMatch( 3, '3', 'block', '' ) )->key() );
		$this->assertNotSame( $a->key(), ( new EmbedMatch( 3, '3', 'shortcode', 'core/shortcode' ) )->key() );
	}

	/**
	 * Name-only references are keyed by their text so different names stay distinct.
	 *
	 * @return void
	 */
	public function test_name_only_references_are_keyed_by_text(): void {
		$contact = new EmbedMatch( null, 'name="Contact"', 'shortcode' );
		$quote   = new EmbedMatch( null, 'name="Quote"', 'shortcode' );

		$this->assertNotSame( $contact->key(), $quote->key() );
	}

	/**
	 * Unique() collapses duplicates and keeps first-seen order.
	 *
	 * @return void
	 */
	public function test_unique_collapses_duplicates(): void {
		$matches = array(
			new EmbedMatch( 3, '3', 'shortcode' ),
			new EmbedMatch( 5, '5', 'shortcode' ),
			new EmbedMatch( 3, '3', 'shortcode' ),
		);

		$unique = EmbedMatch::unique( $matches );

		$this->assertCount( 2, $unique );
		$this->assertSame( array( 3, 5 ), array_map( static fn( EmbedMatch $m ) => $m->form_id(), $unique ) );
	}

	/**
	 * With_source() keeps the form and swaps method and context.
	 *
	 * @return void
	 */
	public function test_with_source_keeps_form(): void {
		$moved = ( new EmbedMatch( 3, '3', 'shortcode' ) )->with_source( 'elementor_shortcode', 'text-editor' );

		$this->assertSame( 3, $moved->form_id() );
		$this->assertSame( 'elementor_shortcode', $moved->embed_method() );
		$this->assertSame( 'text-editor', $moved->context() );
	}
}

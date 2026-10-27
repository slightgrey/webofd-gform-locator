<?php
/**
 * Tests for ShortcodeDetector.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator\Tests\Unit;

use GFFormLocator\Detector\EmbedMatch;
use GFFormLocator\Detector\ShortcodeDetector;
use PHPUnit\Framework\TestCase;

/**
 * Shortcode fixtures from the PRD testing strategy.
 */
final class ShortcodeDetectorTest extends TestCase {

	/**
	 * Detector under test.
	 *
	 * @var ShortcodeDetector
	 */
	private ShortcodeDetector $detector;

	/**
	 * Creates the detector.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->detector = new ShortcodeDetector();
	}

	/**
	 * Returns [form_id, form_ref] pairs for the given text.
	 *
	 * @param string $text Text to scan.
	 *
	 * @return array<int, array{0: int|null, 1: string}> Pairs.
	 */
	private function found( string $text ): array {
		return array_map(
			static fn( EmbedMatch $m ) => array( $m->form_id(), $m->form_ref() ),
			$this->detector->detect( $text )
		);
	}

	/**
	 * Supplies quoting styles and the legacy tag.
	 *
	 * @return array<string, array{0: string, 1: int}> Text and expected form ID.
	 */
	public function id_variants(): array {
		return array(
			'double quotes'      => array( '[gravityform id="3"]', 3 ),
			'unquoted + extras'  => array( '[gravityform id=3 title="false" ajax="true"]', 3 ),
			'single quotes'      => array( "[gravityforms id='5']", 5 ),
			'upper-case tag'     => array( '[GravityForm id="8"]', 8 ),
			'self-closing'       => array( '[gravityform id="9" /]', 9 ),
			'multi-line'         => array( "[gravityform\n  id=\"10\"\n  title=\"false\"]", 10 ),
			'surrounded by html' => array( '<p>Contact us</p>[gravityform id="11"]<p>Thanks</p>', 11 ),
		);
	}

	/**
	 * Every quoting style yields the numeric ID.
	 *
	 * @dataProvider id_variants
	 *
	 * @param string $text        Text to scan.
	 * @param int    $expected_id Expected form ID.
	 *
	 * @return void
	 */
	public function test_reads_id_in_every_format( string $text, int $expected_id ): void {
		$this->assertSame( array( array( $expected_id, (string) $expected_id ) ), $this->found( $text ) );
	}

	/**
	 * Legacy name= references have no numeric ID.
	 *
	 * @return void
	 */
	public function test_name_only_reference(): void {
		$this->assertSame( array( array( null, 'name="Contact"' ) ), $this->found( '[gravityform name="Contact"]' ) );
	}

	/**
	 * Several shortcodes on one page are all found, duplicates collapsed.
	 *
	 * @return void
	 */
	public function test_multiple_per_string(): void {
		$text = '[gravityform id="1"] text [gravityform id="2" ajax="true"] more [gravityform id="1"]';

		$this->assertSame( array( array( 1, '1' ), array( 2, '2' ) ), $this->found( $text ) );
	}

	/**
	 * Escaped shortcodes print literally, so they are not embeds.
	 *
	 * @return void
	 */
	public function test_escaped_shortcode_is_ignored(): void {
		$this->assertSame( array(), $this->found( 'Use [[gravityform id="1"]] to embed.' ) );
		$this->assertSame( array( array( 2, '2' ) ), $this->found( '[[gravityform id="1"]] and [gravityform id="2"]' ) );
	}

	/**
	 * Non-render actions such as conditional are skipped; action="form" is kept.
	 *
	 * @return void
	 */
	public function test_non_form_actions_are_skipped(): void {
		$this->assertSame( array(), $this->found( '[gravityform action="conditional" merge_tag="{Name:1}" condition="is" value="x"]Hi[/gravityform]' ) );
		$this->assertSame( array( array( 4, '4' ) ), $this->found( '[gravityform action="form" id="4"]' ) );
	}

	/**
	 * Lookalike tags, missing identifiers and plain text produce nothing.
	 *
	 * @return void
	 */
	public function test_non_matches(): void {
		$this->assertSame( array(), $this->found( 'No forms here.' ) );
		$this->assertSame( array(), $this->found( '[gravityformsx id="1"]' ) );
		$this->assertSame( array(), $this->found( '[gravityform title="false"]' ) );
	}

	/**
	 * Method and context are recorded on each match.
	 *
	 * @return void
	 */
	public function test_method_and_context_are_recorded(): void {
		$match = $this->detector->detect( '[gravityform id="3"]', 'meta', 'acf_cta' )[0];

		$this->assertSame( 'meta', $match->embed_method() );
		$this->assertSame( 'acf_cta', $match->context() );
	}
}

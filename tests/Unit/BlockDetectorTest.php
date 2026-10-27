<?php
/**
 * Tests for BlockDetector.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator\Tests\Unit;

use GFFormLocator\Detector\BlockDetector;
use GFFormLocator\Detector\EmbedMatch;
use GFFormLocator\Detector\ShortcodeDetector;
use PHPUnit\Framework\TestCase;

/**
 * Block fixtures from the PRD testing strategy.
 */
final class BlockDetectorTest extends TestCase {

	/**
	 * Detector under test.
	 *
	 * @var BlockDetector
	 */
	private BlockDetector $detector;

	/**
	 * Creates the detector.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->detector = new BlockDetector( new ShortcodeDetector() );
	}

	/**
	 * Returns "form_id|method|context" strings for the content.
	 *
	 * @param string $content Content to scan.
	 *
	 * @return array<int, string> Summaries.
	 */
	private function found( string $content ): array {
		return array_map(
			static fn( EmbedMatch $m ) => ( $m->form_id() ?? 'null' ) . '|' . $m->embed_method() . '|' . $m->context(),
			$this->detector->detect( $content )
		);
	}

	/**
	 * A self-closing top-level block with a string formId gives an int ID.
	 *
	 * @return void
	 */
	public function test_top_level_self_closing_block(): void {
		$this->assertSame(
			array( '7|block|gravityforms/form' ),
			$this->found( '<!-- wp:gravityforms/form {"formId":"7","title":false} /-->' )
		);
	}

	/**
	 * A block nested inside group > columns > column is found.
	 *
	 * @return void
	 */
	public function test_nested_block(): void {
		$content = '<!-- wp:group --><div class="wp-block-group"><!-- wp:columns --><div class="wp-block-columns">'
			. '<!-- wp:column --><div class="wp-block-column"><!-- wp:gravityforms/form {"formId":12} /--></div><!-- /wp:column -->'
			. '</div><!-- /wp:columns --></div><!-- /wp:group -->';

		$this->assertSame( array( '12|block|gravityforms/form' ), $this->found( $content ) );
	}

	/**
	 * A shortcode inside core/shortcode records the block name as context.
	 *
	 * @return void
	 */
	public function test_shortcode_block(): void {
		$content = "<!-- wp:shortcode -->\n[gravityform id=\"4\" title=\"false\"]\n<!-- /wp:shortcode -->";

		$this->assertSame( array( '4|shortcode|core/shortcode' ), $this->found( $content ) );
	}

	/**
	 * Shortcodes typed into a paragraph still render, so they count.
	 *
	 * @return void
	 */
	public function test_shortcode_in_paragraph(): void {
		$content = '<!-- wp:paragraph --><p>Get in touch: [gravityform id="6"]</p><!-- /wp:paragraph -->';

		$this->assertSame( array( '6|shortcode|core/paragraph' ), $this->found( $content ) );
	}

	/**
	 * Classic content with no block comments is parsed as freeform with no context.
	 *
	 * @return void
	 */
	public function test_classic_content(): void {
		$this->assertSame( array( '2|shortcode|' ), $this->found( "<p>Hello</p>\n[gravityform id=\"2\"]" ) );
	}

	/**
	 * A block and a shortcode on the same page are both reported.
	 *
	 * @return void
	 */
	public function test_mixed_block_and_shortcode(): void {
		$content = '<!-- wp:gravityforms/form {"formId":"1"} /--><!-- wp:shortcode -->[gravityform id="2"]<!-- /wp:shortcode -->';

		$this->assertSame( array( '1|block|gravityforms/form', '2|shortcode|core/shortcode' ), $this->found( $content ) );
	}

	/**
	 * A form block with no form selected, or no Gravity Forms content at all, gives nothing.
	 *
	 * @return void
	 */
	public function test_non_matches(): void {
		$this->assertSame( array(), $this->found( '<!-- wp:gravityforms/form /-->' ) );
		$this->assertSame( array(), $this->found( '<!-- wp:gravityforms/form {"formId":""} /-->' ) );
		$this->assertSame( array(), $this->found( '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ) );
		$this->assertSame( array(), $this->found( '' ) );
	}
}

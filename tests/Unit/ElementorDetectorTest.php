<?php
/**
 * Tests for ElementorDetector.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator\Tests\Unit;

use GFFormLocator\Detector\ElementorDetector;
use GFFormLocator\Detector\EmbedMatch;
use GFFormLocator\Detector\ShortcodeDetector;
use PHPUnit\Framework\TestCase;

/**
 * Elementor fixtures from the PRD testing strategy.
 */
final class ElementorDetectorTest extends TestCase {

	/**
	 * Detector under test.
	 *
	 * @var ElementorDetector
	 */
	private ElementorDetector $detector;

	/**
	 * Creates the detector and clears test filters.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['gffl_test_filters'] = array();
		$this->detector               = new ElementorDetector( new ShortcodeDetector() );
	}

	/**
	 * Clears test filters.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['gffl_test_filters'] = array();
	}

	/**
	 * Builds a widget element.
	 *
	 * @param string               $type     Widget type.
	 * @param array<string, mixed> $settings Settings.
	 *
	 * @return array<string, mixed> Element.
	 */
	private function widget( string $type, array $settings ): array {
		return array(
			'id'         => substr( md5( $type . json_encode( $settings ) ), 0, 7 ),
			'elType'     => 'widget',
			'widgetType' => $type,
			'settings'   => $settings,
			'elements'   => array(),
		);
	}

	/**
	 * Wraps children in a layout element.
	 *
	 * @param string                           $el_type  Element type (section, column, container).
	 * @param array<int, array<string, mixed>> $children Child elements.
	 *
	 * @return array<string, mixed> Element.
	 */
	private function layout( string $el_type, array $children ): array {
		return array(
			'id'       => 'l' . count( $children ),
			'elType'   => $el_type,
			'settings' => array(),
			'elements' => $children,
		);
	}

	/**
	 * Returns "form_id|method|context" strings for an element tree.
	 *
	 * @param array<int, array<string, mixed>> $elements Top-level elements.
	 *
	 * @return array<int, string> Summaries.
	 */
	private function found( array $elements ): array {
		return array_map(
			static fn( EmbedMatch $m ) => $m->form_id() . '|' . $m->embed_method() . '|' . $m->context(),
			$this->detector->detect( (string) json_encode( $elements ) )
		);
	}

	/**
	 * The Shortcode widget is detected as an elementor_shortcode.
	 *
	 * @return void
	 */
	public function test_shortcode_widget(): void {
		$tree = array( $this->widget( 'shortcode', array( 'shortcode' => '[gravityform id="3" title="false"]' ) ) );

		$this->assertSame( array( '3|elementor_shortcode|shortcode' ), $this->found( $tree ) );
	}

	/**
	 * A shortcode inside Text Editor HTML is detected.
	 *
	 * @return void
	 */
	public function test_text_editor_widget(): void {
		$tree = array( $this->widget( 'text-editor', array( 'editor' => '<p>Reach us:</p><p>[gravityform id="5"]</p>' ) ) );

		$this->assertSame( array( '5|elementor_shortcode|text-editor' ), $this->found( $tree ) );
	}

	/**
	 * The Essential Addons widget's form setting is read.
	 *
	 * @return void
	 */
	public function test_mapped_addon_widget(): void {
		$tree = array( $this->widget( 'eael-gravity-form', array( 'contact_form_list' => '8', 'form_title' => 'yes' ) ) );

		$this->assertSame( array( '8|elementor_widget|eael-gravity-form' ), $this->found( $tree ) );
	}

	/**
	 * Unmapped widgets with "gravity" in the type fall back to common setting keys.
	 *
	 * @return void
	 */
	public function test_unmapped_gravity_widget_uses_fallback_keys(): void {
		$tree = array( $this->widget( 'uael-gf-styler-gravity', array( 'form_id' => 14 ) ) );

		$this->assertSame( array( '14|elementor_widget|uael-gf-styler-gravity' ), $this->found( $tree ) );
	}

	/**
	 * The widget map can be extended through the gffl_elementor_widget_map filter.
	 *
	 * @return void
	 */
	public function test_widget_map_is_filterable(): void {
		$GLOBALS['gffl_test_filters']['gffl_elementor_widget_map'] = static fn( array $map ) => $map + array( 'my-forms' => 'which_form' );

		$tree = array( $this->widget( 'my-forms', array( 'which_form' => '21', 'label' => 'gravity' ) ) );

		$this->assertSame( array( '21|elementor_widget|my-forms' ), $this->found( $tree ) );
	}

	/**
	 * Widgets deep inside section > column > inner section > column are found.
	 *
	 * @return void
	 */
	public function test_deeply_nested_widget(): void {
		$tree = array(
			$this->layout(
				'section',
				array(
					$this->layout(
						'column',
						array(
							$this->layout( 'section', array( $this->layout( 'column', array( $this->widget( 'shortcode', array( 'shortcode' => '[gravityform id="2"]' ) ) ) ) ) ),
						)
					),
				)
			),
			$this->layout( 'container', array( $this->widget( 'eael-gravity-form', array( 'contact_form_list' => '9' ) ) ) ),
		);

		$this->assertSame( array( '2|elementor_shortcode|shortcode', '9|elementor_widget|eael-gravity-form' ), $this->found( $tree ) );
	}

	/**
	 * Shortcodes inside repeater rows are found.
	 *
	 * @return void
	 */
	public function test_repeater_settings(): void {
		$tabs = array(
			array( 'tab_title' => 'One', 'tab_content' => 'No form' ),
			array( 'tab_title' => 'Two', 'tab_content' => '[gravityform id="6"]' ),
		);
		$tree = array( $this->widget( 'tabs', array( 'tabs' => $tabs ) ) );

		$this->assertSame( array( '6|elementor_shortcode|tabs' ), $this->found( $tree ) );
	}

	/**
	 * A form widget with no form chosen gives nothing.
	 *
	 * @return void
	 */
	public function test_widget_without_form(): void {
		$tree = array( $this->widget( 'eael-gravity-form', array( 'contact_form_list' => '0' ) ) );

		$this->assertSame( array(), $this->found( $tree ) );
	}

	/**
	 * Empty, malformed and non-array JSON return nothing without errors.
	 *
	 * @return void
	 */
	public function test_bad_json(): void {
		$this->assertSame( array(), $this->detector->detect( '' ) );
		$this->assertSame( array(), $this->detector->detect( '[]' ) );
		$this->assertSame( array(), $this->detector->detect( '[{"elType":"widget","widgetType":"gravity' ) );
		$this->assertSame( array(), $this->detector->detect( '"gravity"' ) );
		$this->assertSame( array(), $this->detector->detect( '[{"elType":"widget","widgetType":"eael-gravity-form","settings":"oops","elements":"nope"}]' ) );
	}
}

<?php
/**
 * Scans classic and block widgets for Gravity Forms embeds.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator\Source;

use GFFormLocator\Detector\BlockDetector;
use GFFormLocator\Detector\EmbedMatch;
use GFFormLocator\Detector\ShortcodeDetector;

/**
 * Reads the Text, Custom HTML and Block widget options and reports each embed
 * with its widget ID, sidebar, and whether the widget is in an active sidebar.
 *
 * @phpstan-import-type ResultRow from \GFFormLocator\ResultsRepository
 */
class WidgetSource {

	/**
	 * Widget base ID => instance field holding the content.
	 */
	private const WIDGET_FIELDS = array(
		'text'        => 'text',
		'custom_html' => 'content',
		'block'       => 'content',
	);

	/**
	 * Sidebar IDs that hold widgets which are not displayed.
	 */
	private const INACTIVE_SIDEBAR_PREFIXES = array( 'wp_inactive_widgets', 'orphaned_widgets' );

	/**
	 * Detector for block widget markup.
	 *
	 * @var BlockDetector
	 */
	private BlockDetector $blocks;

	/**
	 * Detector for text and HTML widgets.
	 *
	 * @var ShortcodeDetector
	 */
	private ShortcodeDetector $shortcodes;

	/**
	 * Creates the source.
	 *
	 * @param BlockDetector     $blocks     Block detector.
	 * @param ShortcodeDetector $shortcodes Shortcode detector.
	 */
	public function __construct( BlockDetector $blocks, ShortcodeDetector $shortcodes ) {
		$this->blocks     = $blocks;
		$this->shortcodes = $shortcodes;
	}

	/**
	 * Creates the source with the default detectors.
	 *
	 * @return self Widget source.
	 */
	public static function create(): self {
		$shortcodes = new ShortcodeDetector();

		return new self( new BlockDetector( $shortcodes ), $shortcodes );
	}

	/**
	 * Scans every supported widget instance.
	 *
	 * @return array<int, array<string, mixed>> Result rows.
	 * @phpstan-return array<int, ResultRow>
	 */
	public function scan(): array {
		$sidebars = $this->widget_sidebars();
		$rows     = array();

		foreach ( self::WIDGET_FIELDS as $base => $field ) {
			foreach ( $this->instances( $base ) as $number => $instance ) {
				$widget_id = $base . '-' . $number;
				$content   = $instance[ $field ] ?? '';
				$sidebar   = $sidebars[ $widget_id ] ?? '';

				foreach ( $this->detect( $base, is_string( $content ) ? $content : '' ) as $embed ) {
					$rows[] = $this->widget_row( $embed, $widget_id, $sidebar );
				}
			}
		}

		return $rows;
	}

	/**
	 * Returns the numbered instances stored in a widget option.
	 *
	 * @param string $base Widget base ID.
	 *
	 * @return array<int, array<string, mixed>> Number => instance settings.
	 */
	private function instances( string $base ): array {
		$option    = get_option( 'widget_' . $base, array() );
		$instances = array();

		foreach ( is_array( $option ) ? $option : array() as $number => $instance ) {
			if ( is_int( $number ) && is_array( $instance ) ) {
				$instances[ $number ] = $instance;
			}
		}

		return $instances;
	}

	/**
	 * Maps each widget ID to the sidebar it sits in.
	 *
	 * @return array<string, string> Widget ID => sidebar ID.
	 */
	private function widget_sidebars(): array {
		$sidebars = get_option( 'sidebars_widgets', array() );
		$map      = array();

		foreach ( is_array( $sidebars ) ? $sidebars : array() as $sidebar_id => $widget_ids ) {
			foreach ( is_array( $widget_ids ) ? $widget_ids : array() as $widget_id ) {
				$map[ (string) $widget_id ] = (string) $sidebar_id;
			}
		}

		return $map;
	}

	/**
	 * Runs the right detector for a widget type.
	 *
	 * @param string $base    Widget base ID.
	 * @param string $content Widget content.
	 *
	 * @return array<int, EmbedMatch> Matches.
	 */
	private function detect( string $base, string $content ): array {
		if ( 'block' === $base ) {
			return $this->blocks->detect( $content );
		}

		return $this->shortcodes->detect( $content );
	}

	/**
	 * Tells whether a sidebar displays its widgets.
	 *
	 * @param string $sidebar Sidebar ID, '' if the widget isn't in any sidebar.
	 *
	 * @return bool True if active.
	 */
	private function is_active_sidebar( string $sidebar ): bool {
		if ( '' === $sidebar ) {
			return false;
		}

		foreach ( self::INACTIVE_SIDEBAR_PREFIXES as $prefix ) {
			if ( 0 === strpos( $sidebar, $prefix ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Builds a widget result row. The method is "widget"; context is the sidebar.
	 *
	 * @param EmbedMatch $embed     Match.
	 * @param string     $widget_id Widget ID, e.g. text-3.
	 * @param string     $sidebar   Sidebar ID.
	 *
	 * @return array<string, mixed> Row.
	 * @phpstan-return ResultRow
	 */
	private function widget_row( EmbedMatch $embed, string $widget_id, string $sidebar ): array {
		$status = $this->is_active_sidebar( $sidebar ) ? 'active' : 'inactive';

		return $embed->with_source( 'widget', $sidebar )->to_row( 'widget', 0, $widget_id, '', $status );
	}
}

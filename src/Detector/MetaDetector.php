<?php
/**
 * Finds Gravity Forms shortcodes in post meta values.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator\Detector;

/**
 * Scans raw meta values (serialized or JSON included) for shortcodes.
 * Catches ACF fields and page builders that store content in meta.
 */
class MetaDetector {

	/**
	 * Keys skipped by default: Elementor data has its own detector, its caches
	 * hold rendered output (not embeds), and the edit keys never hold content.
	 */
	private const DEFAULT_SKIP_KEYS = array(
		'_elementor_data',
		'_elementor_element_cache',
		'_elementor_css',
		'_elementor_page_assets',
		'_edit_lock',
		'_edit_last',
	);

	/**
	 * Shortcode detector used for each value.
	 *
	 * @var ShortcodeDetector
	 */
	private ShortcodeDetector $shortcodes;

	/**
	 * Creates the detector.
	 *
	 * @param ShortcodeDetector $shortcodes Shortcode detector.
	 */
	public function __construct( ShortcodeDetector $shortcodes ) {
		$this->shortcodes = $shortcodes;
	}

	/**
	 * Finds shortcodes in one meta value.
	 *
	 * @param string $meta_key  Meta key, recorded as the match context.
	 * @param string $raw_value Raw meta value as stored.
	 *
	 * @return array<int, EmbedMatch> Unique matches; empty for skipped keys.
	 */
	public function detect( string $meta_key, string $raw_value ): array {
		if ( $this->is_skipped( $meta_key ) || false === stripos( $raw_value, '[gravityform' ) ) {
			return array();
		}

		$matches = array();

		foreach ( $this->value_variants( $raw_value ) as $text ) {
			$matches = array_merge( $matches, $this->shortcodes->detect( $text, 'meta', $meta_key ) );
		}

		return EmbedMatch::unique( $matches );
	}

	/**
	 * Tells whether a meta key is excluded from scanning.
	 *
	 * @param string $meta_key Meta key.
	 *
	 * @return bool True if skipped.
	 */
	public function is_skipped( string $meta_key ): bool {
		$skip = apply_filters( 'gffl_skip_meta_keys', self::DEFAULT_SKIP_KEYS );

		return in_array( $meta_key, is_array( $skip ) ? $skip : self::DEFAULT_SKIP_KEYS, true );
	}

	/**
	 * Returns the raw value plus an unescaped copy when quotes are JSON-escaped.
	 *
	 * @param string $raw_value Raw meta value.
	 *
	 * @return array<int, string> Texts to scan.
	 */
	private function value_variants( string $raw_value ): array {
		if ( false === strpos( $raw_value, '\\"' ) ) {
			return array( $raw_value );
		}

		return array( $raw_value, stripslashes( $raw_value ) );
	}
}

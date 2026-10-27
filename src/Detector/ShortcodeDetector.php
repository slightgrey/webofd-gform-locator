<?php
/**
 * Finds Gravity Forms shortcodes in text.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator\Detector;

/**
 * Detects [gravityform] and the older [gravityforms] shortcode.
 *
 * Uses WordPress's own shortcode regex so escaping ([[gravityform]]) and
 * attribute parsing behave exactly as they do when the page renders.
 */
class ShortcodeDetector {

	/**
	 * Shortcode tags Gravity Forms registers.
	 */
	private const TAGS = array( 'gravityform', 'gravityforms' );

	/**
	 * Action attribute values that render a form. Others (e.g. "conditional") don't.
	 */
	private const FORM_ACTIONS = array( '', 'form' );

	/**
	 * Finds every form-rendering Gravity Forms shortcode in the text.
	 *
	 * @param string $text    Text to search (post content, widget text, meta value...).
	 * @param string $method  Embed method to record on each match.
	 * @param string $context Context to record on each match.
	 *
	 * @return array<int, EmbedMatch> Unique matches in order of appearance.
	 */
	public function detect( string $text, string $method = 'shortcode', string $context = '' ): array {
		if ( false === stripos( $text, '[gravityform' ) ) {
			return array();
		}

		$matches = array();

		foreach ( $this->find_shortcodes( $text ) as $attributes ) {
			$match = $this->match_from_attributes( $attributes, $method, $context );

			if ( null !== $match ) {
				$matches[] = $match;
			}
		}

		return EmbedMatch::unique( $matches );
	}

	/**
	 * Returns the parsed attributes of every unescaped Gravity Forms shortcode.
	 *
	 * @param string $text Text to search.
	 *
	 * @return array<int, array<string, string>> Attribute arrays, lower-cased keys.
	 */
	private function find_shortcodes( string $text ): array {
		$pattern = '/' . get_shortcode_regex( self::TAGS ) . '/i';

		if ( ! preg_match_all( $pattern, $text, $found, PREG_SET_ORDER ) ) {
			return array();
		}

		$shortcodes = array();

		foreach ( $found as $shortcode ) {
			if ( $this->is_escaped( $shortcode ) ) {
				continue;
			}

			$shortcodes[] = $this->parse_attributes( $shortcode[3] );
		}

		return $shortcodes;
	}

	/**
	 * Tells whether a regex match is an escaped shortcode like [[gravityform id="1"]].
	 *
	 * @param array<int, string> $shortcode Regex groups from get_shortcode_regex().
	 *
	 * @return bool True if escaped.
	 */
	private function is_escaped( array $shortcode ): bool {
		return '[' === $shortcode[1] && ']' === $shortcode[6];
	}

	/**
	 * Parses a shortcode attribute string into a lower-cased key => value array.
	 *
	 * @param string $attribute_text Raw attribute text.
	 *
	 * @return array<string, string> Attributes.
	 */
	private function parse_attributes( string $attribute_text ): array {
		// WordPress < 6.5 returns a string when there are no attributes; the
		// cast turns that into an integer-keyed element the loop below skips.
		$parsed     = (array) shortcode_parse_atts( $attribute_text );
		$attributes = array();

		foreach ( $parsed as $key => $value ) {
			if ( is_string( $key ) ) {
				$attributes[ strtolower( $key ) ] = trim( (string) $value );
			}
		}

		return $attributes;
	}

	/**
	 * Builds a match from shortcode attributes, or null if it doesn't render a form.
	 *
	 * @param array<string, string> $attributes Parsed attributes.
	 * @param string                $method     Embed method.
	 * @param string                $context    Context.
	 *
	 * @return EmbedMatch|null Match, or null for non-form actions and missing identifiers.
	 */
	private function match_from_attributes( array $attributes, string $method, string $context ): ?EmbedMatch {
		$action = strtolower( $attributes['action'] ?? '' );

		if ( ! in_array( $action, self::FORM_ACTIONS, true ) ) {
			return null;
		}

		$id = $attributes['id'] ?? '';

		if ( '' !== $id ) {
			return new EmbedMatch( ctype_digit( $id ) ? (int) $id : null, $id, $method, $context );
		}

		$name = $attributes['name'] ?? '';

		if ( '' !== $name ) {
			return new EmbedMatch( null, 'name="' . $name . '"', $method, $context );
		}

		return null;
	}
}

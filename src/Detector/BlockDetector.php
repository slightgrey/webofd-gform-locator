<?php
/**
 * Finds Gravity Forms blocks and shortcodes in block markup.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator\Detector;

/**
 * Detects the gravityforms/form block at any nesting depth, plus shortcodes
 * inside any block's HTML (WordPress runs shortcodes across all block output).
 * Classic, non-block content is parsed as one freeform block.
 */
class BlockDetector {

	/**
	 * Block name registered by Gravity Forms.
	 */
	private const FORM_BLOCK = 'gravityforms/form';

	/**
	 * Shortcode detector used for each block's HTML.
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
	 * Finds every form block and shortcode in the content.
	 *
	 * @param string $content Post content or block widget markup.
	 *
	 * @return array<int, EmbedMatch> Unique matches in order of appearance.
	 */
	public function detect( string $content ): array {
		if ( false === stripos( $content, 'gravityform' ) ) {
			return array();
		}

		return EmbedMatch::unique( $this->detect_in_blocks( parse_blocks( $content ) ) );
	}

	/**
	 * Walks a list of parsed blocks depth-first.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 *
	 * @return array<int, EmbedMatch> Matches.
	 */
	private function detect_in_blocks( array $blocks ): array {
		$matches = array();

		foreach ( $blocks as $block ) {
			$matches = array_merge( $matches, $this->detect_in_block( $block ) );
		}

		return $matches;
	}

	/**
	 * Collects matches from one block and its inner blocks.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 *
	 * @return array<int, EmbedMatch> Matches.
	 */
	private function detect_in_block( array $block ): array {
		$name    = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
		$html    = is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '';
		$inner   = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
		$matches = $this->shortcodes->detect( $html, 'shortcode', $name );

		if ( self::FORM_BLOCK === $name ) {
			$form_block = $this->match_form_block( $block );

			if ( null !== $form_block ) {
				$matches[] = $form_block;
			}
		}

		return array_merge( $matches, $this->detect_in_blocks( $inner ) );
	}

	/**
	 * Builds a match from a gravityforms/form block, or null if no form is selected.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 *
	 * @return EmbedMatch|null Match, or null when formId is missing or empty.
	 */
	private function match_form_block( array $block ): ?EmbedMatch {
		$attrs   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$form_id = $attrs['formId'] ?? '';

		if ( ! is_scalar( $form_id ) ) {
			return null;
		}

		$form_ref = trim( (string) $form_id );

		if ( '' === $form_ref ) {
			return null;
		}

		return new EmbedMatch( ctype_digit( $form_ref ) ? (int) $form_ref : null, $form_ref, 'block', self::FORM_BLOCK );
	}
}

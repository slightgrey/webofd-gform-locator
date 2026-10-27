<?php
/**
 * One Gravity Forms embed found by a detector.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator\Detector;

/**
 * Immutable description of a single embed: which form, how it is embedded, and where.
 */
final class EmbedMatch {

	/**
	 * Numeric form ID, or null when the embed only names the form.
	 *
	 * @var int|null
	 */
	private ?int $form_id;

	/**
	 * Identifier text as found, e.g. "3" or 'name="Contact"'.
	 *
	 * @var string
	 */
	private string $form_ref;

	/**
	 * Embed method: shortcode, block, elementor_widget, elementor_shortcode, meta or widget.
	 *
	 * @var string
	 */
	private string $embed_method;

	/**
	 * Extra detail: block name, Elementor widget type, meta key or sidebar.
	 *
	 * @var string
	 */
	private string $context;

	/**
	 * Creates a match.
	 *
	 * @param int|null $form_id      Numeric form ID, or null if only a name was given.
	 * @param string   $form_ref     Identifier text as found.
	 * @param string   $embed_method How the form is embedded.
	 * @param string   $context      Block name, widget type, meta key or sidebar.
	 */
	public function __construct( ?int $form_id, string $form_ref, string $embed_method, string $context = '' ) {
		$this->form_id      = $form_id;
		$this->form_ref     = $form_ref;
		$this->embed_method = $embed_method;
		$this->context      = $context;
	}

	/**
	 * Returns the numeric form ID, or null when only a name was given.
	 *
	 * @return int|null Form ID.
	 */
	public function form_id(): ?int {
		return $this->form_id;
	}

	/**
	 * Returns the identifier text as found.
	 *
	 * @return string Form reference.
	 */
	public function form_ref(): string {
		return $this->form_ref;
	}

	/**
	 * Returns the embed method.
	 *
	 * @return string Embed method.
	 */
	public function embed_method(): string {
		return $this->embed_method;
	}

	/**
	 * Returns the context detail.
	 *
	 * @return string Context.
	 */
	public function context(): string {
		return $this->context;
	}

	/**
	 * Returns a copy with a different method and context, keeping the form.
	 *
	 * @param string $embed_method New embed method.
	 * @param string $context      New context.
	 *
	 * @return self New match.
	 */
	public function with_source( string $embed_method, string $context ): self {
		return new self( $this->form_id, $this->form_ref, $embed_method, $context );
	}

	/**
	 * Converts the match into a results-table row for the given location.
	 *
	 * @param string $source_type post or widget.
	 * @param int    $object_id   Post ID, 0 for widgets.
	 * @param string $object_ref  Widget ID or meta key, '' otherwise.
	 * @param string $post_type   Post type, '' for widgets.
	 * @param string $post_status Post status, or active/inactive for widgets.
	 *
	 * @return array{form_id: int|null, form_ref: string, source_type: string, object_id: int, object_ref: string, post_type: string, post_status: string, embed_method: string, context: string} Row.
	 */
	public function to_row( string $source_type, int $object_id, string $object_ref, string $post_type, string $post_status ): array {
		return array(
			'form_id'      => $this->form_id,
			'form_ref'     => $this->form_ref,
			'source_type'  => $source_type,
			'object_id'    => $object_id,
			'object_ref'   => $object_ref,
			'post_type'    => $post_type,
			'post_status'  => $post_status,
			'embed_method' => $this->embed_method,
			'context'      => $this->context,
		);
	}

	/**
	 * Builds the identity key used to collapse duplicate matches.
	 *
	 * @return string Key combining form, method and context.
	 */
	public function key(): string {
		$form = null === $this->form_id ? 'ref:' . $this->form_ref : (string) $this->form_id;

		return $form . '|' . $this->embed_method . '|' . $this->context;
	}

	/**
	 * Removes duplicate matches, keeping the first of each.
	 *
	 * @param array<int, self> $matches Matches.
	 *
	 * @return array<int, self> Unique matches.
	 */
	public static function unique( array $matches ): array {
		$unique = array();

		foreach ( $matches as $match ) {
			$unique[ $match->key() ] = $unique[ $match->key() ] ?? $match;
		}

		return array_values( $unique );
	}
}

<?php
/**
 * Finds and scans posts that may embed a Gravity Forms form.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator\Source;

use GFFormLocator\Detector\BlockDetector;
use GFFormLocator\Detector\ElementorDetector;
use GFFormLocator\Detector\EmbedMatch;
use GFFormLocator\Detector\MetaDetector;
use GFFormLocator\Detector\ShortcodeDetector;
use WP_Post;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off candidate query per scan; WP_Query can't search meta values with LIKE across all keys.

/**
 * Builds the scan queue with cheap LIKE pre-filters, so posts with no
 * possible match are never loaded, then runs the detectors on each post.
 *
 * @phpstan-import-type ResultRow from \GFFormLocator\ResultsRepository
 */
class PostSource {

	/**
	 * Post statuses scanned. Trash, auto-draft and inherit (revisions) are excluded.
	 */
	public const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * Non-public post types that can hold forms, included when registered.
	 */
	private const EXTRA_POST_TYPES = array( 'elementor_library', 'wp_block', 'wp_template', 'wp_template_part' );

	/**
	 * Detector for post_content.
	 *
	 * @var BlockDetector
	 */
	private BlockDetector $blocks;

	/**
	 * Detector for _elementor_data.
	 *
	 * @var ElementorDetector
	 */
	private ElementorDetector $elementor;

	/**
	 * Detector for other meta values.
	 *
	 * @var MetaDetector
	 */
	private MetaDetector $meta;

	/**
	 * Creates the source.
	 *
	 * @param BlockDetector     $blocks    Content detector.
	 * @param ElementorDetector $elementor Elementor detector.
	 * @param MetaDetector      $meta      Meta detector.
	 */
	public function __construct( BlockDetector $blocks, ElementorDetector $elementor, MetaDetector $meta ) {
		$this->blocks    = $blocks;
		$this->elementor = $elementor;
		$this->meta      = $meta;
	}

	/**
	 * Creates the source with the default detectors.
	 *
	 * @return self Post source.
	 */
	public static function create(): self {
		$shortcodes = new ShortcodeDetector();

		return new self( new BlockDetector( $shortcodes ), new ElementorDetector( $shortcodes ), new MetaDetector( $shortcodes ) );
	}

	/**
	 * Runs every detector on one post and returns its result rows.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array<int, array<string, mixed>> Result rows; empty if the post is gone.
	 * @phpstan-return array<int, ResultRow>
	 */
	public function scan_post( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return array();
		}

		$meta = $this->raw_meta( $post_id );
		$rows = array();

		foreach ( $this->content_matches( $post, $meta ) as $match ) {
			$rows[] = $this->post_row( $match, $post, '' );
		}

		foreach ( $this->elementor_matches( $meta ) as $match ) {
			$rows[] = $this->post_row( $match, $post, '' );
		}

		foreach ( $this->meta_matches( $meta ) as $meta_key => $matches ) {
			foreach ( $matches as $match ) {
				$rows[] = $this->post_row( $match, $post, (string) $meta_key );
			}
		}

		return $rows;
	}

	/**
	 * Loads every meta value for a post, unserialized values left raw.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array<string, array<int, string>> Meta key => raw values.
	 */
	private function raw_meta( int $post_id ): array {
		$meta = get_post_meta( $post_id );

		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Tells whether a post is built with Elementor (and so rendered from _elementor_data).
	 *
	 * @param array<string, array<int, string>> $meta Raw post meta.
	 *
	 * @return bool True for Elementor-built posts.
	 */
	private function is_elementor_built( array $meta ): bool {
		return 'builder' === ( $meta['_elementor_edit_mode'][0] ?? '' );
	}

	/**
	 * Detects embeds in post_content, except on Elementor-built posts.
	 *
	 * Elementor writes a plain-text copy of its widgets (shortcodes included)
	 * into post_content as a fallback. That copy isn't what renders, and
	 * scanning it would report every Elementor embed a second time.
	 *
	 * @param WP_Post                           $post Post.
	 * @param array<string, array<int, string>> $meta Raw post meta.
	 *
	 * @return array<int, EmbedMatch> Matches.
	 */
	private function content_matches( WP_Post $post, array $meta ): array {
		if ( $this->is_elementor_built( $meta ) ) {
			return array();
		}

		return $this->blocks->detect( $post->post_content );
	}

	/**
	 * Detects Elementor embeds, but only for pages Elementor actually renders.
	 *
	 * @param array<string, array<int, string>> $meta Raw post meta.
	 *
	 * @return array<int, EmbedMatch> Matches.
	 */
	private function elementor_matches( array $meta ): array {
		if ( ! $this->is_elementor_built( $meta ) ) {
			return array();
		}

		return $this->elementor->detect( $meta['_elementor_data'][0] ?? '' );
	}

	/**
	 * Detects shortcodes in every non-skipped meta value.
	 *
	 * @param array<string, array<int, string>> $meta Raw post meta.
	 *
	 * @return array<string, array<int, EmbedMatch>> Meta key => matches.
	 */
	private function meta_matches( array $meta ): array {
		$found = array();

		foreach ( $meta as $meta_key => $values ) {
			foreach ( $values as $value ) {
				$matches = $this->meta->detect( (string) $meta_key, $value );

				if ( array() !== $matches ) {
					$found[ (string) $meta_key ] = array_merge( $found[ (string) $meta_key ] ?? array(), $matches );
				}
			}
		}

		return $found;
	}

	/**
	 * Builds a post result row.
	 *
	 * @param EmbedMatch $embed      Match.
	 * @param WP_Post    $post       Post.
	 * @param string     $object_ref Meta key for meta matches, '' otherwise.
	 *
	 * @return array<string, mixed> Row.
	 * @phpstan-return ResultRow
	 */
	private function post_row( EmbedMatch $embed, WP_Post $post, string $object_ref ): array {
		return $embed->to_row( 'post', (int) $post->ID, $object_ref, $post->post_type, $post->post_status );
	}

	/**
	 * Lists the post types to scan.
	 *
	 * @return array<int, string> Post type names.
	 */
	public function post_types(): array {
		$types = array_values( get_post_types( array( 'public' => true ) ) );

		foreach ( self::EXTRA_POST_TYPES as $extra ) {
			if ( post_type_exists( $extra ) ) {
				$types[] = $extra;
			}
		}

		$types = apply_filters( 'gffl_post_types', array_values( array_diff( array_unique( $types ), array( 'attachment' ) ) ) );

		return array_values( array_filter( (array) $types, 'is_string' ) );
	}

	/**
	 * Returns IDs of posts whose content or meta might contain an embed.
	 *
	 * @return array<int, int> Post IDs, ascending.
	 */
	public function candidate_ids(): array {
		$types = $this->post_types();

		if ( array() === $types ) {
			return array();
		}

		$ids = array_merge( $this->content_candidates( $types ), $this->meta_candidates( $types ) );
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids );

		return $ids;
	}

	/**
	 * Finds posts whose content mentions a shortcode or the form block.
	 *
	 * @param array<int, string> $types Post types.
	 *
	 * @return array<int, string> Post IDs.
	 */
	private function content_candidates( array $types ): array {
		global $wpdb;

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type IN (" . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ')
				AND post_status IN (' . implode( ', ', array_fill( 0, count( self::STATUSES ), '%s' ) ) . ')
				AND ( post_content LIKE %s OR post_content LIKE %s )',
				array_merge(
					$types,
					self::STATUSES,
					array( '%' . $wpdb->esc_like( '[gravityform' ) . '%', '%' . $wpdb->esc_like( 'wp:gravityforms/' ) . '%' )
				)
			)
		);
	}

	/**
	 * Finds posts with any meta value mentioning "gravity" (shortcodes, Elementor widgets).
	 *
	 * @param array<int, string> $types Post types.
	 *
	 * @return array<int, string> Post IDs.
	 */
	private function meta_candidates( array $types ): array {
		global $wpdb;

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type IN (" . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ')
				AND p.post_status IN (' . implode( ', ', array_fill( 0, count( self::STATUSES ), '%s' ) ) . ')
				AND pm.meta_value LIKE %s',
				array_merge( $types, self::STATUSES, array( '%' . $wpdb->esc_like( 'gravity' ) . '%' ) )
			)
		);
	}
}

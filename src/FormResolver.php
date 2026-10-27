<?php
/**
 * Maps form IDs to titles and status.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Read-only lookups on Gravity Forms' own tables when GF isn't loaded; results are cached per request. Table names are internal.

/**
 * Resolves form IDs using, in order: GFAPI, the gf_form table, the legacy
 * rg_form table. Works whether Gravity Forms is active, deactivated with its
 * tables left behind, or was never installed.
 *
 * @phpstan-type FormInfo array{title: string, status: string}
 */
class FormResolver {

	public const STATUS_ACTIVE   = 'active';
	public const STATUS_INACTIVE = 'inactive';
	public const STATUS_TRASHED  = 'trashed';
	public const STATUS_MISSING  = 'missing';
	public const STATUS_UNKNOWN  = 'unknown';

	/**
	 * Gravity Forms table suffixes, newest first.
	 */
	private const TABLE_SUFFIXES = array( 'gf_form', 'rg_form' );

	/**
	 * Resolved forms keyed by ID.
	 *
	 * @var array<int, array{title: string, status: string}>
	 */
	private array $cache = array();

	/**
	 * Detected forms table, '' if none, null until checked.
	 *
	 * @var string|null
	 */
	private ?string $table = null;

	/**
	 * Tells whether any source of form data is available.
	 *
	 * @return bool True if GFAPI is loaded or a forms table exists.
	 */
	public function has_source(): bool {
		return $this->has_gfapi() || '' !== $this->forms_table();
	}

	/**
	 * Returns a form's title and status.
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return array{title: string, status: string} Title ('' if unknown) and a STATUS_* value.
	 */
	public function resolve( int $form_id ): array {
		if ( ! isset( $this->cache[ $form_id ] ) ) {
			$this->preload( array( $form_id ) );
		}

		return $this->cache[ $form_id ];
	}

	/**
	 * Resolves many forms at once so a list page needs one query.
	 *
	 * @param array<int, int> $form_ids Form IDs.
	 *
	 * @return void
	 */
	public function preload( array $form_ids ): void {
		$pending = array_values( array_diff( array_unique( array_map( 'intval', $form_ids ) ), array_keys( $this->cache ) ) );

		if ( array() === $pending ) {
			return;
		}

		$found = $this->lookup( $pending );

		foreach ( $pending as $form_id ) {
			$this->cache[ $form_id ] = $found[ $form_id ] ?? $this->not_found();
		}
	}

	/**
	 * Looks forms up in the best available source.
	 *
	 * @param array<int, int> $form_ids Form IDs not yet cached.
	 *
	 * @return array<int, array{title: string, status: string}> Forms that were found, keyed by ID.
	 */
	private function lookup( array $form_ids ): array {
		if ( $this->has_gfapi() ) {
			return $this->lookup_gfapi( $form_ids );
		}

		if ( '' !== $this->forms_table() ) {
			return $this->lookup_table( $form_ids );
		}

		return array();
	}

	/**
	 * Result for an ID that isn't in the source: missing if a source exists, else unknown.
	 *
	 * @return array{title: string, status: string} Form info.
	 */
	private function not_found(): array {
		return array(
			'title'  => '',
			'status' => $this->has_source() ? self::STATUS_MISSING : self::STATUS_UNKNOWN,
		);
	}

	/**
	 * Tells whether the Gravity Forms API is loaded.
	 *
	 * @return bool True if GFAPI exists.
	 */
	private function has_gfapi(): bool {
		return class_exists( 'GFAPI' );
	}

	/**
	 * Looks forms up through GFAPI::get_form().
	 *
	 * @param array<int, int> $form_ids Form IDs.
	 *
	 * @return array<int, array{title: string, status: string}> Found forms.
	 */
	private function lookup_gfapi( array $form_ids ): array {
		$found = array();

		foreach ( $form_ids as $form_id ) {
			$form = call_user_func( array( 'GFAPI', 'get_form' ), $form_id );

			if ( is_array( $form ) ) {
				$found[ $form_id ] = $this->info_from_row( $form );
			}
		}

		return $found;
	}

	/**
	 * Looks forms up with one query on the forms table.
	 *
	 * @param array<int, int> $form_ids Form IDs.
	 *
	 * @return array<int, array{title: string, status: string}> Found forms.
	 */
	private function lookup_table( array $form_ids ): array {
		global $wpdb;

		$table = $this->forms_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id IN (" . implode( ', ', array_fill( 0, count( $form_ids ), '%d' ) ) . ')',
				$form_ids
			),
			ARRAY_A
		);
		$found = array();

		foreach ( (array) $rows as $row ) {
			$found[ (int) $row['id'] ] = $this->info_from_row( $row );
		}

		return $found;
	}

	/**
	 * Converts a GF form array or table row into title + status.
	 *
	 * @param array<string, mixed> $row Form data with title, is_active and (GF 1.9+) is_trash.
	 *
	 * @return array{title: string, status: string} Form info.
	 */
	private function info_from_row( array $row ): array {
		$status = self::STATUS_ACTIVE;

		if ( ! empty( $row['is_trash'] ) ) {
			$status = self::STATUS_TRASHED;
		} elseif ( empty( $row['is_active'] ) ) {
			$status = self::STATUS_INACTIVE;
		}

		return array(
			'title'  => is_scalar( $row['title'] ?? null ) ? (string) $row['title'] : '',
			'status' => $status,
		);
	}

	/**
	 * Finds the Gravity Forms forms table for the current site, checked once.
	 *
	 * @return string Table name, or '' if none exists.
	 */
	private function forms_table(): string {
		if ( null !== $this->table ) {
			return $this->table;
		}

		global $wpdb;

		$this->table = '';

		foreach ( self::TABLE_SUFFIXES as $suffix ) {
			$candidate = $wpdb->prefix . $suffix;

			if ( $candidate === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $candidate ) ) ) ) {
				$this->table = $candidate;
				break;
			}
		}

		return $this->table;
	}
}

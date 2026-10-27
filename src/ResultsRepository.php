<?php
/**
 * Reads and writes rows in the gffl_results table.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Custom table with no WP API. Table names are internal; every user value goes through prepare() or an allow-list.

/**
 * All SQL for scan results lives here.
 *
 * @phpstan-type ResultRow array{form_id: int|null, form_ref: string, source_type: string, object_id: int, object_ref: string, post_type: string, post_status: string, embed_method: string, context: string}
 * @phpstan-type QueryArgs array{form_id?: int|null, post_type?: string, source_type?: string, embed_method?: string, search?: string, orderby?: string, order?: string, per_page?: int, paged?: int}
 */
class ResultsRepository {

	/**
	 * Rows inserted per INSERT statement.
	 */
	private const INSERT_CHUNK = 100;

	/**
	 * Sortable keys mapped to SQL columns.
	 */
	private const ORDERBY_COLUMNS = array(
		'form'     => 'r.form_id',
		'location' => 'p.post_title',
		'type'     => 'r.post_type',
		'status'   => 'r.post_status',
		'method'   => 'r.embed_method',
	);

	/**
	 * Deletes every stored result.
	 *
	 * @return void
	 */
	public function truncate(): void {
		global $wpdb;

		$table = Installer::table_name();
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Inserts result rows, skipping duplicates within the given set.
	 *
	 * @param array<int, ResultRow> $rows Rows to insert.
	 *
	 * @return int Number of rows inserted.
	 */
	public function insert_many( array $rows ): int {
		$rows     = $this->dedupe( $rows );
		$inserted = 0;

		foreach ( array_chunk( $rows, self::INSERT_CHUNK ) as $chunk ) {
			$inserted += $this->insert_chunk( $chunk );
		}

		return $inserted;
	}

	/**
	 * Returns one page of results with the location title joined in.
	 *
	 * @param array $args Filters, search, sorting and pagination.
	 * @phpstan-param QueryArgs $args
	 *
	 * @return array<int, array<string, mixed>> Result rows plus post_title.
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$offset   = ( max( 1, (int) ( $args['paged'] ?? 1 ) ) - 1 ) * $per_page;
		$sql      = $this->select_sql( 'r.*, p.post_title', $args )
			. ' ORDER BY ' . $this->order_clause( $args )
			. $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, $offset );

		return (array) $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Counts results matching the filters.
	 *
	 * @param array $args Filters and search.
	 * @phpstan-param QueryArgs $args
	 *
	 * @return int Matching row count.
	 */
	public function count( array $args = array() ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $this->select_sql( 'COUNT(*)', $args ) );
	}

	/**
	 * Lists every form ID found in the last scan, ascending.
	 *
	 * @return array<int, int> Form IDs.
	 */
	public function distinct_form_ids(): array {
		global $wpdb;

		$table = Installer::table_name();
		$ids   = $wpdb->get_col( "SELECT DISTINCT form_id FROM {$table} WHERE form_id IS NOT NULL ORDER BY form_id ASC" );

		return array_map( 'intval', $ids );
	}

	/**
	 * Counts result rows per form ID.
	 *
	 * @return array<int, int> Form ID => number of embeds.
	 */
	public function count_by_form(): array {
		global $wpdb;

		$table  = Installer::table_name();
		$rows   = $wpdb->get_results( "SELECT form_id, COUNT(*) AS embeds FROM {$table} WHERE form_id IS NOT NULL GROUP BY form_id", ARRAY_A );
		$counts = array();

		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['form_id'] ] = (int) $row['embeds'];
		}

		return $counts;
	}

	/**
	 * Counts embeds whose form no longer exists.
	 *
	 * @param FormResolver $forms Form lookup for the current site.
	 *
	 * @return int Embeds pointing to missing forms.
	 */
	public function count_missing( FormResolver $forms ): int {
		$counts = $this->count_by_form();
		$forms->preload( array_keys( $counts ) );
		$missing = 0;

		foreach ( $counts as $form_id => $embeds ) {
			if ( FormResolver::STATUS_MISSING === $forms->resolve( $form_id )['status'] ) {
				$missing += $embeds;
			}
		}

		return $missing;
	}

	/**
	 * Lists every post type found in the last scan, ascending.
	 *
	 * @return array<int, string> Post type names.
	 */
	public function distinct_post_types(): array {
		global $wpdb;

		$table = Installer::table_name();

		return array_map( 'strval', $wpdb->get_col( "SELECT DISTINCT post_type FROM {$table} WHERE post_type <> '' ORDER BY post_type ASC" ) );
	}

	/**
	 * Summarises the stored results.
	 *
	 * @return array{forms: int, locations: int, rows: int} Distinct forms, distinct locations and total rows.
	 */
	public function summary(): array {
		global $wpdb;

		$table = Installer::table_name();
		$row   = $wpdb->get_row(
			"SELECT COUNT(DISTINCT form_id) AS forms,
				COUNT(DISTINCT source_type, object_id, CASE WHEN source_type = 'widget' THEN object_ref ELSE '' END) AS locations,
				COUNT(*) AS total
			FROM {$table}",
			ARRAY_A
		);

		return array(
			'forms'     => (int) ( $row['forms'] ?? 0 ),
			'locations' => (int) ( $row['locations'] ?? 0 ),
			'rows'      => (int) ( $row['total'] ?? 0 ),
		);
	}

	/**
	 * Removes rows that describe the same embed twice.
	 *
	 * @param array<int, ResultRow> $rows Candidate rows.
	 *
	 * @return array<int, ResultRow> Unique rows, original order kept.
	 */
	private function dedupe( array $rows ): array {
		$unique = array();

		foreach ( $rows as $row ) {
			$unique[ $this->row_key( $row ) ] = $row;
		}

		return array_values( $unique );
	}

	/**
	 * Builds the identity key for a row: location + form + method + context.
	 *
	 * @param array $row Result row.
	 * @phpstan-param ResultRow $row
	 *
	 * @return string Identity key.
	 */
	private function row_key( array $row ): string {
		return implode(
			'|',
			array(
				$row['source_type'],
				$row['object_id'],
				$row['object_ref'],
				null === $row['form_id'] ? 'ref:' . $row['form_ref'] : $row['form_id'],
				$row['embed_method'],
				$row['context'],
			)
		);
	}

	/**
	 * Inserts up to INSERT_CHUNK rows in one statement.
	 *
	 * @param array<int, ResultRow> $rows Rows to insert.
	 *
	 * @return int Number of rows inserted.
	 */
	private function insert_chunk( array $rows ): int {
		global $wpdb;

		if ( array() === $rows ) {
			return 0;
		}

		$table      = Installer::table_name();
		$scanned_at = current_time( 'mysql', true );
		$values     = array();

		foreach ( $rows as $row ) {
			$values[] = $this->row_values_sql( $row, $scanned_at );
		}

		$sql = "INSERT INTO {$table}
			(form_id, form_ref, source_type, object_id, object_ref, post_type, post_status, embed_method, context, scanned_at)
			VALUES " . implode( ', ', $values );

		return (int) $wpdb->query( $sql );
	}

	/**
	 * Builds one prepared "(...)" VALUES tuple, writing NULL for a missing form ID.
	 *
	 * @param array  $row        Result row.
	 * @phpstan-param ResultRow $row
	 * @param string $scanned_at GMT timestamp.
	 *
	 * @return string SQL tuple.
	 */
	private function row_values_sql( array $row, string $scanned_at ): string {
		global $wpdb;

		$form_id = null === $row['form_id'] ? 'NULL' : (string) absint( $row['form_id'] );

		return '(' . $form_id . ', ' . $wpdb->prepare(
			'%s, %s, %d, %s, %s, %s, %s, %s, %s',
			$row['form_ref'],
			$row['source_type'],
			$row['object_id'],
			$row['object_ref'],
			$row['post_type'],
			$row['post_status'],
			$row['embed_method'],
			$row['context'],
			$scanned_at
		) . ')';
	}

	/**
	 * Builds "SELECT {columns} FROM results LEFT JOIN posts WHERE ...".
	 *
	 * @param string $columns Column list (trusted, internal).
	 * @param array  $args    Filters and search.
	 * @phpstan-param QueryArgs $args
	 *
	 * @return string SQL without ORDER BY / LIMIT.
	 */
	private function select_sql( string $columns, array $args ): string {
		global $wpdb;

		$table = Installer::table_name();

		return "SELECT {$columns} FROM {$table} r
			LEFT JOIN {$wpdb->posts} p ON p.ID = r.object_id AND r.source_type = 'post'
			WHERE " . $this->where_clause( $args );
	}

	/**
	 * Builds the prepared WHERE clause from filters and search.
	 *
	 * @param array $args Filters and search.
	 * @phpstan-param QueryArgs $args
	 *
	 * @return string SQL conditions joined with AND.
	 */
	private function where_clause( array $args ): string {
		global $wpdb;

		$conditions = array( '1=1' );

		if ( ! empty( $args['form_id'] ) ) {
			$conditions[] = $wpdb->prepare( 'r.form_id = %d', $args['form_id'] );
		}

		if ( ! empty( $args['post_type'] ) ) {
			$conditions[] = $wpdb->prepare( 'r.post_type = %s', $args['post_type'] );
		}

		if ( ! empty( $args['source_type'] ) ) {
			$conditions[] = $wpdb->prepare( 'r.source_type = %s', $args['source_type'] );
		}

		if ( ! empty( $args['embed_method'] ) ) {
			$conditions[] = $wpdb->prepare( 'r.embed_method = %s', $args['embed_method'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$like         = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$conditions[] = $wpdb->prepare( '( p.post_title LIKE %s OR r.object_ref LIKE %s )', $like, $like );
		}

		return implode( ' AND ', $conditions );
	}

	/**
	 * Builds a safe ORDER BY clause from an allow-list.
	 *
	 * @param array $args Sorting args.
	 * @phpstan-param QueryArgs $args
	 *
	 * @return string SQL ORDER BY expression.
	 */
	private function order_clause( array $args ): string {
		$column    = self::ORDERBY_COLUMNS[ $args['orderby'] ?? '' ] ?? 'r.form_id';
		$direction = 'desc' === strtolower( (string) ( $args['order'] ?? '' ) ) ? 'DESC' : 'ASC';

		return "{$column} {$direction}, r.id ASC";
	}
}

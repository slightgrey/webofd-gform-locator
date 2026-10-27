<?php
/**
 * The list of embeds on the Tools page.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator\Admin;

use GFFormLocator\FormResolver;
use GFFormLocator\ResultsRepository;
use WP_List_Table;

/**
 * WP_List_Table of scan results: one row per embed.
 *
 * Load wp-admin/includes/class-wp-list-table.php before referencing this class
 * in any way (including its constants): autoloading it earlier is a fatal error.
 */
class ResultsTable extends WP_List_Table {

	/**
	 * Results storage.
	 *
	 * @var ResultsRepository
	 */
	private ResultsRepository $results;

	/**
	 * Form title/status lookup.
	 *
	 * @var FormResolver
	 */
	private FormResolver $forms;

	/**
	 * Whether a scan has completed (changes the empty-table message).
	 *
	 * @var bool
	 */
	private bool $has_scan;

	/**
	 * Creates the table.
	 *
	 * @param ResultsRepository $results  Results storage.
	 * @param FormResolver      $forms    Form lookup.
	 * @param bool              $has_scan Whether a scan has completed.
	 */
	public function __construct( ResultsRepository $results, FormResolver $forms, bool $has_scan ) {
		$this->results  = $results;
		$this->forms    = $forms;
		$this->has_scan = $has_scan;

		parent::__construct(
			array(
				'singular' => 'gffl_embed',
				'plural'   => 'gffl_embeds',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Column headers.
	 *
	 * @return array<string, string> Column key => label.
	 */
	public function get_columns(): array {
		return array(
			'form'     => __( 'Form', 'webofd-form-locator' ),
			'location' => __( 'Location', 'webofd-form-locator' ),
			'type'     => __( 'Type', 'webofd-form-locator' ),
			'status'   => __( 'Status', 'webofd-form-locator' ),
			'method'   => __( 'Embed method', 'webofd-form-locator' ),
		);
	}

	/**
	 * Sortable columns, keyed to ResultsRepository orderby values.
	 *
	 * @return array<string, array{0: string, 1: bool}> Column => [orderby, initially descending].
	 */
	protected function get_sortable_columns(): array {
		return array(
			'form'     => array( 'form', false ),
			'location' => array( 'location', false ),
			'type'     => array( 'type', false ),
			'status'   => array( 'status', false ),
			'method'   => array( 'method', false ),
		);
	}

	/**
	 * Loads the current page of rows and the pagination totals.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( ReportPage::PER_PAGE_OPTION, 20 );
		$args     = $this->query_args();
		$paging   = array(
			'per_page' => $per_page,
			'paged'    => $this->get_pagenum(),
		);

		$this->items           = $this->results->query( $args + $paging );
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'location' );

		$this->set_pagination_args(
			array(
				'total_items' => $this->results->count( $args ),
				'per_page'    => $per_page,
			)
		);

		$this->forms->preload( array_filter( array_map( 'intval', wp_list_pluck( $this->items, 'form_id' ) ) ) );
	}

	/**
	 * Builds repository filter/sort args from the request.
	 *
	 * @return array<string, mixed> Query args.
	 */
	protected function query_args(): array {
		$type   = sanitize_key( $this->request_value( 'gffl_type' ) );
		$method = sanitize_key( $this->request_value( 'gffl_method' ) );
		$args   = array(
			'form_id' => absint( $this->request_value( 'gffl_form' ) ),
			'search'  => sanitize_text_field( $this->request_value( 's' ) ),
			'orderby' => sanitize_key( $this->request_value( 'orderby' ) ),
			'order'   => sanitize_key( $this->request_value( 'order' ) ),
		);

		if ( 'widget' === $type ) {
			$args['source_type'] = 'widget';
		} elseif ( '' !== $type ) {
			$args['post_type'] = $type;
		}

		if ( isset( self::method_labels()[ $method ] ) ) {
			$args['embed_method'] = $method;
		}

		return $args;
	}

	/**
	 * Renders the Form, Type and Embed method filters above the table.
	 *
	 * @param string $which top or bottom.
	 *
	 * @return void
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		echo '<div class="alignleft actions">';
		$this->render_select( 'gffl_form', __( 'Filter by form', 'webofd-form-locator' ), __( 'All forms', 'webofd-form-locator' ), $this->form_options() );
		$this->render_select( 'gffl_type', __( 'Filter by type', 'webofd-form-locator' ), __( 'All types', 'webofd-form-locator' ), $this->type_options() );
		$this->render_select( 'gffl_method', __( 'Filter by embed method', 'webofd-form-locator' ), __( 'All embed methods', 'webofd-form-locator' ), self::method_labels() );
		submit_button( __( 'Filter', 'webofd-form-locator' ), '', 'filter_action', false, array( 'id' => 'gffl-filter-submit' ) );
		echo '</div>';
	}

	/**
	 * Renders one labelled filter dropdown, keeping the current selection.
	 *
	 * @param string                    $name    Query var.
	 * @param string                    $label   Screen-reader label.
	 * @param string                    $all     Label for the "no filter" option.
	 * @param array<int|string, string> $options Value => label (numeric keys become ints in PHP).
	 *
	 * @return void
	 */
	private function render_select( string $name, string $label, string $all, array $options ): void {
		$current = sanitize_key( $this->request_value( $name ) );

		printf( '<label class="screen-reader-text" for="%1$s">%2$s</label>', esc_attr( $name ), esc_html( $label ) );
		printf( '<select name="%1$s" id="%1$s"><option value="">%2$s</option>', esc_attr( $name ), esc_html( $all ) );

		foreach ( $options as $value => $option_label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $value ), selected( $current, (string) $value, false ), esc_html( $option_label ) );
		}

		echo '</select>';
	}

	/**
	 * Builds the Form dropdown from forms found in the last scan.
	 *
	 * @return array<int, string> Form ID => "#ID – Title".
	 */
	private function form_options(): array {
		$ids = $this->results->distinct_form_ids();
		$this->forms->preload( $ids );
		$options = array();

		foreach ( $ids as $form_id ) {
			$title               = $this->forms->resolve( $form_id )['title'];
			$options[ $form_id ] = '' === $title ? '#' . $form_id : sprintf( '#%1$d – %2$s', $form_id, $title );
		}

		return $options;
	}

	/**
	 * Builds the Type dropdown: post types found, plus Widgets.
	 *
	 * @return array<string, string> Value => label.
	 */
	private function type_options(): array {
		$options = array();

		foreach ( $this->results->distinct_post_types() as $post_type ) {
			$object                = get_post_type_object( $post_type );
			$options[ $post_type ] = $object ? (string) $object->labels->singular_name : $post_type;
		}

		$options['widget'] = __( 'Widgets', 'webofd-form-locator' );

		return $options;
	}

	/**
	 * Reads one unslashed query-string value.
	 *
	 * @param string $key Query var.
	 *
	 * @return string Raw (unsanitized) value, '' if absent.
	 */
	protected function request_value( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filtering/sorting via GET; callers sanitize.
		$value = isset( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : '';

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Message for an empty table.
	 *
	 * @return void
	 */
	public function no_items(): void {
		if ( ! $this->has_scan ) {
			esc_html_e( 'No scan yet: click Scan now.', 'webofd-form-locator' );
			return;
		}

		if ( 0 === $this->results->count() ) {
			esc_html_e( 'Scan complete: no Gravity Forms embeds found.', 'webofd-form-locator' );
			return;
		}

		esc_html_e( 'No embeds match these filters.', 'webofd-form-locator' );
	}

	/**
	 * Form column: "#ID – Title" plus a status badge.
	 *
	 * @param array<string, mixed> $item Result row.
	 *
	 * @return string HTML.
	 */
	protected function column_form( array $item ): string {
		if ( null === $item['form_id'] ) {
			return sprintf(
				'<strong>%1$s</strong> <span class="gffl-muted">%2$s</span>',
				esc_html( (string) $item['form_ref'] ),
				esc_html__( '(name only)', 'webofd-form-locator' )
			);
		}

		$form_id = (int) $item['form_id'];
		$info    = $this->forms->resolve( $form_id );
		$label   = '' === $info['title'] ? '#' . $form_id : sprintf( '#%1$d – %2$s', $form_id, $info['title'] );

		return '<strong>' . esc_html( $label ) . '</strong> ' . $this->status_badge( $info['status'] );
	}

	/**
	 * Builds the form status badge; nothing when status is unknown.
	 *
	 * @param string $status FormResolver STATUS_* value.
	 *
	 * @return string HTML.
	 */
	private function status_badge( string $status ): string {
		$labels = array(
			FormResolver::STATUS_ACTIVE   => __( 'Active', 'webofd-form-locator' ),
			FormResolver::STATUS_INACTIVE => __( 'Inactive', 'webofd-form-locator' ),
			FormResolver::STATUS_TRASHED  => __( 'Trashed', 'webofd-form-locator' ),
			FormResolver::STATUS_MISSING  => __( 'Missing', 'webofd-form-locator' ),
		);

		if ( ! isset( $labels[ $status ] ) ) {
			return '';
		}

		return sprintf( '<span class="gffl-badge gffl-badge--%1$s">%2$s</span>', esc_attr( $status ), esc_html( $labels[ $status ] ) );
	}

	/**
	 * Location column: title with Edit/View links, or the widget and its sidebar.
	 *
	 * @param array<string, mixed> $item Result row.
	 *
	 * @return string HTML.
	 */
	protected function column_location( array $item ): string {
		if ( 'widget' === $item['source_type'] ) {
			return $this->widget_location( $item );
		}

		return $this->post_location( $item );
	}

	/**
	 * Location cell for a post.
	 *
	 * @param array<string, mixed> $item Result row.
	 *
	 * @return string HTML.
	 */
	private function post_location( array $item ): string {
		$post_id = (int) $item['object_id'];

		if ( null === $item['post_title'] ) {
			return '<em>' . esc_html__( '(deleted since the last scan)', 'webofd-form-locator' ) . '</em>';
		}

		$title = '' === $item['post_title'] ? __( '(no title)', 'webofd-form-locator' ) : (string) $item['post_title'];
		$edit  = get_edit_post_link( $post_id );
		$label = $edit ? sprintf( '<a class="row-title" href="%1$s">%2$s</a>', esc_url( $edit ), esc_html( $title ) ) : esc_html( $title );

		return '<strong>' . $label . '</strong>' . $this->row_actions( $this->post_actions( $item, $edit ) );
	}

	/**
	 * Builds Edit / Edit with Elementor / View / Preview links for a post.
	 *
	 * @param array<string, mixed> $item Result row.
	 * @param string|null          $edit Edit URL, or null if the user can't edit.
	 *
	 * @return array<string, string> Action key => link HTML.
	 */
	private function post_actions( array $item, ?string $edit ): array {
		$post_id = (int) $item['object_id'];
		$actions = array();

		if ( $edit ) {
			$actions['edit'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $edit ), esc_html__( 'Edit', 'webofd-form-locator' ) );
		}

		if ( $edit && 0 === strpos( (string) $item['embed_method'], 'elementor_' ) ) {
			$actions['elementor'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( admin_url( 'post.php?post=' . $post_id . '&action=elementor' ) ), esc_html__( 'Edit with Elementor', 'webofd-form-locator' ) );
		}

		$view = $this->view_link( $post_id, (string) $item['post_type'], (string) $item['post_status'] );

		if ( '' !== $view ) {
			$actions['view'] = $view;
		}

		return $actions;
	}

	/**
	 * Builds a View (published) or Preview (unpublished) link for viewable post types.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $post_type   Post type.
	 * @param string $post_status Post status.
	 *
	 * @return string Link HTML, or '' when the post can't be viewed.
	 */
	private function view_link( int $post_id, string $post_type, string $post_status ): string {
		if ( ! is_post_type_viewable( $post_type ) ) {
			return '';
		}

		if ( in_array( $post_status, array( 'publish', 'private' ), true ) ) {
			return sprintf( '<a href="%1$s">%2$s</a>', esc_url( (string) get_permalink( $post_id ) ), esc_html__( 'View', 'webofd-form-locator' ) );
		}

		$preview = get_preview_post_link( $post_id );

		return $preview ? sprintf( '<a href="%1$s">%2$s</a>', esc_url( $preview ), esc_html__( 'Preview', 'webofd-form-locator' ) ) : '';
	}

	/**
	 * Location cell for a widget: widget ID, sidebar name, and a Widgets link.
	 *
	 * @param array<string, mixed> $item Result row.
	 *
	 * @return string HTML.
	 */
	private function widget_location( array $item ): string {
		$html = sprintf(
			'<strong>%1$s</strong><br><span class="gffl-muted">%2$s</span>',
			/* translators: %s: widget ID, e.g. text-3. */
			esc_html( sprintf( __( 'Widget %s', 'webofd-form-locator' ), (string) $item['object_ref'] ) ),
			esc_html( $this->sidebar_name( (string) $item['context'] ) )
		);

		if ( ! current_theme_supports( 'widgets' ) ) {
			return $html;
		}

		$link = sprintf( '<a href="%1$s">%2$s</a>', esc_url( admin_url( 'widgets.php' ) ), esc_html__( 'Edit widgets', 'webofd-form-locator' ) );

		return $html . $this->row_actions( array( 'edit' => $link ) );
	}

	/**
	 * Returns a sidebar's display name.
	 *
	 * @param string $sidebar_id Sidebar ID.
	 *
	 * @return string Name, or a description for inactive/unknown sidebars.
	 */
	private function sidebar_name( string $sidebar_id ): string {
		global $wp_registered_sidebars;

		if ( '' === $sidebar_id || 0 === strpos( $sidebar_id, 'wp_inactive_widgets' ) || 0 === strpos( $sidebar_id, 'orphaned_widgets' ) ) {
			return __( 'Inactive widgets (not displayed)', 'webofd-form-locator' );
		}

		$name = $wp_registered_sidebars[ $sidebar_id ]['name'] ?? '';

		return is_string( $name ) && '' !== $name ? $name : $sidebar_id;
	}

	/**
	 * Type column: post type label, or "Widget".
	 *
	 * @param array<string, mixed> $item Result row.
	 *
	 * @return string HTML.
	 */
	protected function column_type( array $item ): string {
		if ( 'widget' === $item['source_type'] ) {
			return esc_html__( 'Widget', 'webofd-form-locator' );
		}

		$type = get_post_type_object( (string) $item['post_type'] );

		return esc_html( $type ? (string) $type->labels->singular_name : (string) $item['post_type'] );
	}

	/**
	 * Status column: post status label, or Active/Inactive for widgets.
	 *
	 * @param array<string, mixed> $item Result row.
	 *
	 * @return string HTML.
	 */
	protected function column_status( array $item ): string {
		$status = (string) $item['post_status'];

		if ( 'widget' === $item['source_type'] ) {
			return 'active' === $status ? esc_html__( 'Active', 'webofd-form-locator' ) : esc_html__( 'Inactive', 'webofd-form-locator' );
		}

		$object = get_post_status_object( $status );

		return esc_html( $object && is_string( $object->label ) ? $object->label : $status );
	}

	/**
	 * Embed method column: label plus context (block, widget type or meta key).
	 *
	 * @param array<string, mixed> $item Result row.
	 *
	 * @return string HTML.
	 */
	protected function column_method( array $item ): string {
		$method  = (string) $item['embed_method'];
		$labels  = self::method_labels();
		$label   = $labels[ $method ] ?? $method;
		$context = (string) $item['context'];

		if ( '' === $context || 'widget' === $method ) {
			return esc_html( $label );
		}

		return esc_html( $label ) . '<br><code>' . esc_html( $context ) . '</code>';
	}

	/**
	 * Human labels for embed methods.
	 *
	 * @return array<string, string> Method => label.
	 */
	public static function method_labels(): array {
		return array(
			'shortcode'           => __( 'Shortcode', 'webofd-form-locator' ),
			'block'               => __( 'Block', 'webofd-form-locator' ),
			'elementor_widget'    => __( 'Elementor widget', 'webofd-form-locator' ),
			'elementor_shortcode' => __( 'Elementor shortcode', 'webofd-form-locator' ),
			'meta'                => __( 'Custom field', 'webofd-form-locator' ),
			'widget'              => __( 'Widget', 'webofd-form-locator' ),
		);
	}

	/**
	 * Fallback for unknown columns.
	 *
	 * @param array<string, mixed> $item        Result row.
	 * @param string               $column_name Column key.
	 *
	 * @return string Empty string.
	 */
	protected function column_default( $item, $column_name ): string {
		return '';
	}
}

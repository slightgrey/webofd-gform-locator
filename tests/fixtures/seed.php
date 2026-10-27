<?php
/**
 * Seeds (or removes) integration fixtures for Web of D Form Locator.
 *
 * Usage (from anywhere):
 *   php seed.php [--cleanup] [--filler=2000] [--host=plugin.test] [--wp=C:/laragon/www/plugin]
 *
 * Every seeded post carries the _gffl_fixture meta key and every seeded widget
 * uses instance number 9xx, so --cleanup removes exactly what this created.
 *
 * Expected scan result with Elementor active and Gravity Forms absent
 * (form_id | embed_method | context | location):
 *   1  | shortcode           |                   | Fixture: shortcode (page, publish)
 *   2  | block               | gravityforms/form | Fixture: nested block (page, publish)
 *   3  | shortcode           |                   | Fixture: draft (page, draft)
 *   4  | block               | gravityforms/form | Fixture: private post (post, private)
 *   5  | elementor_shortcode | shortcode         | Fixture: Elementor page
 *   6  | elementor_widget    | eael-gravity-form | Fixture: Elementor page
 *   7  | elementor_shortcode | shortcode         | Fixture: Elementor popup (elementor_library)
 *   8  | block               | gravityforms/form | Fixture: reusable block (wp_block)
 *   9  | meta                | page_sections     | Fixture: ACF sections (page)
 *   10 | widget              | <active sidebar>  | text-901 (active)
 *   11 | widget              | wp_inactive_widgets | block-902 (inactive)
 *   1  | shortcode           |                   | each filler post
 * Not reported: trashed page (form 1), escaped [[gravityform id="12"]],
 * stale Elementor data with _elementor_edit_mode off (form 13).
 * Total rows = 11 + filler count.
 *
 * @package GFFormLocator
 */

// phpcs:disable -- CLI test fixture, not shipped in the release zip.

declare( strict_types=1 );

$gffl_args = array(
	'cleanup' => false,
	'filler'  => 2000,
	'host'    => 'plugin.test',
	'wp'      => dirname( __DIR__, 5 ),
);

foreach ( array_slice( $argv, 1 ) as $gffl_arg ) {
	if ( '--cleanup' === $gffl_arg ) {
		$gffl_args['cleanup'] = true;
	} elseif ( preg_match( '/^--(filler|host|wp)=(.+)$/', $gffl_arg, $gffl_m ) ) {
		$gffl_args[ $gffl_m[1] ] = $gffl_m[2];
	}
}

$_SERVER['HTTP_HOST']      = $gffl_args['host'];
$_SERVER['REQUEST_SCHEME'] = 'http';
$_SERVER['REQUEST_URI']    = '/';

require rtrim( (string) $gffl_args['wp'], '/\\' ) . '/wp-load.php';

const GFFL_FIXTURE_META   = '_gffl_fixture';
const GFFL_FIXTURE_TEXT   = 901;
const GFFL_FIXTURE_BLOCK  = 902;

/**
 * Inserts a fixture post.
 *
 * @param string               $title   Title.
 * @param string               $content Content.
 * @param array<string, mixed> $extra   Extra wp_insert_post args (post_type, post_status, meta_input).
 *
 * @return int Post ID.
 */
function gffl_fixture_post( string $title, string $content, array $extra = array() ): int {
	$meta = ( $extra['meta_input'] ?? array() ) + array( GFFL_FIXTURE_META => 1 );
	unset( $extra['meta_input'] );

	$id = wp_insert_post(
		$extra + array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $content,
		)
	);

	foreach ( $meta as $key => $value ) {
		// Raw add so serialized strings are stored exactly as ACF / Elementor store them.
		add_post_meta( (int) $id, $key, wp_slash( $value ) );
	}

	return (int) $id;
}

/**
 * Builds Elementor JSON with the given widgets inside a container.
 *
 * @param array<int, array<string, mixed>> $widgets Widgets (widgetType + settings).
 *
 * @return string JSON.
 */
function gffl_fixture_elementor( array $widgets ): string {
	$elements = array();

	foreach ( $widgets as $i => $widget ) {
		$elements[] = array(
			'id'         => 'w' . $i,
			'elType'     => 'widget',
			'widgetType' => $widget['widgetType'],
			'settings'   => $widget['settings'],
			'elements'   => array(),
		);
	}

	return (string) wp_json_encode(
		array(
			array(
				'id'       => 'c1',
				'elType'   => 'container',
				'settings' => array(),
				'elements' => $elements,
			),
		)
	);
}

/**
 * Seeds all fixtures.
 *
 * @param int $filler Number of filler posts.
 *
 * @return void
 */
function gffl_fixture_seed( int $filler ): void {
	gffl_fixture_post( 'Fixture: shortcode', '[gravityform id="1" title="false"]' );
	gffl_fixture_post(
		'Fixture: nested block',
		'<!-- wp:group --><div class="wp-block-group"><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:gravityforms/form {"formId":"2"} /--></div><!-- /wp:column --></div><!-- /wp:columns --></div><!-- /wp:group -->'
	);
	gffl_fixture_post( 'Fixture: draft', '[gravityform id="3"]', array( 'post_status' => 'draft' ) );
	gffl_fixture_post( 'Fixture: private post', '<!-- wp:gravityforms/form {"formId":"4"} /-->', array( 'post_type' => 'post', 'post_status' => 'private' ) );
	gffl_fixture_post( 'Fixture: trashed', '[gravityform id="1"]', array( 'post_status' => 'trash' ) );
	gffl_fixture_post( 'Fixture: escaped', 'Type [[gravityform id="12"]] to embed a form.' );

	$elementor_page = gffl_fixture_elementor(
		array(
			array( 'widgetType' => 'shortcode', 'settings' => array( 'shortcode' => '[gravityform id="5"]' ) ),
			array( 'widgetType' => 'eael-gravity-form', 'settings' => array( 'contact_form_list' => '6' ) ),
		)
	);
	// post_content holds the plain-text copy Elementor writes on save; it must not be reported again.
	gffl_fixture_post( 'Fixture: Elementor page', '[gravityform id="5"]', array( 'meta_input' => array( '_elementor_edit_mode' => 'builder', '_elementor_data' => $elementor_page ) ) );
	gffl_fixture_post(
		'Fixture: Elementor popup',
		'[gravityform id="7"]',
		array(
			'post_type'  => 'elementor_library',
			'meta_input' => array(
				'_elementor_edit_mode'     => 'builder',
				'_elementor_template_type' => 'popup',
				'_elementor_data'          => gffl_fixture_elementor( array( array( 'widgetType' => 'shortcode', 'settings' => array( 'shortcode' => '[gravityform id="7"]' ) ) ) ),
			),
		)
	);
	gffl_fixture_post(
		'Fixture: stale Elementor data',
		'<p>Plain content.</p>',
		array( 'meta_input' => array( '_elementor_edit_mode' => '', '_elementor_data' => gffl_fixture_elementor( array( array( 'widgetType' => 'shortcode', 'settings' => array( 'shortcode' => '[gravityform id="13"]' ) ) ) ) ) )
	);
	gffl_fixture_post( 'Fixture: reusable block', '<!-- wp:gravityforms/form {"formId":"8"} /-->', array( 'post_type' => 'wp_block' ) );
	gffl_fixture_post(
		'Fixture: ACF sections',
		'<p>Sections live in custom fields.</p>',
		array( 'meta_input' => array( 'page_sections' => serialize( array( array( 'acf_fc_layout' => 'form', 'body' => '<p>[gravityform id="9" ajax="true"]</p>' ) ) ) ) )
	);

	gffl_fixture_widgets();
	gffl_fixture_filler( $filler );
}

/**
 * Adds an active text widget (form 10) and an inactive block widget (form 11).
 *
 * @return void
 */
function gffl_fixture_widgets(): void {
	$text                       = (array) get_option( 'widget_text', array() );
	$text[ GFFL_FIXTURE_TEXT ]  = array( 'title' => 'Fixture', 'text' => '[gravityform id="10"]' );
	$block                      = (array) get_option( 'widget_block', array() );
	$block[ GFFL_FIXTURE_BLOCK ] = array( 'content' => '<!-- wp:gravityforms/form {"formId":"11"} /-->' );
	update_option( 'widget_text', $text );
	update_option( 'widget_block', $block );

	$sidebars = (array) get_option( 'sidebars_widgets', array() );
	$active   = 'sidebar-1';

	foreach ( $sidebars as $id => $widgets ) {
		if ( is_array( $widgets ) && 'wp_inactive_widgets' !== $id ) {
			$active = (string) $id;
			break;
		}
	}

	$sidebars[ $active ][]              = 'text-' . GFFL_FIXTURE_TEXT;
	$sidebars['wp_inactive_widgets'][] = 'block-' . GFFL_FIXTURE_BLOCK;
	update_option( 'sidebars_widgets', $sidebars );
}

/**
 * Inserts filler posts quickly with direct queries; each embeds form 1.
 *
 * @param int $count Number of posts.
 *
 * @return void
 */
function gffl_fixture_filler( int $count ): void {
	global $wpdb;

	$now = current_time( 'mysql' );
	$gmt = current_time( 'mysql', true );

	for ( $i = 1; $i <= $count; $i++ ) {
		$wpdb->insert(
			$wpdb->posts,
			array(
				'post_author'           => 1,
				'post_date'             => $now,
				'post_date_gmt'         => $gmt,
				'post_modified'         => $now,
				'post_modified_gmt'     => $gmt,
				'post_title'            => 'Fixture filler ' . $i,
				'post_name'             => 'fixture-filler-' . $i,
				'post_content'          => '<p>Filler.</p>[gravityform id="1"]',
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'post_type'             => 'post',
				'to_ping'               => '',
				'pinged'                => '',
				'post_content_filtered' => '',
			)
		);
		$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $wpdb->insert_id, 'meta_key' => GFFL_FIXTURE_META, 'meta_value' => '1' ) );
	}
}

/**
 * Removes every fixture post and widget.
 *
 * @return void
 */
function gffl_fixture_cleanup(): void {
	global $wpdb;

	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", GFFL_FIXTURE_META ) );

	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}

	$text  = (array) get_option( 'widget_text', array() );
	$block = (array) get_option( 'widget_block', array() );
	unset( $text[ GFFL_FIXTURE_TEXT ], $block[ GFFL_FIXTURE_BLOCK ] );
	update_option( 'widget_text', $text );
	update_option( 'widget_block', $block );

	$sidebars = (array) get_option( 'sidebars_widgets', array() );

	foreach ( $sidebars as $id => $widgets ) {
		if ( is_array( $widgets ) ) {
			$sidebars[ $id ] = array_values( array_diff( $widgets, array( 'text-' . GFFL_FIXTURE_TEXT, 'block-' . GFFL_FIXTURE_BLOCK ) ) );
		}
	}

	update_option( 'sidebars_widgets', $sidebars );
	echo 'Removed ' . count( $ids ) . " fixture posts and 2 widgets.\n";
}

if ( $gffl_args['cleanup'] ) {
	gffl_fixture_cleanup();
	exit;
}

gffl_fixture_cleanup();
gffl_fixture_seed( (int) $gffl_args['filler'] );
printf( "Seeded 11 fixture posts, 2 widgets and %d filler posts. Expected scan rows: %d.\n", (int) $gffl_args['filler'], 11 + (int) $gffl_args['filler'] );

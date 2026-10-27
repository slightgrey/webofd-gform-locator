<?php
/**
 * Creates or drops a fake Gravity Forms forms table matching the seed.php form IDs.
 *
 * Usage: php fake-gf-tables.php --create|--drop [--host=plugin.test] [--wp=C:/laragon/www/plugin]
 *
 * Forms 1-7 active, 8-9 inactive, 10 trashed, 11 absent (reported as Missing).
 * Columns mirror Gravity Forms' real {prefix}gf_form table.
 *
 * @package GFFormLocator
 */

// phpcs:disable -- CLI test fixture, not shipped in the release zip.

declare( strict_types=1 );

$gffl_args = array(
	'mode' => '',
	'host' => 'plugin.test',
	'wp'   => dirname( __DIR__, 5 ),
);

foreach ( array_slice( $argv, 1 ) as $gffl_arg ) {
	if ( in_array( $gffl_arg, array( '--create', '--drop' ), true ) ) {
		$gffl_args['mode'] = substr( $gffl_arg, 2 );
	} elseif ( preg_match( '/^--(host|wp)=(.+)$/', $gffl_arg, $gffl_m ) ) {
		$gffl_args[ $gffl_m[1] ] = $gffl_m[2];
	}
}

if ( '' === $gffl_args['mode'] ) {
	fwrite( STDERR, "Usage: php fake-gf-tables.php --create|--drop\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']      = $gffl_args['host'];
$_SERVER['REQUEST_SCHEME'] = 'http';
$_SERVER['REQUEST_URI']    = '/';

require rtrim( (string) $gffl_args['wp'], '/\\' ) . '/wp-load.php';

global $wpdb;

$gffl_table = $wpdb->prefix . 'gf_form';

$wpdb->query( "DROP TABLE IF EXISTS {$gffl_table}" );

if ( 'drop' === $gffl_args['mode'] ) {
	echo "Dropped {$gffl_table}.\n";
	exit;
}

$wpdb->query(
	"CREATE TABLE {$gffl_table} (
		id mediumint(10) unsigned NOT NULL AUTO_INCREMENT,
		title varchar(150) NOT NULL,
		date_created datetime NOT NULL,
		date_updated datetime DEFAULT NULL,
		is_active tinyint(10) NOT NULL DEFAULT 1,
		is_trash tinyint(10) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id)
	) {$wpdb->get_charset_collate()}"
);

$gffl_forms = array(
	1  => array( 'Contact', 1, 0 ),
	2  => array( 'Quote request', 1, 0 ),
	3  => array( 'Careers', 1, 0 ),
	4  => array( 'Members only', 1, 0 ),
	5  => array( 'Event signup', 1, 0 ),
	6  => array( 'Callback', 1, 0 ),
	7  => array( 'Popup newsletter', 1, 0 ),
	8  => array( 'Old survey', 0, 0 ),
	9  => array( 'Section form', 0, 0 ),
	10 => array( 'Sidebar signup', 1, 1 ),
);

foreach ( $gffl_forms as $gffl_id => $gffl_form ) {
	$wpdb->insert(
		$gffl_table,
		array(
			'id'           => $gffl_id,
			'title'        => $gffl_form[0],
			'date_created' => current_time( 'mysql', true ),
			'is_active'    => $gffl_form[1],
			'is_trash'     => $gffl_form[2],
		)
	);
}

echo "Created {$gffl_table}: forms 1-7 active, 8-9 inactive, 10 trashed, 11 missing.\n";

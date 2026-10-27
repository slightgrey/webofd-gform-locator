<?php
/**
 * Builds the release zip: webofd-form-locator-v{VERSION}.zip in the plugin root.
 *
 * Usage (from the plugin root): php scripts/build-zip.php
 *
 * The build runs in a temporary staging copy: the shipped files plus
 * composer.json/.lock are copied there and `composer install --no-dev` runs in
 * the copy, so the working vendor/ (and the live dev site using it) is never
 * touched. Set COMPOSER_BIN to override the composer command.
 *
 * @package GFFormLocator
 */

// phpcs:disable -- Build tool, not shipped in the release zip.

declare( strict_types=1 );

const GFFL_BUILD_SLUG    = 'webofd-form-locator';
const GFFL_BUILD_SOURCES = array( 'webofd-form-locator.php', 'uninstall.php', 'composer.json', 'composer.lock', 'src', 'assets', 'languages' );
const GFFL_BUILD_SHIPPED = array( 'webofd-form-locator.php', 'uninstall.php', 'composer.json', 'src', 'assets', 'languages', 'vendor' );

/**
 * Prints a message and stops with a failure code.
 *
 * @param string $message Error.
 *
 * @return void
 */
function gffl_build_fail( string $message ): void {
	fwrite( STDERR, "Build failed: {$message}\n" );
	exit( 1 );
}

/**
 * Reads the plugin version from the main file header.
 *
 * @param string $root Plugin root.
 *
 * @return string Version.
 */
function gffl_build_version( string $root ): string {
	$header = (string) file_get_contents( $root . '/' . GFFL_BUILD_SLUG . '.php', false, null, 0, 2048 );

	if ( ! preg_match( '/^\s*\*\s*Version:\s*([0-9][0-9A-Za-z.\-]*)/m', $header, $match ) ) {
		gffl_build_fail( 'no Version header in the main plugin file.' );
	}

	return $match[1];
}

/**
 * Lists every file under the given paths, relative to $root.
 *
 * @param string             $root  Base directory.
 * @param array<int, string> $paths Files or directories relative to $root.
 *
 * @return array<int, string> Relative paths with forward slashes, sorted.
 */
function gffl_build_files( string $root, array $paths ): array {
	$files = array();

	foreach ( $paths as $path ) {
		$full = $root . '/' . $path;

		if ( is_file( $full ) ) {
			$files[] = $path;
			continue;
		}

		if ( ! is_dir( $full ) ) {
			gffl_build_fail( "missing {$path}." );
		}

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $full, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$files[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
			}
		}
	}

	sort( $files );

	return $files;
}

/**
 * Copies the source files into a fresh staging directory.
 *
 * @param string $root Plugin root.
 *
 * @return string Staging directory.
 */
function gffl_build_stage( string $root ): string {
	$stage = str_replace( '\\', '/', sys_get_temp_dir() ) . '/gffl-build-' . bin2hex( random_bytes( 4 ) );

	foreach ( gffl_build_files( $root, GFFL_BUILD_SOURCES ) as $file ) {
		$target = $stage . '/' . $file;

		if ( ! is_dir( dirname( $target ) ) && ! mkdir( dirname( $target ), 0777, true ) ) {
			gffl_build_fail( "cannot create {$target}." );
		}

		copy( $root . '/' . $file, $target );
	}

	return $stage;
}

/**
 * Runs `composer install --no-dev` in the staging directory.
 *
 * @param string $stage Staging directory.
 *
 * @return void
 */
function gffl_build_composer( string $stage ): void {
	$composer = getenv( 'COMPOSER_BIN' ) ?: 'composer';
	$command  = 'cd ' . escapeshellarg( $stage ) . ' && ' . $composer . ' install --no-dev --optimize-autoloader --no-interaction --quiet';

	passthru( $command, $code );

	if ( 0 !== $code ) {
		gffl_build_fail( "composer install exited with {$code}." );
	}
}

/**
 * Writes the zip with every shipped file under a top-level plugin folder.
 *
 * @param string $stage Staging directory.
 * @param string $zip   Zip path.
 *
 * @return int Number of files added.
 */
function gffl_build_zip( string $stage, string $zip ): int {
	$files = gffl_build_files( $stage, GFFL_BUILD_SHIPPED );

	if ( file_exists( $zip ) ) {
		unlink( $zip );
	}

	$archive = new ZipArchive();

	if ( true !== $archive->open( $zip, ZipArchive::CREATE ) ) {
		gffl_build_fail( "cannot create {$zip}." );
	}

	foreach ( $files as $file ) {
		$archive->addFile( $stage . '/' . $file, GFFL_BUILD_SLUG . '/' . $file );
	}

	$archive->close();

	return count( $files );
}

/**
 * Deletes a directory tree.
 *
 * @param string $dir Directory.
 *
 * @return void
 */
function gffl_build_remove( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );

	foreach ( $iterator as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}

	rmdir( $dir );
}

if ( ! class_exists( 'ZipArchive' ) ) {
	gffl_build_fail( 'the PHP zip extension is not enabled.' );
}

$gffl_root    = str_replace( '\\', '/', dirname( __DIR__ ) );
$gffl_version = gffl_build_version( $gffl_root );
$gffl_zip     = $gffl_root . '/' . GFFL_BUILD_SLUG . '-v' . $gffl_version . '.zip';

echo 'Building ' . basename( $gffl_zip ) . "\n";
$gffl_stage = gffl_build_stage( $gffl_root );

try {
	gffl_build_composer( $gffl_stage );
	$gffl_count = gffl_build_zip( $gffl_stage, $gffl_zip );
} finally {
	gffl_build_remove( $gffl_stage );
}

printf( "Done: %s (%d files, %s KB)\n", basename( $gffl_zip ), $gffl_count, number_format( filesize( $gffl_zip ) / 1024, 1 ) );

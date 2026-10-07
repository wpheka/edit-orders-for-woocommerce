<?php
/**
 * Build the release zip: php bin/build-zip.php
 *
 * Copies the plugin into build/edit-orders-for-woocommerce-<version>.zip, leaving out
 * everything listed in .distignore (tests, dev tooling). Plain PHP with ZipArchive, so it
 * runs the same on macOS, Windows and in the wp-env container. Not shipped.
 *
 * @package Edit_Orders_For_WooCommerce
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput -- a command-line tool with terminal output; no WordPress loaded, not shipped.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$root = dirname( __DIR__ );
$slug = 'edit-orders-for-woocommerce';

if ( ! preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', file_get_contents( $root . '/' . $slug . '.php' ), $match ) ) {
	fwrite( STDERR, "Version header not found.\n" );
	exit( 1 );
}
$version = $match[1];

$ignore = array_filter( array_map( 'trim', file( $root . '/.distignore' ) ) );

$skip = function ( $relative ) use ( $ignore ) {
	foreach ( $ignore as $pattern ) {
		$pattern = rtrim( $pattern, '/' );
		if ( $relative === $pattern || 0 === strpos( $relative, $pattern . '/' ) || fnmatch( $pattern, basename( $relative ) ) ) {
			return true;
		}
	}
	return false;
};

if ( ! is_dir( $root . '/build' ) ) {
	mkdir( $root . '/build' );
}
$target = $root . '/build/' . $slug . '-' . $version . '.zip';
if ( file_exists( $target ) ) {
	unlink( $target );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $target, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Cannot write {$target}\n" );
	exit( 1 );
}

$files    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
$count    = 0;
$included = array();
foreach ( $files as $file ) {
	$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
	if ( $skip( $relative ) ) {
		continue;
	}
	$zip->addFile( $file->getPathname(), $slug . '/' . $relative );
	$included[] = $relative;
	++$count;
}
$zip->close();

sort( $included );
echo implode( "\n", $included ) . "\n\n";
echo "Built {$target} ({$count} files, version {$version}).\n";

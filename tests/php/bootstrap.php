<?php
/**
 * PHPUnit bootstrap. Loads the local SQLite WordPress site built by
 * tests/env/setup.sh so unit and integration tests run against real
 * WordPress, Elementor and ACF code.
 */

declare( strict_types=1 );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$wp_root = getenv( 'EDITTRACE_WP_ROOT' ) ?: dirname( __DIR__, 2 ) . '/.wp-local/wordpress';
$wp_root = rtrim( $wp_root, '/' );
if ( ! is_file( $wp_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "WordPress not found at {$wp_root}. Run tests/env/setup.sh or set EDITTRACE_WP_ROOT.\n" );
	exit( 1 );
}

$site_url = getenv( 'EDITTRACE_SITE_URL' ) ?: 'http://127.0.0.1:8787';
$host     = (string) parse_url( $site_url, PHP_URL_HOST );
$port     = parse_url( $site_url, PHP_URL_PORT );

$_SERVER['HTTP_HOST']       = $host . ( $port ? ':' . $port : '' );
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_NAME']     = $host;
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

define( 'EDITTRACE_PHPUNIT', true );
define( 'WP_USE_THEMES', false );
require_once $wp_root . '/wp-load.php';

// Make sure init has run so post types, blocks and ACF field groups exist.
if ( ! did_action( 'init' ) ) {
	do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
}
if ( ! did_action( 'wp_loaded' ) ) {
	do_action( 'wp_loaded' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
}

require_once __DIR__ . '/TestCase.php';

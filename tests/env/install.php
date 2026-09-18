<?php
/**
 * Non-interactive WordPress installer for the local SQLite test site.
 *
 * Usage: php tests/env/install.php <wp-root> <site-url>
 */
declare( strict_types=1 );

$wp_root  = rtrim( $argv[1] ?? '', '/' );
$site_url = rtrim( $argv[2] ?? 'http://127.0.0.1:8787', '/' );

if ( ! is_file( $wp_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Not a WordPress root: {$wp_root}\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']      = parse_url( $site_url, PHP_URL_HOST ) . ( parse_url( $site_url, PHP_URL_PORT ) ? ':' . parse_url( $site_url, PHP_URL_PORT ) : '' );
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

define( 'WP_INSTALLING', true );
require_once $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! is_blog_installed() ) {
	$result = wp_install( 'EditTrace Test Site', 'admin', 'admin@example.com', true, '', 'password' );
	echo "Installed WordPress (admin / password)\n";
}

$plugins = array(
	'sqlite-database-integration/load.php',
	'elementor/elementor.php',
	'advanced-custom-fields/acf.php',
	'edittrace/edittrace.php',
);
foreach ( $plugins as $plugin ) {
	if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin ) && ! is_plugin_active( $plugin ) ) {
		$r = activate_plugin( $plugin, '', false, true );
		echo ( is_wp_error( $r ) ? 'Failed: ' . $r->get_error_message() : 'Activated ' . $plugin ) . "\n";
	}
}

update_option( 'permalink_structure', '/%postname%/' );
update_option( 'blog_public', 0 );
update_option( 'elementor_onboarded', true );
update_option( 'elementor_disable_color_schemes', 'yes' );
update_option( 'elementor_disable_typography_schemes', 'yes' );
update_option( 'elementor_experiment-container', 'active' );

require_once __DIR__ . '/fixtures.php';
edittrace_install_fixtures();

flush_rewrite_rules( false );
echo "Fixtures ready.\n";

<?php
/**
 * Plugin Name:       EditTrace
 * Plugin URI:        https://github.com/iiiffi-e2/edittrace
 * Description:       Click anything on your WordPress site. See exactly where to edit it.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            EditTrace
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       edittrace
 *
 * @package EditTrace
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EDITTRACE_VERSION', '1.0.0' );
define( 'EDITTRACE_FILE', __FILE__ );
define( 'EDITTRACE_PATH', plugin_dir_path( __FILE__ ) );
define( 'EDITTRACE_URL', plugin_dir_url( __FILE__ ) );

/*
 * EditTrace has no runtime Composer dependencies, so it ships its own tiny
 * PSR-4 autoloader for the EditTrace\ namespace. When a Composer autoloader
 * is present (development), it is used instead.
 */
if ( file_exists( EDITTRACE_PATH . 'vendor/autoload.php' ) ) {
	require_once EDITTRACE_PATH . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			if ( 0 !== strpos( $class, 'EditTrace\\' ) ) {
				return;
			}
			$relative = str_replace( '\\', '/', substr( $class, strlen( 'EditTrace\\' ) ) );
			$file     = EDITTRACE_PATH . 'src/' . $relative . '.php';
			if ( is_file( $file ) ) {
				require_once $file;
			}
		}
	);
}

if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'EditTrace requires PHP 8.0 or newer.', 'edittrace' ) . '</p></div>';
		}
	);
	return;
}

/**
 * Returns the plugin instance.
 */
function edittrace(): \EditTrace\Plugin {
	return \EditTrace\Plugin::instance();
}

add_action( 'plugins_loaded', static function (): void {
	edittrace()->boot();
}, 5 );

register_activation_hook( __FILE__, array( \EditTrace\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \EditTrace\Plugin::class, 'deactivate' ) );

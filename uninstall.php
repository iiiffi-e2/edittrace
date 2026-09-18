<?php
/**
 * Removes EditTrace data when the plugin is deleted.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'edittrace_settings' );

global $wpdb;
// Trace registries are short-lived transients; remove any that have not expired yet.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_edittrace_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_edittrace_' ) . '%'
	)
);
wp_cache_flush();

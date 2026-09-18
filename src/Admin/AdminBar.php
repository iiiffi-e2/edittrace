<?php
/**
 * Admin bar control.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Admin;

use EditTrace\Plugin;
use WP_Admin_Bar;

/**
 * Adds the "EditTrace" toggle to the toolbar on the frontend.
 */
final class AdminBar {

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_node' ), 95 );
	}

	public function add_node( WP_Admin_Bar $bar ): void {
		if ( is_admin() || ! $this->plugin->get_session() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'edittrace',
				'title' => '<span class="ab-icon dashicons dashicons-search" aria-hidden="true"></span><span class="ab-label">EditTrace</span>',
				'href'  => '#edittrace',
				'meta'  => array(
					'class' => 'edittrace-admin-bar',
					'title' => __( 'Inspect this page with EditTrace', 'edittrace' ),
				),
			)
		);
	}
}

<?php
/**
 * Admin bar control.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Admin;

use EditTrace\Plugin;
use EditTrace\Support\UserPreferences;
use WP_Admin_Bar;

/**
 * Adds the "EditTrace" node to the toolbar on the frontend.
 *
 * Switched off (default): the node is a plain link that turns EditTrace on
 * for this user and reopens the page in Inspector Mode. Switched on: the
 * node toggles Inspector Mode and offers "Turn off".
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
		if ( is_admin() || ! $this->plugin->is_frontend_request() || ! $this->plugin->get_access()->user_can_inspect() ) {
			return;
		}
		$user_id = get_current_user_id();
		$on      = null !== $this->plugin->get_session() && UserPreferences::is_tracing_enabled( $user_id );
		$current = $this->current_url();

		if ( $on ) {
			$bar->add_node(
				array(
					'id'    => 'edittrace',
					'title' => '<span class="ab-icon dashicons dashicons-search" aria-hidden="true"></span><span class="ab-label">EditTrace</span>',
					'href'  => '#edittrace',
					'meta'  => array(
						'class' => 'edittrace-admin-bar edittrace-admin-bar--on',
						'title' => __( 'Inspect this page with EditTrace', 'edittrace' ),
					),
				)
			);
			$bar->add_node(
				array(
					'id'     => 'edittrace-off',
					'parent' => 'edittrace',
					'title'  => __( 'Turn off EditTrace', 'edittrace' ),
					'href'   => Toggle::url( false, $current ),
				)
			);
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'edittrace',
				'title' => '<span class="ab-icon dashicons dashicons-search" aria-hidden="true"></span><span class="ab-label">EditTrace</span>',
				'href'  => Toggle::url( true, $current ),
				'meta'  => array(
					'class' => 'edittrace-admin-bar edittrace-admin-bar--off',
					'title' => __( 'Turn on EditTrace and inspect this page', 'edittrace' ),
				),
			)
		);
	}

	private function current_url(): string {
		$request = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return esc_url_raw( home_url( $request ) );
	}
}

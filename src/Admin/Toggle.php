<?php
/**
 * Turns EditTrace on/off for the current user.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Admin;

use EditTrace\Plugin;
use EditTrace\Support\UserPreferences;

/**
 * Nonce-protected admin-post action that flips the per-user switch and
 * sends the user back to the page they were on.
 */
final class Toggle {

	public const ACTION = 'edittrace_toggle';

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * URL that switches tracing on or off and returns to $redirect.
	 */
	public static function url( bool $on, string $redirect ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::ACTION,
					'state'    => $on ? 'on' : 'off',
					'redirect' => rawurlencode( $redirect ),
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION
		);
	}

	public function handle(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in.', 'edittrace' ), '', array( 'response' => 401 ) );
		}
		check_admin_referer( self::ACTION );
		if ( ! $this->plugin->get_access()->user_can_inspect() ) {
			wp_die( esc_html__( 'You are not allowed to use EditTrace.', 'edittrace' ), '', array( 'response' => 403 ) );
		}

		$state = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( (string) $_GET['state'] ) ) : 'off';
		UserPreferences::set_tracing_enabled( get_current_user_id(), 'on' === $state );

		$redirect = isset( $_GET['redirect'] ) ? rawurldecode( wp_unslash( (string) $_GET['redirect'] ) ) : '';
		$redirect = wp_validate_redirect( $redirect, home_url( '/' ) );
		if ( 'on' === $state && false === strpos( $redirect, '#' ) ) {
			$redirect .= '#edittrace'; // Open Inspector Mode right away.
		}
		wp_safe_redirect( $redirect );
		exit;
	}
}

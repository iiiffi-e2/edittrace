<?php
/**
 * Decides who may use EditTrace.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Security;

use EditTrace\Support\Options;
use WP_User;

/**
 * Authorization gate. Every EditTrace surface (admin bar, assets, trace
 * metadata, REST) goes through user_can_inspect().
 */
final class Access {

	/**
	 * Baseline capability required in addition to an allowed role.
	 */
	public const CAPABILITY = 'edit_posts';

	private Options $options;

	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * Whether the given (or current) user may use the inspector.
	 *
	 * @param WP_User|null $user User to check; defaults to the current user.
	 */
	public function user_can_inspect( ?WP_User $user = null ): bool {
		$user = $user ?? wp_get_current_user();

		$allowed = false;
		if ( $user instanceof WP_User && $user->exists() && $this->options->is_enabled() ) {
			$allowed = $this->matches_roles( $user ) && $user->has_cap( self::CAPABILITY );
			if ( is_multisite() && is_super_admin( $user->ID ) ) {
				$allowed = true;
			}
		}

		/**
		 * Filters whether a user may use the EditTrace inspector.
		 *
		 * @param bool    $allowed Whether the user may inspect.
		 * @param WP_User $user    The user being checked.
		 */
		$allowed = (bool) apply_filters( 'edittrace/user_can_inspect', $allowed, $user );

		// Never grant access to logged-out visitors, whatever a filter says.
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return false;
		}
		return $allowed;
	}

	private function matches_roles( WP_User $user ): bool {
		$allowed_roles = $this->options->roles();
		foreach ( (array) $user->roles as $role ) {
			if ( in_array( (string) $role, $allowed_roles, true ) ) {
				return true;
			}
		}
		return false;
	}
}

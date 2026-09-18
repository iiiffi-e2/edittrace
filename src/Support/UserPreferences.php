<?php
/**
 * Per-user on/off switch.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Support;

/**
 * EditTrace is opt-in per user: even an allowed administrator sees nothing
 * (no tracing, no script) until they switch it on from the toolbar.
 */
final class UserPreferences {

	public const META_KEY = 'edittrace_tracing';

	public static function is_tracing_enabled( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$enabled = '1' === (string) get_user_meta( $user_id, self::META_KEY, true );

		/**
		 * Filters whether tracing is switched on for a user.
		 *
		 * @param bool $enabled Whether the user switched EditTrace on.
		 * @param int  $user_id User id.
		 */
		return (bool) apply_filters( 'edittrace/tracing_enabled', $enabled, $user_id );
	}

	public static function set_tracing_enabled( int $user_id, bool $enabled ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		if ( $enabled ) {
			update_user_meta( $user_id, self::META_KEY, '1' );
		} else {
			delete_user_meta( $user_id, self::META_KEY );
		}
	}
}

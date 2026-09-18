<?php
/**
 * Plugin settings access.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Support;

/**
 * Thin wrapper around the single `edittrace_settings` option.
 */
final class Options {

	public const OPTION_NAME = 'edittrace_settings';

	/** @var array<string,mixed>|null */
	private ?array $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'         => true,
			'roles'           => array( 'administrator' ),
			'fallback_search' => true,
			'max_results'     => 10,
			'debug'           => false,
		);
	}

	/**
	 * Returns all settings merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION_NAME, array() );
			$stored      = is_array( $stored ) ? $stored : array();
			$this->cache = self::sanitize( array_merge( self::defaults(), $stored ) );
		}
		return $this->cache;
	}

	/**
	 * Returns one setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public function get( string $key ) {
		$all = $this->all();
		return $all[ $key ] ?? ( self::defaults()[ $key ] ?? null );
	}

	public function is_enabled(): bool {
		return (bool) $this->get( 'enabled' );
	}

	public function is_debug(): bool {
		return (bool) $this->get( 'debug' );
	}

	public function fallback_search_enabled(): bool {
		return (bool) $this->get( 'fallback_search' );
	}

	public function max_results(): int {
		return (int) $this->get( 'max_results' );
	}

	/**
	 * Allowed role slugs.
	 *
	 * @return string[]
	 */
	public function roles(): array {
		$roles = $this->get( 'roles' );
		return is_array( $roles ) ? array_values( array_map( 'strval', $roles ) ) : array( 'administrator' );
	}

	/**
	 * Forget the cached copy (used after saving settings and in tests).
	 */
	public function flush(): void {
		$this->cache = null;
	}

	/**
	 * Sanitizes a settings array. Used by the Settings API and by all().
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();

		$roles = array();
		if ( isset( $input['roles'] ) && is_array( $input['roles'] ) ) {
			foreach ( $input['roles'] as $role ) {
				$role = sanitize_key( (string) $role );
				if ( '' !== $role ) {
					$roles[] = $role;
				}
			}
		}
		if ( empty( $roles ) ) {
			$roles = $defaults['roles'];
		}
		// Administrators can never lock themselves out.
		if ( ! in_array( 'administrator', $roles, true ) ) {
			$roles[] = 'administrator';
		}

		$max = isset( $input['max_results'] ) ? (int) $input['max_results'] : $defaults['max_results'];
		$max = max( 1, min( 10, $max ) );

		return array(
			'enabled'         => isset( $input['enabled'] ) ? (bool) $input['enabled'] : $defaults['enabled'],
			'roles'           => array_values( array_unique( $roles ) ),
			'fallback_search' => isset( $input['fallback_search'] ) ? (bool) $input['fallback_search'] : $defaults['fallback_search'],
			'max_results'     => $max,
			'debug'           => isset( $input['debug'] ) ? (bool) $input['debug'] : $defaults['debug'],
		);
	}
}

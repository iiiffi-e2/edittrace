<?php
/**
 * Values too generic to search for.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Search;

/**
 * Generic UI strings that would match everywhere.
 */
final class IgnoredStrings {

	/**
	 * @return string[]
	 */
	public static function all(): array {
		$defaults = array(
			'home',
			'menu',
			'close',
			'next',
			'previous',
			'prev',
			'submit',
			'search',
			'read more',
			'learn more',
			'more',
			'back',
			'top',
			'skip to content',
			'open',
			'toggle',
			'login',
			'log in',
			'logout',
			'log out',
			'ok',
			'yes',
			'no',
			'cancel',
			'go',
			'send',
			'email',
			'phone',
			'name',
			'contact',
			'about',
			'blog',
			'shop',
			'cart',
		);

		/**
		 * Filters the list of strings the fallback search ignores.
		 *
		 * @param string[] $strings Lower-case strings.
		 */
		return (array) apply_filters( 'edittrace/ignored_search_strings', $defaults );
	}

	public static function is_ignored( string $value ): bool {
		$normalized = TextNormalizer::normalize( $value );
		if ( '' === $normalized || TextNormalizer::length( $normalized ) < 3 ) {
			return true;
		}
		if ( in_array( $normalized, array_map( 'strtolower', self::all() ), true ) ) {
			return true;
		}
		// Pure numbers shorter than a phone number are noise.
		if ( preg_match( '/^\d{1,5}$/', $normalized ) ) {
			return true;
		}
		return false;
	}
}

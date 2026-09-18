<?php
/**
 * Text normalization for matching.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Search;

/**
 * Turns HTML/markup/whitespace-noisy strings into comparable text.
 */
final class TextNormalizer {

	/**
	 * Normalizes for equality/containment comparisons: strips tags, decodes
	 * entities, collapses whitespace, lowercases, trims surrounding punctuation.
	 */
	public static function normalize( string $text, int $max = 5000 ): string {
		$text = self::plain( $text, $max );
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = trim( $text, " \t\n\r\0\x0B.,;:!?\"'«»“”‘’…-–—()[]{}" );
		return $text;
	}

	/**
	 * Strips markup but keeps case.
	 */
	public static function plain( string $text, int $max = 5000 ): string {
		if ( '' === $text ) {
			return '';
		}
		$text = preg_replace( '/<(br|\/p|\/div|\/li|\/h[1-6]|\/tr)\s*[^>]*>/i', ' ', $text ) ?? $text;
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\xC2\xA0", "\xE2\x80\x8B" ), ' ', $text ); // nbsp, zero-width space.
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
		$text = trim( $text );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $max );
		}
		return substr( $text, 0, $max );
	}

	/**
	 * Digits only – useful for phone numbers.
	 */
	public static function digits( string $text ): string {
		return preg_replace( '/\D+/', '', $text ) ?? '';
	}

	/**
	 * Whether two normalized strings represent the same value.
	 */
	public static function equals( string $a, string $b ): bool {
		$a = self::normalize( $a );
		$b = self::normalize( $b );
		if ( '' === $a || '' === $b ) {
			return false;
		}
		if ( $a === $b ) {
			return true;
		}
		// Phone-number style values: compare digits when both are mostly digits.
		$da = self::digits( $a );
		$db = self::digits( $b );
		if ( strlen( $da ) >= 6 && $da === $db && strlen( $da ) >= strlen( $a ) / 2 ) {
			return true;
		}
		return false;
	}

	/**
	 * Whether $needle (normalized) is a meaningful part of $haystack.
	 */
	public static function contains( string $haystack, string $needle ): bool {
		$haystack = self::normalize( $haystack );
		$needle   = self::normalize( $needle );
		if ( '' === $needle || '' === $haystack || self::length( $needle ) < 3 ) {
			return false;
		}
		return false !== ( function_exists( 'mb_strpos' ) ? mb_strpos( $haystack, $needle ) : strpos( $haystack, $needle ) );
	}

	public static function length( string $text ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
	}

	/**
	 * Truncates for display.
	 */
	public static function excerpt( string $text, int $max = 80 ): string {
		$text = self::plain( $text, $max + 1 );
		if ( self::length( $text ) > $max ) {
			return ( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max - 1 ) : substr( $text, 0, $max - 1 ) ) . '…';
		}
		return $text;
	}
}

<?php
/**
 * URL helpers.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Support;

/**
 * Comparison-friendly URL handling.
 */
final class Url {

	/**
	 * Normalizes a URL for comparison: absolute, no scheme, no trailing slash,
	 * no fragment, lower-cased host.
	 */
	public static function normalize( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		} elseif ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		} elseif ( ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
			// Already normalized ("host/path") or a bare host: treat as https.
			if ( preg_match( '#^[a-z0-9.-]+(?::\d+)?(?:/|$)#i', $url ) && false === strpos( $url, ' ' ) ) {
				$url = 'https://' . $url;
			} else {
				return '';
			}
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] ?? '' );
		if ( in_array( $scheme, array( 'mailto', 'tel', 'sms' ), true ) ) {
			return $scheme . ':' . strtolower( (string) ( $parts['path'] ?? '' ) );
		}
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		$host = strtolower( $parts['host'] ?? '' );
		$host = preg_replace( '/^www\./', '', $host ) ?? $host;
		$path = $parts['path'] ?? '/';
		$path = rtrim( $path, '/' );
		$out  = $host . $path;
		if ( ! empty( $parts['query'] ) ) {
			$out .= '?' . $parts['query'];
		}
		return $out;
	}

	public static function equals( string $a, string $b ): bool {
		$a = self::normalize( $a );
		$b = self::normalize( $b );
		return '' !== $a && $a === $b;
	}

	/**
	 * Removes a WordPress intermediate-size suffix (image-300x200.jpg → image.jpg).
	 */
	public static function strip_size_suffix( string $url ): string {
		return preg_replace( '/-\d+x\d+(?=\.[a-zA-Z0-9]{2,5}(?:$|\?))/', '', $url ) ?? $url;
	}

	/**
	 * Whether two image URLs refer to the same attachment file (ignoring size).
	 */
	public static function same_image( string $a, string $b ): bool {
		$a = self::normalize( self::strip_size_suffix( $a ) );
		$b = self::normalize( self::strip_size_suffix( $b ) );
		if ( '' === $a || '' === $b ) {
			return false;
		}
		// Also tolerate -scaled variants.
		$a = str_replace( '-scaled', '', $a );
		$b = str_replace( '-scaled', '', $b );
		return $a === $b;
	}

	/**
	 * Path of a URL relative to the site root ("/services/").
	 */
	public static function path( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		return is_string( $path ) && '' !== $path ? $path : '/';
	}
}

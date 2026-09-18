<?php
/**
 * Fingerprints ACF values for later matching.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Integrations\ACF;

use EditTrace\Search\TextNormalizer;
use EditTrace\Support\Url;

/**
 * Converts a field value into comparable, non-sensitive fingerprints:
 * normalized texts, normalized URLs, attachment ids. Never stores the raw
 * value of password-like fields.
 */
final class Fingerprint {

	public const MAX_TEXTS   = 20;
	public const MAX_URLS    = 30;
	public const MAX_ATTACH  = 30;
	public const MAX_LEN     = 5000;

	private const SKIPPED_TYPES = array( 'password', 'message', 'accordion', 'tab', 'repeater', 'flexible_content', 'group', 'clone', 'google_map', 'true_false', 'acfe_hidden' );

	/**
	 * @param array<string,mixed> $field ACF field array.
	 * @param mixed               $value Raw or formatted value.
	 * @return array{texts:string[],urls:string[],attachments:int[]}
	 */
	public static function build( array $field, $value ): array {
		$out  = array(
			'texts'       => array(),
			'urls'        => array(),
			'attachments' => array(),
		);
		$type = (string) ( $field['type'] ?? '' );
		if ( in_array( $type, self::SKIPPED_TYPES, true ) || null === $value || '' === $value || false === $value ) {
			return $out;
		}

		switch ( $type ) {
			case 'image':
			case 'file':
			case 'gallery':
				self::collect_attachments( $value, $out );
				break;
			case 'link':
				self::collect_link( $value, $out );
				break;
			case 'select':
			case 'radio':
			case 'button_group':
			case 'checkbox':
				self::collect_choices( $field, $value, $out );
				break;
			case 'post_object':
			case 'relationship':
			case 'page_link':
				self::collect_posts( $value, $out );
				break;
			case 'taxonomy':
				self::collect_terms( $value, $out );
				break;
			case 'user':
				self::collect_users( $value, $out );
				break;
			default:
				self::collect_scalar( $value, $out );
				break;
		}

		$out['texts']       = array_values( array_unique( array_slice( array_filter( $out['texts'], 'strlen' ), 0, self::MAX_TEXTS ) ) );
		$out['urls']        = array_values( array_unique( array_slice( array_filter( $out['urls'], 'strlen' ), 0, self::MAX_URLS ) ) );
		$out['attachments'] = array_values( array_unique( array_slice( array_map( 'intval', $out['attachments'] ), 0, self::MAX_ATTACH ) ) );
		return $out;
	}

	/**
	 * Merges two fingerprint sets.
	 *
	 * @param array{texts:string[],urls:string[],attachments:int[]} $a First.
	 * @param array{texts:string[],urls:string[],attachments:int[]} $b Second.
	 * @return array{texts:string[],urls:string[],attachments:int[]}
	 */
	public static function merge( array $a, array $b ): array {
		return array(
			'texts'       => array_values( array_unique( array_slice( array_merge( $a['texts'] ?? array(), $b['texts'] ?? array() ), 0, self::MAX_TEXTS ) ) ),
			'urls'        => array_values( array_unique( array_slice( array_merge( $a['urls'] ?? array(), $b['urls'] ?? array() ), 0, self::MAX_URLS ) ) ),
			'attachments' => array_values( array_unique( array_slice( array_merge( $a['attachments'] ?? array(), $b['attachments'] ?? array() ), 0, self::MAX_ATTACH ) ) ),
		);
	}

	/**
	 * @param mixed                                                $value Value.
	 * @param array{texts:string[],urls:string[],attachments:int[]} $out   Output.
	 */
	private static function collect_scalar( $value, array &$out ): void {
		if ( is_array( $value ) ) {
			foreach ( array_slice( $value, 0, 20 ) as $item ) {
				if ( is_scalar( $item ) ) {
					self::collect_scalar( $item, $out );
				}
			}
			return;
		}
		if ( ! is_scalar( $value ) ) {
			return;
		}
		$string = (string) $value;
		if ( preg_match( '#^(https?:)?//#i', trim( $string ) ) || preg_match( '#^(mailto|tel):#i', trim( $string ) ) ) {
			$out['urls'][] = Url::normalize( trim( $string ) );
		}
		$text = TextNormalizer::normalize( $string, self::MAX_LEN );
		if ( '' !== $text ) {
			$out['texts'][] = $text;
		}
	}

	/**
	 * @param mixed $value Value.
	 * @param array $out   Output.
	 */
	private static function collect_attachments( $value, array &$out ): void {
		$items = is_array( $value ) && ( isset( $value['ID'] ) || isset( $value['id'] ) || isset( $value['url'] ) ) ? array( $value ) : ( is_array( $value ) ? $value : array( $value ) );
		foreach ( array_slice( $items, 0, self::MAX_ATTACH ) as $item ) {
			$id  = 0;
			$url = '';
			if ( is_numeric( $item ) ) {
				$id = (int) $item;
			} elseif ( is_array( $item ) ) {
				$id  = (int) ( $item['ID'] ?? ( $item['id'] ?? 0 ) );
				$url = (string) ( $item['url'] ?? '' );
				foreach ( array( 'alt', 'title', 'caption', 'description', 'filename', 'name' ) as $k ) {
					if ( ! empty( $item[ $k ] ) && is_string( $item[ $k ] ) ) {
						$out['texts'][] = TextNormalizer::normalize( $item[ $k ], 500 );
					}
				}
				if ( ! empty( $item['sizes'] ) && is_array( $item['sizes'] ) ) {
					foreach ( $item['sizes'] as $size ) {
						if ( is_string( $size ) && preg_match( '#^https?://#', $size ) ) {
							$out['urls'][] = Url::normalize( Url::strip_size_suffix( $size ) );
						}
					}
				}
			} elseif ( is_string( $item ) && preg_match( '#^https?://#', $item ) ) {
				$url = $item;
			}
			if ( $id > 0 ) {
				$out['attachments'][] = $id;
				if ( '' === $url ) {
					$url = (string) wp_get_attachment_url( $id );
				}
			}
			if ( '' !== $url ) {
				$out['urls'][] = Url::normalize( Url::strip_size_suffix( $url ) );
			}
		}
	}

	/**
	 * @param mixed $value Value.
	 * @param array $out   Output.
	 */
	private static function collect_link( $value, array &$out ): void {
		if ( is_array( $value ) ) {
			if ( ! empty( $value['title'] ) && is_scalar( $value['title'] ) ) {
				$out['texts'][] = TextNormalizer::normalize( (string) $value['title'], 500 );
			}
			if ( ! empty( $value['url'] ) && is_scalar( $value['url'] ) ) {
				$out['urls'][] = Url::normalize( (string) $value['url'] );
			}
			return;
		}
		self::collect_scalar( $value, $out );
	}

	/**
	 * @param array<string,mixed> $field Field.
	 * @param mixed               $value Value.
	 * @param array               $out   Output.
	 */
	private static function collect_choices( array $field, $value, array &$out ): void {
		$choices = isset( $field['choices'] ) && is_array( $field['choices'] ) ? $field['choices'] : array();
		$values  = is_array( $value ) ? $value : array( $value );
		foreach ( array_slice( $values, 0, 20 ) as $item ) {
			if ( is_array( $item ) ) { // return_format=array → {value,label}
				foreach ( array( 'value', 'label' ) as $k ) {
					if ( isset( $item[ $k ] ) && is_scalar( $item[ $k ] ) ) {
						$out['texts'][] = TextNormalizer::normalize( (string) $item[ $k ], 500 );
					}
				}
				continue;
			}
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$out['texts'][] = TextNormalizer::normalize( (string) $item, 500 );
			if ( isset( $choices[ $item ] ) && is_scalar( $choices[ $item ] ) ) {
				$out['texts'][] = TextNormalizer::normalize( (string) $choices[ $item ], 500 );
			}
		}
	}

	/**
	 * @param mixed $value Value.
	 * @param array $out   Output.
	 */
	private static function collect_posts( $value, array &$out ): void {
		$items = is_array( $value ) && ! ( $value instanceof \WP_Post ) ? $value : array( $value );
		foreach ( array_slice( $items, 0, 20 ) as $item ) {
			$post = null;
			if ( $item instanceof \WP_Post ) {
				$post = $item;
			} elseif ( is_numeric( $item ) ) {
				$post = get_post( (int) $item );
			} elseif ( is_string( $item ) && preg_match( '#^https?://#', $item ) ) {
				$out['urls'][] = Url::normalize( $item );
				continue;
			}
			if ( $post instanceof \WP_Post ) {
				$out['texts'][] = TextNormalizer::normalize( $post->post_title, 500 );
				$out['urls'][]  = Url::normalize( (string) get_permalink( $post ) );
			}
		}
	}

	/**
	 * @param mixed $value Value.
	 * @param array $out   Output.
	 */
	private static function collect_terms( $value, array &$out ): void {
		$items = is_array( $value ) ? $value : array( $value );
		foreach ( array_slice( $items, 0, 20 ) as $item ) {
			if ( $item instanceof \WP_Term ) {
				$out['texts'][] = TextNormalizer::normalize( $item->name, 300 );
				$link           = get_term_link( $item );
				if ( is_string( $link ) ) {
					$out['urls'][] = Url::normalize( $link );
				}
			}
		}
	}

	/**
	 * @param mixed $value Value.
	 * @param array $out   Output.
	 */
	private static function collect_users( $value, array &$out ): void {
		$items = is_array( $value ) && ! isset( $value['ID'] ) ? $value : array( $value );
		foreach ( array_slice( $items, 0, 10 ) as $item ) {
			if ( is_array( $item ) && ! empty( $item['display_name'] ) ) {
				$out['texts'][] = TextNormalizer::normalize( (string) $item['display_name'], 300 );
			} elseif ( $item instanceof \WP_User ) {
				$out['texts'][] = TextNormalizer::normalize( $item->display_name, 300 );
			}
		}
	}
}

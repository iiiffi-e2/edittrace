<?php
/**
 * Finds elements inside Elementor document data.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Integrations\Elementor;

/**
 * Pure functions over Elementor's nested element arrays
 * (id, elType, widgetType, settings, elements[]). No Elementor runtime needed.
 */
final class ElementLocator {

	/**
	 * Locates an element by id and returns it together with its ancestors.
	 *
	 * @param array<int,array<string,mixed>> $elements Document elements data.
	 * @return array{element:array<string,mixed>,ancestors:array<int,array<string,mixed>>,indexes:int[]}|null
	 */
	public static function find( array $elements, string $id, array $ancestors = array(), array $indexes = array(), int $depth = 0 ): ?array {
		if ( '' === $id || $depth > 30 ) {
			return null;
		}
		foreach ( array_values( $elements ) as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['id'] ) && (string) $element['id'] === $id ) {
				return array(
					'element'   => $element,
					'ancestors' => $ancestors,
					'indexes'   => array_merge( $indexes, array( $index ) ),
				);
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$found = self::find( $element['elements'], $id, array_merge( $ancestors, array( $element ) ), array_merge( $indexes, array( $index ) ), $depth + 1 );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Recursively finds elements whose scalar settings contain a value.
	 *
	 * @param array<int,array<string,mixed>> $elements Document elements data.
	 * @param callable(mixed,string):bool     $matcher  Receives (value, setting key).
	 * @return array<int,array{element:array<string,mixed>,ancestors:array<int,array<string,mixed>>,setting:string}>
	 */
	public static function search( array $elements, callable $matcher, array $ancestors = array(), int $depth = 0, int $limit = 5 ): array {
		$results = array();
		if ( $depth > 30 ) {
			return $results;
		}
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
			$hit      = self::match_settings( $settings, $matcher );
			if ( null !== $hit ) {
				$results[] = array(
					'element'   => $element,
					'ancestors' => $ancestors,
					'setting'   => $hit,
				);
				if ( count( $results ) >= $limit ) {
					return $results;
				}
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$results = array_merge( $results, self::search( $element['elements'], $matcher, array_merge( $ancestors, array( $element ) ), $depth + 1, $limit - count( $results ) ) );
				if ( count( $results ) >= $limit ) {
					return array_slice( $results, 0, $limit );
				}
			}
		}
		return $results;
	}

	/**
	 * @param array<string,mixed>         $settings Settings.
	 * @param callable(mixed,string):bool $matcher  Matcher.
	 */
	private static function match_settings( array $settings, callable $matcher, string $prefix = '', int $depth = 0 ): ?string {
		if ( $depth > 4 ) {
			return null;
		}
		foreach ( $settings as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			if ( is_scalar( $value ) ) {
				if ( $matcher( $value, $path ) ) {
					return $path;
				}
			} elseif ( is_array( $value ) ) {
				$hit = self::match_settings( $value, $matcher, $path, $depth + 1 );
				if ( null !== $hit ) {
					return $hit;
				}
			}
		}
		return null;
	}

	/**
	 * Human label for one element (widget title, container name...).
	 *
	 * @param array<string,mixed> $element Element data.
	 */
	public static function label( array $element, ?callable $type_title = null ): string {
		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		if ( ! empty( $settings['_title'] ) && is_string( $settings['_title'] ) ) {
			return wp_strip_all_tags( $settings['_title'] );
		}
		$type = self::type_key( $element );
		if ( null !== $type_title ) {
			$title = $type_title( $element );
			if ( is_string( $title ) && '' !== $title ) {
				return $title;
			}
		}
		return ucwords( str_replace( array( '-', '_' ), ' ', (string) preg_replace( '/\.default$/', '', $type ) ) );
	}

	/**
	 * "heading" for widgets, "container"/"section"/"column" for structure.
	 *
	 * @param array<string,mixed> $element Element data.
	 */
	public static function type_key( array $element ): string {
		if ( ( $element['elType'] ?? '' ) === 'widget' && ! empty( $element['widgetType'] ) ) {
			return (string) $element['widgetType'];
		}
		return (string) ( $element['elType'] ?? 'element' );
	}

	/**
	 * Breadcrumb labels for an element and its ancestors.
	 *
	 * @param array<int,array<string,mixed>> $ancestors Ancestors, outermost first.
	 * @param array<string,mixed>            $element   The element.
	 * @return string[]
	 */
	public static function hierarchy( array $ancestors, array $element, ?callable $type_title = null ): array {
		$labels = array();
		foreach ( $ancestors as $ancestor ) {
			$labels[] = self::label( $ancestor, $type_title );
		}
		$labels[] = self::label( $element, $type_title );
		return $labels;
	}
}

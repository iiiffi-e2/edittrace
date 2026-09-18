<?php
/**
 * Normalized representation of the clicked DOM element.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Inspector;

/**
 * Built from untrusted REST input. Everything is sanitized and bounded here
 * so providers can treat the context as clean, typed data. It is still
 * identification only — never authorization.
 */
final class ElementContext {

	public const MAX_TEXT      = 1000;
	public const MAX_URL       = 2000;
	public const MAX_ANCESTORS = 10;
	public const MAX_CLASSES   = 40;
	public const MAX_DATASET   = 25;

	public string $tag = '';
	public string $text = '';
	public string $href = '';
	public string $src = '';
	public string $alt = '';
	public string $id = '';
	/** @var string[] */
	public array $classes = array();
	/** @var array<string,string> */
	public array $dataset = array();
	/** @var array<int,array{tag:string,id:string,classes:string[],dataset:array<string,string>}> */
	public array $ancestors = array();
	public string $page_url = '';
	public string $trace_id = '';
	public int $width = 0;
	public int $height = 0;

	/**
	 * @param array<string,mixed> $input Raw request data.
	 */
	public static function from_array( array $input ): self {
		$ctx           = new self();
		$ctx->tag      = self::sanitize_tag( $input['tag'] ?? '' );
		$ctx->text     = self::sanitize_text( $input['text'] ?? '', self::MAX_TEXT );
		$ctx->href     = self::sanitize_url( $input['href'] ?? '' );
		$ctx->src      = self::sanitize_url( $input['src'] ?? '' );
		$ctx->alt      = self::sanitize_text( $input['alt'] ?? '', 500 );
		$ctx->id       = self::sanitize_attr( $input['id'] ?? '' );
		$ctx->classes  = self::sanitize_classes( $input['classes'] ?? array() );
		$ctx->dataset  = self::sanitize_dataset( $input['dataset'] ?? array() );
		$ctx->page_url = self::sanitize_url( $input['pageUrl'] ?? ( $input['page_url'] ?? '' ) );
		$ctx->trace_id = self::sanitize_token( $input['traceId'] ?? ( $input['trace_id'] ?? '' ) );
		$ctx->width    = isset( $input['width'] ) ? max( 0, min( 20000, (int) $input['width'] ) ) : 0;
		$ctx->height   = isset( $input['height'] ) ? max( 0, min( 20000, (int) $input['height'] ) ) : 0;

		$ancestors = isset( $input['ancestors'] ) && is_array( $input['ancestors'] ) ? $input['ancestors'] : array();
		foreach ( array_slice( array_values( $ancestors ), 0, self::MAX_ANCESTORS ) as $ancestor ) {
			if ( ! is_array( $ancestor ) ) {
				continue;
			}
			$ctx->ancestors[] = array(
				'tag'     => self::sanitize_tag( $ancestor['tag'] ?? '' ),
				'id'      => self::sanitize_attr( $ancestor['id'] ?? '' ),
				'classes' => self::sanitize_classes( $ancestor['classes'] ?? array() ),
				'dataset' => self::sanitize_dataset( $ancestor['dataset'] ?? array() ),
			);
		}
		return $ctx;
	}

	/**
	 * Returns the element itself followed by its ancestors, nearest first.
	 *
	 * @return array<int,array{tag:string,id:string,classes:string[],dataset:array<string,string>}>
	 */
	public function chain(): array {
		$self = array(
			'tag'     => $this->tag,
			'id'      => $this->id,
			'classes' => $this->classes,
			'dataset' => $this->dataset,
		);
		return array_merge( array( $self ), $this->ancestors );
	}

	/**
	 * Finds the nearest node (self first) with a given dataset key.
	 *
	 * @return array{node:array<string,mixed>,depth:int}|null
	 */
	public function nearest_with_dataset( string $key ): ?array {
		foreach ( $this->chain() as $depth => $node ) {
			if ( isset( $node['dataset'][ $key ] ) && '' !== $node['dataset'][ $key ] ) {
				return array(
					'node'  => $node,
					'depth' => $depth,
				);
			}
		}
		return null;
	}

	/**
	 * Finds the nearest node (self first) having a class matching a regex.
	 *
	 * @return array{node:array<string,mixed>,depth:int,match:string[]}|null
	 */
	public function nearest_with_class( string $pattern ): ?array {
		foreach ( $this->chain() as $depth => $node ) {
			foreach ( $node['classes'] as $class ) {
				if ( preg_match( $pattern, $class, $m ) ) {
					return array(
						'node'  => $node,
						'depth' => $depth,
						'match' => $m,
					);
				}
			}
		}
		return null;
	}

	public function has_class( string $class ): bool {
		return in_array( $class, $this->classes, true );
	}

	public function is_image(): bool {
		return 'img' === $this->tag || '' !== $this->src;
	}

	public function is_link(): bool {
		return 'a' === $this->tag && '' !== $this->href;
	}

	/**
	 * Short human description used as the panel headline.
	 */
	public function summary(): string {
		if ( '' !== $this->text ) {
			return $this->text;
		}
		if ( '' !== $this->alt ) {
			return $this->alt;
		}
		if ( '' !== $this->src ) {
			return wp_basename( (string) wp_parse_url( $this->src, PHP_URL_PATH ) );
		}
		if ( '' !== $this->href ) {
			return $this->href;
		}
		return '<' . $this->tag . '>';
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'tag'       => $this->tag,
			'text'      => $this->text,
			'href'      => $this->href,
			'src'       => $this->src,
			'alt'       => $this->alt,
			'id'        => $this->id,
			'classes'   => $this->classes,
			'dataset'   => $this->dataset,
			'ancestors' => $this->ancestors,
			'pageUrl'   => $this->page_url,
			'traceId'   => $this->trace_id,
		);
	}

	private static function sanitize_tag( $value ): string {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-z][a-z0-9-]{0,40}$/', $value ) ? $value : '';
	}

	private static function sanitize_text( $value, int $max ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = wp_strip_all_tags( (string) $value );
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? '';
		$value = trim( $value );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max );
		}
		return substr( $value, 0, $max );
	}

	private static function sanitize_url( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > self::MAX_URL ) {
			return '';
		}
		if ( 0 === stripos( $value, 'data:' ) || 0 === stripos( $value, 'javascript:' ) ) {
			return '';
		}
		return esc_url_raw( $value );
	}

	private static function sanitize_attr( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		return substr( preg_replace( '/[^\w\-.:\/]/', '', $value ) ?? '', 0, 120 );
	}

	/**
	 * @return string[]
	 */
	private static function sanitize_classes( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\s+/', $value ) ?: array();
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( $value, 0, self::MAX_CLASSES ) as $class ) {
			if ( ! is_scalar( $class ) ) {
				continue;
			}
			$class = substr( trim( (string) $class ), 0, 100 );
			if ( '' !== $class && preg_match( '/^[\w\-:\/\.\[\]%!]+$/u', $class ) ) {
				$out[] = $class;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @return array<string,string>
	 */
	private static function sanitize_dataset( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( $value, 0, self::MAX_DATASET, true ) as $key => $val ) {
			$key = (string) $key;
			if ( ! preg_match( '/^[a-zA-Z][\w\-]{0,60}$/', $key ) || ! is_scalar( $val ) ) {
				continue;
			}
			$out[ $key ] = substr( trim( (string) $val ), 0, 200 );
		}
		return $out;
	}

	private static function sanitize_token( $value ): string {
		$value = is_scalar( $value ) ? strtolower( (string) $value ) : '';
		return preg_match( '/^[a-f0-9]{32}$/', $value ) ? $value : '';
	}
}

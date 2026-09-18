<?php
/**
 * Shared provider behaviour.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Providers;

use EditTrace\Inspector\ElementContext;
use EditTrace\Inspector\SourceCandidate;
use EditTrace\Inspector\TraceContext;
use EditTrace\Inspector\TraceSession;

/**
 * Base class with sensible defaults; concrete providers override what they need.
 */
abstract class AbstractProvider implements SourceProviderInterface {

	public function get_priority(): int {
		return 10;
	}

	public function is_fallback(): bool {
		return false;
	}

	public function register_render_hooks( TraceSession $session ): void {
	}

	public function get_dom_markers(): array {
		return array();
	}

	public function supports( ElementContext $context, TraceContext $trace ): bool {
		return true;
	}

	protected function candidate( string $source_type ): SourceCandidate {
		return SourceCandidate::make( $this->get_name(), $source_type );
	}

	/**
	 * Depth of the nearest node carrying another provider's DOM marker, or
	 * null when none is closer than $own_depth. Markers declare
	 * requiredDatasetKeys / forbiddenDatasetKeys so the check is purely
	 * data-driven and providers need no knowledge of each other.
	 */
	protected function closer_foreign_marker_depth( ElementContext $context, TraceContext $trace, int $own_depth ): ?int {
		$markers = (array) $trace->get( 'dom_markers', array() );
		foreach ( $context->chain() as $depth => $node ) {
			if ( $depth >= $own_depth ) {
				break;
			}
			foreach ( $markers as $marker ) {
				if ( ( $marker['provider'] ?? '' ) === $this->get_name() ) {
					continue;
				}
				if ( self::node_matches_marker( $node, $marker ) ) {
					return $depth;
				}
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $node   Chain node.
	 * @param array<string,mixed> $marker Marker definition.
	 */
	protected static function node_matches_marker( array $node, array $marker ): bool {
		$required = (array) ( $marker['requiredDatasetKeys'] ?? array() );
		if ( empty( $required ) ) {
			return false;
		}
		foreach ( $required as $key ) {
			if ( empty( $node['dataset'][ $key ] ) ) {
				return false;
			}
		}
		foreach ( (array) ( $marker['forbiddenDatasetKeys'] ?? array() ) as $key ) {
			if ( isset( $node['dataset'][ $key ] ) ) {
				return false;
			}
		}
		return true;
	}
}

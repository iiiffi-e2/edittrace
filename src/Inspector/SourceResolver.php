<?php
/**
 * Runs providers and ranks their candidates.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Inspector;

use EditTrace\Providers\SourceProviderInterface;

/**
 * DOM element → ElementContext → providers → ranked SourceResult.
 */
final class SourceResolver {

	/** @var SourceProviderInterface[] */
	private array $providers;

	/**
	 * @param SourceProviderInterface[] $providers Registered providers.
	 */
	public function __construct( array $providers ) {
		$this->providers = $providers;
	}

	/**
	 * @return SourceProviderInterface[] Sorted by priority (highest first).
	 */
	public function get_providers( bool $include_fallback = true ): array {
		$providers = array_filter(
			$this->providers,
			static fn( SourceProviderInterface $p ): bool => $include_fallback || ! $p->is_fallback()
		);
		usort(
			$providers,
			static fn( SourceProviderInterface $a, SourceProviderInterface $b ): int => $b->get_priority() <=> $a->get_priority()
		);
		return array_values( $providers );
	}

	/**
	 * Resolves the element using every non-fallback provider.
	 */
	public function resolve( ElementContext $element, TraceContext $trace ): SourceResult {
		$this->prepare( $trace );
		$candidates = $this->collect( $element, $trace, false );
		$result     = new SourceResult( $element, $trace, $candidates, false );

		/**
		 * Filters the final result.
		 *
		 * @param SourceResult   $result  The result.
		 * @param ElementContext $element The element.
		 * @param TraceContext   $trace   The trace context.
		 */
		return apply_filters( 'edittrace/result', $result, $element, $trace );
	}

	/**
	 * Runs only the fallback providers (on request).
	 */
	public function search( ElementContext $element, TraceContext $trace ): SourceResult {
		$this->prepare( $trace );
		$candidates = array();
		foreach ( $this->get_providers( true ) as $provider ) {
			if ( ! $provider->is_fallback() ) {
				continue;
			}
			$candidates = array_merge( $candidates, $this->run_provider( $provider, $element, $trace ) );
		}
		$candidates = $this->rank( $candidates, $element, $trace );
		$result     = new SourceResult( $element, $trace, $candidates, true );
		return apply_filters( 'edittrace/result', $result, $element, $trace );
	}

	/**
	 * Shares every provider's DOM marker definitions with the context so a
	 * provider can tell whether another system's evidence sits closer to the
	 * clicked element than its own.
	 */
	private function prepare( TraceContext $trace ): void {
		$markers = array();
		foreach ( $this->providers as $provider ) {
			foreach ( $provider->get_dom_markers() as $marker ) {
				$marker['provider']               = $provider->get_name();
				$markers[]                        = $marker;
			}
		}
		$trace->set( 'dom_markers', $markers );
	}

	/**
	 * @return SourceCandidate[]
	 */
	private function collect( ElementContext $element, TraceContext $trace, bool $include_fallback ): array {
		$candidates = array();
		foreach ( $this->get_providers( $include_fallback ) as $provider ) {
			$candidates = array_merge( $candidates, $this->run_provider( $provider, $element, $trace ) );
		}
		return $this->rank( $candidates, $element, $trace );
	}

	/**
	 * @return SourceCandidate[]
	 */
	private function run_provider( SourceProviderInterface $provider, ElementContext $element, TraceContext $trace ): array {
		try {
			if ( ! $provider->supports( $element, $trace ) ) {
				return array();
			}
			$found = $provider->resolve( $element, $trace );
		} catch ( \Throwable $e ) {
			$trace->note( sprintf( '%s provider failed: %s', $provider->get_name(), $e->getMessage() ) );
			return array();
		}
		$out = array();
		foreach ( $found as $candidate ) {
			if ( $candidate instanceof SourceCandidate ) {
				if ( '' === $candidate->provider ) {
					$candidate->provider = $provider->get_name();
				}
				$candidate->technical['providerPriority'] = $provider->get_priority();
				$out[]                                    = $candidate;
			}
		}
		return $out;
	}

	/**
	 * Sorts by confidence, then provider priority; de-duplicates; applies filters.
	 *
	 * @param SourceCandidate[] $candidates Unranked candidates.
	 * @return SourceCandidate[]
	 */
	public function rank( array $candidates, ElementContext $element, TraceContext $trace ): array {
		/**
		 * Filters all candidates before ranking.
		 *
		 * @param SourceCandidate[] $candidates The candidates.
		 * @param ElementContext    $element    The element.
		 * @param TraceContext      $trace      The trace context.
		 */
		$candidates = (array) apply_filters( 'edittrace/source_candidates', $candidates, $element, $trace );
		$candidates = array_values( array_filter( $candidates, static fn( $c ): bool => $c instanceof SourceCandidate ) );

		$unique = array();
		foreach ( $candidates as $candidate ) {
			$key = $candidate->identity();
			if ( ! isset( $unique[ $key ] ) || $unique[ $key ]->confidence < $candidate->confidence ) {
				$unique[ $key ] = $candidate;
			}
		}
		$candidates = array_values( $unique );

		usort(
			$candidates,
			static function ( SourceCandidate $a, SourceCandidate $b ): int {
				if ( $a->confidence !== $b->confidence ) {
					return $b->confidence <=> $a->confidence;
				}
				if ( $a->depth !== $b->depth ) {
					return $a->depth <=> $b->depth;
				}
				return ( $b->technical['providerPriority'] ?? 0 ) <=> ( $a->technical['providerPriority'] ?? 0 );
			}
		);

		return array_slice( $candidates, 0, 10 );
	}
}

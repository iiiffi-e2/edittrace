<?php
declare( strict_types=1 );

namespace EditTrace\Tests\Unit;

use EditTrace\Inspector\ElementContext;
use EditTrace\Inspector\SourceCandidate;
use EditTrace\Inspector\SourceResolver;
use EditTrace\Inspector\TraceContext;
use EditTrace\Providers\AbstractProvider;
use EditTrace\Tests\TestCase;

final class FakeProvider extends AbstractProvider {
	/** @param SourceCandidate[] $candidates */
	public function __construct( private string $name, private int $priority, private array $candidates, private bool $fallback = false, private bool $throws = false ) {}
	public function get_name(): string { return $this->name; }
	public function get_priority(): int { return $this->priority; }
	public function is_fallback(): bool { return $this->fallback; }
	public function resolve( ElementContext $context, TraceContext $trace ): array {
		if ( $this->throws ) {
			throw new \RuntimeException( 'boom' );
		}
		return $this->candidates;
	}
}

final class SourceResolverTest extends TestCase {

	private function cand( string $provider, float $score, int $depth = 0, string $role = 'content', string $id = '' ): SourceCandidate {
		$c            = SourceCandidate::make( $provider, 'x' );
		$c->source_id = '' !== $id ? $id : $provider;
		$c->depth     = $depth;
		$c->role      = $role;
		return $c->with_confidence( $score );
	}

	public function test_ranks_by_confidence_then_depth_then_priority(): void {
		$resolver = new SourceResolver(
			array(
				new FakeProvider( 'weak', 10, array( $this->cand( 'weak', 0.6 ) ) ),
				new FakeProvider( 'far', 100, array( $this->cand( 'far', 1.0, 3 ) ) ),
				new FakeProvider( 'near', 50, array( $this->cand( 'near', 1.0, 1 ) ) ),
				new FakeProvider( 'db', 1, array( $this->cand( 'db', 0.9 ) ), true ),
			)
		);
		$result = $resolver->resolve( $this->element( array( 'tag' => 'a' ) ), $this->trace( null ) );
		$names  = array_map( static fn( SourceCandidate $c ): string => $c->provider, $result->get_candidates() );
		$this->assertSame( array( 'near', 'far', 'weak' ), $names, 'Fallback providers must not run during inspect.' );
		$this->assertSame( 'exact', $result->get_status() );
	}

	public function test_search_only_runs_fallback_providers(): void {
		$resolver = new SourceResolver(
			array(
				new FakeProvider( 'near', 50, array( $this->cand( 'near', 1.0 ) ) ),
				new FakeProvider( 'db', 1, array( $this->cand( 'db', 0.8 ) ), true ),
			)
		);
		$result = $resolver->search( $this->element( array( 'tag' => 'a' ) ), $this->trace( null ) );
		$this->assertSame( array( 'db' ), array_map( static fn( SourceCandidate $c ): string => $c->provider, $result->get_candidates() ) );
		$this->assertSame( 'candidates', $result->get_status() );
	}

	public function test_media_never_outranks_strong_placement_candidate(): void {
		$resolver = new SourceResolver(
			array(
				new FakeProvider( 'media', 50, array( $this->cand( 'media', 1.0, 0, 'media' ) ) ),
				new FakeProvider( 'gutenberg', 100, array( $this->cand( 'gutenberg', 1.0, 1 ) ) ),
			)
		);
		$result = $resolver->resolve( $this->element( array( 'tag' => 'img' ) ), $this->trace( null ) );
		$this->assertSame( 'gutenberg', $result->get_primary()->provider );
		$this->assertCount( 2, $result->get_candidates() );
	}

	public function test_dedupes_and_filters_and_survives_provider_errors(): void {
		$dup1 = $this->cand( 'a', 0.5, 0, 'content', 'same' );
		$dup2 = $this->cand( 'a', 0.9, 0, 'content', 'same' );
		$resolver = new SourceResolver(
			array(
				new FakeProvider( 'a', 10, array( $dup1, $dup2 ) ),
				new FakeProvider( 'broken', 20, array(), false, true ),
			)
		);
		$filter = static function ( array $candidates ): array {
			$extra            = SourceCandidate::make( 'filter', 'x' );
			$extra->source_id = 'f';
			$candidates[]     = $extra->with_confidence( 0.95 );
			return $candidates;
		};
		add_filter( 'edittrace/source_candidates', $filter );
		try {
			$trace  = $this->trace( null );
			$result = $resolver->resolve( $this->element( array( 'tag' => 'a' ) ), $trace );
		} finally {
			remove_filter( 'edittrace/source_candidates', $filter );
		}
		$this->assertSame( array( 'filter', 'a' ), array_map( static fn( SourceCandidate $c ): string => $c->provider, $result->get_candidates() ) );
		$this->assertSame( 0.9, $result->get_candidates()[1]->confidence );
		$this->assertStringContainsString( 'broken provider failed', implode( ' ', $trace->get_notes() ) );
	}

	public function test_result_array_hides_weak_noise_when_exact(): void {
		$resolver = new SourceResolver(
			array(
				new FakeProvider( 'exact', 100, array( $this->cand( 'exact', 1.0 ) ) ),
				new FakeProvider( 'noise', 10, array( $this->cand( 'noise', 0.5 ) ) ),
				new FakeProvider( 'container', 10, array( $this->cand( 'container', 0.5, 2, 'container' ) ) ),
				new FakeProvider( 'unknown', 10, array( $this->cand( 'unknown', 0.2 ) ) ),
			)
		);
		$array = $resolver->resolve( $this->element( array( 'tag' => 'a' ) ), $this->trace( null ) )->to_array();
		$this->assertSame( 'exact', $array['status'] );
		$this->assertSame( array( 'exact', 'container' ), array_column( $array['candidates'], 'provider' ) );
		$this->assertSame( array( 'noise', 'unknown' ), array_column( $array['weak'], 'provider' ) );
	}
}

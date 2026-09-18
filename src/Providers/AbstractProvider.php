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
}

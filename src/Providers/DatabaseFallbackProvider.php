<?php
declare( strict_types=1 );

namespace EditTrace\Providers;

use EditTrace\Inspector\ElementContext;
use EditTrace\Inspector\TraceContext;
use EditTrace\Support\Options;

final class DatabaseFallbackProvider extends AbstractProvider {
	private Options $options;
	public function __construct( Options $options ) {
		$this->options = $options;
	}
	public function get_name(): string {
		return 'database';
	}
	public function is_fallback(): bool {
		return true;
	}
	public function supports( ElementContext $context, TraceContext $trace ): bool {
		return false;
	}
	public function resolve( ElementContext $context, TraceContext $trace ): array {
		return array();
	}
}

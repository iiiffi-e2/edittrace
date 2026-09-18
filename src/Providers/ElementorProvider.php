<?php
declare( strict_types=1 );

namespace EditTrace\Providers;

use EditTrace\Inspector\ElementContext;
use EditTrace\Inspector\TraceContext;

final class ElementorProvider extends AbstractProvider {
	public function get_name(): string {
		return 'elementor';
	}
	public function supports( ElementContext $context, TraceContext $trace ): bool {
		return false;
	}
	public function resolve( ElementContext $context, TraceContext $trace ): array {
		return array();
	}
}

<?php
/**
 * Provider contract.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Providers;

use EditTrace\Inspector\ElementContext;
use EditTrace\Inspector\TraceContext;
use EditTrace\Inspector\SourceCandidate;

/**
 * A provider knows how to map an element context to candidates for one
 * content system (Gutenberg, Elementor, ACF, media...). Providers never
 * touch the DOM; they only see the normalized ElementContext.
 */
interface SourceProviderInterface {

	/**
	 * Unique machine name, e.g. "elementor".
	 */
	public function get_name(): string;

	/**
	 * Higher runs first. Strong-evidence providers use larger numbers.
	 */
	public function get_priority(): int;

	/**
	 * Whether this provider can contribute for the given request.
	 */
	public function supports( ElementContext $context, TraceContext $trace ): bool;

	/**
	 * Returns zero or more candidates.
	 *
	 * @return SourceCandidate[]
	 */
	public function resolve( ElementContext $context, TraceContext $trace ): array;

	/**
	 * Whether this provider is a generic fallback (database search) that
	 * only runs on explicit request rather than on every inspect.
	 */
	public function is_fallback(): bool;

	/**
	 * Hooks the provider needs to register during an authorized frontend
	 * render (instrumentation). Called only for authorized users.
	 */
	public function register_render_hooks( \EditTrace\Inspector\TraceSession $session ): void;

	/**
	 * DOM markers that tell the inspector which elements carry source
	 * information. Each marker: selector, label, typeFromDataset, typeFromClassPrefix.
	 *
	 * @return array<int,array<string,string>>
	 */
	public function get_dom_markers(): array;
}

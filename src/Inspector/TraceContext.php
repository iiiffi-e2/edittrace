<?php
/**
 * Everything a provider may consult while resolving one request.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Inspector;

use EditTrace\Support\Options;

/**
 * Bundles the (optional) restored trace session, plugin settings and a
 * per-request scratch cache that providers can use to share work.
 */
final class TraceContext {

	private ?TraceSession $session;

	private Options $options;

	/** @var array<string,mixed> */
	private array $cache = array();

	/** @var string[] */
	private array $notes = array();

	public function __construct( ?TraceSession $session, Options $options ) {
		$this->session = $session;
		$this->options = $options;
	}

	public function has_session(): bool {
		return null !== $this->session;
	}

	public function get_session(): ?TraceSession {
		return $this->session;
	}

	public function get_registry(): ?TraceRegistry {
		return $this->session ? $this->session->get_registry() : null;
	}

	/**
	 * Page context recorded when the page rendered (empty without a session).
	 *
	 * @return array<string,mixed>
	 */
	public function get_page(): array {
		return $this->session ? $this->session->get_page() : array();
	}

	public function get_options(): Options {
		return $this->options;
	}

	public function is_debug(): bool {
		return $this->options->is_debug();
	}

	/**
	 * Per-request memo shared between providers.
	 *
	 * @param mixed $default Default value.
	 * @return mixed
	 */
	public function remember( string $key, callable $compute ) {
		if ( ! array_key_exists( $key, $this->cache ) ) {
			$this->cache[ $key ] = $compute();
		}
		return $this->cache[ $key ];
	}

	/**
	 * @param mixed $value Value to store.
	 */
	public function set( string $key, $value ): void {
		$this->cache[ $key ] = $value;
	}

	/**
	 * @param mixed $default Default value.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		return $this->cache[ $key ] ?? $default;
	}

	/**
	 * Adds a diagnostic note shown in Technical Details.
	 */
	public function note( string $note ): void {
		$this->notes[] = $note;
	}

	/**
	 * @return string[]
	 */
	public function get_notes(): array {
		return $this->notes;
	}
}

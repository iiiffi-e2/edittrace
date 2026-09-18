<?php
/**
 * In-memory registry of source information collected during one render.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Inspector;

/**
 * Integrations register entries while the page renders (blocks, ACF fields,
 * menu items...). Each entry gets a short opaque id which may be placed in
 * the HTML; the entry data itself stays server-side.
 */
final class TraceRegistry {

	public const MAX_ENTRIES = 4000;

	/** @var array<string,array<string,mixed>> */
	private array $entries = array();

	private int $counter = 0;

	/** @var array<string,int> */
	private array $counts = array();

	/**
	 * Registers an entry and returns its opaque id (e.g. "t42").
	 *
	 * @param string              $kind Entry kind (block, acf_field, menu_item ...).
	 * @param array<string,mixed> $data Entry data. Must be serializable.
	 * @return string|null The id, or null when the registry is full.
	 */
	public function register( string $kind, array $data ): ?string {
		if ( count( $this->entries ) >= self::MAX_ENTRIES ) {
			return null;
		}
		++$this->counter;
		$id                   = 't' . base_convert( (string) $this->counter, 10, 36 );
		$data['kind']         = $kind;
		$data['id']           = $id;
		$this->entries[ $id ] = $data;
		$this->counts[ $kind ] = ( $this->counts[ $kind ] ?? 0 ) + 1;
		return $id;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get( string $id ): ?array {
		return $this->entries[ $id ] ?? null;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function all(): array {
		return $this->entries;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function by_kind( string $kind ): array {
		return array_values(
			array_filter(
				$this->entries,
				static fn( array $entry ): bool => ( $entry['kind'] ?? '' ) === $kind
			)
		);
	}

	public function count( ?string $kind = null ): int {
		if ( null === $kind ) {
			return count( $this->entries );
		}
		return $this->counts[ $kind ] ?? 0;
	}

	/**
	 * Mutates a stored entry (e.g. to attach data learned after registration).
	 *
	 * @param array<string,mixed> $data Data to merge.
	 */
	public function update( string $id, array $data ): void {
		if ( isset( $this->entries[ $id ] ) ) {
			$this->entries[ $id ] = array_merge( $this->entries[ $id ], $data );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'entries' => $this->entries,
			'counter' => $this->counter,
		);
	}

	/**
	 * @param array<string,mixed> $data Serialized registry.
	 */
	public static function from_array( array $data ): self {
		$registry          = new self();
		$registry->entries = isset( $data['entries'] ) && is_array( $data['entries'] ) ? $data['entries'] : array();
		$registry->counter = (int) ( $data['counter'] ?? count( $registry->entries ) );
		foreach ( $registry->entries as $entry ) {
			$kind                       = (string) ( $entry['kind'] ?? '' );
			$registry->counts[ $kind ] = ( $registry->counts[ $kind ] ?? 0 ) + 1;
		}
		return $registry;
	}
}

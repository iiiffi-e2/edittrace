<?php
/**
 * Storage contract for trace registries.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Inspector;

/**
 * Short-lived, server-side storage for per-request trace data.
 */
interface TraceStorage {

	/**
	 * @param string              $token Trace token.
	 * @param array<string,mixed> $data  Serializable trace payload.
	 * @param int                 $ttl   Lifetime in seconds.
	 */
	public function save( string $token, array $data, int $ttl ): bool;

	/**
	 * @return array<string,mixed>|null
	 */
	public function load( string $token ): ?array;

	public function delete( string $token ): void;
}

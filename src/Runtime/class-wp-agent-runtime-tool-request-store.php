<?php
/**
 * External runtime tool request store interface.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Host-provided persistence boundary for pending runtime tool requests.
 */
interface WP_Agent_Runtime_Tool_Request_Store {

	/**
	 * Create or replace a pending runtime tool request.
	 *
	 * @param array<string, mixed> $request Normalized runtime tool request.
	 */
	public function create( array $request ): void;

	/**
	 * Read a runtime tool request by id.
	 *
	 * Stores may retain terminal records after completion or timeout. Completed
	 * records that can expose the prior submitted result should keep that
	 * normalized result under `result` so duplicate submissions can return the
	 * original completion without overwriting it.
	 *
	 * @param string $request_id Runtime tool request id.
	 * @return array<string, mixed>|null Normalized request or null when absent.
	 */
	public function get( string $request_id ): ?array;

	/**
	 * Mark a pending request complete with a client-submitted result.
	 *
	 * Implementations must transition only pending records and should back the
	 * write with a conditional/compare-and-set update so concurrent completions
	 * of the same request cannot both succeed. Return `true` only when this call
	 * transitioned a pending record to complete (the caller "won" the exact-once
	 * completion). Return `false` when the record was already terminal, so the
	 * caller can re-read the retained winner result and treat the call as a
	 * duplicate instead of firing completion side effects a second time.
	 *
	 * Duplicate completions for terminal records must leave existing store data
	 * unchanged.
	 *
	 * @param string               $request_id Runtime tool request id.
	 * @param array<string, mixed> $result Normalized runtime tool result.
	 * @return bool True when this call transitioned a pending record to complete.
	 */
	public function complete( string $request_id, array $result ): bool;

	/**
	 * Mark a pending request timed out.
	 *
	 * Implementations should transition only pending records. The lifecycle layer
	 * short-circuits terminal records before calling this method, so a timeout of
	 * an already-terminal request is treated as an idempotent duplicate and this
	 * method is not invoked again.
	 *
	 * @param string $request_id Runtime tool request id.
	 */
	public function timeout( string $request_id ): void;

	/**
	 * Return recent pending requests for timeout scans or client polling.
	 *
	 * Implementations own concrete filtering semantics, but should support
	 * product-neutral query keys such as `run_id`, `tool_name`, `before`, and
	 * `limit` when they are meaningful for the host store.
	 *
	 * @param array<string, mixed> $query Product-neutral query hints.
	 * @return array<int, array<string, mixed>> Normalized pending requests.
	 */
	public function recent_pending( array $query = array() ): array;
}

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
	 * Read a pending runtime tool request by id.
	 *
	 * @param string $request_id Runtime tool request id.
	 * @return array<string, mixed>|null Normalized request or null when absent.
	 */
	public function get( string $request_id ): ?array;

	/**
	 * Mark a pending request complete with a client-submitted result.
	 *
	 * @param string               $request_id Runtime tool request id.
	 * @param array<string, mixed> $result Normalized runtime tool result.
	 */
	public function complete( string $request_id, array $result ): void;

	/**
	 * Mark a pending request timed out.
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

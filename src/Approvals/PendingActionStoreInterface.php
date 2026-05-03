<?php
/**
 * Pending Action Store Interface
 *
 * Generic persistence contract for actions that must be resumed after an
 * external approval or rejection. The contract deliberately describes only the
 * pending-action payload lifecycle; concrete storage, routing, UI, and
 * scheduling behavior stay in consumers.
 *
 * Payloads MUST remain JSON-serializable. Consumers may store richer payloads
 * when they need additional context for approval prompts, diffs, audit data, or
 * continuation state.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\AI\Approvals;

defined( 'ABSPATH' ) || exit;

interface PendingActionStoreInterface {

	/**
	 * Persist a pending action payload under a caller-provided action ID.
	 *
	 * @param string              $action_id Durable action identifier.
	 * @param array<string,mixed> $payload   JSON-serializable pending action payload.
	 * @return bool Whether the payload was stored successfully.
	 */
	public function store( string $action_id, array $payload ): bool;

	/**
	 * Retrieve a pending action payload by action ID.
	 *
	 * @param string $action_id Durable action identifier.
	 * @return array<string,mixed>|null Pending action payload, or null when not found.
	 */
	public function get( string $action_id ): ?array;

	/**
	 * Delete a pending action payload by action ID.
	 *
	 * Implementations SHOULD make delete idempotent for missing action IDs.
	 *
	 * @param string $action_id Durable action identifier.
	 * @return bool Whether the delete operation completed successfully.
	 */
	public function delete( string $action_id ): bool;
}

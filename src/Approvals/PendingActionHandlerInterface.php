<?php
/**
 * Generic pending-action handler contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Approvals;

defined( 'ABSPATH' ) || exit;

interface PendingActionHandlerInterface {

	/**
	 * Check whether the resolver may resolve a stored pending action.
	 *
	 * Resolver implementations SHOULD call this before applying or rejecting an
	 * action. Returning false denies resolution without encoding product policy in
	 * Agents API itself.
	 *
	 * @param PendingAction    $action   Stored pending action.
	 * @param ApprovalDecision $decision Accepted/rejected decision.
	 * @param array            $payload  Fresh resolver payload supplied with the decision.
	 * @param array            $context  Optional caller context.
	 * @return bool Whether resolution is allowed.
	 */
	public function can_resolve_pending_action( PendingAction $action, ApprovalDecision $decision, array $payload = array(), array $context = array() ): bool;

	/**
	 * Resolve a stored pending action with a caller-provided decision.
	 *
	 * Product-specific apply/reject behavior stays in consumer handlers. Agents API
	 * only defines the generic handoff shape.
	 *
	 * @param PendingAction    $action   Stored pending action.
	 * @param ApprovalDecision $decision Accepted/rejected decision.
	 * @param array            $payload  Fresh resolver payload supplied with the decision.
	 * @param array            $context  Optional caller context.
	 * @return mixed Generic implementation result.
	 */
	public function handle_pending_action( PendingAction $action, ApprovalDecision $decision, array $payload = array(), array $context = array() ): mixed;
}

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
	 * Resolve a stored pending action with a caller-provided decision.
	 *
	 * The apply input is the implementation-owned data captured when the action
	 * was queued. The payload is fresh resolver input supplied with the decision.
	 * Permission checks, persistence, and transport concerns stay with callers.
	 *
	 * @param ApprovalDecision $decision    Accepted/rejected decision.
	 * @param array            $apply_input Stored apply input for the pending action.
	 * @param array            $payload     Fresh resolver payload supplied with the decision.
	 * @param array            $context     Optional caller context.
	 * @return mixed Generic implementation result.
	 */
	public function handle_pending_action( ApprovalDecision $decision, array $apply_input, array $payload = array(), array $context = array() ): mixed;
}

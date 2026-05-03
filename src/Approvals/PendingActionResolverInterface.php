<?php
/**
 * Generic pending-action resolver contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Approvals;

defined( 'ABSPATH' ) || exit;

interface PendingActionResolverInterface {

	/**
	 * Resolve a pending action by identifier.
	 *
	 * Implementations own lookup and persistence. Callers own authentication and
	 * authorization before invoking the resolver.
	 *
	 * @param string           $pending_action_id Stable pending-action identifier.
	 * @param ApprovalDecision $decision          Accepted/rejected decision.
	 * @param array            $payload           Fresh resolver payload supplied with the decision.
	 * @param array            $context           Optional caller context.
	 * @return mixed Generic resolver result.
	 */
	public function resolve_pending_action( string $pending_action_id, ApprovalDecision $decision, array $payload = array(), array $context = array() ): mixed;
}

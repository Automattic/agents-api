<?php
/**
 * Pure-PHP smoke test for pending-action approval resolver contracts.
 *
 * Run with: php tests/approval-resolver-contract-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-approval-resolver-contract-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

$accepted = AgentsAPI\AI\Approvals\ApprovalDecision::accepted();
$rejected = AgentsAPI\AI\Approvals\ApprovalDecision::from_string( AgentsAPI\AI\Approvals\ApprovalDecision::REJECTED );

agents_api_smoke_assert_equals( 'accepted', AgentsAPI\AI\Approvals\ApprovalDecision::ACCEPTED, 'accepted vocabulary is stable', $failures, $passes );
agents_api_smoke_assert_equals( 'rejected', AgentsAPI\AI\Approvals\ApprovalDecision::REJECTED, 'rejected vocabulary is stable', $failures, $passes );
agents_api_smoke_assert_equals( 'accepted', $accepted->value(), 'accepted decision exposes normalized value', $failures, $passes );
agents_api_smoke_assert_equals( true, $accepted->is_accepted(), 'accepted decision reports accepted', $failures, $passes );
agents_api_smoke_assert_equals( false, $accepted->is_rejected(), 'accepted decision does not report rejected', $failures, $passes );
agents_api_smoke_assert_equals( 'rejected', (string) $rejected, 'rejected decision stringifies to normalized value', $failures, $passes );
agents_api_smoke_assert_equals( true, $rejected->is_rejected(), 'rejected decision reports rejected', $failures, $passes );
agents_api_smoke_assert_equals( array( 'pending', 'accepted', 'rejected', 'expired', 'deleted' ), AgentsAPI\AI\Approvals\PendingActionStatus::values(), 'status vocabulary is stable', $failures, $passes );
agents_api_smoke_assert_equals( true, AgentsAPI\AI\Approvals\PendingActionStatus::is_terminal( AgentsAPI\AI\Approvals\PendingActionStatus::ACCEPTED ), 'accepted status is terminal', $failures, $passes );

$action = AgentsAPI\AI\Approvals\PendingAction::from_array(
	array(
		'action_id'   => 'diff-123',
		'kind'        => 'file_patch',
		'summary'     => 'Apply a patch.',
		'preview'     => array( 'diff' => '...' ),
		'apply_input' => array( 'target' => 'diff-123' ),
		'workspace'   => array(
			'workspace_type' => 'code_workspace',
			'workspace_id'   => 'Automattic/agents-api@durable-approvals',
		),
		'agent'       => 'agent-reviewer',
		'creator'     => 'user-7',
		'created_at'  => '2026-05-03T15:00:00Z',
	)
);

$handler = new class() implements AgentsAPI\AI\Approvals\PendingActionHandlerInterface {
	public function can_resolve_pending_action( AgentsAPI\AI\Approvals\PendingAction $action, AgentsAPI\AI\Approvals\ApprovalDecision $decision, array $payload = array(), array $context = array() ): bool {
		return 'blocked' !== ( $payload['reason'] ?? null ) && 'Automattic/agents-api@durable-approvals' === $action->get_workspace()->workspace_id;
	}

	public function handle_pending_action( AgentsAPI\AI\Approvals\PendingAction $action, AgentsAPI\AI\Approvals\ApprovalDecision $decision, array $payload = array(), array $context = array() ): mixed {
		return array(
			'decision'  => $decision->value(),
			'target'    => $action->get_apply_input()['target'] ?? null,
			'workspace' => $action->get_workspace()->to_array(),
			'reason'    => $payload['reason'] ?? null,
			'actor'     => $context['actor'] ?? null,
		);
	}
};

$handled = $handler->handle_pending_action(
	$action,
	$accepted,
	array( 'reason' => 'looks-good' ),
	array( 'actor' => 'reviewer' )
);

agents_api_smoke_assert_equals( true, $handler->can_resolve_pending_action( $action, $accepted, array( 'reason' => 'looks-good' ) ), 'handler-level permission check can allow resolution', $failures, $passes );
agents_api_smoke_assert_equals( false, $handler->can_resolve_pending_action( $action, $accepted, array( 'reason' => 'blocked' ) ), 'handler-level permission check can deny resolution', $failures, $passes );
agents_api_smoke_assert_equals( 'accepted', $handled['decision'], 'handler receives decision object', $failures, $passes );
agents_api_smoke_assert_equals( 'diff-123', $handled['target'], 'handler receives stored action apply input', $failures, $passes );
agents_api_smoke_assert_equals( array( 'workspace_type' => 'code_workspace', 'workspace_id' => 'Automattic/agents-api@durable-approvals' ), $handled['workspace'], 'handler receives stored workspace scope', $failures, $passes );
agents_api_smoke_assert_equals( 'looks-good', $handled['reason'], 'handler receives resolver payload', $failures, $passes );
agents_api_smoke_assert_equals( 'reviewer', $handled['actor'], 'handler receives optional context', $failures, $passes );

$resolver = new class( $handler ) implements AgentsAPI\AI\Approvals\PendingActionResolverInterface {
	public function __construct( private AgentsAPI\AI\Approvals\PendingActionHandlerInterface $handler ) {}

	public function resolve_pending_action( string $pending_action_id, AgentsAPI\AI\Approvals\ApprovalDecision $decision, string $resolver, array $payload = array(), array $context = array() ): mixed {
		$action = AgentsAPI\AI\Approvals\PendingAction::from_array(
			array(
				'action_id'   => $pending_action_id,
				'kind'        => 'file_patch',
				'summary'     => 'Apply a patch.',
				'preview'     => array( 'diff' => '...' ),
				'apply_input' => array( 'target' => $pending_action_id ),
				'workspace'   => array(
					'workspace_type' => 'code_workspace',
					'workspace_id'   => 'Automattic/agents-api@durable-approvals',
				),
				'created_at'  => '2026-05-03T15:00:00Z',
			)
		);

		if ( ! $this->handler->can_resolve_pending_action( $action, $decision, $payload, $context ) ) {
			return array( 'success' => false, 'resolver' => $resolver );
		}

		return $this->handler->handle_pending_action(
			$action,
			$decision,
			$payload,
			$context
		) + array( 'resolver' => $resolver );
	}
};

$resolved = $resolver->resolve_pending_action(
	'diff-456',
	AgentsAPI\AI\Approvals\ApprovalDecision::rejected(),
	'user-9',
	array( 'reason' => 'needs-work' )
);

agents_api_smoke_assert_equals( 'rejected', $resolved['decision'], 'resolver receives rejected decision', $failures, $passes );
agents_api_smoke_assert_equals( 'diff-456', $resolved['target'], 'resolver maps pending action id to stored input', $failures, $passes );
agents_api_smoke_assert_equals( 'needs-work', $resolved['reason'], 'resolver forwards payload to handler', $failures, $passes );
agents_api_smoke_assert_equals( 'user-9', $resolved['resolver'], 'resolver receives resolver audit identity', $failures, $passes );

try {
	AgentsAPI\AI\Approvals\ApprovalDecision::from_string( 'approved' );
	agents_api_smoke_assert_equals( true, false, 'unknown decision is rejected', $failures, $passes );
} catch ( InvalidArgumentException $e ) {
	agents_api_smoke_assert_equals( true, str_contains( $e->getMessage(), 'accepted or rejected' ), 'unknown decision is rejected', $failures, $passes );
}

agents_api_smoke_finish( 'Agents API approval resolver contract', $failures, $passes );

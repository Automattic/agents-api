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

$handler = new class() implements AgentsAPI\AI\Approvals\PendingActionHandlerInterface {
	public function handle_pending_action( AgentsAPI\AI\Approvals\ApprovalDecision $decision, array $apply_input, array $payload = array(), array $context = array() ): mixed {
		return array(
			'decision' => $decision->value(),
			'target'   => $apply_input['target'] ?? null,
			'reason'   => $payload['reason'] ?? null,
			'actor'    => $context['actor'] ?? null,
		);
	}
};

$handled = $handler->handle_pending_action(
	$accepted,
	array( 'target' => 'diff-123' ),
	array( 'reason' => 'looks-good' ),
	array( 'actor' => 'reviewer' )
);

agents_api_smoke_assert_equals( 'accepted', $handled['decision'], 'handler receives decision object', $failures, $passes );
agents_api_smoke_assert_equals( 'diff-123', $handled['target'], 'handler receives stored apply input', $failures, $passes );
agents_api_smoke_assert_equals( 'looks-good', $handled['reason'], 'handler receives resolver payload', $failures, $passes );
agents_api_smoke_assert_equals( 'reviewer', $handled['actor'], 'handler receives optional context', $failures, $passes );

$resolver = new class( $handler ) implements AgentsAPI\AI\Approvals\PendingActionResolverInterface {
	public function __construct( private AgentsAPI\AI\Approvals\PendingActionHandlerInterface $handler ) {}

	public function resolve_pending_action( string $pending_action_id, AgentsAPI\AI\Approvals\ApprovalDecision $decision, array $payload = array(), array $context = array() ): mixed {
		return $this->handler->handle_pending_action(
			$decision,
			array( 'target' => $pending_action_id ),
			$payload,
			$context
		);
	}
};

$resolved = $resolver->resolve_pending_action(
	'diff-456',
	AgentsAPI\AI\Approvals\ApprovalDecision::rejected(),
	array( 'reason' => 'needs-work' )
);

agents_api_smoke_assert_equals( 'rejected', $resolved['decision'], 'resolver receives rejected decision', $failures, $passes );
agents_api_smoke_assert_equals( 'diff-456', $resolved['target'], 'resolver maps pending action id to stored input', $failures, $passes );
agents_api_smoke_assert_equals( 'needs-work', $resolved['reason'], 'resolver forwards payload to handler', $failures, $passes );

try {
	AgentsAPI\AI\Approvals\ApprovalDecision::from_string( 'approved' );
	agents_api_smoke_assert_equals( true, false, 'unknown decision is rejected', $failures, $passes );
} catch ( InvalidArgumentException $e ) {
	agents_api_smoke_assert_equals( true, str_contains( $e->getMessage(), 'accepted or rejected' ), 'unknown decision is rejected', $failures, $passes );
}

agents_api_smoke_finish( 'Agents API approval resolver contract', $failures, $passes );

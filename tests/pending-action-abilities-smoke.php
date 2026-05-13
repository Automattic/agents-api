<?php
/**
 * Pure-PHP smoke test for pending-action abilities.
 *
 * Run with: php tests/pending-action-abilities-smoke.php
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "pending-action-abilities-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function current_user_can( string $cap ): bool { unset( $cap ); return $GLOBALS['__can'] ?? false; }

require_once __DIR__ . '/../src/Workspace/class-wp-agent-workspace-scope.php';
require_once __DIR__ . '/../src/Approvals/class-wp-agent-pending-action-store.php';
require_once __DIR__ . '/../src/Approvals/class-wp-agent-pending-action-status.php';
require_once __DIR__ . '/../src/Approvals/class-wp-agent-pending-action.php';
require_once __DIR__ . '/../src/Approvals/class-wp-agent-approval-decision.php';
require_once __DIR__ . '/../src/Approvals/class-wp-agent-pending-action-resolver.php';
require_once __DIR__ . '/../src/Approvals/register-pending-action-abilities.php';

use AgentsAPI\AI\Approvals\WP_Agent_Approval_Decision;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Resolver;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Store;
use function AgentsAPI\AI\Approvals\agents_get_pending_action;
use function AgentsAPI\AI\Approvals\agents_get_pending_action_resolver;
use function AgentsAPI\AI\Approvals\agents_get_pending_action_store;
use function AgentsAPI\AI\Approvals\agents_list_pending_actions;
use function AgentsAPI\AI\Approvals\agents_pending_action_permission;
use function AgentsAPI\AI\Approvals\agents_resolve_pending_action;
use function AgentsAPI\AI\Approvals\agents_summary_pending_actions;

$action = WP_Agent_Pending_Action::from_array(
	array(
		'action_id'   => 'pa_123',
		'kind'        => 'demo/action',
		'summary'     => 'Approve demo action',
		'preview'     => array( 'message' => 'hello' ),
		'apply_input' => array( 'message' => 'hello' ),
		'created_at'  => '2026-05-12T00:00:00Z',
	)
);

$store = new class( $action ) implements WP_Agent_Pending_Action_Store {
	public array $last_filters = array();

	public function __construct( private WP_Agent_Pending_Action $action ) {}
	public function store( WP_Agent_Pending_Action $action ): bool { unset( $action ); return true; }
	public function get( string $action_id, bool $include_resolved = false ): ?WP_Agent_Pending_Action {
		return 'pa_123' === $action_id && ! $include_resolved ? $this->action : null;
	}
	public function list( array $filters = array() ): array {
		$this->last_filters = $filters;
		return array( $this->action );
	}
	public function summary( array $filters = array() ): array {
		$this->last_filters = $filters;
		return array( 'total' => 1, 'by_status' => array( 'pending' => 1 ) );
	}
	public function record_resolution( string $action_id, WP_Agent_Approval_Decision $decision, string $resolver, $result = null, ?string $error = null, array $metadata = array() ): bool {
		unset( $action_id, $decision, $resolver, $result, $error, $metadata );
		return true;
	}
	public function expire( ?string $before = null ): int { unset( $before ); return 0; }
	public function delete( string $action_id ): bool { unset( $action_id ); return true; }
};

$resolver = new class() implements WP_Agent_Pending_Action_Resolver {
	public array $calls = array();

	public function resolve_pending_action( string $pending_action_id, WP_Agent_Approval_Decision $decision, string $resolver, array $payload = array(), array $context = array() ): mixed {
		$this->calls[] = compact( 'pending_action_id', 'decision', 'resolver', 'payload', 'context' );
		return array( 'ok' => true );
	}
};

$GLOBALS['__can'] = false;
agents_api_smoke_assert_equals( false, agents_pending_action_permission( array() ), 'permission defaults to manage_options denial', $failures, $passes );
$GLOBALS['__can'] = true;
agents_api_smoke_assert_equals( true, agents_pending_action_permission( array() ), 'permission allows manage_options', $failures, $passes );

agents_api_smoke_assert_equals( null, agents_get_pending_action_store(), 'store discovery returns null without host filter', $failures, $passes );
add_filter( 'wp_agent_pending_action_store', static fn() => $store );
add_filter( 'wp_agent_pending_action_resolver', static fn() => $resolver );

agents_api_smoke_assert_equals( true, agents_get_pending_action_store() instanceof WP_Agent_Pending_Action_Store, 'store discovery uses filter', $failures, $passes );
agents_api_smoke_assert_equals( true, agents_get_pending_action_resolver() instanceof WP_Agent_Pending_Action_Resolver, 'resolver discovery uses filter', $failures, $passes );

$listed = agents_list_pending_actions( array( 'filters' => array( 'status' => 'pending' ) ) );
agents_api_smoke_assert_equals( 'pa_123', $listed['actions'][0]['action_id'] ?? '', 'list returns pending action arrays', $failures, $passes );
agents_api_smoke_assert_equals( array( 'status' => 'pending' ), $store->last_filters, 'list forwards filters', $failures, $passes );

$summary = agents_summary_pending_actions( array( 'filters' => array( 'kind' => 'demo/action' ) ) );
agents_api_smoke_assert_equals( 1, $summary['total'] ?? 0, 'summary returns store summary', $failures, $passes );
agents_api_smoke_assert_equals( array( 'kind' => 'demo/action' ), $store->last_filters, 'summary forwards filters', $failures, $passes );

$found = agents_get_pending_action( array( 'action_id' => 'pa_123' ) );
agents_api_smoke_assert_equals( 'Approve demo action', $found['action']['summary'] ?? '', 'get returns pending action array', $failures, $passes );

$missing = agents_get_pending_action( array( 'action_id' => 'missing' ) );
agents_api_smoke_assert_equals( true, array_key_exists( 'action', $missing ) && null === $missing['action'], 'get returns null for missing action', $failures, $passes );

$resolved = agents_resolve_pending_action(
	array(
		'action_id' => 'pa_123',
		'decision'  => 'accepted',
		'resolver'  => 'user:1',
		'payload'   => array( 'note' => 'ship it' ),
		'context'   => array( 'surface' => 'chat' ),
	)
);
agents_api_smoke_assert_equals( 'accepted', $resolved['decision'] ?? '', 'resolve returns normalized decision', $failures, $passes );
agents_api_smoke_assert_equals( array( 'ok' => true ), $resolved['result'] ?? null, 'resolve returns resolver result', $failures, $passes );
agents_api_smoke_assert_equals( 'user:1', $resolver->calls[0]['resolver'] ?? '', 'resolve forwards resolver id', $failures, $passes );
agents_api_smoke_assert_equals( array( 'surface' => 'chat' ), $resolver->calls[0]['context'] ?? array(), 'resolve forwards context', $failures, $passes );

$invalid_decision = agents_resolve_pending_action( array( 'action_id' => 'pa_123', 'decision' => 'approved', 'resolver' => 'user:1' ) );
agents_api_smoke_assert_equals( true, $invalid_decision instanceof WP_Error, 'invalid decision returns WP_Error', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_pending_action_invalid_decision', $invalid_decision instanceof WP_Error ? $invalid_decision->get_error_code() : '', 'invalid decision error code', $failures, $passes );

$GLOBALS['__agents_api_smoke_actions'] = array();
$no_store = agents_list_pending_actions( array() );
agents_api_smoke_assert_equals( true, $no_store instanceof WP_Error, 'missing store returns WP_Error', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_pending_action_no_store', $no_store instanceof WP_Error ? $no_store->get_error_code() : '', 'missing store error code', $failures, $passes );

$no_resolver = agents_resolve_pending_action( array( 'action_id' => 'pa_123', 'decision' => 'accepted', 'resolver' => 'user:1' ) );
agents_api_smoke_assert_equals( true, $no_resolver instanceof WP_Error, 'missing resolver returns WP_Error', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_pending_action_no_resolver', $no_resolver instanceof WP_Error ? $no_resolver->get_error_code() : '', 'missing resolver error code', $failures, $passes );

agents_api_smoke_finish( 'pending action abilities', $failures, $passes );

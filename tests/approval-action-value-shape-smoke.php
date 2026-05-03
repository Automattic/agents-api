<?php
/**
 * Pure-PHP smoke test for the Agents API pending approval action value shape.
 *
 * Run with: php tests/approval-action-value-shape-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-approval-action-value-shape-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

echo "\n[1] Array input round-trips through the pending action shape:\n";
$raw = array(
	'action_id'   => 'approve-123',
	'kind'        => 'content_update',
	'summary'     => 'Update the public content summary.',
	'preview'     => array(
		'before' => 'Old summary',
		'after'  => 'New summary',
	),
	'apply_input' => array(
		'target_id' => 'content-42',
		'changes'   => array( 'summary' => 'New summary' ),
	),
	'created_by'  => 'user-7',
	'agent_id'    => 'agent-reviewer',
	'context'     => array(
		'source' => 'runtime',
		'trace'  => array( 'request_id' => 'req-abc' ),
	),
	'created_at'  => '2026-05-03T15:00:00Z',
	'expires_at'  => '2026-05-04T15:00:00Z',
);

$action = AgentsAPI\AI\Approvals\PendingAction::from_array( $raw );
$array  = $action->to_array();

agents_api_smoke_assert_equals( $raw, $array, 'canonical array preserves all public fields', $failures, $passes );
agents_api_smoke_assert_equals( 'approve-123', $action->get_action_id(), 'action id getter returns canonical id', $failures, $passes );
agents_api_smoke_assert_equals( 'content_update', $action->get_kind(), 'kind getter returns generic kind', $failures, $passes );
agents_api_smoke_assert_equals( array( 'source' => 'runtime', 'trace' => array( 'request_id' => 'req-abc' ) ), $action->get_context(), 'context getter returns context', $failures, $passes );

echo "\n[2] Optional identity and expiration fields can be absent:\n";
$minimal = AgentsAPI\AI\Approvals\PendingAction::from_array(
	array(
		'action_id'   => 'approve-124',
		'kind'        => 'file_patch',
		'summary'     => 'Review a proposed patch.',
		'preview'     => 'diff --git a/example b/example',
		'apply_input' => array( 'patch' => '...' ),
		'created_at'  => '2026-05-03T15:10:00Z',
	)
)->to_array();

agents_api_smoke_assert_equals( null, $minimal['created_by'], 'created_by defaults to null', $failures, $passes );
agents_api_smoke_assert_equals( null, $minimal['agent_id'], 'agent_id defaults to null', $failures, $passes );
agents_api_smoke_assert_equals( array(), $minimal['context'], 'context defaults to empty array', $failures, $passes );
agents_api_smoke_assert_equals( null, $minimal['expires_at'], 'expires_at defaults to null', $failures, $passes );

echo "\n[3] Invalid fields are rejected generically:\n";
$invalid_cases = array(
	'missing action_id'       => array( 'kind' => 'update', 'summary' => 'Summary', 'preview' => array(), 'apply_input' => array(), 'created_at' => 'now' ),
	'invalid action_id'       => array( 'action_id' => 123, 'kind' => 'update', 'summary' => 'Summary', 'preview' => array(), 'apply_input' => array(), 'created_at' => 'now' ),
	'empty kind'              => array( 'action_id' => 'approve-1', 'kind' => '', 'summary' => 'Summary', 'preview' => array(), 'apply_input' => array(), 'created_at' => 'now' ),
	'invalid context'         => array( 'action_id' => 'approve-1', 'kind' => 'update', 'summary' => 'Summary', 'preview' => array(), 'apply_input' => array(), 'context' => 'nope', 'created_at' => 'now' ),
	'non-serializable input'  => array( 'action_id' => 'approve-1', 'kind' => 'update', 'summary' => 'Summary', 'preview' => array(), 'apply_input' => tmpfile(), 'created_at' => 'now' ),
	'invalid optional string' => array( 'action_id' => 'approve-1', 'kind' => 'update', 'summary' => 'Summary', 'preview' => array(), 'apply_input' => array(), 'created_by' => '', 'created_at' => 'now' ),
);

foreach ( $invalid_cases as $name => $invalid_action ) {
	$thrown = false;
	try {
		AgentsAPI\AI\Approvals\PendingAction::from_array( $invalid_action );
	} catch ( InvalidArgumentException $error ) {
		$thrown = 0 === strpos( $error->getMessage(), 'invalid_ai_pending_action:' );
	}
	agents_api_smoke_assert_equals( true, $thrown, $name . ' throws contract exception', $failures, $passes );
}

agents_api_smoke_finish( 'Agents API approval action value shape', $failures, $passes );

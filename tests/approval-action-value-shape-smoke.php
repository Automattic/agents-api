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
	'action_id'           => 'approve-123',
	'kind'                => 'content_update',
	'summary'             => 'Update the public content summary.',
	'preview'             => array(
		'before' => 'Old summary',
		'after'  => 'New summary',
	),
	'apply_input'         => array(
		'target_id' => 'content-42',
		'changes'   => array( 'summary' => 'New summary' ),
	),
	'workspace'           => array(
		'workspace_type' => 'code_workspace',
		'workspace_id'   => 'Automattic/agents-api@durable-approvals',
	),
	'agent'               => 'agent-reviewer',
	'creator'             => 'user-7',
	'status'              => AgentsAPI\AI\Approvals\PendingActionStatus::PENDING,
	'created_at'          => '2026-05-03T15:00:00Z',
	'expires_at'          => '2026-05-04T15:00:00Z',
	'resolved_at'         => null,
	'resolver'            => null,
	'resolution_result'   => null,
	'resolution_error'    => null,
	'resolution_metadata' => array(),
	'metadata'            => array(
		'source' => 'runtime',
		'trace'  => array( 'request_id' => 'req-abc' ),
	),
);

$action = AgentsAPI\AI\Approvals\PendingAction::from_array( $raw );
$array  = $action->to_array();

agents_api_smoke_assert_equals( $raw, $array, 'canonical array preserves all public fields', $failures, $passes );
agents_api_smoke_assert_equals( 'approve-123', $action->get_action_id(), 'action id getter returns canonical id', $failures, $passes );
agents_api_smoke_assert_equals( 'content_update', $action->get_kind(), 'kind getter returns generic kind', $failures, $passes );
agents_api_smoke_assert_equals( array( 'workspace_type' => 'code_workspace', 'workspace_id' => 'Automattic/agents-api@durable-approvals' ), $action->get_workspace()->to_array(), 'workspace getter returns generic workspace scope', $failures, $passes );
agents_api_smoke_assert_equals( 'agent-reviewer', $action->get_agent(), 'agent getter returns generic agent', $failures, $passes );
agents_api_smoke_assert_equals( 'user-7', $action->get_creator(), 'creator getter returns generic creator', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\Approvals\PendingActionStatus::PENDING, $action->get_status(), 'status getter returns canonical status', $failures, $passes );
agents_api_smoke_assert_equals( array( 'source' => 'runtime', 'trace' => array( 'request_id' => 'req-abc' ) ), $action->get_metadata(), 'metadata getter returns metadata', $failures, $passes );

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

agents_api_smoke_assert_equals( null, $minimal['workspace'], 'workspace defaults to null', $failures, $passes );
agents_api_smoke_assert_equals( null, $minimal['agent'], 'agent defaults to null', $failures, $passes );
agents_api_smoke_assert_equals( null, $minimal['creator'], 'creator defaults to null', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\Approvals\PendingActionStatus::PENDING, $minimal['status'], 'status defaults to pending', $failures, $passes );
agents_api_smoke_assert_equals( null, $minimal['resolved_at'], 'resolved_at defaults to null', $failures, $passes );
agents_api_smoke_assert_equals( null, $minimal['resolver'], 'resolver defaults to null', $failures, $passes );
agents_api_smoke_assert_equals( null, $minimal['resolution_result'], 'resolution_result defaults to null', $failures, $passes );
agents_api_smoke_assert_equals( null, $minimal['resolution_error'], 'resolution_error defaults to null', $failures, $passes );
agents_api_smoke_assert_equals( array(), $minimal['resolution_metadata'], 'resolution_metadata defaults to empty array', $failures, $passes );
agents_api_smoke_assert_equals( array(), $minimal['metadata'], 'metadata defaults to empty array', $failures, $passes );
agents_api_smoke_assert_equals( null, $minimal['expires_at'], 'expires_at defaults to null', $failures, $passes );

echo "\n[3] Terminal audit fields are preserved:\n";
$resolved = AgentsAPI\AI\Approvals\PendingAction::from_array(
	array(
		'action_id'          => 'approve-125',
		'kind'               => 'file_patch',
		'summary'            => 'Apply a patch.',
		'preview'            => array( 'diff' => '...' ),
		'apply_input'        => array( 'patch' => '...' ),
		'workspace'          => array(
			'workspace_type' => 'code_workspace',
			'workspace_id'   => 'Automattic/agents-api@durable-approvals',
		),
		'agent'              => 'agent-reviewer',
		'creator'            => 'user-7',
		'status'             => AgentsAPI\AI\Approvals\PendingActionStatus::ACCEPTED,
		'created_at'         => '2026-05-03T15:10:00Z',
		'resolved_at'        => '2026-05-03T15:12:00Z',
		'resolver'           => 'user-9',
		'resolution_result'  => array( 'applied' => true ),
		'resolution_metadata' => array( 'request_id' => 'req-xyz' ),
	)
)->to_array();

agents_api_smoke_assert_equals( AgentsAPI\AI\Approvals\PendingActionStatus::ACCEPTED, $resolved['status'], 'terminal status is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'user-9', $resolved['resolver'], 'resolver audit field is preserved', $failures, $passes );
agents_api_smoke_assert_equals( array( 'applied' => true ), $resolved['resolution_result'], 'resolution result is preserved', $failures, $passes );
agents_api_smoke_assert_equals( array( 'request_id' => 'req-xyz' ), $resolved['resolution_metadata'], 'resolution metadata is preserved', $failures, $passes );

echo "\n[4] Invalid fields are rejected generically:\n";
$invalid_cases = array(
	'missing action_id'       => array( 'kind' => 'update', 'summary' => 'Summary', 'preview' => array(), 'apply_input' => array(), 'created_at' => 'now' ),
	'invalid action_id'       => array( 'action_id' => 123, 'kind' => 'update', 'summary' => 'Summary', 'preview' => array(), 'apply_input' => array(), 'created_at' => 'now' ),
	'empty kind'              => array( 'action_id' => 'approve-1', 'kind' => '', 'summary' => 'Summary', 'preview' => array(), 'apply_input' => array(), 'created_at' => 'now' ),
	'invalid metadata'        => array( 'action_id' => 'approve-1', 'kind' => 'update', 'summary' => 'Summary', 'preview' => array(), 'apply_input' => array(), 'metadata' => 'nope', 'created_at' => 'now' ),
	'invalid workspace'       => array( 'action_id' => 'approve-1', 'kind' => 'update', 'summary' => 'Summary', 'preview' => array(), 'apply_input' => array(), 'workspace' => array( 'workspace_type' => '', 'workspace_id' => '' ), 'created_at' => 'now' ),
	'invalid status'          => array( 'action_id' => 'approve-1', 'kind' => 'update', 'summary' => 'Summary', 'preview' => array(), 'apply_input' => array(), 'status' => 'approved', 'created_at' => 'now' ),
	'non-serializable input'  => array( 'action_id' => 'approve-1', 'kind' => 'update', 'summary' => 'Summary', 'preview' => array(), 'apply_input' => tmpfile(), 'created_at' => 'now' ),
	'invalid optional string' => array( 'action_id' => 'approve-1', 'kind' => 'update', 'summary' => 'Summary', 'preview' => array(), 'apply_input' => array(), 'creator' => '', 'created_at' => 'now' ),
	'terminal missing audit'  => array( 'action_id' => 'approve-1', 'kind' => 'update', 'summary' => 'Summary', 'preview' => array(), 'apply_input' => array(), 'status' => 'accepted', 'created_at' => 'now' ),
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

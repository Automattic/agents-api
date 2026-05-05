<?php
/**
 * Pure-PHP smoke test for generic message envelopes.
 *
 * Run with: php tests/message-envelope-smoke.php
 *
 * @package AgentsAPI\Tests
 */

$failures = array();
$passes   = 0;

echo "agents-api-message-envelope-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

echo "\n[1] Approval-required envelopes carry generic pending action payloads:\n";
$payload  = array(
	'action_id'  => 'approve-diff-123',
	'kind'       => 'diff',
	'summary'    => 'Review generated changes before they are applied.',
	'preview'    => array(
		'format' => 'unified_diff',
		'value'  => "--- a/example.txt\n+++ b/example.txt\n@@\n-old\n+new\n",
	),
	'resolve'    => array(
		'approve' => array( 'label' => 'Approve' ),
		'reject'  => array( 'label' => 'Reject' ),
	),
	'expires_at' => '2026-05-03T12:00:00Z',
);
$metadata = array(
	'source' => 'smoke',
);

$envelope = AgentsAPI\AI\WP_Agent_Message::approvalRequired( 'Approval required before applying changes.', $payload, $metadata );
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Message::SCHEMA, $envelope['schema'], 'approval envelope uses canonical schema', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Message::TYPE_APPROVAL_REQUIRED, $envelope['type'], 'approval envelope has approval_required type', $failures, $passes );
agents_api_smoke_assert_equals( 'tool', $envelope['role'], 'approval envelope uses action role', $failures, $passes );
agents_api_smoke_assert_equals( $payload, $envelope['payload'], 'approval envelope preserves generic payload', $failures, $passes );
agents_api_smoke_assert_equals( $metadata, $envelope['metadata'], 'approval envelope preserves metadata', $failures, $passes );

echo "\n[2] Normalization and provider projection preserve the typed envelope:\n";
$normalized = AgentsAPI\AI\WP_Agent_Message::normalize( $envelope );
agents_api_smoke_assert_equals( $envelope, $normalized, 'normalization preserves approval envelope', $failures, $passes );

$provider_message = AgentsAPI\AI\WP_Agent_Message::to_provider_message( $envelope );
agents_api_smoke_assert_equals( 'tool', $provider_message['role'], 'provider projection preserves role', $failures, $passes );
agents_api_smoke_assert_equals( 'Approval required before applying changes.', $provider_message['content'], 'provider projection preserves content', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Message::TYPE_APPROVAL_REQUIRED, $provider_message['metadata']['type'], 'provider projection preserves type metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'approve-diff-123', $provider_message['metadata']['action_id'], 'provider projection exposes action_id metadata', $failures, $passes );
agents_api_smoke_assert_equals( $payload['preview'], $provider_message['metadata']['preview'], 'provider projection exposes preview metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'smoke', $provider_message['metadata']['source'], 'provider projection keeps extension metadata', $failures, $passes );

agents_api_smoke_finish( 'message envelope smoke', $failures, $passes );

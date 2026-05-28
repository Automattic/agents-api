<?php
/**
 * Pure-PHP smoke test for WP_Agent_Message::coalesce_consecutive_same_role().
 *
 * Run with: php tests/message-coalesce-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-message-coalesce-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

use AgentsAPI\AI\WP_Agent_Message;

echo "\n[1] Empty input round-trips:\n";
agents_api_smoke_assert_equals( array(), WP_Agent_Message::coalesce_consecutive_same_role( array() ), 'empty list returns empty list', $failures, $passes );

echo "\n[2] Alternating roles are preserved:\n";
$alternating = array(
	WP_Agent_Message::text( 'user', 'hello' ),
	WP_Agent_Message::text( 'assistant', 'hi' ),
	WP_Agent_Message::text( 'user', 'how are you' ),
);
$result = WP_Agent_Message::coalesce_consecutive_same_role( $alternating );
agents_api_smoke_assert_equals( 3, count( $result ), 'alternating roles are not merged', $failures, $passes );
agents_api_smoke_assert_equals( 'user', $result[0]['role'], 'first message stays as user', $failures, $passes );
agents_api_smoke_assert_equals( 'assistant', $result[1]['role'], 'second message stays as assistant', $failures, $passes );

echo "\n[3] Preamble text + tool_call from same assistant turn are coalesced (Anthropic-safe replay shape):\n";
$preamble  = WP_Agent_Message::text( 'assistant', 'Let me search the archive first.' );
$tool_call = WP_Agent_Message::toolCall( 'Calling search', 'archive/search', array( 'q' => 'hello' ), 1 );
$result    = WP_Agent_Message::coalesce_consecutive_same_role(
	array(
		WP_Agent_Message::text( 'user', 'find that note' ),
		$preamble,
		$tool_call,
	)
);

agents_api_smoke_assert_equals( 2, count( $result ), 'user turn + coalesced assistant turn produces two envelopes', $failures, $passes );
agents_api_smoke_assert_equals( 'user', $result[0]['role'], 'user envelope kept intact', $failures, $passes );
agents_api_smoke_assert_equals( 'assistant', $result[1]['role'], 'coalesced envelope keeps assistant role', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Message::TYPE_MULTIMODAL_PART, $result[1]['type'], 'coalesced envelope is typed multimodal_part', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $result[1]['payload']['parts'] ?? array() ), 'coalesced envelope carries both original parts', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Message::TYPE_TEXT, $result[1]['payload']['parts'][0]['type'] ?? '', 'first part is the original text envelope', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Message::TYPE_TOOL_CALL, $result[1]['payload']['parts'][1]['type'] ?? '', 'second part is the original tool_call envelope', $failures, $passes );
agents_api_smoke_assert_equals( 'Let me search the archive first.', $result[1]['content'], 'top-level content reduces to joined text for readability', $failures, $passes );

echo "\n[4] Multiple consecutive tool_calls merge into one envelope:\n";
$call_a = WP_Agent_Message::toolCall( 'a', 'tool/a', array(), 1 );
$call_b = WP_Agent_Message::toolCall( 'b', 'tool/b', array(), 1 );
$call_c = WP_Agent_Message::toolCall( 'c', 'tool/c', array(), 1 );
$result = WP_Agent_Message::coalesce_consecutive_same_role( array( $call_a, $call_b, $call_c ) );

agents_api_smoke_assert_equals( 1, count( $result ), 'three same-role envelopes collapse to one', $failures, $passes );
agents_api_smoke_assert_equals( 3, count( $result[0]['payload']['parts'] ?? array() ), 'all three original parts preserved inside coalesced envelope', $failures, $passes );

echo "\n[5] Already-multipart envelopes are merged without losing existing parts:\n";
$already_merged = WP_Agent_Message::coalesce_consecutive_same_role( array( $call_a, $call_b ) );
$result         = WP_Agent_Message::coalesce_consecutive_same_role( array( $already_merged[0], $call_c ) );

agents_api_smoke_assert_equals( 1, count( $result ), 'merging an already-coalesced envelope still produces one envelope', $failures, $passes );
agents_api_smoke_assert_equals( 3, count( $result[0]['payload']['parts'] ?? array() ), 'flattens parts rather than nesting multipart inside multipart', $failures, $passes );

echo "\n[6] Tool_result envelopes (role=user) do not merge with assistant tool_calls:\n";
$tool_result = WP_Agent_Message::toolResult( 'ok', 'tool/a', array( 'success' => true ) );
$result      = WP_Agent_Message::coalesce_consecutive_same_role( array( $call_a, $tool_result, $call_b ) );

agents_api_smoke_assert_equals( 3, count( $result ), 'role boundary between assistant and user tool_result is preserved', $failures, $passes );

echo "\n[7] Idempotent: calling twice yields the same result:\n";
$once  = WP_Agent_Message::coalesce_consecutive_same_role( array( $preamble, $tool_call ) );
$twice = WP_Agent_Message::coalesce_consecutive_same_role( $once );

agents_api_smoke_assert_equals( count( $once ), count( $twice ), 'idempotent envelope count', $failures, $passes );
agents_api_smoke_assert_equals( count( $once[0]['payload']['parts'] ?? array() ), count( $twice[0]['payload']['parts'] ?? array() ), 'idempotent parts count', $failures, $passes );

agents_api_smoke_finish( 'WP_Agent_Message coalesce', $failures, $passes );

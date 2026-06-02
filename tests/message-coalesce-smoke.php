<?php
/**
 * Pure-PHP smoke test for WP_Agent_Message wiring.
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

echo "\n[2] Preamble text + tool_call from same assistant turn are coalesced after plugin bootstrap:\n";
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

agents_api_smoke_finish( 'WP_Agent_Message coalesce', $failures, $passes );

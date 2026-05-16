<?php
/**
 * Pure-PHP smoke test for the tool-call / tool-result pair validator.
 *
 * Run with: php tests/tool-pair-validator-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-tool-pair-validator-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

use AgentsAPI\AI\WP_Agent_Message;
use AgentsAPI\AI\WP_Agent_Tool_Pair_Validator;

$user_message = array( 'role' => 'user', 'content' => 'hello' );
$assistant    = array( 'role' => 'assistant', 'content' => 'hi' );

$call_search   = WP_Agent_Message::toolCall( 'searching', 'search', array( 'q' => 'foo' ), 1 );
$result_search = WP_Agent_Message::toolResult( 'results', 'search', array( 'success' => true, 'tool_data' => array( 'hits' => 0 ) ) );

$call_fetch   = WP_Agent_Message::toolCall( 'fetching', 'fetch', array( 'url' => 'https://example.com' ), 2 );
$result_fetch = WP_Agent_Message::toolResult( 'fetched', 'fetch', array( 'success' => true, 'tool_data' => array( 'status' => 200 ) ) );

echo "\n[1] Empty transcript has no orphans:\n";
agents_api_smoke_assert_equals( array(), WP_Agent_Tool_Pair_Validator::validate( array() ), 'empty transcript validates clean', $failures, $passes );
agents_api_smoke_assert_equals( true, WP_Agent_Tool_Pair_Validator::is_paired( array() ), 'empty transcript is paired', $failures, $passes );

echo "\n[2] Transcript without tool messages has no orphans:\n";
$plain = array( $user_message, $assistant, $user_message );
agents_api_smoke_assert_equals( array(), WP_Agent_Tool_Pair_Validator::validate( $plain ), 'plain transcript has no orphans', $failures, $passes );
agents_api_smoke_assert_equals( true, WP_Agent_Tool_Pair_Validator::is_paired( $plain ), 'plain transcript is paired', $failures, $passes );

echo "\n[3] Properly paired tool_call + tool_result is clean:\n";
$paired = array( $user_message, $call_search, $result_search, $assistant );
agents_api_smoke_assert_equals( array(), WP_Agent_Tool_Pair_Validator::validate( $paired ), 'paired call+result has no orphans', $failures, $passes );

echo "\n[4] Tool_call with no matching tool_result is flagged:\n";
$orphan_call = array( $user_message, $call_search, $assistant );
$orphans     = WP_Agent_Tool_Pair_Validator::validate( $orphan_call );
agents_api_smoke_assert_equals( 1, count( $orphans ), 'orphan call produces one report', $failures, $passes );
agents_api_smoke_assert_equals( 1, $orphans[0]['index'], 'orphan call index points at the call', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Tool_Pair_Validator::KIND_ORPHAN_TOOL_CALL, $orphans[0]['kind'], 'orphan call kind is correct', $failures, $passes );
agents_api_smoke_assert_equals( 'search', $orphans[0]['tool_name'], 'orphan call tool_name is preserved', $failures, $passes );

echo "\n[5] Tool_result with no matching tool_call is flagged:\n";
$orphan_result = array( $user_message, $result_search, $assistant );
$orphans       = WP_Agent_Tool_Pair_Validator::validate( $orphan_result );
agents_api_smoke_assert_equals( 1, count( $orphans ), 'orphan result produces one report', $failures, $passes );
agents_api_smoke_assert_equals( 1, $orphans[0]['index'], 'orphan result index points at the result', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Tool_Pair_Validator::KIND_ORPHAN_TOOL_RESULT, $orphans[0]['kind'], 'orphan result kind is correct', $failures, $passes );
agents_api_smoke_assert_equals( 'search', $orphans[0]['tool_name'], 'orphan result tool_name is preserved', $failures, $passes );

echo "\n[6] Multiple interleaved calls match FIFO by tool name:\n";
$multi = array(
	$call_search,
	$call_fetch,
	$result_fetch,
	$result_search,
);
agents_api_smoke_assert_equals( array(), WP_Agent_Tool_Pair_Validator::validate( $multi ), 'interleaved pairs validate clean', $failures, $passes );

echo "\n[7] Two calls for the same tool with one result leaves the second call orphan:\n";
$double_call = array( $call_search, $call_search, $result_search );
$orphans     = WP_Agent_Tool_Pair_Validator::validate( $double_call );
agents_api_smoke_assert_equals( 1, count( $orphans ), 'double-call leaves one orphan', $failures, $passes );
agents_api_smoke_assert_equals( 1, $orphans[0]['index'], 'second call is the orphan (FIFO matches first)', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Tool_Pair_Validator::KIND_ORPHAN_TOOL_CALL, $orphans[0]['kind'], 'orphan kind is tool_call', $failures, $passes );

echo "\n[8] Result for a different tool name does not consume an unrelated pending call:\n";
$crossed = array( $call_search, $result_fetch );
$orphans = WP_Agent_Tool_Pair_Validator::validate( $crossed );
agents_api_smoke_assert_equals( 2, count( $orphans ), 'crossed names produce two orphans', $failures, $passes );
agents_api_smoke_assert_equals( 0, $orphans[0]['index'], 'first orphan (by index) is the call at 0', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Tool_Pair_Validator::KIND_ORPHAN_TOOL_CALL, $orphans[0]['kind'], 'first orphan kind is tool_call', $failures, $passes );
agents_api_smoke_assert_equals( 1, $orphans[1]['index'], 'second orphan is the result at 1', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Tool_Pair_Validator::KIND_ORPHAN_TOOL_RESULT, $orphans[1]['kind'], 'second orphan kind is tool_result', $failures, $passes );

echo "\n[9] prune() drops orphans and emits a lifecycle event:\n";
$messy = array( $user_message, $call_search, $result_fetch, $assistant );
$pruned = WP_Agent_Tool_Pair_Validator::prune( $messy );
agents_api_smoke_assert_equals( 2, count( $pruned['messages'] ), 'pruned transcript drops both orphans', $failures, $passes );
agents_api_smoke_assert_equals( 'user', $pruned['messages'][0]['role'], 'first retained message is the user turn', $failures, $passes );
agents_api_smoke_assert_equals( 'assistant', $pruned['messages'][1]['role'], 'second retained message is the assistant turn', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $pruned['removed'] ), 'two orphans are reported as removed', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $pruned['events'] ), 'prune emits a single lifecycle event', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Tool_Pair_Validator::EVENT_PRUNED, $pruned['events'][0]['type'], 'lifecycle event type is tool_pair_pruned', $failures, $passes );
agents_api_smoke_assert_equals( 2, $pruned['events'][0]['metadata']['orphan_count'], 'event metadata records orphan_count', $failures, $passes );

echo "\n[10] prune() on a clean transcript is a no-op with a validated event:\n";
$clean_pruned = WP_Agent_Tool_Pair_Validator::prune( $paired );
agents_api_smoke_assert_equals( 4, count( $clean_pruned['messages'] ), 'clean prune retains all messages', $failures, $passes );
agents_api_smoke_assert_equals( array(), $clean_pruned['removed'], 'clean prune removes nothing', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Tool_Pair_Validator::EVENT_VALIDATED, $clean_pruned['events'][0]['type'], 'clean prune emits validated event', $failures, $passes );
agents_api_smoke_assert_equals( 0, $clean_pruned['events'][0]['metadata']['orphan_count'], 'clean prune event reports orphan_count=0', $failures, $passes );

agents_api_smoke_finish( 'tool-pair-validator', $failures, $passes );

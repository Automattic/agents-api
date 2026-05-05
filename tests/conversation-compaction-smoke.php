<?php
/**
 * Pure-PHP smoke test for the Agents API conversation compaction contract.
 *
 * Run with: php tests/conversation-compaction-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-conversation-compaction-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

$policy = array(
	'enabled'         => true,
	'max_messages'    => 5,
	'recent_messages' => 2,
);

$conservation_policy = array_merge(
	$policy,
	array(
		'conservation_enabled' => true,
	)
);

$messages = array(
	array( 'role' => 'user', 'content' => 'one' ),
	array( 'role' => 'assistant', 'content' => 'two' ),
	array( 'role' => 'user', 'content' => 'three' ),
	array( 'role' => 'assistant', 'content' => 'four' ),
);

echo "\n[1] Below-threshold transcripts are unchanged:\n";
$below_threshold = AgentsAPI\AI\WP_Agent_Conversation_Compaction::compact(
	$messages,
	$policy,
	static function (): string {
		return 'should not run';
	}
);
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Conversation_Compaction::STATUS_SKIPPED, $below_threshold['metadata']['compaction']['status'], 'below-threshold compaction is skipped', $failures, $passes );
agents_api_smoke_assert_equals( 4, count( $below_threshold['messages'] ), 'below-threshold message count is unchanged', $failures, $passes );
agents_api_smoke_assert_equals( array(), $below_threshold['events'], 'below-threshold compaction emits no lifecycle events', $failures, $passes );

echo "\n[2] Successful compaction returns a synthetic summary and lifecycle events:\n";
$long_messages = array_merge(
	$messages,
	array(
		array( 'role' => 'user', 'content' => 'five' ),
		array( 'role' => 'assistant', 'content' => 'six' ),
	)
);

$compacted = AgentsAPI\AI\WP_Agent_Conversation_Compaction::compact(
	$long_messages,
	$conservation_policy,
	static function ( array $messages_to_summarize, array $context ): array {
		return array(
			'summary'        => 'Summarized ' . count( $messages_to_summarize ) . ' of ' . $context['total_messages'] . ' messages: one two three four.',
			'archived_items' => $messages_to_summarize,
		);
	}
);
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Conversation_Compaction::STATUS_COMPACTED, $compacted['metadata']['compaction']['status'], 'long transcript is compacted', $failures, $passes );
agents_api_smoke_assert_equals( 3, count( $compacted['messages'] ), 'compacted transcript contains summary plus retained messages', $failures, $passes );
agents_api_smoke_assert_equals( 'system', $compacted['messages'][0]['role'], 'summary message uses policy role', $failures, $passes );
agents_api_smoke_assert_equals( 4, $compacted['messages'][0]['metadata']['agents_api_compaction']['compacted_message_count'], 'summary metadata records compacted boundary', $failures, $passes );
agents_api_smoke_assert_equals( 6, $compacted['metadata']['compaction']['provenance']['original']['item_count'], 'metadata records original item count', $failures, $passes );
agents_api_smoke_assert_equals( 1, $compacted['metadata']['compaction']['provenance']['compacted']['item_count'], 'metadata records compacted item count', $failures, $passes );
agents_api_smoke_assert_equals( 2, $compacted['metadata']['compaction']['provenance']['retained']['item_count'], 'metadata records retained item count', $failures, $passes );
agents_api_smoke_assert_equals( 4, $compacted['metadata']['compaction']['provenance']['archived']['item_count'], 'metadata records archived item count', $failures, $passes );
agents_api_smoke_assert_equals( true, 0 < $compacted['metadata']['compaction']['provenance']['original']['byte_count'], 'metadata records original byte count', $failures, $passes );
agents_api_smoke_assert_equals( true, 0 < $compacted['metadata']['compaction']['provenance']['compacted']['byte_count'], 'metadata records compacted byte count', $failures, $passes );
agents_api_smoke_assert_equals( true, 0 < $compacted['metadata']['compaction']['provenance']['retained']['byte_count'], 'metadata records retained byte count', $failures, $passes );
agents_api_smoke_assert_equals( true, 0 < $compacted['metadata']['compaction']['provenance']['archived']['byte_count'], 'metadata records archived byte count', $failures, $passes );
agents_api_smoke_assert_equals( true, $compacted['metadata']['compaction']['conservation']['passed'], 'healthy compaction passes conservation', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Conversation_Compaction::EVENT_STARTED, $compacted['events'][0]['type'], 'compaction start event is emitted', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Conversation_Compaction::EVENT_COMPLETED, $compacted['events'][1]['type'], 'compaction completed event is emitted', $failures, $passes );

echo "\n[3] Lossy compaction fails closed when conservation is enabled:\n";
$durable_messages = array(
	array( 'role' => 'user', 'content' => str_repeat( 'alpha ', 40 ) ),
	array( 'role' => 'assistant', 'content' => str_repeat( 'bravo ', 40 ) ),
	array( 'role' => 'user', 'content' => str_repeat( 'charlie ', 40 ) ),
	array( 'role' => 'assistant', 'content' => str_repeat( 'delta ', 40 ) ),
);
$lossy_failed = AgentsAPI\AI\WP_Agent_Conversation_Compaction::compact(
	$durable_messages,
	array(
		'enabled'                      => true,
		'conservation_enabled'         => true,
		'max_messages'                 => 3,
		'recent_messages'              => 1,
		'minimum_conserved_byte_ratio' => 1.0,
	),
	static function (): string {
		return 'tiny';
	}
);
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Conversation_Compaction::STATUS_FAILED, $lossy_failed['metadata']['compaction']['status'], 'lossy compaction is rejected', $failures, $passes );
agents_api_smoke_assert_equals( count( $durable_messages ), count( $lossy_failed['messages'] ), 'lossy rejection keeps original transcript length', $failures, $passes );
agents_api_smoke_assert_equals( false, $lossy_failed['metadata']['compaction']['conservation']['passed'], 'lossy rejection records failed conservation', $failures, $passes );
agents_api_smoke_assert_equals( true, $lossy_failed['metadata']['compaction']['conservation']['failed_closed'], 'lossy rejection records fail-closed state', $failures, $passes );

echo "\n[4] Conservation can be disabled for intentionally lossy compaction:\n";
$lossy_allowed = AgentsAPI\AI\WP_Agent_Conversation_Compaction::compact(
	$durable_messages,
	array(
		'enabled'                      => true,
		'conservation_enabled'         => false,
		'max_messages'                 => 3,
		'recent_messages'              => 1,
		'minimum_conserved_byte_ratio' => 1.0,
	),
	static function (): string {
		return 'tiny';
	}
);
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Conversation_Compaction::STATUS_COMPACTED, $lossy_allowed['metadata']['compaction']['status'], 'disabled conservation allows lossy compaction', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $lossy_allowed['messages'] ), 'disabled conservation returns compacted transcript', $failures, $passes );
agents_api_smoke_assert_equals( false, $lossy_allowed['metadata']['compaction']['conservation']['enabled'], 'disabled conservation records opt-out', $failures, $passes );

echo "\n[5] Summarizer failures retain the original transcript:\n";
$failed = AgentsAPI\AI\WP_Agent_Conversation_Compaction::compact(
	$long_messages,
	$policy,
	static function (): string {
		throw new RuntimeException( 'summary backend unavailable' );
	}
);
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Conversation_Compaction::STATUS_FAILED, $failed['metadata']['compaction']['status'], 'summarizer failure is reported', $failures, $passes );
agents_api_smoke_assert_equals( count( $long_messages ), count( $failed['messages'] ), 'summarizer failure keeps original transcript length', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Conversation_Compaction::EVENT_FAILED, $failed['events'][1]['type'], 'summarizer failure emits failed event', $failures, $passes );

echo "\n[6] Boundary selection does not split tool-call/tool-result pairs:\n";
$tool_messages = array(
	array( 'role' => 'user', 'content' => 'question' ),
	AgentsAPI\AI\WP_Agent_Message::toolCall( 'call weather', 'weather', array( 'city' => 'New York' ), 1 ),
	AgentsAPI\AI\WP_Agent_Message::toolResult( 'weather result', 'weather', array( 'success' => true, 'turn' => 1 ) ),
	array( 'role' => 'assistant', 'content' => 'answer' ),
	array( 'role' => 'user', 'content' => 'follow up' ),
);

$boundary = AgentsAPI\AI\WP_Agent_Conversation_Compaction::select_boundary(
	AgentsAPI\AI\WP_Agent_Message::normalize_many( $tool_messages ),
	array(
		'enabled'         => true,
		'max_messages'    => 4,
		'recent_messages' => 3,
	)
);
agents_api_smoke_assert_equals( 1, $boundary, 'boundary moves before retained tool result', $failures, $passes );

echo "\n[7] WP_Agent exposes declarative compaction capability and policy:\n";
$agent = new WP_Agent(
	'compacting-agent',
	array(
		'supports_conversation_compaction' => true,
		'conversation_compaction_policy'   => array(
			'enabled'         => true,
			'max_messages'    => 10,
			'recent_messages' => 4,
		),
	)
);
agents_api_smoke_assert_equals( true, $agent->supports_conversation_compaction(), 'agent declares compaction support', $failures, $passes );
agents_api_smoke_assert_equals( 10, $agent->get_conversation_compaction_policy()['max_messages'], 'agent exposes normalized compaction policy', $failures, $passes );
agents_api_smoke_assert_equals( true, $agent->to_array()['supports_conversation_compaction'], 'agent array includes compaction capability', $failures, $passes );

echo "\n[6] Oversized transcripts archive deterministically without summarizer calls:\n";
$overflow_policy = array(
	'enabled'                    => true,
	'max_messages'               => 100,
	'recent_messages'            => 2,
	'overflow_archive_enabled'   => true,
	'overflow_threshold_bytes'   => 180,
	'overflow_retained_messages' => 2,
	'overflow_archive_pointer'   => array( 'destination' => 'memory://daily/2026/05/01.md' ),
);
$overflow_messages = array();
for ( $i = 1; $i <= 6; ++$i ) {
	$overflow_messages[] = array(
		'id'       => 'message-' . $i,
		'role'     => 0 === $i % 2 ? 'assistant' : 'user',
		'content'  => 'message ' . $i . ' ' . str_repeat( 'x', 40 ),
		'metadata' => array(
			'position' => $i,
			'source'   => 'overflow-smoke',
		),
	);
}

$overflow_summarizer_calls = 0;
$overflow_result           = AgentsAPI\AI\WP_Agent_Conversation_Compaction::compact(
	$overflow_messages,
	$overflow_policy,
	static function () use ( &$overflow_summarizer_calls ): string {
		++$overflow_summarizer_calls;
		return 'should not run';
	}
);
$archive_metadata = $overflow_result['metadata']['compaction'];
$archive_items    = $overflow_result['archive_items'] ?? array();
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Conversation_Compaction::STATUS_ARCHIVED, $archive_metadata['status'], 'oversized transcript uses archive status', $failures, $passes );
agents_api_smoke_assert_equals( 0, $overflow_summarizer_calls, 'overflow archive does not call summarizer', $failures, $passes );
agents_api_smoke_assert_equals( 3, count( $overflow_result['messages'] ), 'overflow result retains stub plus active subset', $failures, $passes );
agents_api_smoke_assert_equals( 4, count( $archive_items ), 'overflow result returns archived items', $failures, $passes );
agents_api_smoke_assert_equals( $overflow_messages[1], $archive_items[1], 'archived items retain original IDs and metadata verbatim', $failures, $passes );
agents_api_smoke_assert_equals( $overflow_policy['overflow_archive_pointer'], $archive_metadata['archive_pointer'], 'archive metadata includes consumer pointer', $failures, $passes );
agents_api_smoke_assert_equals( $archive_metadata['archive_id'], $overflow_result['messages'][0]['metadata']['agents_api_compaction_archive']['archive_id'], 'stub metadata includes archive ID', $failures, $passes );

echo "\n[7] Small overflow-enabled transcripts are unchanged:\n";
$small_overflow_calls = 0;
$small_overflow       = AgentsAPI\AI\WP_Agent_Conversation_Compaction::compact(
	$messages,
	array(
		'enabled'                    => true,
		'max_messages'               => 100,
		'overflow_archive_enabled'   => true,
		'overflow_threshold_bytes'   => 100000,
		'overflow_retained_messages' => 2,
	),
	static function () use ( &$small_overflow_calls ): string {
		++$small_overflow_calls;
		return 'should not run';
	}
);
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Conversation_Compaction::STATUS_SKIPPED, $small_overflow['metadata']['compaction']['status'], 'small overflow-enabled transcript is skipped', $failures, $passes );
agents_api_smoke_assert_equals( 0, $small_overflow_calls, 'small overflow-enabled transcript does not call summarizer', $failures, $passes );
agents_api_smoke_assert_equals( false, array_key_exists( 'archive_items', $small_overflow ), 'small overflow-enabled transcript has no archive items', $failures, $passes );

echo "\n[8] Single oversized items remain intact when they cannot be split safely:\n";
$single_overflow_calls = 0;
$single_oversized      = AgentsAPI\AI\WP_Agent_Conversation_Compaction::compact(
	array(
		array(
			'id'       => 'single-large-message',
			'role'     => 'user',
			'content'  => str_repeat( 'single ', 80 ),
			'metadata' => array( 'keep' => 'verbatim' ),
		),
	),
	array(
		'enabled'                    => true,
		'max_messages'               => 100,
		'overflow_archive_enabled'   => true,
		'overflow_threshold_bytes'   => 40,
		'overflow_retained_messages' => 1,
	),
	static function () use ( &$single_overflow_calls ): string {
		++$single_overflow_calls;
		return 'should not run';
	}
);
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Conversation_Compaction::STATUS_SKIPPED, $single_oversized['metadata']['compaction']['status'], 'single oversized item is skipped', $failures, $passes );
agents_api_smoke_assert_equals( 'overflow_input_unsplittable', $single_oversized['metadata']['compaction']['reason'], 'single oversized item records unsplittable reason', $failures, $passes );
agents_api_smoke_assert_equals( 0, $single_overflow_calls, 'single oversized item does not call summarizer', $failures, $passes );
agents_api_smoke_assert_equals( 'single-large-message', $single_oversized['messages'][0]['id'], 'single oversized item ID is preserved', $failures, $passes );
agents_api_smoke_assert_equals( array( 'keep' => 'verbatim' ), $single_oversized['messages'][0]['metadata'], 'single oversized item metadata is preserved', $failures, $passes );

echo "\n[9] Overflow archive output is deterministic:\n";
$overflow_result_again = AgentsAPI\AI\WP_Agent_Conversation_Compaction::compact(
	$overflow_messages,
	$overflow_policy,
	static function (): string {
		return 'should not run';
	}
);
$archive_items_again = $overflow_result_again['archive_items'] ?? array();
agents_api_smoke_assert_equals( $overflow_result['metadata']['compaction']['archive_id'], $overflow_result_again['metadata']['compaction']['archive_id'], 'archive IDs are deterministic', $failures, $passes );
agents_api_smoke_assert_equals( $archive_items, $archive_items_again, 'archive item output is deterministic', $failures, $passes );
agents_api_smoke_assert_equals( $overflow_result['messages'], $overflow_result_again['messages'], 'retained message output is deterministic', $failures, $passes );

agents_api_smoke_finish( 'Agents API conversation compaction', $failures, $passes );

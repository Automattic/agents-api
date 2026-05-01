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
$below_threshold = AgentsAPI\AI\AgentConversationCompaction::compact(
	$messages,
	$policy,
	static function (): string {
		return 'should not run';
	}
);
agents_api_smoke_assert_equals( AgentsAPI\AI\AgentConversationCompaction::STATUS_SKIPPED, $below_threshold['metadata']['compaction']['status'], 'below-threshold compaction is skipped', $failures, $passes );
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

$compacted = AgentsAPI\AI\AgentConversationCompaction::compact(
	$long_messages,
	$conservation_policy,
	static function ( array $messages_to_summarize, array $context ): array {
		return array(
			'summary'        => 'Summarized ' . count( $messages_to_summarize ) . ' of ' . $context['total_messages'] . ' messages: one two three four.',
			'archived_items' => $messages_to_summarize,
		);
	}
);
agents_api_smoke_assert_equals( AgentsAPI\AI\AgentConversationCompaction::STATUS_COMPACTED, $compacted['metadata']['compaction']['status'], 'long transcript is compacted', $failures, $passes );
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
agents_api_smoke_assert_equals( AgentsAPI\AI\AgentConversationCompaction::EVENT_STARTED, $compacted['events'][0]['type'], 'compaction start event is emitted', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\AgentConversationCompaction::EVENT_COMPLETED, $compacted['events'][1]['type'], 'compaction completed event is emitted', $failures, $passes );

echo "\n[3] Lossy compaction fails closed when conservation is enabled:\n";
$durable_messages = array(
	array( 'role' => 'user', 'content' => str_repeat( 'alpha ', 40 ) ),
	array( 'role' => 'assistant', 'content' => str_repeat( 'bravo ', 40 ) ),
	array( 'role' => 'user', 'content' => str_repeat( 'charlie ', 40 ) ),
	array( 'role' => 'assistant', 'content' => str_repeat( 'delta ', 40 ) ),
);
$lossy_failed = AgentsAPI\AI\AgentConversationCompaction::compact(
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
agents_api_smoke_assert_equals( AgentsAPI\AI\AgentConversationCompaction::STATUS_FAILED, $lossy_failed['metadata']['compaction']['status'], 'lossy compaction is rejected', $failures, $passes );
agents_api_smoke_assert_equals( count( $durable_messages ), count( $lossy_failed['messages'] ), 'lossy rejection keeps original transcript length', $failures, $passes );
agents_api_smoke_assert_equals( false, $lossy_failed['metadata']['compaction']['conservation']['passed'], 'lossy rejection records failed conservation', $failures, $passes );
agents_api_smoke_assert_equals( true, $lossy_failed['metadata']['compaction']['conservation']['failed_closed'], 'lossy rejection records fail-closed state', $failures, $passes );

echo "\n[4] Conservation can be disabled for intentionally lossy compaction:\n";
$lossy_allowed = AgentsAPI\AI\AgentConversationCompaction::compact(
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
agents_api_smoke_assert_equals( AgentsAPI\AI\AgentConversationCompaction::STATUS_COMPACTED, $lossy_allowed['metadata']['compaction']['status'], 'disabled conservation allows lossy compaction', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $lossy_allowed['messages'] ), 'disabled conservation returns compacted transcript', $failures, $passes );
agents_api_smoke_assert_equals( false, $lossy_allowed['metadata']['compaction']['conservation']['enabled'], 'disabled conservation records opt-out', $failures, $passes );

echo "\n[5] Summarizer failures retain the original transcript:\n";
$failed = AgentsAPI\AI\AgentConversationCompaction::compact(
	$long_messages,
	$policy,
	static function (): string {
		throw new RuntimeException( 'summary backend unavailable' );
	}
);
agents_api_smoke_assert_equals( AgentsAPI\AI\AgentConversationCompaction::STATUS_FAILED, $failed['metadata']['compaction']['status'], 'summarizer failure is reported', $failures, $passes );
agents_api_smoke_assert_equals( count( $long_messages ), count( $failed['messages'] ), 'summarizer failure keeps original transcript length', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\AgentConversationCompaction::EVENT_FAILED, $failed['events'][1]['type'], 'summarizer failure emits failed event', $failures, $passes );

echo "\n[6] Boundary selection does not split tool-call/tool-result pairs:\n";
$tool_messages = array(
	array( 'role' => 'user', 'content' => 'question' ),
	AgentsAPI\AI\AgentMessageEnvelope::toolCall( 'call weather', 'weather', array( 'city' => 'New York' ), 1 ),
	AgentsAPI\AI\AgentMessageEnvelope::toolResult( 'weather result', 'weather', array( 'success' => true, 'turn' => 1 ) ),
	array( 'role' => 'assistant', 'content' => 'answer' ),
	array( 'role' => 'user', 'content' => 'follow up' ),
);

$boundary = AgentsAPI\AI\AgentConversationCompaction::select_boundary(
	AgentsAPI\AI\AgentMessageEnvelope::normalize_many( $tool_messages ),
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

agents_api_smoke_finish( 'Agents API conversation compaction', $failures, $passes );

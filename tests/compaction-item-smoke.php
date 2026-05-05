<?php
/**
 * Pure-PHP smoke test for the Agents API generic compaction item contract.
 *
 * Run with: php tests/compaction-item-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-compaction-item-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

echo "\n[1] Valid items normalize to the public contract:\n";
$item = AgentsAPI\AI\WP_Agent_Compaction_Item::normalize(
	array(
		'id'       => 'section-intro',
		'type'     => 'markdown_section',
		'content'  => 'Intro text',
		'metadata' => array(
			'source' => 'handbook',
			'level'  => 2,
		),
		'group'    => 'handbook-page',
		'boundary' => array( 'starts_group' => true ),
	)
);
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Compaction_Item::SCHEMA, $item['schema'], 'item schema is public', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Compaction_Item::VERSION, $item['version'], 'item version is public', $failures, $passes );
agents_api_smoke_assert_equals( 'section-intro', $item['id'], 'caller-provided id is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'markdown_section', $item['type'], 'type is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'Intro text', $item['content'], 'content is preserved', $failures, $passes );
agents_api_smoke_assert_equals( array( 'source' => 'handbook', 'level' => 2 ), $item['metadata'], 'metadata is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'handbook-page', $item['group'], 'group hint is preserved', $failures, $passes );
agents_api_smoke_assert_equals( array( 'starts_group' => true ), $item['boundary'], 'boundary hint is preserved', $failures, $passes );

echo "\n[2] Ordered item lists retain order and metadata:\n";
$ordered = AgentsAPI\AI\WP_Agent_Compaction_Item::normalize_many(
	array(
		array(
			'id'       => 'first',
			'type'     => 'record',
			'content'  => 'one',
			'metadata' => array( 'ordinal' => 1 ),
		),
		array(
			'id'       => 'second',
			'type'     => 'record',
			'content'  => 'two',
			'metadata' => array( 'ordinal' => 2 ),
		),
	)
);
agents_api_smoke_assert_equals( 'first', $ordered[0]['id'], 'first item remains first', $failures, $passes );
agents_api_smoke_assert_equals( 'second', $ordered[1]['id'], 'second item remains second', $failures, $passes );
agents_api_smoke_assert_equals( array( 'ordinal' => 2 ), $ordered[1]['metadata'], 'ordered item metadata is preserved', $failures, $passes );

echo "\n[3] Missing IDs are generated deterministically:\n";
$without_id = array(
	'type'     => 'memory_record',
	'content'  => array( 'text' => 'Remember this' ),
	'metadata' => array( 'source' => 'runtime' ),
);
$generated_one = AgentsAPI\AI\WP_Agent_Compaction_Item::normalize( $without_id, 3 );
$generated_two = AgentsAPI\AI\WP_Agent_Compaction_Item::normalize( $without_id, 3 );
agents_api_smoke_assert_equals( $generated_one['id'], $generated_two['id'], 'generated id is stable for the same item and index', $failures, $passes );
agents_api_smoke_assert_equals( 'item-', substr( $generated_one['id'], 0, 5 ), 'generated id uses item prefix', $failures, $passes );

echo "\n[4] Invalid or missing fields are rejected:\n";
$invalid_cases = array(
	'missing type'     => array( 'content' => 'text', 'metadata' => array() ),
	'missing content'  => array( 'type' => 'record', 'metadata' => array() ),
	'invalid content'  => array( 'type' => 'record', 'content' => 12, 'metadata' => array() ),
	'invalid metadata' => array( 'type' => 'record', 'content' => 'text', 'metadata' => 'nope' ),
	'invalid id'       => array( 'id' => '', 'type' => 'record', 'content' => 'text', 'metadata' => array() ),
	'invalid boundary' => array( 'type' => 'record', 'content' => 'text', 'metadata' => array(), 'boundary' => 'nope' ),
);

foreach ( $invalid_cases as $name => $invalid_item ) {
	$thrown = false;
	try {
		AgentsAPI\AI\WP_Agent_Compaction_Item::normalize( $invalid_item );
	} catch ( InvalidArgumentException $error ) {
		$thrown = 0 === strpos( $error->getMessage(), 'invalid_ai_compaction_item:' );
	}
	agents_api_smoke_assert_equals( true, $thrown, $name . ' throws contract exception', $failures, $passes );
}

echo "\n[5] Message envelopes can be projected without fake chat-message inputs:\n";
$message_item = AgentsAPI\AI\WP_Agent_Compaction_Item::from_message(
	array(
		'id'       => 'message-1',
		'role'     => 'assistant',
		'content'  => 'Answer',
		'metadata' => array( 'trace' => 'abc' ),
	),
	0
);
agents_api_smoke_assert_equals( 'message-1', $message_item['id'], 'message id is preserved in compaction item', $failures, $passes );
agents_api_smoke_assert_equals( 'message:text', $message_item['type'], 'message item type is namespaced', $failures, $passes );
agents_api_smoke_assert_equals( 'abc', $message_item['metadata']['trace'], 'message metadata is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'assistant', $message_item['metadata']['message']['role'], 'message role is retained as metadata', $failures, $passes );

agents_api_smoke_finish( 'Agents API compaction item', $failures, $passes );

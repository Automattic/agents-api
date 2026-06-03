<?php
/**
 * Pure-PHP smoke test for canonical citation metadata.
 *
 * Run with: php tests/citation-metadata-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-citation-metadata-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

$citations = array(
	array(
		'source'       => 'docs',
		'source_id'    => 'docs-primary',
		'source_title' => 'Agents Handbook',
		'source_url'   => 'https://example.com/agents',
		'item_id'      => 'item-1',
		'fragment_id'  => 'fragment-2',
		'score'        => '0.87',
		'excerpt'      => 'Retrieved context excerpt.',
		'document_id'  => 'doc-extension',
		'plugin_name'  => 'product-specific-field',
	),
);

$metadata = array(
	'citations' => $citations,
	'trace_id'  => 'trace-123',
);

$normalized_metadata = AgentsAPI\AI\WP_Agent_Citation_Metadata::normalize_metadata( $metadata );
agents_api_smoke_assert_equals( 'trace-123', $normalized_metadata['trace_id'] ?? '', 'citation normalization preserves unrelated metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'docs', $normalized_metadata['citations'][0]['source'] ?? '', 'citation preserves generic source label', $failures, $passes );
agents_api_smoke_assert_equals( 'docs-primary', $normalized_metadata['citations'][0]['source_id'] ?? '', 'citation preserves generic source id', $failures, $passes );
agents_api_smoke_assert_equals( 0.87, $normalized_metadata['citations'][0]['score'] ?? null, 'citation normalizes numeric score', $failures, $passes );
agents_api_smoke_assert_equals( 'product-specific-field', $normalized_metadata['citations'][0]['plugin_name'] ?? '', 'citation preserves caller-owned extension fields', $failures, $passes );
agents_api_smoke_assert_equals( 'doc-extension', $normalized_metadata['citations'][0]['document_id'] ?? '', 'citation preserves non-canonical caller-owned ids as extensions', $failures, $passes );

$context_item = new AgentsAPI\AI\Context\WP_Agent_Context_Item(
	'Retrieved context excerpt.',
	array( 'type' => 'workspace' ),
	AgentsAPI\AI\Context\WP_Agent_Context_Authority_Tier::WORKSPACE_SHARED,
	array( 'source' => 'search' ),
	AgentsAPI\AI\Context\WP_Agent_Context_Conflict_Kind::PREFERENCE,
	null,
	$metadata
);
$context_array = $context_item->to_array();
agents_api_smoke_assert_equals( 'item-1', $context_array['metadata']['citations'][0]['item_id'] ?? '', 'context item carries citation metadata through array export', $failures, $passes );

$tool_result = AgentsAPI\AI\Tools\WP_Agent_Tool_Result::success(
	'ability/search_docs',
	array( 'matches' => array( 'doc-1' ) ),
	$metadata
);
agents_api_smoke_assert_equals( 'fragment-2', $tool_result['metadata']['citations'][0]['fragment_id'] ?? '', 'tool result normalization preserves citation metadata', $failures, $passes );

$runtime_result = AgentsAPI\AI\WP_Agent_Runtime_Tool_Result::normalize(
	array(
		'request_id' => 'request-1',
		'tool_name'  => 'client/search_docs',
		'success'    => true,
		'result'     => array( 'matches' => array( 'doc-1' ) ),
		'metadata'   => $metadata,
	)
);
agents_api_smoke_assert_equals( 'https://example.com/agents', $runtime_result['metadata']['citations'][0]['source_url'] ?? '', 'runtime tool result normalization preserves citation metadata', $failures, $passes );

agents_api_smoke_finish( 'Agents API citation metadata', $failures, $passes );

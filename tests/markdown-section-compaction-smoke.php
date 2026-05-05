<?php
/**
 * Pure-PHP smoke test for markdown section compaction items.
 *
 * Run with: php tests/markdown-section-compaction-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-markdown-section-compaction-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

$markdown = "Intro line\n\n# Memory\nMemory body.\n## Project Alpha\nAlpha details.\n### Nested Note\nNested details.\n## Empty Section\n# Later\nLater body.\n";

echo "\n[1] Markdown parses into preamble, top-level, nested, and empty section items:\n";
$items = AgentsAPI\AI\WP_Agent_Markdown_Section_Compaction_Adapter::parse( $markdown );
agents_api_smoke_assert_equals( 6, count( $items ), 'parser returns one preamble plus five sections', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Markdown_Section_Compaction_Adapter::TYPE_PREAMBLE, $items[0]['type'], 'first item is the preamble', $failures, $passes );
agents_api_smoke_assert_equals( "Intro line\n\n", $items[0]['content'], 'preamble content is preserved', $failures, $passes );
agents_api_smoke_assert_equals( '# Memory' . "\n", $items[1]['metadata']['heading_line'], 'top-level heading line is preserved', $failures, $passes );
agents_api_smoke_assert_equals( array( 'Memory', 'Project Alpha', 'Nested Note' ), $items[3]['metadata']['heading_path'], 'nested heading path is preserved', $failures, $passes );
agents_api_smoke_assert_equals( '', $items[4]['content'], 'empty sections are represented as empty items', $failures, $passes );

echo "\n[2] Round-trip reconstruction preserves markdown exactly:\n";
agents_api_smoke_assert_equals( $markdown, AgentsAPI\AI\WP_Agent_Markdown_Section_Compaction_Adapter::reconstruct( $items ), 'parsed items reconstruct the original markdown', $failures, $passes );

echo "\n[3] Summary and pointer items reconstruct under original headings:\n";
$compacted_items = array(
	$items[0],
	AgentsAPI\AI\WP_Agent_Markdown_Section_Compaction_Adapter::summary_item( $items[1], "Summary of memory.\n" ),
	$items[2],
	AgentsAPI\AI\WP_Agent_Markdown_Section_Compaction_Adapter::pointer_item( $items[3], 'consumer-owned/archive-target.md' ),
	$items[4],
	$items[5],
);
$expected_compacted = "Intro line\n\n# Memory\nSummary of memory.\n## Project Alpha\nAlpha details.\n### Nested Note\n[Archived section: consumer-owned/archive-target.md]\n## Empty Section\n# Later\nLater body.\n";
agents_api_smoke_assert_equals( $expected_compacted, AgentsAPI\AI\WP_Agent_Markdown_Section_Compaction_Adapter::reconstruct( $compacted_items ), 'summary and pointer items reconstruct markdown', $failures, $passes );
agents_api_smoke_assert_equals( 'consumer-owned/archive-target.md', $compacted_items[3]['metadata']['pointer_destination'], 'pointer destination stays consumer-owned metadata', $failures, $passes );

echo "\n[4] Boundary grouping follows section headings:\n";
$groups = AgentsAPI\AI\WP_Agent_Markdown_Section_Compaction_Adapter::group_by_heading_boundary( $items );
agents_api_smoke_assert_equals( array( '__preamble', 'memory', 'later' ), array_keys( $groups ), 'items are grouped by top-level heading boundary', $failures, $passes );
agents_api_smoke_assert_equals( 4, count( $groups['memory'] ), 'top-level boundary includes nested section items', $failures, $passes );

$nested_groups = AgentsAPI\AI\WP_Agent_Markdown_Section_Compaction_Adapter::group_by_heading_boundary( $items, 2 );
agents_api_smoke_assert_equals( array( '__preamble', 'memory', 'memory/project-alpha', 'memory/empty-section', 'later' ), array_keys( $nested_groups ), 'items can group by nested heading boundary', $failures, $passes );

agents_api_smoke_finish( 'Agents API markdown section compaction', $failures, $passes );

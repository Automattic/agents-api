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

echo "\n[5] Markdown overflow archives tail sections and inserts a pointer:\n";
$overflow_markdown = "# Agent Memory\n\nIntro stays.\n\n";
for ( $i = 1; $i <= 6; ++$i ) {
	$overflow_markdown .= "## Section {$i}\n\n" . str_repeat( "Line {$i} durable memory.\n", 8 ) . "\n";
}
$overflow_items = AgentsAPI\AI\WP_Agent_Markdown_Section_Compaction_Adapter::parse( $overflow_markdown );
$overflow       = AgentsAPI\AI\WP_Agent_Markdown_Section_Compaction_Adapter::split_for_overflow(
	$overflow_items,
	array(
		'target_bytes'        => 900,
		'pointer_destination' => 'daily/2026/05/01.md',
		'pointer_heading'     => 'Archived Memory Overflow',
		'pointer_content'     => "On 2026-05-01, sections were archived verbatim to `daily/2026/05/01.md`.\n",
	)
);
$retained_markdown = AgentsAPI\AI\WP_Agent_Markdown_Section_Compaction_Adapter::reconstruct( $overflow['retained_items'] );
$archive_markdown  = AgentsAPI\AI\WP_Agent_Markdown_Section_Compaction_Adapter::reconstruct( $overflow['archive_items'] );
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Markdown_Section_Compaction_Adapter::STATUS_ARCHIVED, $overflow['status'], 'overflow produces archived status', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $retained_markdown, '## Archived Memory Overflow' ), 'retained markdown includes archive pointer heading', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $retained_markdown, 'daily/2026/05/01.md' ), 'retained markdown includes consumer archive destination', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $archive_markdown, '## Section 6' ), 'archive markdown includes tail section', $failures, $passes );
agents_api_smoke_assert_equals( false, str_contains( $retained_markdown, '## Section 6' ), 'archived tail section is removed from retained markdown', $failures, $passes );
agents_api_smoke_assert_equals( true, 0 < $overflow['metadata']['provenance']['archived']['byte_count'], 'overflow metadata includes archived bytes', $failures, $passes );
agents_api_smoke_assert_equals( 'compaction_overflow_archived', $overflow['events'][0]['type'], 'overflow emits archive lifecycle event', $failures, $passes );

echo "\n[6] Small markdown and unsplittable markdown remain unchanged:\n";
$small = AgentsAPI\AI\WP_Agent_Markdown_Section_Compaction_Adapter::split_for_overflow(
	AgentsAPI\AI\WP_Agent_Markdown_Section_Compaction_Adapter::parse( "# Only\n\nSmall file.\n" ),
	array(
		'target_bytes'        => 10000,
		'pointer_destination' => 'daily/2026/05/01.md',
	)
);
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Markdown_Section_Compaction_Adapter::STATUS_SKIPPED, $small['status'], 'small markdown skips overflow', $failures, $passes );
agents_api_smoke_assert_equals( 'overflow_input_below_target', $small['metadata']['reason'], 'small markdown records below-target reason', $failures, $passes );
agents_api_smoke_assert_equals( array(), $small['archive_items'], 'small markdown has no archive items', $failures, $passes );

$unsplittable = AgentsAPI\AI\WP_Agent_Markdown_Section_Compaction_Adapter::split_for_overflow(
	AgentsAPI\AI\WP_Agent_Markdown_Section_Compaction_Adapter::parse( "# Only\n\n" . str_repeat( 'single section ', 100 ) . "\n" ),
	array(
		'target_bytes'        => 40,
		'pointer_destination' => 'daily/2026/05/01.md',
	)
);
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Markdown_Section_Compaction_Adapter::STATUS_SKIPPED, $unsplittable['status'], 'single-section oversized markdown skips overflow', $failures, $passes );
agents_api_smoke_assert_equals( 'overflow_input_unsplittable', $unsplittable['metadata']['reason'], 'single-section oversized markdown records unsplittable reason', $failures, $passes );

agents_api_smoke_finish( 'Agents API markdown section compaction', $failures, $passes );

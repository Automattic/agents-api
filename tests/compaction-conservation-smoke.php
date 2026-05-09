<?php
/**
 * Pure-PHP smoke test for generic compaction conservation metadata.
 *
 * Run with: php tests/compaction-conservation-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-compaction-conservation-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

$original_items = array(
	array( 'type' => 'memory', 'content' => str_repeat( 'alpha ', 20 ) ),
	array( 'type' => 'memory', 'content' => str_repeat( 'bravo ', 20 ) ),
);
$compacted_items = array(
	array( 'type' => 'summary', 'content' => str_repeat( 'alpha bravo ', 10 ) ),
);
$archived_items = array(
	array( 'type' => 'archive', 'content' => str_repeat( 'full archive ', 18 ) ),
);

echo "\n[1] Healthy compaction exposes provenance and passes conservation:\n";
$metadata = AgentsAPI\AI\WP_Agent_Compaction_Conservation::metadata(
	array(
		'conservation_enabled'         => true,
		'minimum_conserved_byte_ratio' => 0.85,
	),
	$original_items,
	$compacted_items,
	array(),
	$archived_items
);
agents_api_smoke_assert_equals( 2, $metadata['provenance']['original']['item_count'], 'original item count is recorded', $failures, $passes );
agents_api_smoke_assert_equals( 1, $metadata['provenance']['compacted']['item_count'], 'compacted item count is recorded', $failures, $passes );
agents_api_smoke_assert_equals( 1, $metadata['provenance']['archived']['item_count'], 'archived item count is recorded', $failures, $passes );
agents_api_smoke_assert_equals( true, $metadata['conservation']['passed'], 'healthy compaction passes conservation', $failures, $passes );
agents_api_smoke_assert_equals( false, AgentsAPI\AI\WP_Agent_Compaction_Conservation::failed_closed( $metadata ), 'healthy compaction is not failed closed', $failures, $passes );

echo "\n[2] Lossy compaction fails closed without conversation compaction:\n";
$lossy = AgentsAPI\AI\WP_Agent_Compaction_Conservation::metadata(
	array(
		'conservation_enabled'         => true,
		'minimum_conserved_byte_ratio' => 0.95,
	),
	$original_items,
	array( array( 'type' => 'summary', 'content' => 'tiny' ) ),
	array(),
	array()
);
agents_api_smoke_assert_equals( false, $lossy['conservation']['passed'], 'lossy compaction records failed conservation', $failures, $passes );
agents_api_smoke_assert_equals( true, $lossy['conservation']['failed_closed'], 'lossy compaction records failed-closed state', $failures, $passes );
agents_api_smoke_assert_equals( true, AgentsAPI\AI\WP_Agent_Compaction_Conservation::failed_closed( $lossy ), 'failed_closed helper detects failure', $failures, $passes );

echo "\n[3] Disabled conservation records opt-out while preserving metadata:\n";
$disabled = AgentsAPI\AI\WP_Agent_Compaction_Conservation::metadata(
	array(
		'conservation_enabled'         => false,
		'minimum_conserved_byte_ratio' => 1.0,
	),
	$original_items,
	array( array( 'type' => 'summary', 'content' => 'tiny' ) )
);
agents_api_smoke_assert_equals( false, $disabled['conservation']['enabled'], 'disabled conservation records opt-out', $failures, $passes );
agents_api_smoke_assert_equals( true, $disabled['conservation']['passed'], 'disabled conservation does not fail compacted output', $failures, $passes );

agents_api_smoke_finish( 'Agents API compaction conservation', $failures, $passes );

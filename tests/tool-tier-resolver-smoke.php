<?php
/**
 * Pure-PHP smoke test for tool tier resolver contracts.
 *
 * Run with: php tests/tool-tier-resolver-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-tool-tier-resolver-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

echo "\n[1] Tool tier contracts are available:\n";
agents_api_smoke_assert_equals( true, interface_exists( 'WP_Agent_Tool_Tier_Resolver' ), 'tier resolver interface is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Default_Tool_Tier_Resolver' ), 'default tier resolver is available', $failures, $passes );
agents_api_smoke_assert_equals( true, interface_exists( 'WP_Agent_Tool_Usage_Tracker' ), 'usage tracker interface is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Null_Tool_Usage_Tracker' ), 'null usage tracker is available', $failures, $passes );

$tools = array(
	'client/read'      => array(
		'name'        => 'client/read',
		'description' => 'Read content.',
		'parameters'  => array(
			'type'       => 'object',
			'required'   => array( 'post_id' ),
			'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
		),
	),
	'client/write'     => array(
		'name'        => 'client/write',
		'description' => 'Write content.',
		'parameters'  => array(),
	),
	'client/search'    => array(
		'name'        => 'client/search',
		'description' => 'Search content.',
		'parameters'  => array(
			'type'       => 'object',
			'required'   => array( 'query' ),
			'properties' => array( 'query' => array( 'type' => 'string' ) ),
		),
	),
	'client/mandatory' => array(
		'name'        => 'client/mandatory',
		'description' => 'Always available.',
		'mandatory'   => true,
	),
);

echo "\n[2] Curated tools land in Tier-1 before fallback tools:\n";
$resolver = new WP_Agent_Default_Tool_Tier_Resolver( null, 2 );
$resolved = $resolver->resolve(
	$tools,
	array(
		'tier_1_tools' => array( 'client/search' ),
	)
);
agents_api_smoke_assert_equals( array( 'client/search', 'client/mandatory' ), array_keys( $resolved['tier_1'] ), 'curated and mandatory tools fill Tier-1', $failures, $passes );
agents_api_smoke_assert_equals( array( 'client/read', 'client/write' ), array_keys( $resolved['tier_2'] ), 'remaining tools fall to Tier-2', $failures, $passes );

echo "\n[3] Usage tracker influences Tier-1 within the hard cap:\n";
$tracker = new class() implements WP_Agent_Tool_Usage_Tracker {
	public array $recorded = array();
	public function record_call( string $tool_name, string $workspace_id ): void {
		$this->recorded[] = array( $tool_name, $workspace_id );
	}
	public function top_n( string $workspace_id, int $limit ): array {
		unset( $limit );
		return 'site-1' === $workspace_id ? array( 'client/write', 'client/read' ) : array();
	}
};
$resolver = new WP_Agent_Default_Tool_Tier_Resolver( $tracker, 2 );
$resolved = $resolver->resolve( $tools, array( 'workspace_id' => 'site-1' ) );
agents_api_smoke_assert_equals( array( 'client/write', 'client/read' ), array_keys( $resolved['tier_1'] ), 'usage tracker selects top tools', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $resolved['tier_1'] ), 'hard cap limits Tier-1 size', $failures, $passes );

echo "\n[4] Tier-2 manifest exposes compact required-field hints:\n";
$manifest_by_name = array();
foreach ( $resolved['manifest'] as $entry ) {
	$manifest_by_name[ $entry['name'] ] = $entry;
}
agents_api_smoke_assert_equals( 'Search content.', $manifest_by_name['client/search']['summary'] ?? null, 'manifest carries tool summary', $failures, $passes );
agents_api_smoke_assert_equals( array( 'query' ), $manifest_by_name['client/search']['required_fields'] ?? null, 'manifest carries required-field hint', $failures, $passes );

echo "\n[5] Null usage tracker is transparent:\n";
$null_tracker = new WP_Agent_Null_Tool_Usage_Tracker();
$null_tracker->record_call( 'client/read', 'site-1' );
agents_api_smoke_assert_equals( array(), $null_tracker->top_n( 'site-1', 5 ), 'null tracker returns no usage', $failures, $passes );

agents_api_smoke_finish( 'Tool tier resolver', $failures, $passes );

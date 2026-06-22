<?php
/**
 * Pure-PHP smoke test for memory store resolver semantics.
 *
 * Run with: php tests/memory-store-resolver-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-memory-store-resolver-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

$store = new class() implements AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store {
	public function capabilities(): AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store_Capabilities {
		return AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store_Capabilities::none();
	}

	public function read( AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Scope $scope, array $metadata_fields = AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Metadata::FIELDS ): AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Read_Result {
		unset( $scope, $metadata_fields );
		return AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Read_Result::not_found();
	}

	public function write( AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Scope $scope, string $content, ?string $if_match = null, ?AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Metadata $metadata = null ): AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Write_Result {
		unset( $scope, $content, $if_match, $metadata );
		return AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Write_Result::ok( '', 0 );
	}

	public function exists( AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Scope $scope ): bool {
		unset( $scope );
		return false;
	}

	public function delete( AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Scope $scope ): AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Write_Result {
		unset( $scope );
		return AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Write_Result::ok( '', 0 );
	}

	public function list_layer( AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Scope $scope_query, ?AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Query $query = null ): array {
		unset( $scope_query, $query );
		return array();
	}

	public function list_subtree( AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Scope $scope_query, string $prefix, ?AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Query $query = null ): array {
		unset( $scope_query, $prefix, $query );
		return array();
	}
};

echo "\n[1] Direct context store wins:\n";
$resolved = AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Stores::get_store( array( 'memory_store' => $store ) );
agents_api_smoke_assert_equals( true, $resolved === $store, 'context memory_store is returned directly', $failures, $passes );

echo "\n[2] Filter-provided store is resolved:\n";
add_filter(
	'wp_agent_memory_store',
	static function ( $candidate, array $context ) use ( $store ) {
		unset( $candidate );
		return ! empty( $context['use_store'] ) ? $store : null;
	},
	10,
	2
);

$resolved = AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Stores::get_store( array( 'use_store' => true ) );
agents_api_smoke_assert_equals( true, $resolved === $store, 'wp_agent_memory_store filter can provide the store', $failures, $passes );

echo "\n[3] Missing or invalid stores return null:\n";
agents_api_smoke_assert_equals( null, AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Stores::get_store(), 'missing store resolves to null', $failures, $passes );
agents_api_smoke_assert_equals( null, AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Stores::get_store( array( 'memory_store' => new stdClass() ) ), 'invalid context store resolves to null', $failures, $passes );

agents_api_smoke_finish( 'Agents API memory store resolver', $failures, $passes );

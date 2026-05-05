<?php
/**
 * Pure-PHP smoke test for the Agents API materialized identity contract.
 *
 * Run with: php tests/identity-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-identity-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

echo "\n[1] Agent identity scope normalizes logical identity tuples:\n";
$scope            = new AgentsAPI\Core\Identity\WP_Agent_Identity_Scope( 'Research Assistant', 12, ' Site:42 / Primary ' );
$normalized_scope = $scope->normalize();
agents_api_smoke_assert_equals( 'research-assistant', $normalized_scope->agent_slug, 'agent slug is normalized like registered agents', $failures, $passes );
agents_api_smoke_assert_equals( 12, $normalized_scope->owner_user_id, 'owner user ID is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'site:42/primary', $normalized_scope->instance_key, 'instance key is normalized but product-addressable', $failures, $passes );
agents_api_smoke_assert_equals( 'research-assistant:12:site:42/primary', $scope->key(), 'scope key is stable', $failures, $passes );

echo "\n[2] Materialized identity exposes durable store identity without backend coupling:\n";
$identity = new AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity(
	23,
	$scope,
	array( 'temperature' => 0.2 ),
	array( 'source' => 'smoke' ),
	1713370000,
	1713370500
);
agents_api_smoke_assert_equals( '23', $identity->key(), 'identity key uses durable store ID', $failures, $passes );
agents_api_smoke_assert_equals(
	array(
		'id'            => 23,
		'agent_slug'    => 'research-assistant',
		'owner_user_id' => 12,
		'instance_key'  => 'site:42/primary',
		'config'        => array( 'temperature' => 0.2 ),
		'meta'          => array( 'source' => 'smoke' ),
		'created_at'    => 1713370000,
		'updated_at'    => 1713370500,
	),
	$identity->to_array(),
	'identity exports normalized payload',
	$failures,
	$passes
);

$updated = $identity->with_config( array( 'temperature' => 0.5 ) )->with_meta( array( 'source' => 'updated' ) );
agents_api_smoke_assert_equals( array( 'temperature' => 0.5 ), $updated->config, 'with_config returns replacement config copy', $failures, $passes );
agents_api_smoke_assert_equals( array( 'source' => 'updated' ), $updated->meta, 'with_meta returns replacement metadata copy', $failures, $passes );
agents_api_smoke_assert_equals( array( 'temperature' => 0.2 ), $identity->config, 'original identity remains immutable', $failures, $passes );

echo "\n[3] Identity store contract is available without a concrete backend:\n";
agents_api_smoke_assert_equals( true, interface_exists( 'AgentsAPI\\Core\\Identity\\WP_Agent_Identity_Store' ), 'materialized identity store interface is available', $failures, $passes );
agents_api_smoke_assert_equals( false, class_exists( 'DataMachine\\Core\\Identity\\WP_Agent_Identity_Store', false ) || interface_exists( 'DataMachine\\Core\\Identity\\WP_Agent_Identity_Store', false ), 'Data Machine identity store alias is not loaded', $failures, $passes );

agents_api_smoke_finish( 'Agents API materialized identity', $failures, $passes );

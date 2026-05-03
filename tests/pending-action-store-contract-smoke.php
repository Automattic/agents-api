<?php
/**
 * Pure-PHP smoke test for the Agents API pending action store contract.
 *
 * Run with: php tests/pending-action-store-contract-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-pending-action-store-contract-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

echo "\n[1] Pending action store contract is available without a concrete backend:\n";
agents_api_smoke_assert_equals( true, interface_exists( 'AgentsAPI\\AI\\Approvals\\PendingActionStoreInterface' ), 'pending action store interface is available', $failures, $passes );

$reflection = new ReflectionClass( 'AgentsAPI\\AI\\Approvals\\PendingActionStoreInterface' );
$methods    = array();
foreach ( $reflection->getMethods() as $method ) {
	$methods[ $method->getName() ] = $method;
}

echo "\n[2] Pending action store exposes the minimal generic lifecycle:\n";
agents_api_smoke_assert_equals( array( 'store', 'get', 'delete' ), array_keys( $methods ), 'contract exposes only store/get/delete', $failures, $passes );
agents_api_smoke_assert_equals( 'bool', (string) $methods['store']->getReturnType(), 'store returns bool', $failures, $passes );
agents_api_smoke_assert_equals( '?array', (string) $methods['get']->getReturnType(), 'get returns nullable array', $failures, $passes );
agents_api_smoke_assert_equals( 'bool', (string) $methods['delete']->getReturnType(), 'delete returns bool', $failures, $passes );

$store_parameters  = $methods['store']->getParameters();
$get_parameters    = $methods['get']->getParameters();
$delete_parameters = $methods['delete']->getParameters();
agents_api_smoke_assert_equals( array( 'action_id', 'payload' ), array_map( static fn( ReflectionParameter $parameter ): string => $parameter->getName(), $store_parameters ), 'store accepts action ID and payload', $failures, $passes );
agents_api_smoke_assert_equals( 'string', (string) $store_parameters[0]->getType(), 'store action ID is string', $failures, $passes );
agents_api_smoke_assert_equals( 'array', (string) $store_parameters[1]->getType(), 'store payload is array', $failures, $passes );
agents_api_smoke_assert_equals( array( 'action_id' ), array_map( static fn( ReflectionParameter $parameter ): string => $parameter->getName(), $get_parameters ), 'get accepts action ID only', $failures, $passes );
agents_api_smoke_assert_equals( 'string', (string) $get_parameters[0]->getType(), 'get action ID is string', $failures, $passes );
agents_api_smoke_assert_equals( array( 'action_id' ), array_map( static fn( ReflectionParameter $parameter ): string => $parameter->getName(), $delete_parameters ), 'delete accepts action ID only', $failures, $passes );
agents_api_smoke_assert_equals( 'string', (string) $delete_parameters[0]->getType(), 'delete action ID is string', $failures, $passes );

agents_api_smoke_finish( 'Agents API pending action store contract', $failures, $passes );

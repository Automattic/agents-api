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

echo "\n[2] Pending action store exposes the generic durable lifecycle and audit surface:\n";
agents_api_smoke_assert_equals( array( 'store', 'get', 'list', 'summary', 'record_resolution', 'expire', 'delete' ), array_keys( $methods ), 'contract exposes durable lifecycle methods', $failures, $passes );
agents_api_smoke_assert_equals( 'bool', (string) $methods['store']->getReturnType(), 'store returns bool', $failures, $passes );
agents_api_smoke_assert_equals( '?AgentsAPI\\AI\\Approvals\\PendingAction', (string) $methods['get']->getReturnType(), 'get returns nullable pending action', $failures, $passes );
agents_api_smoke_assert_equals( 'array', (string) $methods['list']->getReturnType(), 'list returns array', $failures, $passes );
agents_api_smoke_assert_equals( 'array', (string) $methods['summary']->getReturnType(), 'summary returns array', $failures, $passes );
agents_api_smoke_assert_equals( 'bool', (string) $methods['record_resolution']->getReturnType(), 'record_resolution returns bool', $failures, $passes );
agents_api_smoke_assert_equals( 'int', (string) $methods['expire']->getReturnType(), 'expire returns int', $failures, $passes );
agents_api_smoke_assert_equals( 'bool', (string) $methods['delete']->getReturnType(), 'delete returns bool', $failures, $passes );

$store_parameters       = $methods['store']->getParameters();
$get_parameters         = $methods['get']->getParameters();
$list_parameters        = $methods['list']->getParameters();
$summary_parameters     = $methods['summary']->getParameters();
$resolution_parameters  = $methods['record_resolution']->getParameters();
$expire_parameters      = $methods['expire']->getParameters();
$delete_parameters      = $methods['delete']->getParameters();
agents_api_smoke_assert_equals( array( 'action' ), array_map( static fn( ReflectionParameter $parameter ): string => $parameter->getName(), $store_parameters ), 'store accepts pending action value object', $failures, $passes );
agents_api_smoke_assert_equals( 'AgentsAPI\\AI\\Approvals\\PendingAction', (string) $store_parameters[0]->getType(), 'store action is pending action', $failures, $passes );
agents_api_smoke_assert_equals( array( 'action_id', 'include_resolved' ), array_map( static fn( ReflectionParameter $parameter ): string => $parameter->getName(), $get_parameters ), 'get accepts action ID and audit flag', $failures, $passes );
agents_api_smoke_assert_equals( 'string', (string) $get_parameters[0]->getType(), 'get action ID is string', $failures, $passes );
agents_api_smoke_assert_equals( 'bool', (string) $get_parameters[1]->getType(), 'get include_resolved is bool', $failures, $passes );
agents_api_smoke_assert_equals( array( 'filters' ), array_map( static fn( ReflectionParameter $parameter ): string => $parameter->getName(), $list_parameters ), 'list accepts filters', $failures, $passes );
agents_api_smoke_assert_equals( 'array', (string) $list_parameters[0]->getType(), 'list filters are array', $failures, $passes );
agents_api_smoke_assert_equals( array( 'filters' ), array_map( static fn( ReflectionParameter $parameter ): string => $parameter->getName(), $summary_parameters ), 'summary accepts filters', $failures, $passes );
agents_api_smoke_assert_equals( 'array', (string) $summary_parameters[0]->getType(), 'summary filters are array', $failures, $passes );
agents_api_smoke_assert_equals( array( 'action_id', 'decision', 'resolver', 'result', 'error', 'metadata' ), array_map( static fn( ReflectionParameter $parameter ): string => $parameter->getName(), $resolution_parameters ), 'record_resolution accepts audit fields', $failures, $passes );
agents_api_smoke_assert_equals( 'AgentsAPI\\AI\\Approvals\\ApprovalDecision', (string) $resolution_parameters[1]->getType(), 'record_resolution decision is approval decision', $failures, $passes );
agents_api_smoke_assert_equals( 'string', (string) $resolution_parameters[2]->getType(), 'record_resolution resolver is string', $failures, $passes );
agents_api_smoke_assert_equals( array( 'before' ), array_map( static fn( ReflectionParameter $parameter ): string => $parameter->getName(), $expire_parameters ), 'expire accepts optional boundary', $failures, $passes );
agents_api_smoke_assert_equals( '?string', (string) $expire_parameters[0]->getType(), 'expire boundary is nullable string', $failures, $passes );
agents_api_smoke_assert_equals( array( 'action_id' ), array_map( static fn( ReflectionParameter $parameter ): string => $parameter->getName(), $delete_parameters ), 'delete accepts action ID only', $failures, $passes );
agents_api_smoke_assert_equals( 'string', (string) $delete_parameters[0]->getType(), 'delete action ID is string', $failures, $passes );

agents_api_smoke_finish( 'Agents API pending action store contract', $failures, $passes );

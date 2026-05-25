<?php
/**
 * Pure-PHP smoke test for the Abilities lifecycle bridge.
 *
 * Run with: php tests/ability-lifecycle-bridge-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-ability-lifecycle-bridge-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

use AgentsAPI\AI\Abilities\WP_Agent_Ability_Lifecycle_Bridge;

WP_Agent_Ability_Lifecycle_Bridge::register();

$observed = array();
add_action(
	WP_Agent_Ability_Lifecycle_Bridge::ACTION_ABILITY_EXECUTED,
	static function ( string $ability_name, $result, $input, $ability ) use ( &$observed ): void {
		$observed[] = array(
			'ability_name' => $ability_name,
			'result'       => $result,
			'input'        => $input,
			'ability'      => $ability,
		);
	},
	10,
	4
);

$ability_stub       = new \stdClass();
$ability_stub->name = 'test/echo';

echo "\n[1] Bridge passes a successful result through and emits the action:\n";
$result_in   = array( 'echoed' => 'hello' );
$input_in    = array( 'q' => 'hi' );
$result_out  = apply_filters( 'wp_ability_execute_result', $result_in, 'test/echo', $input_in, $ability_stub );

agents_api_smoke_assert_equals( $result_in, $result_out, 'filter returns the result unchanged', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $observed ), 'observer fired exactly once', $failures, $passes );
agents_api_smoke_assert_equals( 'test/echo', $observed[0]['ability_name'] ?? null, 'observer received ability name', $failures, $passes );
agents_api_smoke_assert_equals( $result_in, $observed[0]['result'] ?? null, 'observer received result payload', $failures, $passes );
agents_api_smoke_assert_equals( $input_in, $observed[0]['input'] ?? null, 'observer received normalized input', $failures, $passes );
agents_api_smoke_assert_equals( true, ( $observed[0]['ability'] ?? null ) === $ability_stub, 'observer received the ability instance', $failures, $passes );

echo "\n[2] Bridge does not transform a failure result:\n";
$observed   = array();
$error_in   = new \RuntimeException( 'pretend WP_Error' );
$result_out = apply_filters( 'wp_ability_execute_result', $error_in, 'test/fail', array(), $ability_stub );

agents_api_smoke_assert_equals( true, $result_out === $error_in, 'failure result passes through unchanged', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $observed ), 'observer fires on failure too', $failures, $passes );
agents_api_smoke_assert_equals( 'test/fail', $observed[0]['ability_name'] ?? null, 'observer records failing ability name', $failures, $passes );

echo "\n[3] Repeated executions each emit independently:\n";
$observed = array();
apply_filters( 'wp_ability_execute_result', 'one', 'test/one', array( 'i' => 1 ), $ability_stub );
apply_filters( 'wp_ability_execute_result', 'two', 'test/two', array( 'i' => 2 ), $ability_stub );
apply_filters( 'wp_ability_execute_result', 'three', 'test/three', array( 'i' => 3 ), $ability_stub );

agents_api_smoke_assert_equals( 3, count( $observed ), 'three executions emit three observations', $failures, $passes );
agents_api_smoke_assert_equals( 'test/one', $observed[0]['ability_name'] ?? null, 'first observation carries ability name', $failures, $passes );
agents_api_smoke_assert_equals( 'test/three', $observed[2]['ability_name'] ?? null, 'third observation carries ability name', $failures, $passes );

agents_api_smoke_finish( 'ability lifecycle bridge', $failures, $passes );

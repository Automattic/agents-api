<?php
/**
 * Pure-PHP smoke test for the WP_Agent_Iteration_Budget value object.
 *
 * Run with: php tests/iteration-budget-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-iteration-budget-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

echo "\n[1] Budget initializes with correct defaults:\n";
$budget = new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'turns', 5 );
agents_api_smoke_assert_equals( 'turns', $budget->name(), 'name returns budget name', $failures, $passes );
agents_api_smoke_assert_equals( 5, $budget->ceiling(), 'ceiling returns configured maximum', $failures, $passes );
agents_api_smoke_assert_equals( 0, $budget->current(), 'current starts at zero', $failures, $passes );
agents_api_smoke_assert_equals( false, $budget->exceeded(), 'new budget is not exceeded', $failures, $passes );
agents_api_smoke_assert_equals( 5, $budget->remaining(), 'remaining equals ceiling when current is zero', $failures, $passes );

echo "\n[2] Increment ticks current and exceeded triggers at ceiling:\n";
$budget = new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'tool_calls', 3 );
$budget->increment();
agents_api_smoke_assert_equals( 1, $budget->current(), 'current ticks after first increment', $failures, $passes );
agents_api_smoke_assert_equals( false, $budget->exceeded(), 'budget not exceeded below ceiling', $failures, $passes );
agents_api_smoke_assert_equals( 2, $budget->remaining(), 'remaining decreases after increment', $failures, $passes );

$budget->increment();
agents_api_smoke_assert_equals( 2, $budget->current(), 'current ticks after second increment', $failures, $passes );
agents_api_smoke_assert_equals( false, $budget->exceeded(), 'budget not exceeded one below ceiling', $failures, $passes );

$budget->increment();
agents_api_smoke_assert_equals( 3, $budget->current(), 'current reaches ceiling', $failures, $passes );
agents_api_smoke_assert_equals( true, $budget->exceeded(), 'budget exceeded at ceiling', $failures, $passes );
agents_api_smoke_assert_equals( 0, $budget->remaining(), 'remaining is zero when exceeded', $failures, $passes );

echo "\n[3] Budget exceeded stays true past ceiling:\n";
$budget->increment();
agents_api_smoke_assert_equals( 4, $budget->current(), 'current can exceed ceiling', $failures, $passes );
agents_api_smoke_assert_equals( true, $budget->exceeded(), 'budget stays exceeded past ceiling', $failures, $passes );
agents_api_smoke_assert_equals( 0, $budget->remaining(), 'remaining clamps to zero past ceiling', $failures, $passes );

echo "\n[4] Ceiling clamps to minimum of 1:\n";
$small = new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'edge', 0 );
agents_api_smoke_assert_equals( 1, $small->ceiling(), 'zero ceiling clamps to 1', $failures, $passes );

$negative = new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'edge', -5 );
agents_api_smoke_assert_equals( 1, $negative->ceiling(), 'negative ceiling clamps to 1', $failures, $passes );

echo "\n[5] Starting current clamps negative to zero:\n";
$preloaded = new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'retries', 5, -3 );
agents_api_smoke_assert_equals( 0, $preloaded->current(), 'negative starting current clamps to zero', $failures, $passes );

echo "\n[6] Starting current at or above ceiling is already exceeded:\n";
$pre_exceeded = new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'depth', 3, 3 );
agents_api_smoke_assert_equals( true, $pre_exceeded->exceeded(), 'budget starting at ceiling is exceeded', $failures, $passes );
agents_api_smoke_assert_equals( 0, $pre_exceeded->remaining(), 'remaining is zero for pre-exceeded budget', $failures, $passes );

$above = new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'depth', 3, 5 );
agents_api_smoke_assert_equals( true, $above->exceeded(), 'budget starting above ceiling is exceeded', $failures, $passes );

agents_api_smoke_finish( 'Agents API iteration budget', $failures, $passes );

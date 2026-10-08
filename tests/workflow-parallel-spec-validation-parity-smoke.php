<?php
/**
 * Pure-PHP parity smoke test for parallel-roles / parallel-map spec validation.
 *
 * Run with: php tests/workflow-parallel-spec-validation-parity-smoke.php
 *
 * The SYNC in-process loops (`run_parallel_roles()` / `run_parallel_map()`) and
 * the ASYNC dispatch-plan builders (`build_roles_dispatch_plan()` /
 * `build_map_dispatch_plan()`) used to re-implement the SAME spec validation
 * independently, so they could silently DRIFT and start accepting different
 * specs. Both paths now route through the shared `validate_parallel_roles_spec()`
 * / `validate_parallel_map_spec()` helpers. This test PINS that parity:
 *
 *   1. Both entry points REJECT the same invalid specs with the SAME error code.
 *   2. Both entry points ACCEPT the same valid spec (roles-with-aggregator + map)
 *      — the legitimate valid-spec path does NOT regress.
 *
 * The private static entry points are invoked directly via reflection so the
 * assertion targets the exact methods that were prone to drift.
 *
 * No WordPress required.
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "workflow-parallel-spec-validation-parity-smoke\n";

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}
if ( ! class_exists( 'WP_Ability' ) ) {
	class WP_Ability {
		public function __construct( private string $name, private array $args ) {}
		public function get_name(): string { return $this->name; }
		public function get_input_schema(): array { return isset( $this->args['input_schema'] ) && is_array( $this->args['input_schema'] ) ? $this->args['input_schema'] : array(); }
		public function get_meta_item( string $key, $default = null ) { return $this->args['meta'][ $key ] ?? $default; }
		public function execute( $input = null ) {
			$callback = $this->args['execute_callback'] ?? null;
			return is_callable( $callback ) ? call_user_func( $callback, is_array( $input ) ? $input : array() ) : null;
		}
	}
}

$GLOBALS['__filters']   = array();
$GLOBALS['__abilities'] = array();
$GLOBALS['__options']   = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
		unset( $accepted_args );
		$GLOBALS['__filters'][ $hook ][ $priority ][] = $cb;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		$cbs = $GLOBALS['__filters'][ $hook ] ?? array();
		ksort( $cbs );
		foreach ( $cbs as $bucket ) {
			foreach ( $bucket as $cb ) {
				$value = call_user_func_array( $cb, array_merge( array( $value ), $args ) );
			}
		}
		return $value;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
		add_filter( $hook, $cb, $priority, $accepted_args );
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		$cbs = $GLOBALS['__filters'][ $hook ] ?? array();
		ksort( $cbs );
		foreach ( $cbs as $bucket ) {
			foreach ( $bucket as $cb ) {
				call_user_func_array( $cb, $args );
			}
		}
	}
}
if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( string $name ) {
		return $GLOBALS['__abilities'][ $name ] ?? null;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) {
		return $GLOBALS['__options'][ $option ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		unset( $autoload );
		$GLOBALS['__options'][ $option ] = $value;
		return true;
	}
}

function parity_register_ability( string $name, \Closure $handler ): void {
	$GLOBALS['__abilities'][ $name ] = new WP_Ability(
		$name,
		array(
			'label'            => $name,
			'description'      => 'Parallel spec-validation parity smoke stub.',
			'input_schema'     => array( 'type' => 'object' ),
			'execute_callback' => $handler,
		)
	);
}

function smoke_assert( $expected, $actual, string $name, array &$failures, int &$passes ): void {
	if ( $expected === $actual ) {
		++$passes;
		echo "  PASS {$name}\n";
		return;
	}
	$failures[] = $name;
	echo "  FAIL {$name}\n";
	echo '    expected: ' . var_export( $expected, true ) . "\n";
	echo '    actual:   ' . var_export( $actual, true ) . "\n";
}

require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-bindings.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-spec-validator.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-spec.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-result.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-store.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-recorder.php';
require_once __DIR__ . '/../src/Abilities/class-wp-agent-ability-dispatcher.php';
require_once __DIR__ . '/../src/Runtime/interface-wp-agent-run-control-store.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-option-run-control-store.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-run-control.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-context.php';
require_once __DIR__ . '/../src/Workflows/interface-wp-agent-workflow-branch-executor.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-step-executor.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-runner.php';

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Runner;

// A trivial worker so a VALID spec's branches actually execute on the sync path.
parity_register_ability(
	'demo/echo',
	static function ( array $input ): array {
		return array( 'echoed' => (string) ( $input['value'] ?? '' ) );
	}
);

// ── Reflection handles to the private static entry points ────────────────────

$runner_class = new ReflectionClass( WP_Agent_Workflow_Runner::class );

$sync_roles  = $runner_class->getMethod( 'run_parallel_roles' );
$async_roles = $runner_class->getMethod( 'build_roles_dispatch_plan' );
$sync_map    = $runner_class->getMethod( 'run_parallel_map' );
$async_map   = $runner_class->getMethod( 'build_map_dispatch_plan' );
$handlers_m  = $runner_class->getMethod( 'default_step_handlers' );
foreach ( array( $sync_roles, $async_roles, $sync_map, $async_map, $handlers_m ) as $m ) {
	$m->setAccessible( true );
}

/** @var array<string,mixed> $handlers */
$handlers = $handlers_m->invoke( null );

/**
 * Invoke a sync entry point (run_parallel_roles / run_parallel_map) and return
 * the error code, or '' when it returned a non-error result.
 */
function parity_sync_code( ReflectionMethod $method, array $step, array $handlers ): string {
	$result = $method->invoke( null, $step, array(), $handlers );
	return is_wp_error( $result ) ? $result->get_error_code() : '';
}

/**
 * Invoke an async entry point (build_*_dispatch_plan) and return the error code,
 * or '' when it returned a non-error result.
 */
function parity_async_code( ReflectionMethod $method, array $step ): string {
	$result = $method->invoke( null, $step );
	return is_wp_error( $result ) ? $result->get_error_code() : '';
}

// ── 1. Roles: invalid specs rejected with the SAME code on both paths ─────────

$roles_invalid = array(
	'branch-not-array' => array(
		'step' => array( 'branches' => array( 'not-an-array' ) ),
		'code' => 'workflow_parallel_branch_invalid',
	),
	'empty-branches' => array(
		'step' => array( 'branches' => array() ),
		'code' => 'workflow_parallel_branches_empty',
	),
	'two-aggregators' => array(
		'step' => array(
			'branches' => array(
				array( 'role' => 'a', 'is_aggregator' => true, 'steps' => array( array( 'id' => 's1', 'type' => 'ability', 'ability' => 'demo/echo' ) ) ),
				array( 'role' => 'b', 'is_aggregator' => true, 'steps' => array( array( 'id' => 's2', 'type' => 'ability', 'ability' => 'demo/echo' ) ) ),
			),
		),
		'code' => 'workflow_parallel_aggregator_invalid',
	),
);

foreach ( $roles_invalid as $label => $case ) {
	$sync  = parity_sync_code( $sync_roles, $case['step'], $handlers );
	$async = parity_async_code( $async_roles, $case['step'] );
	smoke_assert( $case['code'], $sync, "roles sync rejects {$label} with {$case['code']}", $failures, $passes );
	smoke_assert( $case['code'], $async, "roles async rejects {$label} with {$case['code']}", $failures, $passes );
	smoke_assert( $sync, $async, "roles sync/async PARITY on {$label} (same code)", $failures, $passes );
}

// ── 2. Map: invalid specs rejected with the SAME code on both paths ───────────

$map_invalid = array(
	'items-not-array' => array(
		'step' => array( 'items' => 'not-an-array', 'steps' => array( array( 'id' => 'd', 'type' => 'ability', 'ability' => 'demo/echo' ) ) ),
		'code' => 'workflow_parallel_items_invalid',
	),
	'empty-steps' => array(
		'step' => array( 'items' => array( 1, 2 ), 'steps' => array() ),
		'code' => 'workflow_parallel_steps_invalid',
	),
);

foreach ( $map_invalid as $label => $case ) {
	$sync  = parity_sync_code( $sync_map, $case['step'], $handlers );
	$async = parity_async_code( $async_map, $case['step'] );
	smoke_assert( $case['code'], $sync, "map sync rejects {$label} with {$case['code']}", $failures, $passes );
	smoke_assert( $case['code'], $async, "map async rejects {$label} with {$case['code']}", $failures, $passes );
	smoke_assert( $sync, $async, "map sync/async PARITY on {$label} (same code)", $failures, $passes );
}

// ── 3. Roles: the SAME valid spec (siblings + aggregator) is ACCEPTED on both ─

$roles_valid = array(
	'context'  => array( 'marker' => 'M' ),
	'branches' => array(
		array(
			'role'          => 'headline',
			'required'      => true,
			'is_aggregator' => false,
			'steps'         => array( array( 'id' => 'h', 'type' => 'ability', 'ability' => 'demo/echo', 'args' => array( 'value' => 'H' ) ) ),
		),
		array(
			'role'          => 'body',
			'required'      => true,
			'is_aggregator' => false,
			'steps'         => array( array( 'id' => 'b', 'type' => 'ability', 'ability' => 'demo/echo', 'args' => array( 'value' => 'B' ) ) ),
		),
		array(
			'role'          => 'fuse',
			'required'      => true,
			'is_aggregator' => true,
			'steps'         => array( array( 'id' => 'agg', 'type' => 'ability', 'ability' => 'demo/echo', 'args' => array( 'value' => 'F' ) ) ),
		),
	),
);

$sync_roles_result  = $sync_roles->invoke( null, $roles_valid, array(), $handlers );
$async_roles_result = $async_roles->invoke( null, $roles_valid );

smoke_assert( false, is_wp_error( $sync_roles_result ), 'roles sync ACCEPTS the valid spec (no WP_Error)', $failures, $passes );
smoke_assert( false, is_wp_error( $async_roles_result ), 'roles async ACCEPTS the valid spec (no WP_Error)', $failures, $passes );
smoke_assert( 'roles', is_array( $sync_roles_result ) ? ( $sync_roles_result['shape'] ?? '' ) : '', 'roles sync valid path produces the roles shape (no regression)', $failures, $passes );
smoke_assert( 'fuse', is_array( $sync_roles_result ) ? ( $sync_roles_result['aggregator'] ?? '' ) : '', 'roles sync valid path ran the aggregator', $failures, $passes );
// Async plan: 2 sibling descriptors dispatched, aggregator deferred into the plan.
smoke_assert( 2, is_array( $async_roles_result ) ? count( $async_roles_result['branches'] ?? array() ) : -1, 'roles async valid path builds one descriptor per sibling', $failures, $passes );
smoke_assert( 'fuse', is_array( $async_roles_result ) ? ( $async_roles_result['aggregate']['aggregator_role'] ?? '' ) : '', 'roles async valid path carries the aggregator role in the collect plan', $failures, $passes );

// ── 4. Map: the SAME valid spec is ACCEPTED on both paths ─────────────────────

$map_valid = array(
	'items' => array( 10, 20 ),
	'as'    => 'num',
	'steps' => array( array( 'id' => 'd', 'type' => 'ability', 'ability' => 'demo/echo', 'args' => array( 'value' => '${vars.num}' ) ) ),
);

$sync_map_result  = $sync_map->invoke( null, $map_valid, array(), $handlers );
$async_map_result = $async_map->invoke( null, $map_valid );

smoke_assert( false, is_wp_error( $sync_map_result ), 'map sync ACCEPTS the valid spec (no WP_Error)', $failures, $passes );
smoke_assert( false, is_wp_error( $async_map_result ), 'map async ACCEPTS the valid spec (no WP_Error)', $failures, $passes );
smoke_assert( 'map', is_array( $sync_map_result ) ? ( $sync_map_result['shape'] ?? '' ) : '', 'map sync valid path produces the map shape (no regression)', $failures, $passes );
smoke_assert( 2, is_array( $sync_map_result ) ? ( $sync_map_result['count'] ?? 0 ) : -1, 'map sync valid path fans out one branch per item', $failures, $passes );
smoke_assert( 2, is_array( $async_map_result ) ? count( $async_map_result['branches'] ?? array() ) : -1, 'map async valid path builds one descriptor per item', $failures, $passes );

echo "Passed: {$passes}, Failed: " . count( $failures ) . "\n";
exit( count( $failures ) > 0 ? 1 : 0 );

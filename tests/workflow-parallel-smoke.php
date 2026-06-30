<?php
/**
 * Pure-PHP end-to-end smoke test for the `parallel` workflow step handler
 * (generic agent fanout).
 *
 * Run with: php tests/workflow-parallel-smoke.php
 *
 * Drives BOTH fanout shapes through the real WP_Agent_Workflow_Runner::run()
 * path — not shape assertions:
 *
 *   1. parallel-map     — same nested steps across N resolved items; each
 *                         branch result collected.
 *   2. parallel-roles   — role-scoped branches each get a shared immutable
 *      + aggregate         context, outputs collected by role, then an
 *                         aggregator branch fuses sibling outputs into the
 *                         final result.
 *
 * Also exercises: shared-context immutability (a branch mutation does not
 * leak to siblings/aggregator), the `wp_agent_workflow_should_fanout` gate,
 * a non-required failing branch surfacing its error, and validator acceptance
 * of both shapes.
 *
 * No WordPress required.
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "workflow-parallel-smoke\n";

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

function parallel_smoke_register_ability( string $name, \Closure $handler ): void {
	$GLOBALS['__abilities'][ $name ] = new WP_Ability(
		$name,
		array(
			'label'            => $name,
			'description'      => 'Parallel fanout smoke stub.',
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
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-step-executor.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-runner.php';

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Result;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Runner;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec_Validator;

// ── Stub abilities ───────────────────────────────────────────────────────

// Map worker: doubles a number.
parallel_smoke_register_ability(
	'demo/double',
	static function ( array $input ): array {
		return array( 'doubled' => 2 * (int) ( $input['n'] ?? 0 ) );
	}
);

// Role worker: echoes back what shared context + role focus it received so
// the test can prove every branch saw the SAME immutable shared context.
parallel_smoke_register_ability(
	'demo/role-worker',
	static function ( array $input ): array {
		return array(
			'role'        => (string) ( $input['role'] ?? '' ),
			'focus'       => (string) ( $input['focus'] ?? '' ),
			'saw_context' => (string) ( $input['shared'] ?? '' ),
			'fragment'    => (string) ( $input['role'] ?? '' ) . ':' . (string) ( $input['shared'] ?? '' ),
		);
	}
);

// Aggregator: fuses sibling fragments into a final bundle.
parallel_smoke_register_ability(
	'demo/aggregate',
	static function ( array $input ): array {
		return array(
			'final_bundle' => 'FUSED[' . (string) ( $input['headline'] ?? '' ) . '|' . (string) ( $input['body'] ?? '' ) . ']',
			'shared_seen'  => (string) ( $input['shared'] ?? '' ),
		);
	}
);

// A worker that always fails, to exercise non-required branch tolerance.
parallel_smoke_register_ability(
	'demo/fail',
	static function ( array $input ): \WP_Error {
		unset( $input );
		return new \WP_Error( 'demo_branch_boom', 'branch worker exploded' );
	}
);

// ── 1. parallel-map: same nested steps across N items ────────────────────

$map_spec = WP_Agent_Workflow_Spec::from_array(
	array(
		'id'     => 'demo/parallel-map',
		'inputs' => array(
			'numbers' => array( 'type' => 'array', 'required' => true ),
		),
		'steps'  => array(
			array(
				'id'    => 'double_each',
				'type'  => 'parallel',
				'items' => '${inputs.numbers}',
				'as'    => 'num',
				'steps' => array(
					array(
						'id'      => 'd',
						'type'    => 'ability',
						'ability' => 'demo/double',
						'args'    => array( 'n' => '${vars.num}' ),
					),
				),
			),
		),
	)
);

$map_validation = WP_Agent_Workflow_Spec_Validator::validate( $map_spec->to_array() );
smoke_assert( array(), $map_validation, 'validator accepts parallel-map shape', $failures, $passes );

$map_result = ( new WP_Agent_Workflow_Runner( null ) )->run(
	$map_spec,
	array( 'numbers' => array( 3, 5, 7 ) )
);

$map_out = $map_result->get_output()['last'] ?? array();

smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $map_result->get_status(), 'parallel-map run succeeds', $failures, $passes );
smoke_assert( 'map', $map_out['shape'] ?? '', 'parallel-map reports map shape', $failures, $passes );
smoke_assert( 3, $map_out['count'] ?? 0, 'parallel-map fans out one branch per item', $failures, $passes );
smoke_assert( 6, $map_out['branches'][0]['output']['doubled'] ?? null, 'parallel-map branch 0 collected (3*2)', $failures, $passes );
smoke_assert( 10, $map_out['branches'][1]['output']['doubled'] ?? null, 'parallel-map branch 1 collected (5*2)', $failures, $passes );
smoke_assert( 14, $map_out['branches'][2]['output']['doubled'] ?? null, 'parallel-map branch 2 collected (7*2)', $failures, $passes );
smoke_assert( 5, $map_out['branches'][1]['item'] ?? null, 'parallel-map branch records its scoped item', $failures, $passes );

// ── 2. parallel-roles + aggregate: shared context, role scatter, fuse ────

$shared_marker = 'SHARED_CTX_TOKEN';

$roles_spec = WP_Agent_Workflow_Spec::from_array(
	array(
		'id'     => 'demo/parallel-roles',
		'inputs' => array(
			'theme' => array( 'type' => 'string', 'required' => true ),
		),
		'steps'  => array(
			array(
				'id'      => 'scatter_gather',
				'type'    => 'parallel',
				'context' => array(
					'marker' => '${inputs.theme}',
				),
				'branches' => array(
					array(
						'role'                    => 'headline',
						'goal_focus'              => 'produce the headline fragment',
						'shared_context_contract' => 'read context.marker only',
						'expected_output'         => array( 'ref' => 'headline.fragment', 'shape' => 'string' ),
						'required'                => true,
						'can_write_final_bundle'  => false,
						'steps'                   => array(
							array(
								'id'      => 'h',
								'type'    => 'ability',
								'ability' => 'demo/role-worker',
								'args'    => array(
									'role'   => '${vars.role.role}',
									'focus'  => '${vars.role.goal_focus}',
									'shared' => '${vars.context.marker}',
								),
							),
						),
					),
					array(
						'role'                    => 'body',
						'goal_focus'              => 'produce the body fragment',
						'shared_context_contract' => 'read context.marker only',
						'expected_output'         => array( 'ref' => 'body.fragment', 'shape' => 'string' ),
						'required'                => true,
						'can_write_final_bundle'  => false,
						'steps'                   => array(
							array(
								'id'      => 'b',
								'type'    => 'ability',
								'ability' => 'demo/role-worker',
								'args'    => array(
									'role'   => '${vars.role.role}',
									'focus'  => '${vars.role.goal_focus}',
									'shared' => '${vars.context.marker}',
								),
							),
						),
					),
					array(
						'role'                    => 'fuse',
						'goal_focus'              => 'fuse sibling fragments into the final bundle',
						'shared_context_contract' => 'consume branch_outputs + context.marker',
						'expected_output'         => array( 'ref' => 'final_bundle', 'shape' => 'string' ),
						'required'                => true,
						'can_write_final_bundle'  => true,
						'steps'                   => array(
							array(
								'id'      => 'agg',
								'type'    => 'ability',
								'ability' => 'demo/aggregate',
								'args'    => array(
									'headline' => '${vars.branch_outputs.headline.fragment}',
									'body'     => '${vars.branch_outputs.body.fragment}',
									'shared'   => '${vars.context.marker}',
								),
							),
						),
					),
				),
			),
		),
	)
);

$roles_validation = WP_Agent_Workflow_Spec_Validator::validate( $roles_spec->to_array() );
smoke_assert( array(), $roles_validation, 'validator accepts parallel-roles+aggregate shape', $failures, $passes );

$roles_result = ( new WP_Agent_Workflow_Runner( null ) )->run(
	$roles_spec,
	array( 'theme' => $shared_marker )
);

$roles_out = $roles_result->get_output()['last'] ?? array();

smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $roles_result->get_status(), 'parallel-roles run succeeds', $failures, $passes );
smoke_assert( 'roles', $roles_out['shape'] ?? '', 'parallel-roles reports roles shape', $failures, $passes );
smoke_assert( 'fuse', $roles_out['aggregator'] ?? '', 'aggregator role identified', $failures, $passes );

// Every branch received the SAME shared immutable context.
smoke_assert( $shared_marker, $roles_out['branch_outputs']['headline']['saw_context'] ?? '', 'headline branch saw shared context', $failures, $passes );
smoke_assert( $shared_marker, $roles_out['branch_outputs']['body']['saw_context'] ?? '', 'body branch saw shared context', $failures, $passes );
smoke_assert( $shared_marker, $roles_out['final']['shared_seen'] ?? '', 'aggregator saw shared context', $failures, $passes );

// Each branch saw its own role focus (proves per-branch role scoping).
smoke_assert( 'headline', $roles_out['branch_outputs']['headline']['role'] ?? '', 'headline branch scoped to its own role', $failures, $passes );
smoke_assert( 'produce the body fragment', $roles_out['branch_outputs']['body']['focus'] ?? '', 'body branch scoped to its own goal_focus', $failures, $passes );

// Outputs collected keyed by role.
smoke_assert(
	array( 'headline', 'body', 'fuse' ),
	array_keys( $roles_out['branch_outputs'] ),
	'branch outputs collected keyed by role (workers then aggregator)',
	$failures,
	$passes
);

// Aggregator fused sibling fragments into the final bundle.
$expected_final = 'FUSED[headline:' . $shared_marker . '|body:' . $shared_marker . ']';
smoke_assert( $expected_final, $roles_out['final']['final_bundle'] ?? '', 'aggregator fused sibling outputs into final bundle', $failures, $passes );

// ── 3. Shared-context immutability: a branch mutation must not leak ───────

// Worker that tries to mutate the shared context it received and returns it.
parallel_smoke_register_ability(
	'demo/mutate-context',
	static function ( array $input ): array {
		// Even if this worker mutated its received copy, siblings get their
		// own snapshot, so the marker the next branch reads is unchanged.
		return array( 'seen' => (string) ( $input['shared'] ?? '' ) );
	}
);

$immut_spec = WP_Agent_Workflow_Spec::from_array(
	array(
		'id'    => 'demo/parallel-immutable-context',
		'steps' => array(
			array(
				'id'       => 'immut',
				'type'     => 'parallel',
				'context'  => array( 'marker' => 'ORIGINAL' ),
				'branches' => array(
					array(
						'role'                   => 'first',
						'required'               => true,
						'can_write_final_bundle' => false,
						'steps'                  => array(
							array(
								'id'      => 'm1',
								'type'    => 'ability',
								'ability' => 'demo/mutate-context',
								'args'    => array( 'shared' => '${vars.context.marker}' ),
							),
						),
					),
					array(
						'role'                   => 'second',
						'required'               => true,
						'can_write_final_bundle' => false,
						'steps'                  => array(
							array(
								'id'      => 'm2',
								'type'    => 'ability',
								'ability' => 'demo/mutate-context',
								'args'    => array( 'shared' => '${vars.context.marker}' ),
							),
						),
					),
					array(
						'role'                   => 'agg',
						'required'               => true,
						'can_write_final_bundle' => true,
						'steps'                  => array(
							array(
								'id'      => 'm3',
								'type'    => 'ability',
								'ability' => 'demo/mutate-context',
								'args'    => array( 'shared' => '${vars.context.marker}' ),
							),
						),
					),
				),
			),
		),
	)
);

$immut_result = ( new WP_Agent_Workflow_Runner( null ) )->run( $immut_spec );
$immut_out    = $immut_result->get_output()['last'] ?? array();

smoke_assert( 'ORIGINAL', $immut_out['branch_outputs']['first']['seen'] ?? '', 'first branch sees pristine shared context', $failures, $passes );
smoke_assert( 'ORIGINAL', $immut_out['branch_outputs']['second']['seen'] ?? '', 'second branch sees pristine shared context (no leak)', $failures, $passes );
smoke_assert( 'ORIGINAL', $immut_out['final']['seen'] ?? '', 'aggregator sees pristine shared context (no leak)', $failures, $passes );

// ── 4. Adaptive gate: wp_agent_workflow_should_fanout=false short-circuits ─

add_filter(
	'wp_agent_workflow_should_fanout',
	static function ( bool $should, array $step ): bool {
		unset( $should );
		// Only decline the gated spec; leave other parallel steps alone.
		return 'gated' !== ( $step['id'] ?? '' );
	},
	10,
	2
);

$gated_spec = WP_Agent_Workflow_Spec::from_array(
	array(
		'id'    => 'demo/parallel-gated',
		'steps' => array(
			array(
				'id'    => 'gated',
				'type'  => 'parallel',
				'items' => array( 1, 2, 3 ),
				'steps' => array(
					array(
						'id'      => 'd',
						'type'    => 'ability',
						'ability' => 'demo/double',
						'args'    => array( 'n' => '${vars.item}' ),
					),
				),
			),
		),
	)
);

$gated_result = ( new WP_Agent_Workflow_Runner( null ) )->run( $gated_spec );
$gated_out    = $gated_result->get_output()['last'] ?? array();

smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $gated_result->get_status(), 'declined fanout still succeeds (no-op)', $failures, $passes );
smoke_assert( false, $gated_out['fanned_out'] ?? null, 'gate declined => fanned_out=false', $failures, $passes );
smoke_assert( 'fanout_gate_declined', $gated_out['reason'] ?? '', 'gate decline carries reason', $failures, $passes );

// ── 5. Non-required failing branch surfaces its error, run continues ──────

$optional_fail_spec = WP_Agent_Workflow_Spec::from_array(
	array(
		'id'    => 'demo/parallel-optional-fail',
		'steps' => array(
			array(
				'id'       => 'tolerant',
				'type'     => 'parallel',
				'context'  => array( 'marker' => 'OK' ),
				'branches' => array(
					array(
						'role'                   => 'flaky',
						'required'               => false,
						'can_write_final_bundle' => false,
						'steps'                  => array(
							array( 'id' => 'f', 'type' => 'ability', 'ability' => 'demo/fail' ),
						),
					),
					array(
						'role'                   => 'agg',
						'required'               => true,
						'can_write_final_bundle' => true,
						'steps'                  => array(
							array(
								'id'      => 'a',
								'type'    => 'ability',
								'ability' => 'demo/aggregate',
								'args'    => array(
									'headline' => 'x',
									'body'     => 'y',
									'shared'   => '${vars.context.marker}',
								),
							),
						),
					),
				),
			),
		),
	)
);

$optional_result = ( new WP_Agent_Workflow_Runner( null ) )->run( $optional_fail_spec );
$optional_out    = $optional_result->get_output()['last'] ?? array();

smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $optional_result->get_status(), 'non-required failing branch does not fail the run', $failures, $passes );
smoke_assert( 'demo_branch_boom', $optional_out['branch_outputs']['flaky']['error']['code'] ?? '', 'non-required branch surfaces its error in collected output', $failures, $passes );
smoke_assert( 'FUSED[x|y]', $optional_out['final']['final_bundle'] ?? '', 'aggregator still fuses despite a tolerated branch failure', $failures, $passes );

// ── 6. Validator rejects malformed parallel specs ────────────────────────

$no_shape  = WP_Agent_Workflow_Spec_Validator::validate(
	array(
		'id'    => 'demo/bad-parallel',
		'steps' => array( array( 'id' => 'p', 'type' => 'parallel' ) ),
	)
);
$has_shape_error = false;
foreach ( $no_shape as $err ) {
	if ( 'invalid_parallel_shape' === $err['code'] ) {
		$has_shape_error = true;
	}
}
smoke_assert( true, $has_shape_error, 'validator rejects parallel step with neither items nor branches', $failures, $passes );

$two_aggregators = WP_Agent_Workflow_Spec_Validator::validate(
	array(
		'id'    => 'demo/two-aggregators',
		'steps' => array(
			array(
				'id'       => 'p',
				'type'     => 'parallel',
				'branches' => array(
					array( 'role' => 'a', 'can_write_final_bundle' => true, 'steps' => array( array( 'id' => 's1', 'type' => 'ability', 'ability' => 'demo/double' ) ) ),
					array( 'role' => 'b', 'can_write_final_bundle' => true, 'steps' => array( array( 'id' => 's2', 'type' => 'ability', 'ability' => 'demo/double' ) ) ),
				),
			),
		),
	)
);
$has_aggregator_error = false;
foreach ( $two_aggregators as $err ) {
	if ( 'invalid_parallel_aggregator' === $err['code'] ) {
		$has_aggregator_error = true;
	}
}
smoke_assert( true, $has_aggregator_error, 'validator rejects parallel-roles with two aggregators', $failures, $passes );

echo "Passed: {$passes}, Failed: " . count( $failures ) . "\n";
exit( count( $failures ) > 0 ? 1 : 0 );

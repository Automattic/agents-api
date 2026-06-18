<?php
/**
 * Pure-PHP smoke test for WP_Agent_Workflow_Runner.
 *
 * Run with: php tests/workflow-runner-smoke.php
 *
 * Drives the full execute path with stub abilities, a stub recorder, and
 * a hand-rolled spec. No WordPress required.
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "workflow-runner-smoke\n";

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

$GLOBALS['__filters']    = array();
$GLOBALS['__abilities']  = array();

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

/**
 * Register a stub ability so the runner can resolve it through wp_get_ability()
 * in either backend: pure-PHP keeps the Stub_Ability in an in-memory registry;
 * real WordPress registers it with the Abilities API (deferred to the init
 * action) using the handler as the execute callback.
 */
$GLOBALS['__workflow_runner_smoke_pending'] = array();
function workflow_runner_smoke_register_ability( string $name, \Closure $handler ): void {
	$GLOBALS['__abilities'][ $name ] = new WP_Ability(
		$name,
		array(
			'input_schema'     => array( 'type' => 'object' ),
			'execute_callback' => $handler,
		)
	);

	// Defer real Abilities API registration to the init action (fired once after
	// all stubs are queued); core rejects registration outside that window.
	if ( function_exists( 'wp_register_ability' ) ) {
		$GLOBALS['__workflow_runner_smoke_pending'][ $name ] = $handler;
	}
}

if ( function_exists( 'add_action' ) && function_exists( 'wp_register_ability' ) ) {
	add_action(
		'wp_abilities_api_categories_init',
		static function (): void {
			if ( function_exists( 'wp_has_ability_category' ) && ! wp_has_ability_category( 'workflow-runner-smoke' ) ) {
				wp_register_ability_category(
					'workflow-runner-smoke',
					array(
						'label'       => 'Workflow Runner Smoke',
						'description' => 'Workflow runner smoke stubs.',
					)
				);
			}
		}
	);

	add_action(
		'wp_abilities_api_init',
		static function (): void {
			foreach ( $GLOBALS['__workflow_runner_smoke_pending'] as $name => $handler ) {
				wp_register_ability(
					$name,
					array(
						'label'               => $name,
						'description'         => 'Workflow runner smoke stub.',
						'category'            => 'workflow-runner-smoke',
						'input_schema'        => array( 'type' => 'object' ),
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => $handler,
						'permission_callback' => '__return_true',
					)
				);
			}
			$GLOBALS['__workflow_runner_smoke_pending'] = array();
		}
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
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-context.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-step-executor.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-runner.php';

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Recorder;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Result;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Runner;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec;

class Capture_Recorder implements WP_Agent_Workflow_Run_Recorder {
	public array $writes = array();
	public function start( WP_Agent_Workflow_Run_Result $result ) {
		$this->writes[] = array(
			'op'     => 'start',
			'status' => $result->get_status(),
			'steps'  => count( $result->get_steps() ),
			'result' => $result->to_array(),
		);
		return $result->get_run_id();
	}
	public function update( WP_Agent_Workflow_Run_Result $result ) {
		$this->writes[] = array(
			'op'     => 'update',
			'status' => $result->get_status(),
			'steps'  => count( $result->get_steps() ),
			'result' => $result->to_array(),
		);
		return true;
	}
	public function find( string $run_id ): ?WP_Agent_Workflow_Run_Result { return null; }
	public function recent( array $args = array() ): array { return array(); }
}

// ─── Happy path: 2 sequential ability steps with bindings between them ───

workflow_runner_smoke_register_ability(
	'demo/uppercase',
	static function ( array $input ): array {
		return array( 'value' => strtoupper( (string) ( $input['text'] ?? '' ) ) );
	}
);
workflow_runner_smoke_register_ability(
	'demo/wrap',
	static function ( array $input ): array {
		return array( 'wrapped' => '<<' . (string) ( $input['inner'] ?? '' ) . '>>' );
	}
);
workflow_runner_smoke_register_ability(
	'demo/score-item',
	static function ( array $input ): array {
		return array(
			'id'     => (int) ( $input['id'] ?? 0 ),
			'points' => (int) ( $input['points'] ?? 0 ),
		);
	}
);
workflow_runner_smoke_register_ability(
	'demo/boom',
	static function ( array $input ): \WP_Error {
		unset( $input );
		return new \WP_Error( 'demo_bang', 'something broke' );
	}
);
workflow_runner_smoke_register_ability(
	'agents/chat',
	static function ( array $input ): \WP_Error {
		unset( $input );
		return new \WP_Error( 'agent_dispatch_failed', 'agent step failed' );
	}
);

// Register all stub abilities with the real Abilities API (no-op in pure-PHP).
do_action( 'wp_abilities_api_categories_init' );
do_action( 'wp_abilities_api_init' );

$spec = WP_Agent_Workflow_Spec::from_array(
	array(
		'id'    => 'demo/transform',
		'inputs' => array( 'text' => array( 'type' => 'string', 'required' => true ) ),
		'steps' => array(
			array(
				'id'      => 'upper',
				'type'    => 'ability',
				'ability' => 'demo/uppercase',
				'args'    => array( 'text' => '${inputs.text}' ),
			),
			array(
				'id'      => 'wrap',
				'type'    => 'ability',
				'ability' => 'demo/wrap',
				'args'    => array( 'inner' => '${steps.upper.output.value}' ),
			),
		),
	)
);

$recorder = new Capture_Recorder();
$runner   = new WP_Agent_Workflow_Runner( $recorder );
$result   = $runner->run( $spec, array( 'text' => 'hello' ) );

smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $result->get_status(), 'happy path succeeds', $failures, $passes );
smoke_assert( 2, count( $result->get_steps() ), 'two step records produced', $failures, $passes );
smoke_assert( 'HELLO', $result->get_steps()[0]['output']['value'], 'step 1 output recorded', $failures, $passes );
smoke_assert( '<<HELLO>>', $result->get_steps()[1]['output']['wrapped'], 'step 2 receives previous step output via binding', $failures, $passes );
smoke_assert( '<<HELLO>>', $result->get_output()['last']['wrapped'], 'final output exposes last step', $failures, $passes );
smoke_assert( true, count( $recorder->writes ) >= 3, 'recorder hit at least 3 times (start + per-step updates + final)', $failures, $passes );
smoke_assert( 'start', $recorder->writes[0]['op'], 'recorder start fires first', $failures, $passes );

// ─── Evidence refs stay first-class through results and recorders ─────

$evidence_refs     = array(
	'artifact_refs' => array(
		array(
			'type'  => 'wp_guideline_artifact',
			'id'    => 'guideline-artifact:123',
			'url'   => 'https://example.com/artifacts/123',
			'label' => 'Generated guideline artifact',
		),
	),
	'log_refs'      => array(
		array(
			'type' => 'wpai_request_log',
			'id'   => 'wpai_request_logs:42',
			'url'  => 'https://example.com/logs/42',
		),
	),
);
$evidence_recorder = new Capture_Recorder();
$evidence_result   = ( new WP_Agent_Workflow_Runner( $evidence_recorder ) )->run(
	$spec,
	array( 'text' => 'evidence' ),
	array(
		'run_id'        => 'evidence-run-1',
		'evidence_refs' => $evidence_refs,
	)
);
$roundtrip         = WP_Agent_Workflow_Run_Result::from_array( $evidence_result->to_array() );
$last_write        = end( $evidence_recorder->writes );

smoke_assert( $evidence_refs, $evidence_result->get_evidence_refs(), 'result exposes first-class evidence refs', $failures, $passes );
smoke_assert( $evidence_result->to_array(), $roundtrip->to_array(), 'run result round-trips through to_array/from_array', $failures, $passes );
smoke_assert( $evidence_refs, $last_write['result']['evidence_refs'] ?? array(), 'recorder update preserves evidence refs', $failures, $passes );
smoke_assert( true, is_string( json_encode( $evidence_result->get_evidence_refs() ) ), 'evidence refs are JSON-serializable', $failures, $passes );

// ─── Failed step short-circuits ───────────────────────────────────────

$bad_spec = WP_Agent_Workflow_Spec::from_array(
	array(
		'id'    => 'demo/bad',
		'steps' => array(
			array( 'id' => 'first',  'type' => 'ability', 'ability' => 'demo/boom' ),
			array( 'id' => 'second', 'type' => 'ability', 'ability' => 'demo/uppercase', 'args' => array( 'text' => 'never reached' ) ),
		),
	)
);

$recorder2 = new Capture_Recorder();
$result2   = ( new WP_Agent_Workflow_Runner( $recorder2 ) )->run( $bad_spec );

smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $result2->get_status(), 'failed step yields failed run', $failures, $passes );
smoke_assert( 1, count( $result2->get_steps() ), 'short-circuit stops at first failure', $failures, $passes );
smoke_assert( 'demo_bang', $result2->get_error()['code'], 'top-level error carries failed step code', $failures, $passes );

// ─── continue_on_error keeps running ─────────────────────────────────

$result3 = ( new WP_Agent_Workflow_Runner( null ) )->run( $bad_spec, array(), array( 'continue_on_error' => true ) );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $result3->get_status(), 'continue_on_error still surfaces failure', $failures, $passes );
smoke_assert( 2, count( $result3->get_steps() ), 'continue_on_error executes the second step too', $failures, $passes );

// ─── Agent step errors use the shared ability dispatcher path ─────────

$agent_error_spec = WP_Agent_Workflow_Spec::from_array(
	array(
		'id'    => 'demo/agent-error',
		'steps' => array(
			array(
				'id'      => 'ask_agent',
				'type'    => 'agent',
				'agent'   => 'demo-agent',
				'message' => 'please fail',
			),
		),
	)
);

$agent_error_result = ( new WP_Agent_Workflow_Runner( null ) )->run( $agent_error_spec );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $agent_error_result->get_status(), 'agent step error fails run', $failures, $passes );
smoke_assert( 'agent_dispatch_failed', $agent_error_result->get_error()['code'], 'agent step surfaces dispatcher error code', $failures, $passes );
smoke_assert( 'ask_agent', $agent_error_result->get_steps()[0]['id'] ?? '', 'agent step error records step id', $failures, $passes );

// ─── Required-input check ────────────────────────────────────────────

$result4 = ( new WP_Agent_Workflow_Runner( null ) )->run( $spec /* missing text */ );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $result4->get_status(), 'missing required input fails fast', $failures, $passes );
smoke_assert( 'missing_required_input', $result4->get_error()['code'], 'input error has expected code', $failures, $passes );
smoke_assert( 0, count( $result4->get_steps() ), 'no steps run when input validation fails', $failures, $passes );

// ─── Unknown step type with no handler ───────────────────────────────

$martian = WP_Agent_Workflow_Spec::from_array(
	array(
		'id'    => 'demo/martian',
		'steps' => array( array( 'id' => 'a', 'type' => 'martian', 'foo' => 'bar' ) ),
	)
);
// Validator would have rejected this — but test the runner's defensive handling
// by passing an already-constructed Spec where the type made it through.
if ( $martian instanceof WP_Error ) {
	// Validator extension already exists from a previous test run — register a stub handler so the spec
	// constructs but the runner has no handler.
	add_filter( 'wp_agent_workflow_known_step_types', static fn( $t ) => array_merge( (array) $t, array( 'martian' ) ) );
	$martian = WP_Agent_Workflow_Spec::from_array(
		array(
			'id'    => 'demo/martian',
			'steps' => array( array( 'id' => 'a', 'type' => 'martian', 'foo' => 'bar' ) ),
		)
	);
}

if ( ! ( $martian instanceof WP_Error ) ) {
	$result5 = ( new WP_Agent_Workflow_Runner( null ) )->run( $martian );
	smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $result5->get_status(), 'no handler => failed run', $failures, $passes );
	smoke_assert( 'no_step_handler', $result5->get_error()['code'], 'runner reports no_step_handler', $failures, $passes );
}

// ─── Recorder->start() returning WP_Error fails fast ──────────────────

class Failing_Start_Recorder implements WP_Agent_Workflow_Run_Recorder {
	public int $start_calls  = 0;
	public int $update_calls = 0;
	public function start( WP_Agent_Workflow_Run_Result $result ) {
		++$this->start_calls;
		return new WP_Error( 'storage_offline', 'recorder is unavailable' );
	}
	public function update( WP_Agent_Workflow_Run_Result $result ) {
		++$this->update_calls;
		return true;
	}
	public function find( string $run_id ): ?WP_Agent_Workflow_Run_Result { return null; }
	public function recent( array $args = array() ): array { return array(); }
}

$recorder3 = new Failing_Start_Recorder();
$result6   = ( new WP_Agent_Workflow_Runner( $recorder3 ) )->run( $spec, array( 'text' => 'hi' ) );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $result6->get_status(), 'recorder start failure => failed run', $failures, $passes );
smoke_assert( 'recorder_start_failed', $result6->get_error()['code'], 'recorder start failure has expected code', $failures, $passes );
smoke_assert( 0, count( $result6->get_steps() ), 'no steps run when recorder start fails', $failures, $passes );
smoke_assert( 0, $recorder3->update_calls, 'no update fired when start failed', $failures, $passes );

// ─── Input-validation failure goes through start → update lifecycle ───

class Lifecycle_Tracker_Recorder implements WP_Agent_Workflow_Run_Recorder {
	public array $events = array();
	public function start( WP_Agent_Workflow_Run_Result $result ) {
		$this->events[] = array( 'op' => 'start', 'status' => $result->get_status() );
		return $result->get_run_id();
	}
	public function update( WP_Agent_Workflow_Run_Result $result ) {
		$this->events[] = array( 'op' => 'update', 'status' => $result->get_status() );
		return true;
	}
	public function find( string $run_id ): ?WP_Agent_Workflow_Run_Result { return null; }
	public function recent( array $args = array() ): array { return array(); }
}

$tracker = new Lifecycle_Tracker_Recorder();
$result7 = ( new WP_Agent_Workflow_Runner( $tracker ) )->run( $spec /* missing required text */ );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $result7->get_status(), 'missing input still fails', $failures, $passes );
smoke_assert( 'start', $tracker->events[0]['op'] ?? '', 'recorder sees start first', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_RUNNING, $tracker->events[0]['status'] ?? '', 'start is called with RUNNING', $failures, $passes );
smoke_assert( 'update', $tracker->events[1]['op'] ?? '', 'recorder sees update second', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $tracker->events[1]['status'] ?? '', 'update flips status to FAILED', $failures, $passes );

// ─── foreach step iterates over bound arrays with scoped vars ────────

$foreach_spec = WP_Agent_Workflow_Spec::from_array(
	array(
		'id'    => 'demo/foreach',
		'inputs' => array(
			'items' => array( 'type' => 'array', 'required' => true ),
		),
		'steps' => array(
			array(
				'id'    => 'score_each',
				'type'  => 'foreach',
				'items' => '${inputs.items}',
				'as'    => 'prediction',
				'steps' => array(
					array(
						'id'      => 'score',
						'type'    => 'ability',
						'ability' => 'demo/score-item',
						'args'    => array(
							'id'     => '${vars.prediction.id}',
							'points' => '${vars.prediction.points}',
						),
					),
				),
			),
		),
	)
);

$result8 = ( new WP_Agent_Workflow_Runner( null ) )->run(
	$foreach_spec,
	array(
		'items' => array(
			array( 'id' => 10, 'points' => 5 ),
			array( 'id' => 11, 'points' => 1 ),
		),
	)
);

smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $result8->get_status(), 'foreach run succeeds', $failures, $passes );
smoke_assert( 2, $result8->get_output()['last']['count'] ?? 0, 'foreach reports iteration count', $failures, $passes );
smoke_assert( 10, $result8->get_output()['last']['iterations'][0]['last']['id'] ?? 0, 'foreach first iteration receives scoped item', $failures, $passes );
smoke_assert( 1, $result8->get_output()['last']['iterations'][1]['last']['points'] ?? 0, 'foreach second iteration receives scoped item', $failures, $passes );

// ─── foreach reuses constructor-injected handlers for nested steps ────

add_filter( 'wp_agent_workflow_known_step_types', static fn( $types ) => array_merge( (array) $types, array( 'custom_nested' ) ) );

$custom_foreach_spec = WP_Agent_Workflow_Spec::from_array(
	array(
		'id'    => 'demo/foreach-custom-handler',
		'inputs' => array(
			'items' => array( 'type' => 'array', 'required' => true ),
		),
		'steps' => array(
			array(
				'id'    => 'custom_each',
				'type'  => 'foreach',
				'items' => '${inputs.items}',
				'as'    => 'item',
				'steps' => array(
					array(
						'id'     => 'custom',
						'type'   => 'custom_nested',
						'prefix' => 'item',
						'value'  => '${vars.item.id}',
					),
				),
			),
		),
	)
);

$custom_result = ( new WP_Agent_Workflow_Runner(
	null,
	array(
		'custom_nested' => static function ( array $step, array $context ): array {
			unset( $context );
			return array( 'label' => (string) ( $step['prefix'] ?? '' ) . '-' . (string) ( $step['value'] ?? '' ) );
		},
	)
) )->run(
	$custom_foreach_spec,
	array(
		'items' => array(
			array( 'id' => 42 ),
		),
	)
);

smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $custom_result->get_status(), 'foreach nested custom handler run succeeds', $failures, $passes );
smoke_assert( 'item-42', $custom_result->get_output()['last']['iterations'][0]['last']['label'] ?? '', 'foreach nested step uses constructor-injected handler', $failures, $passes );

echo "Passed: {$passes}, Failed: " . count( $failures ) . "\n";
exit( count( $failures ) > 0 ? 1 : 0 );

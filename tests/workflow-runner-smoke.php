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
			'label'            => $name,
			'description'      => 'Workflow runner smoke stub.',
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
require_once __DIR__ . '/../src/Runtime/interface-wp-agent-run-control-store.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-option-run-control-store.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-run-control.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-context.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-step-executor.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-runner.php';

use AgentsAPI\AI\WP_Agent_Run_Control;
use AgentsAPI\AI\WP_Agent_Atomic_Run_Control_Store;
use AgentsAPI\AI\WP_Agent_Run_Control_Store;
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

class Memory_Run_Control_Store implements WP_Agent_Run_Control_Store {
	public array $states = array();
	public int $reads    = 0;
	public int $writes   = 0;

	public function get_state( string $store_key ): array {
		++$this->reads;
		$state = $this->states[ $store_key ] ?? array();
		return array(
			'runs'   => isset( $state['runs'] ) && is_array( $state['runs'] ) ? $state['runs'] : array(),
			'queues' => isset( $state['queues'] ) && is_array( $state['queues'] ) ? $state['queues'] : array(),
		);
	}

	public function save_state( string $store_key, array $state ): void {
		++$this->writes;
		$this->states[ $store_key ] = $state;
	}
}

class Workflow_Late_Cancel_Store implements WP_Agent_Atomic_Run_Control_Store {
	public bool $cancel_before_next_mutation = false;
	public int $mutations_until_cancel = 0;
	public ?string $terminal_before_next_mutation = null;
	/** @var array<string,array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>}> */
	private array $states = array();

	public function get_state( string $store_key ): array {
		return $this->states[ $store_key ] ?? array( 'runs' => array(), 'queues' => array(), 'events' => array() );
	}

	public function save_state( string $store_key, array $state ): void {
		$this->states[ $store_key ] = $state;
	}

	public function mutate_state( string $store_key, callable $mutation ): mixed {
		$state = $this->get_state( $store_key );
		$cancel = $this->cancel_before_next_mutation;
		if ( $this->mutations_until_cancel > 0 ) {
			--$this->mutations_until_cancel;
			$cancel = 0 === $this->mutations_until_cancel;
		}
		if ( null !== $this->terminal_before_next_mutation ) {
			$status                              = $this->terminal_before_next_mutation;
			$this->terminal_before_next_mutation = null;
			foreach ( $state['runs'] as &$run ) {
				$run['status']    = $status;
				$run['cancelled'] = false;
			}
			unset( $run );
		} elseif ( $cancel ) {
			$this->cancel_before_next_mutation = false;
			foreach ( $state['runs'] as &$run ) {
				$run['status']    = WP_Agent_Run_Control::STATUS_CANCELLING;
				$run['cancelled'] = true;
			}
			unset( $run );
		}
		$mutated                   = $mutation( $state );
		$this->states[ $store_key ] = $mutated['state'];
		return $mutated['result'];
	}
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
	'demo/request-cancel',
	static function ( array $input ): array {
		WP_Agent_Run_Control::request_cancel( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, (string) ( $input['run_id'] ?? '' ) );
		return array( 'cancel_requested' => true );
	}
);
workflow_runner_smoke_register_ability(
	'demo/arm-late-cancel',
	static function (): array {
		$store = $GLOBALS['__workflow_late_cancel_store'] ?? null;
		if ( $store instanceof Workflow_Late_Cancel_Store ) {
			$store->cancel_before_next_mutation = true;
		}
		return array( 'completed' => true );
	}
);
workflow_runner_smoke_register_ability(
	'demo/arm-terminal-winner',
	static function (): array {
		$store = $GLOBALS['__workflow_terminal_store'] ?? null;
		if ( $store instanceof Workflow_Late_Cancel_Store ) {
			$store->terminal_before_next_mutation = (string) ( $GLOBALS['__workflow_terminal_status'] ?? '' );
		}
		return array( 'completed' => true );
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

// ─── Generic run-control facade can use an injected store ──────────────

$memory_store = new Memory_Run_Control_Store();
WP_Agent_Run_Control::set_store( $memory_store );

$stored_run = WP_Agent_Run_Control::start_run(
	'injected_run_control_store',
	'injected-run-1',
	array(
		'workflow_id' => 'demo/injected-store',
		'metadata'    => array( 'source' => 'smoke' ),
	)
);
$cancelled  = WP_Agent_Run_Control::request_cancel( 'injected_run_control_store', 'injected-run-1' );

smoke_assert( 'injected-run-1', $stored_run['run_id'] ?? '', 'injected run-control store writes through facade', $failures, $passes );
smoke_assert( WP_Agent_Run_Control::STATUS_CANCELLING, $cancelled['status'] ?? '', 'injected run-control store updates cancellation status', $failures, $passes );
smoke_assert( true, $memory_store->writes >= 2, 'injected run-control store receives writes', $failures, $passes );
smoke_assert( array(), $GLOBALS['__options']['injected_run_control_store'] ?? array(), 'injected run-control store does not write default options', $failures, $passes );

WP_Agent_Run_Control::reset_store();

$spec = WP_Agent_Workflow_Spec::from_array(
	array(
		'id'      => 'demo/transform',
		'version' => '1.2.3',
		'inputs'  => array( 'text' => array( 'type' => 'string', 'required' => true ) ),
		'steps'  => array(
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
smoke_assert( 'demo/uppercase', $result->get_steps()[0]['resolved_step']['ability'] ?? '', 'step record includes resolved step data', $failures, $passes );
smoke_assert( 'HELLO', $result->get_steps()[1]['resolved_step']['args']['inner'] ?? '', 'resolved step data includes expanded bindings', $failures, $passes );
smoke_assert( 'method', $result->get_steps()[0]['handler']['type'] ?? '', 'step record includes handler metadata', $failures, $passes );
smoke_assert( 1, $result->get_replay_metadata()['run_record_schema_version'] ?? 0, 'replay metadata includes run record schema version', $failures, $passes );
smoke_assert( '1.2.3', $result->get_replay_metadata()['workflow_spec_version'] ?? '', 'replay metadata includes workflow spec version', $failures, $passes );
smoke_assert( true, 64 === strlen( $result->get_replay_metadata()['workflow_spec_hash'] ?? '' ), 'replay metadata includes sha256 spec hash', $failures, $passes );
smoke_assert( $spec->to_array(), $result->get_replay_metadata()['workflow_spec_snapshot'] ?? array(), 'replay metadata includes workflow spec snapshot', $failures, $passes );

// ─── Cancellation committed inside finish wins every terminal projection ─

$late_cancel_spec = WP_Agent_Workflow_Spec::from_array(
	array(
		'id'    => 'demo/late-cancel',
		'steps' => array(
			array( 'id' => 'arm', 'type' => 'ability', 'ability' => 'demo/arm-late-cancel' ),
		),
	)
);
$late_cancel_store                         = new Workflow_Late_Cancel_Store();
$GLOBALS['__workflow_late_cancel_store']  = $late_cancel_store;
$late_cancel_hook_result                  = null;
WP_Agent_Run_Control::set_store( $late_cancel_store );
add_action(
	'wp_agent_workflow_run_completed',
	static function ( WP_Agent_Workflow_Run_Result $completed, string $run_id ) use ( &$late_cancel_hook_result ): void {
		if ( 'late-cancel-run' === $run_id ) {
			$late_cancel_hook_result = $completed;
		}
	},
	10,
	2
);
$late_cancel_recorder = new Capture_Recorder();
$late_cancel_result   = ( new WP_Agent_Workflow_Runner( $late_cancel_recorder ) )->run( $late_cancel_spec, array(), array( 'run_id' => 'late-cancel-run' ) );
$late_cancel_record   = end( $late_cancel_recorder->writes );
$late_cancel_stored   = WP_Agent_Run_Control::get_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'late-cancel-run' );

smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_CANCELLED, $late_cancel_result->get_status(), 'late atomic cancellation replaces the runner candidate return', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_CANCELLED, $late_cancel_record['status'] ?? '', 'late atomic cancellation reaches the recorder terminal update', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_CANCELLED, $late_cancel_hook_result instanceof WP_Agent_Workflow_Run_Result ? $late_cancel_hook_result->get_status() : '', 'late atomic cancellation reaches the completion hook', $failures, $passes );
smoke_assert( WP_Agent_Run_Control::STATUS_CANCELLED, $late_cancel_stored['status'] ?? '', 'late atomic cancellation is the run-control winner', $failures, $passes );
smoke_assert( 'cancel_requested', $late_cancel_result->get_error()['code'] ?? '', 'late atomic cancellation returns the canonical cancellation error', $failures, $passes );
WP_Agent_Run_Control::reset_store();
unset( $GLOBALS['__workflow_late_cancel_store'] );

$terminal_winner_spec = WP_Agent_Workflow_Spec::from_array(
	array(
		'id'    => 'demo/generic-terminal-winner',
		'steps' => array( array( 'id' => 'arm', 'type' => 'ability', 'ability' => 'demo/arm-terminal-winner' ) ),
	)
);
foreach (
	array(
		WP_Agent_Run_Control::STATUS_BUDGET_EXCEEDED => 'workflow_run_budget_exceeded',
		WP_Agent_Run_Control::STATUS_STALLED         => 'workflow_run_stalled',
		WP_Agent_Run_Control::STATUS_INTERRUPTED     => 'workflow_run_interrupted',
	) as $generic_status => $workflow_error
) {
	$terminal_store                             = new Workflow_Late_Cancel_Store();
	$GLOBALS['__workflow_terminal_store']       = $terminal_store;
	$GLOBALS['__workflow_terminal_status']      = $generic_status;
	$terminal_hook                              = null;
	$terminal_run_id                            = 'workflow-' . $generic_status;
	WP_Agent_Run_Control::set_store( $terminal_store );
	add_action(
		'wp_agent_workflow_run_completed',
		static function ( WP_Agent_Workflow_Run_Result $completed, string $run_id ) use ( &$terminal_hook, $terminal_run_id ): void {
			if ( $terminal_run_id === $run_id ) {
				$terminal_hook = $completed;
			}
		},
		10,
		2
	);
	$terminal_recorder = new Capture_Recorder();
	$terminal_result   = ( new WP_Agent_Workflow_Runner( $terminal_recorder ) )->run( $terminal_winner_spec, array(), array( 'run_id' => $terminal_run_id ) );
	$terminal_record   = end( $terminal_recorder->writes );
	$terminal_stored   = WP_Agent_Run_Control::get_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, $terminal_run_id );
	smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $terminal_result->get_status(), "{$generic_status} winner returns an honest failed workflow result", $failures, $passes );
	smoke_assert( $workflow_error, $terminal_result->get_error()['code'] ?? '', "{$generic_status} winner uses a stable workflow error", $failures, $passes );
	smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $terminal_record['status'] ?? '', "{$generic_status} winner reaches recorder projection", $failures, $passes );
	smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $terminal_hook instanceof WP_Agent_Workflow_Run_Result ? $terminal_hook->get_status() : '', "{$generic_status} winner reaches completion projection", $failures, $passes );
	smoke_assert( $generic_status, $terminal_stored['status'] ?? '', "{$generic_status} workflow projection matches authoritative storage", $failures, $passes );
}
WP_Agent_Run_Control::reset_store();
unset( $GLOBALS['__workflow_terminal_store'], $GLOBALS['__workflow_terminal_status'] );

// ─── Generic run-control cancellation stops before the next step ──────

$cancel_spec = WP_Agent_Workflow_Spec::from_array(
	array(
		'id'    => 'demo/cancel-before-next-step',
		'steps' => array(
			array(
				'id'      => 'request_cancel',
				'type'    => 'ability',
				'ability' => 'demo/request-cancel',
				'args'    => array( 'run_id' => 'cancel-run-1' ),
			),
			array(
				'id'      => 'should_not_run',
				'type'    => 'ability',
				'ability' => 'demo/uppercase',
				'args'    => array( 'text' => 'never reached' ),
			),
		),
	)
);

$cancel_result = ( new WP_Agent_Workflow_Runner( null ) )->run( $cancel_spec, array(), array( 'run_id' => 'cancel-run-1' ) );
$cancel_stored = WP_Agent_Run_Control::get_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'cancel-run-1' );

smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_CANCELLED, $cancel_result->get_status(), 'cancel_requested cancels workflow run', $failures, $passes );
smoke_assert( 1, count( $cancel_result->get_steps() ), 'cancel_requested stops before next workflow step', $failures, $passes );
smoke_assert( 'cancel_requested', $cancel_result->get_error()['code'] ?? '', 'cancelled workflow exposes cancel_requested error code', $failures, $passes );
smoke_assert( WP_Agent_Run_Control::STATUS_CANCELLED, $cancel_stored['status'] ?? '', 'workflow run-control record is marked cancelled', $failures, $passes );

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
smoke_assert( $spec->to_array(), $last_write['result']['replay']['workflow_spec_snapshot'] ?? array(), 'recorder update preserves replay metadata', $failures, $passes );
smoke_assert( true, is_string( json_encode( $evidence_result->get_evidence_refs() ) ), 'evidence refs are JSON-serializable', $failures, $passes );

// ─── Artifacts and logs stay first-class through results and recorders ─

$artifacts          = array(
	array(
		'type'  => 'json',
		'id'    => 'artifact:123',
		'label' => 'Workflow artifact',
	),
	'ignored',
);
$logs               = array(
	array(
		'level'   => 'info',
		'message' => 'Workflow run completed.',
	),
	'ignored',
);
$artifact_metadata  = array(
	'artifacts' => array( 'legacy metadata artifact stays untouched' ),
	'logs'      => array( 'legacy metadata log stays untouched' ),
);
$artifact_recorder  = new Capture_Recorder();
$artifact_result    = ( new WP_Agent_Workflow_Runner( $artifact_recorder ) )->run(
	$spec,
	array( 'text' => 'artifact' ),
	array(
		'run_id'    => 'artifact-run-1',
		'metadata'  => $artifact_metadata,
		'artifacts' => $artifacts,
		'logs'      => $logs,
	)
);
$artifact_roundtrip = WP_Agent_Workflow_Run_Result::from_array( $artifact_result->to_array() );
$artifact_last      = end( $artifact_recorder->writes );
$expected_artifacts = array( $artifacts[0] );
$expected_logs      = array( $logs[0] );

smoke_assert( $expected_artifacts, $artifact_result->get_artifacts(), 'result exposes first-class artifacts', $failures, $passes );
smoke_assert( $expected_logs, $artifact_result->get_logs(), 'result exposes first-class logs', $failures, $passes );
smoke_assert( $artifact_metadata, $artifact_result->get_metadata(), 'metadata remains untouched when artifacts/logs are first-class', $failures, $passes );
smoke_assert( $artifact_result->to_array(), $artifact_roundtrip->to_array(), 'artifacts/logs round-trip through to_array/from_array', $failures, $passes );
smoke_assert( $expected_artifacts, $artifact_last['result']['artifacts'] ?? array(), 'recorder update preserves first-class artifacts', $failures, $passes );
smoke_assert( $expected_logs, $artifact_last['result']['logs'] ?? array(), 'recorder update preserves first-class logs', $failures, $passes );

$legacy_result = WP_Agent_Workflow_Run_Result::from_array(
	array(
		'run_id'      => 'legacy-run',
		'workflow_id' => 'demo/legacy',
		'status'      => WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED,
	)
);
smoke_assert( array(), $legacy_result->get_artifacts(), 'legacy result defaults artifacts to empty array', $failures, $passes );
smoke_assert( array(), $legacy_result->get_logs(), 'legacy result defaults logs to empty array', $failures, $passes );

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

// ─── Throwing step handlers become failed terminal run records ─────────

add_filter( 'wp_agent_workflow_known_step_types', static fn( $types ) => array_merge( (array) $types, array( 'throwing' ) ) );

$throwing_spec = WP_Agent_Workflow_Spec::from_array(
	array(
		'id'    => 'demo/throwing-handler',
		'steps' => array(
			array( 'id' => 'explode', 'type' => 'throwing' ),
		),
	)
);

$throwing_recorder = new Capture_Recorder();
$throwing_result   = ( new WP_Agent_Workflow_Runner(
	$throwing_recorder,
	array(
		'throwing' => static function (): array {
			throw new \RuntimeException( 'handler exploded' );
		},
	)
) )->run( $throwing_spec );
$throwing_last     = end( $throwing_recorder->writes );

smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $throwing_result->get_status(), 'handler exception fails run', $failures, $passes );
smoke_assert( 'handler_exception', $throwing_result->get_error()['code'] ?? '', 'handler exception uses expected error code', $failures, $passes );
smoke_assert( 'handler_exception', $throwing_result->get_steps()[0]['error']['error_type'] ?? '', 'handler exception records expected error_type', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $throwing_last['status'] ?? '', 'recorder receives terminal failed update for handler exception', $failures, $passes );

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

$input_cancel_store                       = new Workflow_Late_Cancel_Store();
$input_cancel_store->mutations_until_cancel = 2;
$input_cancel_hook                        = null;
WP_Agent_Run_Control::set_store( $input_cancel_store );
add_action(
	'wp_agent_workflow_run_completed',
	static function ( WP_Agent_Workflow_Run_Result $completed, string $run_id ) use ( &$input_cancel_hook ): void {
		if ( 'input-cancel-run' === $run_id ) {
			$input_cancel_hook = $completed;
		}
	},
	10,
	2
);
$input_cancel_recorder = new Capture_Recorder();
$input_cancel_result   = ( new WP_Agent_Workflow_Runner( $input_cancel_recorder ) )->run( $spec, array(), array( 'run_id' => 'input-cancel-run' ) );
$input_cancel_record   = end( $input_cancel_recorder->writes );
$input_cancel_stored   = WP_Agent_Run_Control::get_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'input-cancel-run' );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_CANCELLED, $input_cancel_result->get_status(), 'input-validation finish returns a concurrent cancellation winner', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_CANCELLED, $input_cancel_record['status'] ?? '', 'input-validation recorder receives the cancellation winner', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_CANCELLED, $input_cancel_hook instanceof WP_Agent_Workflow_Run_Result ? $input_cancel_hook->get_status() : '', 'input-validation completion hook receives the cancellation winner', $failures, $passes );
smoke_assert( WP_Agent_Run_Control::STATUS_CANCELLED, $input_cancel_stored['status'] ?? '', 'input-validation response matches authoritative storage', $failures, $passes );
WP_Agent_Run_Control::reset_store();

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

$recorder_start_completions = 0;
add_action(
	'wp_agent_workflow_run_completed',
	static function ( WP_Agent_Workflow_Run_Result $completed, string $run_id ) use ( &$recorder_start_completions ): void {
		unset( $completed );
		if ( 'recorder-start-fail' === $run_id ) {
			++$recorder_start_completions;
		}
	},
	10,
	2
);
$recorder3 = new Failing_Start_Recorder();
$result6   = ( new WP_Agent_Workflow_Runner( $recorder3 ) )->run( $spec, array( 'text' => 'hi' ), array( 'run_id' => 'recorder-start-fail' ) );
$recorder_start_stored = WP_Agent_Run_Control::get_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'recorder-start-fail' );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $result6->get_status(), 'recorder start failure => failed run', $failures, $passes );
smoke_assert( 'recorder_start_failed', $result6->get_error()['code'], 'recorder start failure has expected code', $failures, $passes );
smoke_assert( 0, count( $result6->get_steps() ), 'no steps run when recorder start fails', $failures, $passes );
smoke_assert( 0, $recorder3->update_calls, 'no update fired when start failed', $failures, $passes );
smoke_assert( 1, $recorder_start_completions, 'recorder start failure fires completion exactly once', $failures, $passes );
smoke_assert( WP_Agent_Run_Control::STATUS_FAILED, $recorder_start_stored['status'] ?? '', 'recorder start failure terminalizes run-control', $failures, $passes );

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

class Resume_Failure_Recorder implements WP_Agent_Workflow_Run_Recorder {
	public int $updates = 0;
	public function __construct( private WP_Agent_Workflow_Run_Result $result ) {}
	public function start( WP_Agent_Workflow_Run_Result $result ) { unset( $result ); return ''; }
	public function update( WP_Agent_Workflow_Run_Result $result ) { ++$this->updates; $this->result = $result; return true; }
	public function find( string $run_id ): ?WP_Agent_Workflow_Run_Result { return $run_id === $this->result->get_run_id() ? $this->result : null; }
	public function recent( array $args = array() ): array { unset( $args ); return array( $this->result ); }
}

$resume_source = new WP_Agent_Workflow_Run_Result(
	'resume-spec-missing',
	'demo/missing-replay',
	WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED,
	array(),
	array(),
	array(),
	array(),
	time(),
	0,
	array( '_suspension' => array( 'step_index' => 0 ) ),
	array(),
	array()
);
$resume_recorder    = new Resume_Failure_Recorder( $resume_source );
$resume_completions = 0;
WP_Agent_Run_Control::start_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'resume-spec-missing', array( 'workflow_id' => 'demo/missing-replay' ) );
add_action(
	'wp_agent_workflow_run_completed',
	static function ( WP_Agent_Workflow_Run_Result $completed, string $run_id ) use ( &$resume_completions ): void {
		unset( $completed );
		if ( 'resume-spec-missing' === $run_id ) {
			++$resume_completions;
		}
	},
	10,
	2
);
$resume_runner = new WP_Agent_Workflow_Runner( $resume_recorder );
$resume_failed = $resume_runner->resume( 'resume-spec-missing' );
$resume_stored = WP_Agent_Run_Control::get_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'resume-spec-missing' );
$resume_replay = $resume_runner->resume( 'resume-spec-missing' );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $resume_failed->get_status(), 'missing resume spec returns terminal failure', $failures, $passes );
smoke_assert( 'workflow_resume_spec_unavailable', $resume_failed->get_error()['code'] ?? '', 'missing resume spec keeps checked failure code', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $resume_recorder->find( 'resume-spec-missing' )->get_status(), 'missing resume spec updates recorder terminal state', $failures, $passes );
smoke_assert( WP_Agent_Run_Control::STATUS_FAILED, $resume_stored['status'] ?? '', 'missing resume spec terminalizes run-control', $failures, $passes );
smoke_assert( 1, $resume_completions, 'missing resume spec publishes completion exactly once', $failures, $passes );
smoke_assert( 1, $resume_recorder->updates, 'duplicate failed resume does not update recorder again', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $resume_replay->get_status(), 'duplicate failed resume is idempotent', $failures, $passes );

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

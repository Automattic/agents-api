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

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

$GLOBALS['__filters']    = array();
$GLOBALS['__abilities']  = array();

function add_filter( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
	unset( $accepted_args );
	$GLOBALS['__filters'][ $hook ][ $priority ][] = $cb;
}
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
function add_action( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
	add_filter( $hook, $cb, $priority, $accepted_args );
}
function do_action( string $hook, ...$args ): void {
	$cbs = $GLOBALS['__filters'][ $hook ] ?? array();
	ksort( $cbs );
	foreach ( $cbs as $bucket ) {
		foreach ( $bucket as $cb ) {
			call_user_func_array( $cb, $args );
		}
	}
}
function wp_get_ability( string $name ) {
	return $GLOBALS['__abilities'][ $name ] ?? null;
}

class Stub_Ability {
	public function __construct( private \Closure $handler ) {}
	public function execute( array $input ) {
		return ( $this->handler )( $input );
	}
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
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-runner.php';

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Recorder;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Result;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Runner;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec;

class Capture_Recorder implements WP_Agent_Workflow_Run_Recorder {
	public array $writes = array();
	public function start( WP_Agent_Workflow_Run_Result $result ) {
		$this->writes[] = array( 'op' => 'start', 'status' => $result->get_status(), 'steps' => count( $result->get_steps() ) );
		return $result->get_run_id();
	}
	public function update( WP_Agent_Workflow_Run_Result $result ) {
		$this->writes[] = array( 'op' => 'update', 'status' => $result->get_status(), 'steps' => count( $result->get_steps() ) );
		return true;
	}
	public function find( string $run_id ): ?WP_Agent_Workflow_Run_Result { return null; }
	public function recent( array $args = array() ): array { return array(); }
}

// ─── Happy path: 2 sequential ability steps with bindings between them ───

$GLOBALS['__abilities']['demo/uppercase'] = new Stub_Ability(
	static function ( array $input ): array {
		return array( 'value' => strtoupper( (string) ( $input['text'] ?? '' ) ) );
	}
);
$GLOBALS['__abilities']['demo/wrap'] = new Stub_Ability(
	static function ( array $input ): array {
		return array( 'wrapped' => '<<' . (string) ( $input['inner'] ?? '' ) . '>>' );
	}
);

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

// ─── Failed step short-circuits ───────────────────────────────────────

$GLOBALS['__abilities']['demo/boom'] = new Stub_Ability(
	static function ( array $input ): \WP_Error {
		unset( $input );
		return new \WP_Error( 'demo_bang', 'something broke' );
	}
);

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

echo "Passed: {$passes}, Failed: " . count( $failures ) . "\n";
exit( count( $failures ) > 0 ? 1 : 0 );

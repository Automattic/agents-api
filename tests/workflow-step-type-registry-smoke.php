<?php
/**
 * Pure-PHP smoke test for WP_Agent_Workflow_Step_Type_Registry.
 *
 * Run with: php tests/workflow-step-type-registry-smoke.php
 *
 * Covers agents-api#574: a single step-type registry pairing each step
 * type with its handler and field validation, so the validator, runner,
 * and reconcile branch resolver can't drift apart.
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "workflow-step-type-registry-smoke\n";

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

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $value ) {
		return $value;
	}
}

$GLOBALS['__filters']  = array();
$GLOBALS['__diw_calls'] = array();

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
if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( string $function_name, string $message, string $version ): void {
		$GLOBALS['__diw_calls'][] = array(
			'function' => $function_name,
			'message'  => $message,
			'version'  => $version,
		);
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
		unset( $name );
		return null;
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
$GLOBALS['__options'] = array();

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
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-step-type-registry.php';
require_once __DIR__ . '/../src/Workflows/register-workflow-step-types.php';
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

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Recorder;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Result;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Runner;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec_Validator;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Step_Type_Registry;
use function AgentsAPI\AI\Workflows\register_workflow_step_type;

class Registry_Smoke_Capture_Recorder implements WP_Agent_Workflow_Run_Recorder {
	public array $writes = array();
	public function start( WP_Agent_Workflow_Run_Result $result ) {
		$this->writes[] = $result->get_status();
		return $result->get_run_id();
	}
	public function update( WP_Agent_Workflow_Run_Result $result ) {
		$this->writes[] = $result->get_status();
		return true;
	}
	public function find( string $run_id ): ?WP_Agent_Workflow_Run_Result { return null; }
	public function recent( array $args = array() ): array { return array(); }
}

// ─── Built-ins are registered lazily, before any explicit register() call ──

smoke_assert(
	array( 'ability', 'agent', 'foreach', 'parallel' ),
	WP_Agent_Workflow_Step_Type_Registry::types(),
	'built-in step types registered lazily, in order',
	$failures,
	$passes
);

// ─── Custom type: required + validate, invalid then valid, then runs ──────

WP_Agent_Workflow_Step_Type_Registry::reset();

$registered = register_workflow_step_type(
	'greet',
	array(
		'handler'  => static function ( array $step, array $context ) {
			unset( $context );
			return array( 'greeting' => 'hello ' . ( $step['name'] ?? '' ) );
		},
		'required' => array( 'name' ),
		'validate' => static function ( array $step, string $path ): array {
			$errors = array();
			if ( isset( $step['name'] ) && is_string( $step['name'] ) && strlen( $step['name'] ) > 40 ) {
				$errors[] = array(
					'path'    => "{$path}.name",
					'code'    => 'name_too_long',
					'message' => 'greet step `name` must be 40 characters or fewer',
				);
			}
			return $errors;
		},
	)
);
smoke_assert( true, $registered, 'register_workflow_step_type() registers a custom type', $failures, $passes );

// Invalid: missing the required `name` field.
$errors = WP_Agent_Workflow_Spec_Validator::validate(
	array(
		'id'    => 'demo/greet-missing',
		'steps' => array( array( 'id' => 'a', 'type' => 'greet' ) ),
	)
);
smoke_assert( 'steps.0.name', $errors[0]['path'] ?? '', 'custom type missing required field has correct path', $failures, $passes );
smoke_assert( 'missing_required', $errors[0]['code'] ?? '', 'custom type missing required field has correct code', $failures, $passes );

// Invalid: the custom `validate` callback's own rule.
$errors = WP_Agent_Workflow_Spec_Validator::validate(
	array(
		'id'    => 'demo/greet-long',
		'steps' => array( array( 'id' => 'a', 'type' => 'greet', 'name' => str_repeat( 'x', 41 ) ) ),
	)
);
$codes = array_column( $errors, 'code' );
$paths = array_column( $errors, 'path' );
smoke_assert( true, in_array( 'name_too_long', $codes, true ), 'custom validate callback flags its own rule', $failures, $passes );
smoke_assert( true, in_array( 'steps.0.name', $paths, true ), 'custom validate callback error has correct path', $failures, $passes );

// Valid step passes both the required check and the validate callback.
$errors = WP_Agent_Workflow_Spec_Validator::validate(
	array(
		'id'    => 'demo/greet-ok',
		'steps' => array( array( 'id' => 'a', 'type' => 'greet', 'name' => 'Chris' ) ),
	)
);
smoke_assert( array(), $errors, 'valid custom step passes validation', $failures, $passes );

// The registered handler actually runs and produces output.
$handlers = WP_Agent_Workflow_Step_Type_Registry::handlers();
smoke_assert( true, isset( $handlers['greet'] ) && is_callable( $handlers['greet'] ), 'custom handler is present in handlers()', $failures, $passes );
$output = call_user_func( $handlers['greet'], array( 'name' => 'Chris' ), array() );
smoke_assert( array( 'greeting' => 'hello Chris' ), $output, 'custom handler runs and returns its output', $failures, $passes );

// ─── Duplicate registration keeps the first, warns, doesn't overwrite ─────

$GLOBALS['__diw_calls'] = array();
$second                 = register_workflow_step_type(
	'greet',
	array(
		'handler' => static function ( array $step, array $context ) {
			unset( $step, $context );
			return array( 'greeting' => 'should not run' );
		},
	)
);
smoke_assert( false, $second, 'duplicate registration returns false', $failures, $passes );
smoke_assert( true, count( $GLOBALS['__diw_calls'] ) > 0, 'duplicate registration triggers _doing_it_wrong', $failures, $passes );

$handlers = WP_Agent_Workflow_Step_Type_Registry::handlers();
$output   = call_user_func( $handlers['greet'], array( 'name' => 'Chris' ), array() );
smoke_assert( array( 'greeting' => 'hello Chris' ), $output, 'duplicate registration keeps the first handler', $failures, $passes );

// ─── Built-in error codes are unchanged after the move into the registry ──

WP_Agent_Workflow_Step_Type_Registry::reset();
$GLOBALS['__filters'] = array();

// ability: missing required `ability`.
$errors = WP_Agent_Workflow_Spec_Validator::validate(
	array(
		'id'    => 'demo/ability-missing',
		'steps' => array( array( 'id' => 'a', 'type' => 'ability' ) ),
	)
);
smoke_assert( 'steps.0.ability', $errors[0]['path'] ?? '', 'ability step missing field: unchanged path', $failures, $passes );
smoke_assert( 'missing_required', $errors[0]['code'] ?? '', 'ability step missing field: unchanged code', $failures, $passes );
smoke_assert( 'ability step is missing a non-empty `ability`', $errors[0]['message'] ?? '', 'ability step missing field: unchanged message', $failures, $passes );

// agent: missing required `agent` and `message`.
$errors = WP_Agent_Workflow_Spec_Validator::validate(
	array(
		'id'    => 'demo/agent-missing',
		'steps' => array( array( 'id' => 'a', 'type' => 'agent' ) ),
	)
);
$paths = array_column( $errors, 'path' );
smoke_assert( true, in_array( 'steps.0.agent', $paths, true ), 'agent step missing `agent`: unchanged path', $failures, $passes );
smoke_assert( true, in_array( 'steps.0.message', $paths, true ), 'agent step missing `message`: unchanged path', $failures, $passes );

// foreach: missing `items` and non-list `steps`.
$errors = WP_Agent_Workflow_Spec_Validator::validate(
	array(
		'id'    => 'demo/foreach-missing',
		'steps' => array( array( 'id' => 'a', 'type' => 'foreach' ) ),
	)
);
$paths = array_column( $errors, 'path' );
$codes = array_column( $errors, 'code' );
smoke_assert( true, in_array( 'steps.0.items', $paths, true ), 'foreach step missing `items`: unchanged path', $failures, $passes );
smoke_assert( true, in_array( 'steps.0.steps', $paths, true ), 'foreach step missing `steps`: unchanged path', $failures, $passes );
smoke_assert( true, in_array( 'missing_required', $codes, true ), 'foreach step missing fields: unchanged code', $failures, $passes );

// parallel: neither `branches` nor `items`.
$errors = WP_Agent_Workflow_Spec_Validator::validate(
	array(
		'id'    => 'demo/parallel-shapeless',
		'steps' => array( array( 'id' => 'a', 'type' => 'parallel' ) ),
	)
);
smoke_assert( 'invalid_parallel_shape', $errors[0]['code'] ?? '', 'parallel step with no shape: unchanged code', $failures, $passes );

// parallel-roles: more than one aggregator.
$errors = WP_Agent_Workflow_Spec_Validator::validate(
	array(
		'id'    => 'demo/parallel-two-aggregators',
		'steps' => array(
			array(
				'id'       => 'a',
				'type'     => 'parallel',
				'branches' => array(
					array(
						'role'          => 'one',
						'is_aggregator' => true,
						'steps'         => array( array( 'id' => 'a1', 'type' => 'ability', 'ability' => 'x' ) ),
					),
					array(
						'role'          => 'two',
						'is_aggregator' => true,
						'steps'         => array( array( 'id' => 'a2', 'type' => 'ability', 'ability' => 'y' ) ),
					),
				),
			),
		),
	)
);
$codes = array_column( $errors, 'code' );
smoke_assert( true, in_array( 'invalid_parallel_aggregator', $codes, true ), 'parallel-roles with two aggregators: unchanged code', $failures, $passes );

// foreach nested-step errors still report a nested path via the same recursive validator.
$errors = WP_Agent_Workflow_Spec_Validator::validate(
	array(
		'id'    => 'demo/foreach-nested',
		'steps' => array(
			array(
				'id'    => 'each',
				'type'  => 'foreach',
				'items' => array(),
				'steps' => array( array( 'id' => 'inner', 'type' => 'agent' ) ),
			),
		),
	)
);
$paths = array_column( $errors, 'path' );
smoke_assert( true, in_array( 'steps.0.steps.0.agent', $paths, true ), 'foreach nested step error reports nested path unchanged', $failures, $passes );

echo "Passed: {$passes}, Failed: " . count( $failures ) . "\n";
exit( count( $failures ) > 0 ? 1 : 0 );

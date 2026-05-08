<?php
/**
 * Pure-PHP smoke test for WP_Agent_Workflow_Spec_Validator + WP_Agent_Workflow_Spec.
 *
 * Run with: php tests/workflow-spec-validator-smoke.php
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "workflow-spec-validator-smoke\n";

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

$GLOBALS['__filters'] = array();
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

require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-spec-validator.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-spec.php';

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec_Validator;

// ─── Validator ──────────────────────────────────────────────────────

// Valid minimal spec.
$min = array(
	'id'    => 'demo/min',
	'steps' => array(
		array( 'id' => 'a', 'type' => 'ability', 'ability' => 'core/some-op' ),
	),
);
smoke_assert( array(), WP_Agent_Workflow_Spec_Validator::validate( $min ), 'valid minimal spec returns no errors', $failures, $passes );

// Missing id.
$errors = WP_Agent_Workflow_Spec_Validator::validate( array( 'steps' => $min['steps'] ) );
smoke_assert( 'missing_required', $errors[0]['code'] ?? '', 'missing id flagged', $failures, $passes );
smoke_assert( 'id', $errors[0]['path'] ?? '', 'missing id has correct path', $failures, $passes );

// Missing steps.
$errors = WP_Agent_Workflow_Spec_Validator::validate( array( 'id' => 'demo/x' ) );
smoke_assert( true, ! empty( $errors ), 'missing steps flagged', $failures, $passes );
smoke_assert( 'steps', $errors[0]['path'] ?? '', 'missing steps reports path', $failures, $passes );

// Empty steps array.
$errors = WP_Agent_Workflow_Spec_Validator::validate( array( 'id' => 'demo/x', 'steps' => array() ) );
smoke_assert( true, ! empty( $errors ), 'empty steps still flagged', $failures, $passes );

// Step missing id.
$errors = WP_Agent_Workflow_Spec_Validator::validate(
	array(
		'id'    => 'demo/x',
		'steps' => array( array( 'type' => 'ability', 'ability' => 'core/op' ) ),
	)
);
smoke_assert( 'steps.0.id', $errors[0]['path'] ?? '', 'step missing id reports indexed path', $failures, $passes );

// Duplicate step ids.
$errors = WP_Agent_Workflow_Spec_Validator::validate(
	array(
		'id'    => 'demo/x',
		'steps' => array(
			array( 'id' => 'same', 'type' => 'ability', 'ability' => 'a' ),
			array( 'id' => 'same', 'type' => 'ability', 'ability' => 'b' ),
		),
	)
);
$dup_codes = array_column( $errors, 'code' );
smoke_assert( true, in_array( 'duplicate_id', $dup_codes, true ), 'duplicate step id flagged', $failures, $passes );

// Unknown step type.
$errors = WP_Agent_Workflow_Spec_Validator::validate(
	array(
		'id'    => 'demo/x',
		'steps' => array( array( 'id' => 'a', 'type' => 'martian', 'something' => 'x' ) ),
	)
);
smoke_assert( 'unknown_step_type', $errors[0]['code'] ?? '', 'unknown step type flagged', $failures, $passes );

// Filter widens the known step types.
add_filter(
	'wp_agent_workflow_known_step_types',
	static fn( $types ) => array_merge( (array) $types, array( 'martian' ) )
);
$errors = WP_Agent_Workflow_Spec_Validator::validate(
	array(
		'id'    => 'demo/x',
		'steps' => array( array( 'id' => 'a', 'type' => 'martian', 'something' => 'x' ) ),
	)
);
smoke_assert( array(), $errors, 'filter-extended step type accepted', $failures, $passes );

// Agent step missing message.
$errors = WP_Agent_Workflow_Spec_Validator::validate(
	array(
		'id'    => 'demo/x',
		'steps' => array( array( 'id' => 'a', 'type' => 'agent', 'agent' => 'demo' ) ),
	)
);
$paths = array_column( $errors, 'path' );
smoke_assert( true, in_array( 'steps.0.message', $paths, true ), 'agent step missing message flagged', $failures, $passes );

// wp_action trigger missing hook.
$errors = WP_Agent_Workflow_Spec_Validator::validate(
	array(
		'id'       => 'demo/x',
		'steps'    => $min['steps'],
		'triggers' => array( array( 'type' => 'wp_action' ) ),
	)
);
smoke_assert( 'triggers.0.hook', $errors[0]['path'] ?? '', 'wp_action trigger missing hook flagged', $failures, $passes );

// cron trigger missing both expression and interval.
$errors = WP_Agent_Workflow_Spec_Validator::validate(
	array(
		'id'       => 'demo/x',
		'steps'    => $min['steps'],
		'triggers' => array( array( 'type' => 'cron' ) ),
	)
);
smoke_assert( 'missing_required', $errors[0]['code'] ?? '', 'cron trigger missing schedule flagged', $failures, $passes );

// ─── Spec value object ──────────────────────────────────────────────

$spec = WP_Agent_Workflow_Spec::from_array(
	array(
		'id'       => 'demo/full',
		'version'  => '1.2.3',
		'inputs'   => array( 'request' => array( 'type' => 'string', 'required' => true ) ),
		'steps'    => array(
			array( 'id' => 'classify', 'type' => 'agent', 'agent' => 'demo', 'message' => 'Hi' ),
			array( 'id' => 'send', 'type' => 'ability', 'ability' => 'core/notify' ),
		),
		'triggers' => array( array( 'type' => 'on_demand' ) ),
		'meta'     => array( 'source_plugin' => 'unit-test' ),
	)
);

smoke_assert( false, $spec instanceof WP_Error, 'valid spec constructs without WP_Error', $failures, $passes );
smoke_assert( 'demo/full', $spec->get_id(), 'Spec::get_id', $failures, $passes );
smoke_assert( '1.2.3', $spec->get_version(), 'Spec::get_version', $failures, $passes );
smoke_assert( 2, count( $spec->get_steps() ), 'Spec::get_steps count', $failures, $passes );
smoke_assert( 'unit-test', $spec->get_meta()['source_plugin'], 'Spec::get_meta', $failures, $passes );

// from_array on invalid spec returns WP_Error.
$err = WP_Agent_Workflow_Spec::from_array( array( 'steps' => array() ) );
smoke_assert( true, $err instanceof WP_Error, 'invalid raw returns WP_Error', $failures, $passes );
smoke_assert( 'workflow_spec_invalid', $err instanceof WP_Error ? $err->get_error_code() : '', 'WP_Error has expected code', $failures, $passes );

echo "Passed: {$passes}, Failed: " . count( $failures ) . "\n";
exit( count( $failures ) > 0 ? 1 : 0 );

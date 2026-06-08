<?php
/**
 * Pure-PHP smoke test for the agents/run-workflow + validate-workflow
 * + describe-workflow ability dispatchers.
 *
 * Run with: php tests/agents-workflow-ability-smoke.php
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "agents-workflow-ability-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap ): bool { unset( $cap ); return $GLOBALS['__can'] ?? false; }
} else {
	add_filter(
		'user_has_cap',
		static function ( array $allcaps ): array {
			$allcaps['manage_options'] = (bool) ( $GLOBALS['__can'] ?? false );
			return $allcaps;
		}
	);
}

function smoke_reset_workflow_filters(): void {
	$hooks = array(
		'agents_run_workflow_dispatch_failed',
		'agents_run_workflow_permission',
		'wp_agent_workflow_handler',
	);

	foreach ( $hooks as $hook ) {
		if ( function_exists( 'remove_all_filters' ) ) {
			remove_all_filters( $hook );
			continue;
		}

		unset( $GLOBALS['__agents_api_smoke_actions'][ $hook ] );
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

agents_api_smoke_require_module();

use function AgentsAPI\AI\Workflows\agents_describe_workflow;
use function AgentsAPI\AI\Workflows\agents_run_workflow_dispatch;
use function AgentsAPI\AI\Workflows\agents_run_workflow_permission;
use function AgentsAPI\AI\Workflows\agents_validate_workflow;
use function AgentsAPI\AI\Workflows\register_workflow_handler;

// ─── Permission gate ─────────────────────────────────────────────────

$GLOBALS['__can'] = false;
smoke_assert( false, agents_run_workflow_permission( array() ), 'permission denied without manage_options', $failures, $passes );

$GLOBALS['__can'] = true;
smoke_assert( true, agents_run_workflow_permission( array() ), 'permission granted with manage_options', $failures, $passes );

add_filter( 'agents_run_workflow_permission', static fn() => false );
smoke_assert( false, agents_run_workflow_permission( array() ), 'filter can override permission to deny', $failures, $passes );

// ─── validate-workflow ───────────────────────────────────────────────

$valid = agents_validate_workflow(
	array(
		'spec' => array(
			'id'    => 'demo/v',
			'steps' => array( array( 'id' => 'a', 'type' => 'ability', 'ability' => 'core/op' ) ),
		),
	)
);
smoke_assert( true, $valid['valid'], 'validate ability returns valid=true on good spec', $failures, $passes );
smoke_assert( array(), $valid['errors'], 'validate ability returns no errors on good spec', $failures, $passes );

$invalid = agents_validate_workflow( array( 'spec' => array( 'steps' => array() ) ) );
smoke_assert( false, $invalid['valid'], 'validate ability returns valid=false on bad spec', $failures, $passes );
smoke_assert( true, count( $invalid['errors'] ) >= 1, 'validate ability returns at least one error', $failures, $passes );

// ─── describe-workflow ───────────────────────────────────────────────

\AgentsAPI\AI\Workflows\WP_Agent_Workflow_Registry::reset();
\AgentsAPI\AI\Workflows\WP_Agent_Workflow_Registry::register(
	array(
		'id'     => 'demo/describe',
		'inputs' => array( 'q' => array( 'type' => 'string', 'required' => true ) ),
		'steps'  => array( array( 'id' => 'a', 'type' => 'ability', 'ability' => 'core/x' ) ),
	)
);

$desc_hit = agents_describe_workflow( array( 'workflow_id' => 'demo/describe' ) );
smoke_assert( 'demo/describe', $desc_hit['spec']['id'], 'describe returns the registered spec', $failures, $passes );
smoke_assert( true, isset( $desc_hit['inputs']['q'] ), 'describe surfaces input declarations', $failures, $passes );

$desc_miss = agents_describe_workflow( array( 'workflow_id' => 'nope' ) );
smoke_assert( null, $desc_miss['spec'], 'describe returns null for unknown workflow', $failures, $passes );

// ─── run-workflow dispatcher: no handler ─────────────────────────────

$result = agents_run_workflow_dispatch( array( 'workflow_id' => 'whatever' ) );
smoke_assert( true, $result instanceof WP_Error, 'no handler => WP_Error', $failures, $passes );
smoke_assert(
	'agents_run_workflow_no_handler',
	$result instanceof WP_Error ? $result->get_error_code() : '',
	'no handler error code',
	$failures,
	$passes
);

// ─── run-workflow dispatcher: register a handler and invoke ──────────

register_workflow_handler(
	static function ( array $input ): array {
		return array(
			'run_id'      => 'fake-run-1',
			'workflow_id' => (string) ( $input['workflow_id'] ?? '' ),
			'status'      => 'succeeded',
			'output'      => array( 'echo' => $input['inputs'] ?? array() ),
			'steps'       => array(),
			'started_at'  => time(),
			'ended_at'    => time(),
		);
	}
);

$ok = agents_run_workflow_dispatch( array( 'workflow_id' => 'demo/x', 'inputs' => array( 'a' => 1 ) ) );
smoke_assert( false, $ok instanceof WP_Error, 'registered handler runs', $failures, $passes );
smoke_assert( 'fake-run-1', $ok['run_id'] ?? '', 'handler output is returned to caller', $failures, $passes );
smoke_assert( array( 'a' => 1 ), $ok['output']['echo'] ?? array(), 'handler sees forwarded inputs', $failures, $passes );

// ─── handler returns invalid type ────────────────────────────────────

smoke_reset_workflow_filters();
add_filter( 'wp_agent_workflow_handler', static fn() => static fn() => 'not an array' );

$bad = agents_run_workflow_dispatch( array( 'workflow_id' => 'demo/x' ) );
smoke_assert( true, $bad instanceof WP_Error, 'invalid handler return => WP_Error', $failures, $passes );
smoke_assert(
	'agents_run_workflow_invalid_result',
	$bad instanceof WP_Error ? $bad->get_error_code() : '',
	'invalid result code',
	$failures,
	$passes
);

// ─── observability: dispatch_failed fires ────────────────────────────

$failed_reasons = array();
smoke_reset_workflow_filters();
add_filter(
	'agents_run_workflow_dispatch_failed',
	static function ( $reason ) use ( &$failed_reasons ) {
		$failed_reasons[] = $reason;
	}
);
agents_run_workflow_dispatch( array( 'workflow_id' => 'whatever' ) );
smoke_assert( true, in_array( 'no_handler', $failed_reasons, true ), 'dispatch_failed fires with no_handler', $failures, $passes );

echo "Passed: {$passes}, Failed: " . count( $failures ) . "\n";
exit( count( $failures ) > 0 ? 1 : 0 );

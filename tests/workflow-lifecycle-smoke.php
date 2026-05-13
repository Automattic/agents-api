<?php
/**
 * Pure-PHP smoke test for WP_Agent_Workflow_Lifecycle and the durable-store
 * bridge sync subscriber.
 *
 * Verifies:
 *   - Each lifecycle method fires its canonical action hook.
 *   - The bridge-sync subscriber dispatches saved → sync, deleted → unregister,
 *     disabled → unregister, enabled → sync on the Action Scheduler bridge.
 *   - sync() unschedules existing actions before re-registering, so an update
 *     that drops a cron trigger leaves nothing scheduled.
 *
 * Run with: php tests/workflow-lifecycle-smoke.php
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "workflow-lifecycle-smoke\n";

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

$GLOBALS['__hooks'] = array();

function add_action( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['__hooks'][ $hook ][ $priority ][] = array( 'cb' => $cb, 'args' => $accepted_args );
}

function do_action( string $hook, ...$args ): void {
	$buckets = $GLOBALS['__hooks'][ $hook ] ?? array();
	ksort( $buckets );
	foreach ( $buckets as $bucket ) {
		foreach ( $bucket as $entry ) {
			call_user_func_array( $entry['cb'], array_slice( $args, 0, (int) $entry['args'] ) );
		}
	}
}

function add_filter( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
	add_action( $hook, $cb, $priority, $accepted_args );
}

function apply_filters( string $hook, $value, ...$args ) {
	$buckets = $GLOBALS['__hooks'][ $hook ] ?? array();
	ksort( $buckets );
	foreach ( $buckets as $bucket ) {
		foreach ( $bucket as $entry ) {
			$value = call_user_func_array( $entry['cb'], array_slice( array_merge( array( $value ), $args ), 0, (int) $entry['args'] ) );
		}
	}
	return $value;
}

function smoke_assert( $expected, $actual, string $name, array &$failures, int &$passes ): void {
	if ( $expected === $actual ) {
		echo "  PASS {$name}\n";
		++$passes;
		return;
	}
	$failures[] = $name;
	echo "  FAIL {$name}\n";
	echo "    expected: " . var_export( $expected, true ) . "\n";
	echo "    actual:   " . var_export( $actual, true ) . "\n";
}

// Stub out Action Scheduler so the bridge thinks it's available and records
// every call we care about.
$GLOBALS['__as_calls'] = array();
function as_schedule_recurring_action( int $start, int $interval, string $hook, array $args = array(), string $group = '' ): int {
	$GLOBALS['__as_calls'][] = array( 'op' => 'schedule_recurring', 'interval' => $interval, 'hook' => $hook, 'args' => $args, 'group' => $group );
	return 1;
}
function as_schedule_cron_action( int $start, string $schedule, string $hook, array $args = array(), string $group = '' ): int {
	$GLOBALS['__as_calls'][] = array( 'op' => 'schedule_cron', 'expression' => $schedule, 'hook' => $hook, 'args' => $args, 'group' => $group );
	return 1;
}
function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): int {
	$GLOBALS['__as_calls'][] = array( 'op' => 'unschedule', 'hook' => $hook, 'args' => $args, 'group' => $group );
	return 0;
}

require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-spec-validator.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-spec.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-action-scheduler-bridge.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-lifecycle.php';
require_once __DIR__ . '/../src/Workflows/register-workflow-bridge-sync.php';

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Action_Scheduler_Bridge;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Lifecycle;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec;

function make_spec( string $id, array $triggers = array() ): WP_Agent_Workflow_Spec {
	$raw = array(
		'id'       => $id,
		'version'  => '1.0.0',
		'steps'    => array(
			array( 'id' => 'noop', 'type' => 'ability', 'ability' => 'demo/noop' ),
		),
		'triggers' => $triggers,
	);
	return WP_Agent_Workflow_Spec::from_array( $raw );
}

// ─── 1. Lifecycle methods fire canonical hooks ──────────────────────────

$captured = array();
add_action( 'wp_agent_workflow_saved', static function ( WP_Agent_Workflow_Spec $spec ) use ( &$captured ) {
	$captured['saved'] = $spec->get_id();
} );
add_action( 'wp_agent_workflow_deleted', static function ( string $id, $spec ) use ( &$captured ) {
	$captured['deleted'] = array( 'id' => $id, 'spec_id' => $spec ? $spec->get_id() : null );
}, 10, 2 );
add_action( 'wp_agent_workflow_disabled', static function ( WP_Agent_Workflow_Spec $spec ) use ( &$captured ) {
	$captured['disabled'] = $spec->get_id();
} );
add_action( 'wp_agent_workflow_enabled', static function ( WP_Agent_Workflow_Spec $spec ) use ( &$captured ) {
	$captured['enabled'] = $spec->get_id();
} );

$spec = make_spec( 'demo/lifecycle' );

WP_Agent_Workflow_Lifecycle::saved( $spec );
smoke_assert( 'demo/lifecycle', $captured['saved'] ?? null, 'saved hook fires with spec', $failures, $passes );

WP_Agent_Workflow_Lifecycle::deleted( 'demo/lifecycle', $spec );
smoke_assert( 'demo/lifecycle', $captured['deleted']['id'] ?? null, 'deleted hook fires with id', $failures, $passes );
smoke_assert( 'demo/lifecycle', $captured['deleted']['spec_id'] ?? null, 'deleted hook forwards spec', $failures, $passes );

WP_Agent_Workflow_Lifecycle::deleted( 'demo/lifecycle-no-spec' );
smoke_assert( 'demo/lifecycle-no-spec', $captured['deleted']['id'] ?? null, 'deleted hook works when spec is null', $failures, $passes );
smoke_assert( true, array_key_exists( 'spec_id', $captured['deleted'] ) && null === $captured['deleted']['spec_id'], 'deleted hook passes null spec through', $failures, $passes );

WP_Agent_Workflow_Lifecycle::disabled( $spec );
smoke_assert( 'demo/lifecycle', $captured['disabled'] ?? null, 'disabled hook fires with spec', $failures, $passes );

WP_Agent_Workflow_Lifecycle::enabled( $spec );
smoke_assert( 'demo/lifecycle', $captured['enabled'] ?? null, 'enabled hook fires with spec', $failures, $passes );

// ─── 2. Bridge sync subscriber dispatches to the AS bridge ──────────────

$cron_spec = make_spec( 'demo/cron', array( array( 'type' => 'cron', 'interval' => 300 ) ) );

$GLOBALS['__as_calls'] = array();
WP_Agent_Workflow_Lifecycle::saved( $cron_spec );

$ops = array_column( $GLOBALS['__as_calls'], 'op' );
smoke_assert( array( 'unschedule', 'unschedule', 'schedule_recurring' ), $ops, 'saved → sync unschedules then registers', $failures, $passes );
smoke_assert( 300, $GLOBALS['__as_calls'][2]['interval'] ?? null, 'sync registers the new interval', $failures, $passes );

$GLOBALS['__as_calls'] = array();
WP_Agent_Workflow_Lifecycle::deleted( 'demo/cron', $cron_spec );
$ops = array_column( $GLOBALS['__as_calls'], 'op' );
smoke_assert( array( 'unschedule' ), $ops, 'deleted → unregister tears down schedule', $failures, $passes );
smoke_assert( array( 'workflow_id' => 'demo/cron' ), $GLOBALS['__as_calls'][0]['args'], 'deleted unschedule keys on workflow_id', $failures, $passes );

$GLOBALS['__as_calls'] = array();
WP_Agent_Workflow_Lifecycle::disabled( $cron_spec );
smoke_assert( array( 'unschedule' ), array_column( $GLOBALS['__as_calls'], 'op' ), 'disabled → unregister tears down schedule', $failures, $passes );

$GLOBALS['__as_calls'] = array();
WP_Agent_Workflow_Lifecycle::enabled( $cron_spec );
$ops = array_column( $GLOBALS['__as_calls'], 'op' );
smoke_assert( array( 'unschedule', 'unschedule', 'schedule_recurring' ), $ops, 'enabled → sync re-registers', $failures, $passes );

// ─── 3. sync() unschedules even when new spec has no cron triggers ──────

$no_cron_spec = make_spec( 'demo/cron', array( array( 'type' => 'on_demand' ) ) );
$GLOBALS['__as_calls'] = array();
$count = WP_Agent_Workflow_Action_Scheduler_Bridge::sync( $no_cron_spec );
smoke_assert( 0, $count, 'sync returns zero registrations when no cron triggers', $failures, $passes );
smoke_assert( array( 'unschedule' ), array_column( $GLOBALS['__as_calls'], 'op' ), 'sync still unschedules stale actions', $failures, $passes );

// ─── 4. wp_agent_workflow_registered (in-memory) keeps register() semantics ─

$GLOBALS['__as_calls'] = array();
do_action( 'wp_agent_workflow_registered', $cron_spec );
$ops = array_column( $GLOBALS['__as_calls'], 'op' );
smoke_assert( array( 'unschedule', 'schedule_recurring' ), $ops, 'registered hook still routes through register()', $failures, $passes );

// ─── Summary ────────────────────────────────────────────────────────────

if ( ! empty( $failures ) ) {
	echo "\nFAILED " . count( $failures ) . " of " . ( count( $failures ) + $passes ) . "\n";
	exit( 1 );
}

echo "OK {$passes} passed\n";

<?php
/**
 * Pure-PHP smoke for the Routines substrate.
 *
 * Run with: php tests/routine-smoke.php
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "routine-smoke\n";

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

function smoke_assert_throws( callable $fn, string $message_substring, string $name, array &$failures, int &$passes ): void {
	try {
		$fn();
	} catch ( \Throwable $e ) {
		if ( false === strpos( $e->getMessage(), $message_substring ) ) {
			$failures[] = $name;
			echo "  FAIL {$name}\n";
			echo '    expected substring: ' . $message_substring . "\n";
			echo '    actual message:     ' . $e->getMessage() . "\n";
			return;
		}
		++$passes;
		echo "  PASS {$name}\n";
		return;
	}
	$failures[] = $name;
	echo "  FAIL {$name} (no exception thrown)\n";
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $str ) {
		$str = strtolower( (string) $str );
		$str = preg_replace( '/[^a-z0-9_-]+/', '-', $str ) ?? '';
		return trim( $str, '-' );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		// no-op for substrate-only smoke; the registry doesn't depend on
		// listener side effects.
	}
}

require_once __DIR__ . '/../src/Routines/class-wp-agent-routine.php';
require_once __DIR__ . '/../src/Routines/class-wp-agent-routine-registry.php';

use AgentsAPI\AI\Routines\WP_Agent_Routine;
use AgentsAPI\AI\Routines\WP_Agent_Routine_Registry;

WP_Agent_Routine_Registry::reset();

// 1. Construction with `interval` trigger.
$routine = new WP_Agent_Routine(
	'lunar-monitor',
	array(
		'label'    => 'Lunar Monitor',
		'agent'    => 'commander',
		'interval' => 3600,
		'prompt'   => 'Status check.',
	)
);
smoke_assert( 'lunar-monitor', $routine->get_id(), 'interval routine: id', $failures, $passes );
smoke_assert( 'commander', $routine->get_agent_slug(), 'interval routine: agent', $failures, $passes );
smoke_assert( WP_Agent_Routine::TRIGGER_INTERVAL, $routine->get_trigger_type(), 'interval routine: trigger type', $failures, $passes );
smoke_assert( 3600, $routine->get_interval_seconds(), 'interval routine: seconds', $failures, $passes );
smoke_assert( '', $routine->get_expression(), 'interval routine: expression empty', $failures, $passes );
smoke_assert( 'routine:lunar-monitor', $routine->get_session_id(), 'session_id defaults to routine: prefix + id', $failures, $passes );

// 2. Construction with `expression` trigger.
$cron_routine = new WP_Agent_Routine(
	'nightly-digest',
	array(
		'agent'      => 'commander',
		'expression' => '0 9 * * *',
		'session_id' => 'custom-session',
	)
);
smoke_assert( WP_Agent_Routine::TRIGGER_EXPRESSION, $cron_routine->get_trigger_type(), 'cron routine: trigger type', $failures, $passes );
smoke_assert( '0 9 * * *', $cron_routine->get_expression(), 'cron routine: expression', $failures, $passes );
smoke_assert( 0, $cron_routine->get_interval_seconds(), 'cron routine: interval zero', $failures, $passes );
smoke_assert( 'custom-session', $cron_routine->get_session_id(), 'cron routine: explicit session_id honoured', $failures, $passes );

// 3. Validation failures.
smoke_assert_throws(
	static fn() => new WP_Agent_Routine( '', array( 'agent' => 'a', 'interval' => 60 ) ),
	'id cannot be empty',
	'rejects empty id',
	$failures,
	$passes
);
smoke_assert_throws(
	static fn() => new WP_Agent_Routine( 'r', array( 'interval' => 60 ) ),
	'must specify an agent slug',
	'rejects missing agent',
	$failures,
	$passes
);
smoke_assert_throws(
	static fn() => new WP_Agent_Routine( 'r', array( 'agent' => 'a' ) ),
	'must specify a trigger',
	'rejects missing trigger',
	$failures,
	$passes
);
smoke_assert_throws(
	static fn() => new WP_Agent_Routine(
		'r',
		array( 'agent' => 'a', 'interval' => 60, 'expression' => '* * * * *' )
	),
	'not both',
	'rejects double trigger',
	$failures,
	$passes
);

// 4. Registry round-trip.
$registered = WP_Agent_Routine_Registry::register(
	'lunar-monitor',
	array( 'agent' => 'commander', 'interval' => 60 )
);
smoke_assert( 'lunar-monitor', $registered->get_id(), 'registry returns the registered routine', $failures, $passes );
smoke_assert( 1, count( WP_Agent_Routine_Registry::all() ), 'registry has one routine', $failures, $passes );

$found = WP_Agent_Routine_Registry::find( 'lunar-monitor' );
smoke_assert( true, $found instanceof WP_Agent_Routine, 'find returns the routine instance', $failures, $passes );
smoke_assert( null, WP_Agent_Routine_Registry::find( 'unknown' ), 'find returns null for unknown id', $failures, $passes );

$dropped = WP_Agent_Routine_Registry::unregister( 'lunar-monitor' );
smoke_assert( true, $dropped, 'unregister returns true on existing id', $failures, $passes );
smoke_assert( 0, count( WP_Agent_Routine_Registry::all() ), 'registry empty after unregister', $failures, $passes );

$missing = WP_Agent_Routine_Registry::unregister( 'never-registered' );
smoke_assert( true, is_object( $missing ) && method_exists( $missing, 'get_error_code' ) && 'not_registered' === $missing->get_error_code(), 'unregister returns WP_Error for unknown id', $failures, $passes );

// 5. Registry rejects invalid args via WP_Error.
$bad = WP_Agent_Routine_Registry::register( 'x', array( 'interval' => 1 ) );
smoke_assert( true, is_object( $bad ) && method_exists( $bad, 'get_error_code' ) && 'invalid_routine' === $bad->get_error_code(), 'registry returns WP_Error on validation failure', $failures, $passes );

if ( count( $failures ) > 0 ) {
	echo 'FAIL ' . count( $failures ) . " failures\n";
	exit( 1 );
}
echo "OK {$passes} passed\n";

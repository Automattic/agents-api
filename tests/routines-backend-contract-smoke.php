<?php
/**
 * Pure-PHP smoke for the routine backend contract.
 *
 * Runs with NO Action Scheduler functions defined: the default backend is
 * null, and a fake in-memory backend is installed through the
 * `wp_agent_routine_backend` filter. Proves that registry register,
 * pause, resume, run-now, and reconcile all route through the
 * {@see AgentsAPI\AI\Routines\WP_Agent_Routine_Backend} contract, and that
 * a filter returning garbage falls back to the (null) default.
 *
 * Run with: php tests/routines-backend-contract-smoke.php
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "routines-backend-contract-smoke\n";

function contract_assert( $expected, $actual, string $name ): void {
	global $failures, $passes;
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

// ---------------------------------------------------------------------------
// WordPress shims (no as_* functions — that is the point of this smoke).
// ---------------------------------------------------------------------------

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $str ) {
		$str = strtolower( (string) $str );
		$str = preg_replace( '/[^a-z0-9_-]+/', '-', $str ) ?? '';
		return trim( $str, '-' );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) {
		return $s;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
		public function get_error_data() {
			return $this->data;
		}
	}
}

// Mini hook system (mirrors tests/routines-durability-smoke.php).
$GLOBALS['smoke_hooks'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		unset( $accepted_args );
		$GLOBALS['smoke_hooks'][ $hook ][ $priority ][] = $callback;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		add_action( $hook, $callback, $priority, $accepted_args );
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		$callbacks = $GLOBALS['smoke_hooks'][ $hook ] ?? array();
		ksort( $callbacks );
		foreach ( $callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $callback ) {
				call_user_func_array( $callback, $args );
			}
		}
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		$callbacks = $GLOBALS['smoke_hooks'][ $hook ] ?? array();
		ksort( $callbacks );
		foreach ( $callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $callback ) {
				$value = call_user_func_array( $callback, array_merge( array( $value ), $args ) );
			}
		}
		return $value;
	}
}

// ---------------------------------------------------------------------------
// Module under test. No as_* functions exist, so the default backend is null.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../src/Routines/class-wp-agent-routine.php';
require_once __DIR__ . '/../src/Routines/interface-wp-agent-routine-backend.php';
require_once __DIR__ . '/../src/Routines/class-wp-agent-routine-registry.php';
require_once __DIR__ . '/../src/Routines/class-wp-agent-routine-action-scheduler-bridge.php';
require_once __DIR__ . '/../src/Routines/register-routine-bridge-sync.php';

use AgentsAPI\AI\Routines\WP_Agent_Routine;
use AgentsAPI\AI\Routines\WP_Agent_Routine_Backend;
use AgentsAPI\AI\Routines\WP_Agent_Routine_Registry;

/**
 * Fake in-memory backend recording every contract call.
 */
class Contract_Fake_Backend implements WP_Agent_Routine_Backend {

	/** @var list<string> */
	public array $calls = array();

	/** @var list<WP_Agent_Routine> */
	public array $registered_routines = array();

	/** @var array<string,list<int>> */
	public array $pending = array();

	/** @var list<string> */
	public array $paused_ids = array();

	private int $next_handle = 100;

	public function is_available(): bool {
		$this->calls[] = 'is_available';
		return true;
	}

	public function register( WP_Agent_Routine $routine ): bool {
		$this->calls[]                         = 'register:' . $routine->get_id();
		$this->registered_routines[]           = $routine;
		$this->pending[ $routine->get_id() ][] = ++$this->next_handle;
		return true;
	}

	public function unregister( string $routine_id ): void {
		$this->calls[] = 'unregister:' . $routine_id;
		unset( $this->pending[ $routine_id ] );
	}

	public function pause( string $routine_id ): void {
		$this->calls[]      = 'pause:' . $routine_id;
		$this->paused_ids[] = $routine_id;
	}

	public function resume( WP_Agent_Routine $routine ): bool {
		$this->calls[]    = 'resume:' . $routine->get_id();
		$this->paused_ids = array_values( array_diff( $this->paused_ids, array( $routine->get_id() ) ) );
		return $this->register( $routine );
	}

	public function run_now( WP_Agent_Routine $routine ): bool {
		$this->calls[] = 'run_now:' . $routine->get_id();
		return true;
	}

	public function is_paused( string $routine_id ): bool {
		return in_array( $routine_id, $this->paused_ids, true );
	}

	public function current_generation( string $routine_id ): ?string {
		unset( $routine_id );
		return null;
	}

	public function pending_by_routine(): array {
		$this->calls[] = 'pending_by_routine';
		return $this->pending;
	}

	public function cancel( int $handle ): bool {
		$this->calls[] = 'cancel:' . $handle;
		foreach ( $this->pending as $routine_id => $handles ) {
			$index = array_search( $handle, $handles, true );
			if ( false !== $index ) {
				unset( $this->pending[ $routine_id ][ $index ] );
				$this->pending[ $routine_id ] = array_values( $this->pending[ $routine_id ] );
				if ( array() === $this->pending[ $routine_id ] ) {
					unset( $this->pending[ $routine_id ] );
				}
				return true;
			}
		}
		return false;
	}

	/**
	 * @return list<string>
	 */
	public function calls_of( string $prefix ): array {
		return array_values(
			array_filter(
				$this->calls,
				static fn( string $call ): bool => str_starts_with( $call, $prefix )
			)
		);
	}
}

// The filter reads a global so a single registration can swap backends.
$GLOBALS['contract_backend'] = null;
add_filter(
	'wp_agent_routine_backend',
	static fn() => $GLOBALS['contract_backend']
);

function contract_reset( ?Contract_Fake_Backend $backend ): void {
	WP_Agent_Routine_Registry::reset();
	WP_Agent_Routine_Registry::reset_backend();
	$GLOBALS['contract_backend'] = $backend;
}

// Sanity: with no filter return at all, the default backend is null (no
// as_* functions are defined in this process).
contract_reset( null );
contract_assert( null, WP_Agent_Routine_Registry::backend(), 'default: no as_* functions means the default backend is null' );

// ---------------------------------------------------------------------------
// 1. A fake backend installed via the filter is what the registry resolves.
// ---------------------------------------------------------------------------

contract_reset( new Contract_Fake_Backend() );
$fake = $GLOBALS['contract_backend'];
contract_assert( true, WP_Agent_Routine_Registry::backend() instanceof WP_Agent_Routine_Backend, 'filter: the resolved backend is the fake' );
contract_assert( true, WP_Agent_Routine_Registry::backend() === $fake, 'filter: the backend resolves to the exact fake instance' );

// ---------------------------------------------------------------------------
// 2. register() routes through the backend with the routine.
// ---------------------------------------------------------------------------

$registered = WP_Agent_Routine_Registry::register( 'alpha', array( 'agent' => 'commander', 'interval' => 600 ) );
contract_assert( true, $registered instanceof WP_Agent_Routine, 'register: the routine registers cleanly' );
contract_assert( array( 'register:alpha' ), $fake->calls_of( 'register:' ), 'register: the backend register verb is called' );
contract_assert( true, isset( $fake->registered_routines[0] ) && $fake->registered_routines[0] === $registered, 'register: the backend receives the exact routine instance' );
contract_assert( true, ! empty( $fake->pending['alpha'] ), 'register: the backend records a pending handle for the routine' );

// ---------------------------------------------------------------------------
// 3. pause / resume / run_now route through the backend.
// ---------------------------------------------------------------------------

contract_assert( true, WP_Agent_Routine_Registry::pause( 'alpha' ), 'pause: the registry verb returns true' );
contract_assert( array( 'pause:alpha' ), $fake->calls_of( 'pause:' ), 'pause: the backend pause verb is called' );

contract_assert( true, WP_Agent_Routine_Registry::resume( 'alpha' ), 'resume: the registry verb returns true' );
contract_assert( array( 'resume:alpha' ), $fake->calls_of( 'resume:' ), 'resume: the backend resume verb is called' );
contract_assert( array(), $fake->paused_ids, 'resume: the fake clears the paused marker' );

contract_assert( true, WP_Agent_Routine_Registry::run_now( 'alpha' ), 'run_now: the registry verb returns true' );
contract_assert( array( 'run_now:alpha' ), $fake->calls_of( 'run_now:' ), 'run_now: the backend run_now verb is called' );

// Unregister tears down through the backend too.
contract_assert( true, WP_Agent_Routine_Registry::unregister( 'alpha' ), 'unregister: the registry verb returns true' );
contract_assert( array( 'unregister:alpha' ), $fake->calls_of( 'unregister:' ), 'unregister: the backend unregister verb is called' );

// ---------------------------------------------------------------------------
// 4. reconcile() works entirely off the contract reads/writes.
// ---------------------------------------------------------------------------

// Re-register alpha (covered), register beta, then simulate drift: beta's
// schedule disappeared from the backend and a ghost handle is pending.
WP_Agent_Routine_Registry::register( 'alpha', array( 'agent' => 'commander', 'interval' => 600 ) );
WP_Agent_Routine_Registry::register( 'beta', array( 'agent' => 'commander', 'interval' => 900 ) );
unset( $fake->pending['beta'] );
$fake->pending['ghost'] = array( 999 );

$fake->calls = array();
$result      = WP_Agent_Routine_Registry::reconcile();

contract_assert( true, in_array( 'pending_by_routine', $fake->calls, true ), 'reconcile: the backend bulk read is used' );
contract_assert( array( 'register:beta' ), $fake->calls_of( 'register:' ), 'reconcile: the missing routine is enqueued through the backend' );
contract_assert( array( 'cancel:999' ), $fake->calls_of( 'cancel:' ), 'reconcile: the orphaned handle is cancelled through the backend' );
contract_assert( array( 'beta' ), $result['enqueued'], 'reconcile: enqueued reports the missing routine' );
contract_assert( array( 'ghost' ), $result['removed'], 'reconcile: removed reports the orphan' );
contract_assert( array( 'alpha' ), $result['unchanged'], 'reconcile: unchanged reports the covered routine' );
contract_assert( array(), $result['errors'], 'reconcile: no errors on a healthy reconcile' );

// Dry run reports the same shape without writing through the backend.
unset( $fake->pending['beta'] );
$fake->calls = array();
$result      = WP_Agent_Routine_Registry::reconcile( array( 'dry_run' => true ) );
contract_assert( array(), $fake->calls_of( 'register:' ), 'reconcile: dry run writes nothing through the backend' );
contract_assert( array(), $fake->calls_of( 'cancel:' ), 'reconcile: dry run cancels nothing through the backend' );
contract_assert( array( 'beta' ), $result['enqueued'], 'reconcile: dry run reports the missing schedule' );

// A paused routine is intentionally uncovered: reconcile must not re-enqueue.
WP_Agent_Routine_Registry::register( 'delta-ops', array( 'agent' => 'commander', 'interval' => 300 ) );
unset( $fake->pending['delta-ops'] );
WP_Agent_Routine_Registry::pause( 'delta-ops' );
$result = WP_Agent_Routine_Registry::reconcile();
contract_assert( false, in_array( 'delta-ops', $result['enqueued'], true ), 'reconcile: a paused routine is not re-enqueued' );
contract_assert( array( 'beta' ), $result['enqueued'], 'reconcile: the still-missing active routine is enqueued' );

// ---------------------------------------------------------------------------
// 5. A garbage filter return falls back to the default (null here).
// ---------------------------------------------------------------------------

$GLOBALS['contract_backend'] = 'not-a-backend';
WP_Agent_Routine_Registry::reset_backend();
contract_assert( null, WP_Agent_Routine_Registry::backend(), 'fallback: a garbage filter return falls back to the default' );

$result = WP_Agent_Routine_Registry::reconcile();
contract_assert( true, isset( $result['errors']['_scheduler'] ), 'fallback: reconcile reports the _scheduler error with no backend' );
contract_assert( array(), $result['enqueued'], 'fallback: reconcile enqueues nothing with no backend' );

// Lifecycle verbs still succeed (the hooks fire; the sync listeners no-op).
contract_assert( true, WP_Agent_Routine_Registry::pause( 'alpha' ), 'fallback: pause still returns true with no backend' );
contract_assert( true, WP_Agent_Routine_Registry::resume( 'alpha' ), 'fallback: resume still returns true with no backend' );
contract_assert( true, WP_Agent_Routine_Registry::run_now( 'alpha' ), 'fallback: run_now still returns true with no backend' );

// The backend resolves lazily again once a real one is back.
contract_reset( new Contract_Fake_Backend() );
contract_assert( true, WP_Agent_Routine_Registry::backend() instanceof WP_Agent_Routine_Backend, 'fallback: reset_backend() re-resolves the filter' );

// ---------------------------------------------------------------------------

if ( count( $failures ) > 0 ) {
	echo 'FAIL ' . count( $failures ) . " failures\n";
	exit( 1 );
}
echo "OK {$passes} passed\n";

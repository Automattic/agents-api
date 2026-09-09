<?php
/**
 * Pure-PHP smoke for routine wake targets: ability-targeted routines run
 * alongside the default chat target.
 *
 * Runs against a fake in-memory Action Scheduler and a fake Abilities API
 * (function shims + minimal store/ability classes) so constructor
 * validation, listener branching, and the ability permission gate are
 * exercised end to end.
 *
 * Run with: php tests/routine-ability-target-smoke.php
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "routine-ability-target-smoke\n";

function target_assert( $expected, $actual, string $name ) {
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
// WordPress shims.
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
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
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
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		static $seq = 0;
		++$seq;
		return sprintf( 'gen-%08d-%s', $seq, bin2hex( random_bytes( 4 ) ) );
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
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $hook, callable $callback, int $priority = 10 ): void {
		foreach ( $GLOBALS['smoke_hooks'][ $hook ] ?? array() as $prio => $callbacks ) {
			foreach ( $callbacks as $index => $existing ) {
				if ( $existing === $callback ) {
					unset( $GLOBALS['smoke_hooks'][ $hook ][ $prio ][ $index ] );
				}
			}
		}
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
		$GLOBALS['smoke_fired_hooks'][] = array( $hook, $args );
	}
}
if ( ! function_exists( 'do_action_ref_array' ) ) {
	function do_action_ref_array( string $hook, array $args ): void {
		do_action( $hook, ...$args );
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

// Option layer backed by a plain array.
$GLOBALS['smoke_options'] = array();
if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $name, $value = '', string $deprecated = '', $autoload = null ): bool {
		unset( $deprecated, $autoload );
		if ( array_key_exists( $name, $GLOBALS['smoke_options'] ) ) {
			return false;
		}
		$GLOBALS['smoke_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		return $GLOBALS['smoke_options'][ $name ] ?? $default;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $name ): bool {
		unset( $GLOBALS['smoke_options'][ $name ] );
		return true;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value, $autoload = null ): bool {
		unset( $autoload );
		$GLOBALS['smoke_options'][ $name ] = $value;
		return true;
	}
}

// Fake chat ability invoked by the listener's chat branch.
class Smoke_Target_Chat_Ability {
	/** @var array<int,array<string,mixed>> */
	public array $calls = array();

	/**
	 * @param array<string,mixed> $input Chat input.
	 * @return array<string,mixed>
	 */
	public function execute( array $input ): array {
		$this->calls[] = $input;
		return array( 'ok' => true );
	}
}

// Fake target ability: `wp_register_ability()` stores the definition and
// `wp_get_ability()` hands back a instance whose execute() runs the stored
// callback — the same contract the Abilities API exposes.
class Smoke_Target_Ability {
	public string $name;
	/** @var array<string,mixed> */
	private array $args;
	/** @var array<int,mixed> */
	public array $calls = array();

	/**
	 * @param string              $name Ability slug.
	 * @param array<string,mixed> $args Ability definition.
	 */
	public function __construct( string $name, array $args ) {
		$this->name = $name;
		$this->args = $args;
	}

	/**
	 * @param mixed $input Ability input.
	 * @return mixed
	 */
	public function execute( $input = null ) {
		$this->calls[] = $input;
		$callback      = $this->args['execute_callback'] ?? null;
		return is_callable( $callback ) ? $callback( $input ) : array( 'ok' => true );
	}
}

$GLOBALS['smoke_chat_ability'] = new Smoke_Target_Chat_Ability();
$GLOBALS['smoke_abilities']    = array();
if ( ! function_exists( 'wp_get_ability' ) ) {
	/**
	 * @return object|null
	 */
	function wp_get_ability( string $name ): ?object {
		if ( 'agents/chat' === $name ) {
			return $GLOBALS['smoke_chat_ability'];
		}
		return $GLOBALS['smoke_abilities'][ $name ] ?? null;
	}
}
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( string $name, array $args ): void {
		$GLOBALS['smoke_abilities'][ $name ] = new Smoke_Target_Ability( $name, $args );
	}
}

// ---------------------------------------------------------------------------
// Fake Action Scheduler.
// ---------------------------------------------------------------------------

$GLOBALS['smoke_as']         = array();
$GLOBALS['smoke_as_next_id'] = 0;

class Target_Smoke_Action {
	public function __construct( private string $hook = '', private array $args = array() ) {}

	public function execute(): void {
		do_action_ref_array( $this->hook, array_values( $this->args ) );
	}
}

/**
 * @param array<array-key,mixed> $args
 */
function target_smoke_as_save( string $hook, array $args, string $group, int $timestamp, int $interval = 0 ): int {
	$id                         = ++$GLOBALS['smoke_as_next_id'];
	$GLOBALS['smoke_as'][ $id ] = array(
		'hook'      => $hook,
		'args'      => $args,
		'group'     => $group,
		'timestamp' => $timestamp,
		'interval'  => $interval,
		'status'    => 'pending',
	);
	do_action( 'action_scheduler_stored_action', $id );
	return $id;
}

/**
 * Mirror ActionScheduler_Abstract_QueueRunner::process_action(): fire
 * before_execute, re-check pending, then execute.
 */
function target_smoke_as_run( int $action_id ): bool {
	do_action( 'action_scheduler_before_execute', $action_id, 'smoke' );
	if ( 'pending' !== ( $GLOBALS['smoke_as'][ $action_id ]['status'] ?? '' ) ) {
		do_action( 'action_scheduler_execution_ignored', $action_id, 'smoke' );
		return false;
	}
	$row = $GLOBALS['smoke_as'][ $action_id ];
	( new Target_Smoke_Action( $row['hook'], $row['args'] ) )->execute();
	return true;
}

/**
 * @param array<array-key,mixed> $args
 */
function as_schedule_recurring_action( int $timestamp, int $interval, string $hook, array $args = array(), string $group = '' ): int {
	unset( $group );
	return target_smoke_as_save( $hook, $args, 'agents-api', $timestamp, $interval );
}

/**
 * @param array<array-key,mixed> $args
 */
function as_schedule_cron_action( int $timestamp, string $schedule, string $hook, array $args = array(), string $group = '' ): int {
	unset( $group );
	return target_smoke_as_save( $hook, $args, 'agents-api', $timestamp, 0 );
}

/**
 * @param array<array-key,mixed> $args
 */
function as_enqueue_async_action( string $hook, array $args = array(), string $group = '' ): int {
	unset( $group );
	return target_smoke_as_save( $hook, $args, 'agents-api', time() );
}

/**
 * @param array<array-key,mixed> $args
 */
function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): void {
	unset( $group );
	foreach ( $GLOBALS['smoke_as'] as $id => $row ) {
		if ( $row['hook'] === $hook && $row['args'] === $args && 'pending' === $row['status'] ) {
			$GLOBALS['smoke_as'][ $id ]['status'] = 'canceled';
		}
	}
}

// ---------------------------------------------------------------------------
// Module under test.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../src/Routines/class-wp-agent-routine.php';
require_once __DIR__ . '/../src/Routines/interface-wp-agent-routine-backend.php';
require_once __DIR__ . '/../src/Routines/class-wp-agent-routine-registry.php';
require_once __DIR__ . '/../src/Routines/class-wp-agent-routine-action-scheduler-bridge.php';
require_once __DIR__ . '/../src/Routines/register-routine-bridge-sync.php';
require_once __DIR__ . '/../src/Routines/register-action-scheduler-listener.php';

use AgentsAPI\AI\Routines\WP_Agent_Routine;
use AgentsAPI\AI\Routines\WP_Agent_Routine_Registry;

function target_smoke_reset_state(): void {
	WP_Agent_Routine_Registry::reset();
	WP_Agent_Routine_Registry::reset_backend();
	$GLOBALS['smoke_as']           = array();
	$GLOBALS['smoke_as_next_id']   = 0;
	$GLOBALS['smoke_options']      = array();
	$GLOBALS['smoke_fired_hooks']  = array();
	$GLOBALS['smoke_chat_ability'] = new Smoke_Target_Chat_Ability();
	$GLOBALS['smoke_abilities']    = array();
	// Note: $GLOBALS['smoke_hooks'] is NOT reset — the bridge-sync and
	// listener modules register their hooks at load time. Tests remove their
	// own filters explicitly via remove_filter().
}

/** @return array<int,array<array-key,mixed>> */
function target_smoke_pending_rows(): array {
	return array_filter(
		$GLOBALS['smoke_as'],
		static fn( array $row ): bool => 'pending' === $row['status']
	);
}

/**
 * @return array{code:string,context:array<string,mixed>}|null
 */
function target_smoke_last_failure(): ?array {
	foreach ( array_reverse( $GLOBALS['smoke_fired_hooks'] ) as $entry ) {
		if ( 'agents_run_routine_dispatch_failed' === $entry[0] ) {
			return array(
				'code'    => $entry[1][0],
				'context' => $entry[1][1],
			);
		}
	}
	return null;
}

/**
 * @return array{routine:WP_Agent_Routine,result:mixed}|null
 */
function target_smoke_last_completion(): ?array {
	foreach ( array_reverse( $GLOBALS['smoke_fired_hooks'] ) as $entry ) {
		if ( 'wp_agent_routine_run_completed' === $entry[0] ) {
			return array(
				'routine' => $entry[1][0],
				'result'  => $entry[1][1],
			);
		}
	}
	return null;
}

// ---------------------------------------------------------------------------
// 1. Value object: ability targets, chat targets, getters.
// ---------------------------------------------------------------------------

$ability_routine = new WP_Agent_Routine(
	'nightly-report',
	array(
		'ability'  => 'smoke/report',
		'input'    => array( 'range' => 'day' ),
		'interval' => 600,
	)
);
target_assert( WP_Agent_Routine::TARGET_ABILITY, $ability_routine->get_target_type(), 'ability routine: target type' );
target_assert( 'smoke/report', $ability_routine->get_ability(), 'ability routine: ability slug' );
target_assert( array( 'range' => 'day' ), $ability_routine->get_input(), 'ability routine: input' );
target_assert( '', $ability_routine->get_agent_slug(), 'ability routine: agent slug is empty' );

$chat_routine = new WP_Agent_Routine( 'chat-r', array( 'agent' => 'commander', 'interval' => 600 ) );
target_assert( WP_Agent_Routine::TARGET_CHAT, $chat_routine->get_target_type(), 'chat routine: target type' );
target_assert( '', $chat_routine->get_ability(), 'chat routine: ability slug is empty' );
target_assert( array(), $chat_routine->get_input(), 'chat routine: input is empty' );

$mixed_keys = new WP_Agent_Routine(
	'mixed-keys',
	array(
		'ability'  => 'smoke/report',
		'input'    => array( 7 => 'dropped', 'keep' => 'kept' ),
		'interval' => 600,
	)
);
target_assert( array( 'keep' => 'kept' ), $mixed_keys->get_input(), 'ability routine: non-string input keys are dropped' );

// ---------------------------------------------------------------------------
// 2. Validation: exactly one of agent / ability.
// ---------------------------------------------------------------------------

$threw = null;
try {
	new WP_Agent_Routine( 'both', array( 'agent' => 'commander', 'ability' => 'smoke/report', 'interval' => 60 ) );
} catch ( \InvalidArgumentException $e ) {
	$threw = $e->getMessage();
}
target_assert( true, null !== $threw && false !== strpos( $threw, 'not both' ), 'rejects both agent and ability targets' );

$threw = null;
try {
	new WP_Agent_Routine( 'neither', array( 'interval' => 60 ) );
} catch ( \InvalidArgumentException $e ) {
	$threw = $e->getMessage();
}
target_assert( true, null !== $threw && false !== strpos( $threw, 'must specify an agent slug' ), 'rejects neither agent nor ability' );

// ---------------------------------------------------------------------------
// 3. to_array() shapes for both target types.
// ---------------------------------------------------------------------------

target_assert(
	array(
		'id'         => 'chat-r',
		'label'      => 'chat-r',
		'target'     => 'chat',
		'agent'      => 'commander',
		'prompt'     => '',
		'session_id' => 'routine:chat-r',
		'meta'       => array(),
		'interval'   => 600,
		'stagger'    => 3600,
	),
	$chat_routine->to_array(),
	'chat routine: to_array shape'
);
target_assert(
	array(
		'id'         => 'nightly-report',
		'label'      => 'nightly-report',
		'target'     => 'ability',
		'agent'      => '',
		'prompt'     => '',
		'session_id' => 'routine:nightly-report',
		'meta'       => array(),
		'ability'    => 'smoke/report',
		'input'      => array( 'range' => 'day' ),
		'interval'   => 600,
		'stagger'    => 3600,
	),
	$ability_routine->to_array(),
	'ability routine: to_array shape'
);

// ---------------------------------------------------------------------------
// 4. Ability-target dispatch: executed with the configured input.
// ---------------------------------------------------------------------------

target_smoke_reset_state();
wp_register_ability(
	'smoke/target',
	array(
		'label'            => 'Target',
		'execute_callback' => static fn( $input ): array => array( 'echo' => $input['thing'] ?? null ),
	)
);

$captured     = array();
$allow_filter = static function ( $allowed, $routine, $ability ) use ( &$captured ) {
	$captured['routine'] = $routine;
	$captured['ability'] = $ability;
	return true;
};
add_filter( 'wp_agent_routine_ability_permission', $allow_filter, 10, 3 );

WP_Agent_Routine_Registry::register(
	'targeted',
	array(
		'ability'  => 'smoke/target',
		'input'    => array( 'thing' => 'abc' ),
		'interval' => 600,
	)
);
$rows   = array_values( target_smoke_pending_rows() );
$run_id = array_keys( target_smoke_pending_rows() )[0];
target_assert( array( 'routine_id' => 'targeted' ), $rows[0]['args'], 'ability target: scheduled args are purely logical' );
target_assert( true, target_smoke_as_run( $run_id ), 'ability target: the scheduled action executes' );
target_assert( array( array( 'thing' => 'abc' ) ), $GLOBALS['smoke_abilities']['smoke/target']->calls, 'ability target: the ability receives the configured input' );
target_assert( true, $captured['routine'] instanceof WP_Agent_Routine && 'targeted' === $captured['routine']->get_id(), 'permission filter receives the routine' );
target_assert( true, $captured['ability'] instanceof Smoke_Target_Ability && 'smoke/target' === $captured['ability']->name, 'permission filter receives the ability' );

$completed = target_smoke_last_completion();
target_assert( true, null !== $completed && $completed['routine'] instanceof WP_Agent_Routine && 'targeted' === $completed['routine']->get_id(), 'ability target: run_completed fires with the routine' );
target_assert( array( 'echo' => 'abc' ), null === $completed ? null : $completed['result'], 'ability target: run_completed fires with the ability result' );
target_assert( null, target_smoke_last_failure(), 'ability target: no dispatch failure on the happy path' );

// ---------------------------------------------------------------------------
// 5. Missing ability: dispatch_failed( 'ability_missing' ).
// ---------------------------------------------------------------------------

target_smoke_reset_state();
WP_Agent_Routine_Registry::register(
	'ghosted',
	array(
		'ability'  => 'smoke/ghost',
		'interval' => 600,
	)
);
target_assert( true, target_smoke_as_run( array_keys( target_smoke_pending_rows() )[0] ), 'missing ability: the scheduled action executes' );
$failure = target_smoke_last_failure();
target_assert( 'ability_missing', null === $failure ? null : $failure['code'], 'missing ability: dispatch_failed reports ability_missing' );
target_assert( 'smoke/ghost', null === $failure ? null : ( $failure['context']['ability'] ?? null ), 'missing ability: the failure context names the ability' );
target_assert( null, target_smoke_last_completion(), 'missing ability: run_completed does not fire' );

// ---------------------------------------------------------------------------
// 6. Permission filter absent: deny by default, ability not executed.
// ---------------------------------------------------------------------------

target_smoke_reset_state();
remove_filter( 'wp_agent_routine_ability_permission', $allow_filter );
wp_register_ability( 'smoke/target', array( 'label' => 'Target' ) );
WP_Agent_Routine_Registry::register(
	'denied',
	array(
		'ability'  => 'smoke/target',
		'input'    => array( 'thing' => 'abc' ),
		'interval' => 600,
	)
);
target_assert( true, target_smoke_as_run( array_keys( target_smoke_pending_rows() )[0] ), 'permission denied: the scheduled action executes' );
$failure = target_smoke_last_failure();
target_assert( 'permission_denied', null === $failure ? null : $failure['code'], 'permission denied: dispatch_failed reports permission_denied' );
target_assert( array(), $GLOBALS['smoke_abilities']['smoke/target']->calls, 'permission denied: the ability is not executed' );
target_assert( null, target_smoke_last_completion(), 'permission denied: run_completed does not fire' );

// ---------------------------------------------------------------------------
// 7. Ability WP_Error: dispatch_failed with the ability's error code.
// ---------------------------------------------------------------------------

target_smoke_reset_state();
$allow_all = static fn( $allowed ): bool => true;
add_filter( 'wp_agent_routine_ability_permission', $allow_all, 10, 3 );
wp_register_ability(
	'smoke/failing',
	array(
		'label'            => 'Failing',
		'execute_callback' => static fn( $input ) => new WP_Error( 'boom', 'exploded', array( 'status' => 500 ) ),
	)
);
WP_Agent_Routine_Registry::register(
	'failing',
	array(
		'ability'  => 'smoke/failing',
		'interval' => 600,
	)
);
target_assert( true, target_smoke_as_run( array_keys( target_smoke_pending_rows() )[0] ), 'ability error: the scheduled action executes' );
$failure = target_smoke_last_failure();
target_assert( 'boom', null === $failure ? null : $failure['code'], 'ability error: dispatch_failed carries the ability error code' );
target_assert( true, $GLOBALS['smoke_abilities']['smoke/failing']->calls === array( array() ), 'ability error: the ability ran once with empty input' );
target_assert( null, target_smoke_last_completion(), 'ability error: run_completed does not fire' );

// ---------------------------------------------------------------------------
// 8. Chat path through the same listener is unchanged.
// ---------------------------------------------------------------------------

target_smoke_reset_state();
WP_Agent_Routine_Registry::register(
	'chatty',
	array(
		'agent'    => 'commander',
		'prompt'   => 'Status check.',
		'interval' => 600,
	)
);
target_assert( true, target_smoke_as_run( array_keys( target_smoke_pending_rows() )[0] ), 'chat target: the scheduled action executes' );
target_assert( array( array( 'agent' => 'commander', 'message' => 'Status check.', 'session_id' => 'routine:chatty' ) ), $GLOBALS['smoke_chat_ability']->calls, 'chat target: dispatched through agents/chat unchanged' );
$completed = target_smoke_last_completion();
target_assert( true, null !== $completed && $completed['routine'] instanceof WP_Agent_Routine && 'chatty' === $completed['routine']->get_id(), 'chat target: run_completed fires with the routine' );
target_assert( array( 'ok' => true ), null === $completed ? null : $completed['result'], 'chat target: run_completed fires with the chat result' );
target_assert( null, target_smoke_last_failure(), 'chat target: no dispatch failure' );

// ---------------------------------------------------------------------------

if ( count( $failures ) > 0 ) {
	echo 'FAIL ' . count( $failures ) . " failures\n";
	exit( 1 );
}
echo "OK {$passes} passed\n";

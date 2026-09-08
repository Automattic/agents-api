<?php
/**
 * Pure-PHP smoke for routine durability: generation fencing, stagger offsets,
 * and registry/scheduler reconcile.
 *
 * Runs against a fake in-memory Action Scheduler (function shims + minimal
 * store/action classes) so the fencing filter, the stored-successor
 * reconciler, and the registry reconcile walk are exercised end to end.
 *
 * Run with: php tests/routines-durability-smoke.php
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "routines-durability-smoke\n";

function durable_assert( $expected, $actual, string $name ) {
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

// Mini hook system (mirrors tests/agents-api-smoke-helpers.php).
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
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value, $autoload = null ): bool {
		unset( $autoload );
		$GLOBALS['smoke_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $name ): bool {
		unset( $GLOBALS['smoke_options'][ $name ] );
		return true;
	}
}

// Fake chat ability invoked by the scheduled-run listener.
class Smoke_Chat_Ability {
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
$GLOBALS['smoke_chat_ability'] = new Smoke_Chat_Ability();
if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( string $name ): ?Smoke_Chat_Ability {
		return 'agents/chat' === $name ? $GLOBALS['smoke_chat_ability'] : null;
	}
}

// ---------------------------------------------------------------------------
// Fake Action Scheduler.
// ---------------------------------------------------------------------------

$GLOBALS['smoke_as']         = array();
$GLOBALS['smoke_as_next_id'] = 0;

class ActionScheduler_Schedule {
	public function __construct( private ?int $timestamp = null, private int $interval = 0, private string $cron = '' ) {}

	public function is_recurring(): bool {
		return $this->interval > 0 || '' !== $this->cron;
	}

	public function get_date(): ?DateTime {
		return null === $this->timestamp ? null : new DateTime( '@' . $this->timestamp );
	}

	/** @return int|string */
	public function get_recurrence() {
		return '' !== $this->cron ? $this->cron : $this->interval;
	}
}

class ActionScheduler_CanceledSchedule extends ActionScheduler_Schedule {
	public function __construct( DateTime $date ) {
		parent::__construct( $date->getTimestamp(), 0, '' );
	}

	public function is_recurring(): bool {
		return false;
	}
}

class ActionScheduler_Action {
	public function __construct( private string $hook = '', private array $args = array(), private ?ActionScheduler_Schedule $schedule = null, private string $group = '' ) {
		$this->schedule = $schedule ?? new ActionScheduler_Schedule();
	}

	public function execute(): void {
		do_action_ref_array( $this->hook, array_values( $this->args ) );
	}

	public function get_hook(): string {
		return $this->hook;
	}

	/** @return array<array-key,mixed> */
	public function get_args(): array {
		return $this->args;
	}

	public function get_schedule(): ActionScheduler_Schedule {
		return $this->schedule;
	}

	public function get_group(): string {
		return $this->group;
	}

	/** @param int $priority Action priority. */
	public function set_priority( $priority ): void {
		unset( $priority );
	}
}

class ActionScheduler_Store {
	public const STATUS_PENDING  = 'pending';
	public const STATUS_RUNNING  = 'in-progress';
	public const STATUS_CANCELED = 'canceled';

	public static function instance(): self {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}
		return $instance;
	}

	/** @param int|string $action_id Action id. */
	public function fetch_action( $action_id ): ActionScheduler_Action {
		$row = $GLOBALS['smoke_as'][ (int) $action_id ] ?? null;
		if ( null === $row ) {
			throw new RuntimeException( 'unknown action' );
		}

		$schedule = new ActionScheduler_Schedule( $row['timestamp'], $row['interval'], $row['cron'] );
		$action   = new ActionScheduler_Action( $row['hook'], $row['args'], $schedule, $row['group'] );
		return apply_filters( 'action_scheduler_stored_action_instance', $action, $row['hook'], $row['args'], $schedule, $row['group'], 10 );
	}

	/** @param int|string $action_id Action id. */
	public function cancel_action( $action_id ): void {
		if ( isset( $GLOBALS['smoke_as'][ (int) $action_id ] ) ) {
			$GLOBALS['smoke_as'][ (int) $action_id ]['status'] = self::STATUS_CANCELED;
		}
	}
}

/**
 * @param array<array-key,mixed> $args
 */
function smoke_as_save( string $hook, array $args, string $group, int $timestamp, int $interval = 0, string $cron = '' ): int {
	$id                         = ++$GLOBALS['smoke_as_next_id'];
	$GLOBALS['smoke_as'][ $id ] = array(
		'hook'      => $hook,
		'args'      => $args,
		'group'     => $group,
		'timestamp' => $timestamp,
		'interval'  => $interval,
		'cron'      => $cron,
		'status'    => ActionScheduler_Store::STATUS_PENDING,
	);
	do_action( 'action_scheduler_stored_action', $id );
	return $id;
}

/** @param array<array-key,mixed> $args */
function as_schedule_recurring_action( int $timestamp, int $interval, string $hook, array $args = array(), string $group = '' ): int {
	return smoke_as_save( $hook, $args, $group, $timestamp, $interval );
}

/** @param array<array-key,mixed> $args */
function as_schedule_cron_action( int $timestamp, string $schedule, string $hook, array $args = array(), string $group = '' ): int {
	return smoke_as_save( $hook, $args, $group, $timestamp, 0, $schedule );
}

/** @param array<array-key,mixed> $args */
function as_enqueue_async_action( string $hook, array $args = array(), string $group = '' ): int {
	return smoke_as_save( $hook, $args, $group, time() );
}

/** @param array<array-key,mixed> $args */
function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): void {
	foreach ( $GLOBALS['smoke_as'] as $id => $row ) {
		if ( $row['hook'] === $hook && $row['group'] === $group && $row['args'] === $args && ActionScheduler_Store::STATUS_PENDING === $row['status'] ) {
			$GLOBALS['smoke_as'][ $id ]['status'] = ActionScheduler_Store::STATUS_CANCELED;
		}
	}
}

/**
 * @param array<string,mixed> $query
 * @return array<int,mixed>
 */
function as_get_scheduled_actions( array $query = array(), string $return_format = 'OBJECT' ): array {
	$ids = array();
	foreach ( $GLOBALS['smoke_as'] as $id => $row ) {
		if ( isset( $query['hook'] ) && $row['hook'] !== $query['hook'] ) {
			continue;
		}
		if ( isset( $query['group'] ) && $row['group'] !== $query['group'] ) {
			continue;
		}
		if ( isset( $query['status'] ) && $row['status'] !== $query['status'] ) {
			continue;
		}
		$ids[] = $id;
	}

	if ( 'ids' === $return_format ) {
		return $ids;
	}

	$actions = array();
	foreach ( $ids as $id ) {
		$actions[ $id ] = ActionScheduler_Store::instance()->fetch_action( $id );
	}
	return $actions;
}

// ---------------------------------------------------------------------------
// Module under test.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../src/Routines/class-wp-agent-routine.php';
require_once __DIR__ . '/../src/Routines/class-wp-agent-routine-action-identity.php';
require_once __DIR__ . '/../src/Routines/class-wp-agent-generation-fenced-action.php';
require_once __DIR__ . '/../src/Routines/class-wp-agent-routine-registry.php';
require_once __DIR__ . '/../src/Routines/class-wp-agent-routine-action-scheduler-bridge.php';
require_once __DIR__ . '/../src/Routines/register-routine-bridge-sync.php';
require_once __DIR__ . '/../src/Routines/register-action-scheduler-listener.php';

use AgentsAPI\AI\Routines\WP_Agent_Generation_Fenced_Action;
use AgentsAPI\AI\Routines\WP_Agent_Routine;
use AgentsAPI\AI\Routines\WP_Agent_Routine_Action_Identity;
use AgentsAPI\AI\Routines\WP_Agent_Routine_Action_Scheduler_Bridge;
use AgentsAPI\AI\Routines\WP_Agent_Routine_Registry;

function smoke_reset_state(): void {
	WP_Agent_Routine_Registry::reset();
	$GLOBALS['smoke_as']           = array();
	$GLOBALS['smoke_as_next_id']   = 0;
	$GLOBALS['smoke_options']      = array();
	$GLOBALS['smoke_fired_hooks']  = array();
	$GLOBALS['smoke_chat_ability'] = new Smoke_Chat_Ability();
}

/** @return array<int,array<string,mixed>> */
function smoke_pending_rows(): array {
	$rows = array();
	foreach ( $GLOBALS['smoke_as'] as $id => $row ) {
		if ( ActionScheduler_Store::STATUS_PENDING === $row['status'] ) {
			$rows[ $id ] = $row;
		}
	}
	return $rows;
}

function smoke_hook_fired( string $hook ): bool {
	foreach ( $GLOBALS['smoke_fired_hooks'] as $entry ) {
		if ( $entry[0] === $hook ) {
			return true;
		}
	}
	return false;
}

// ---------------------------------------------------------------------------
// 1. Action identity: stamp, strip, read.
// ---------------------------------------------------------------------------

$logical = array( 'routine_id' => 'alpha' );
$stamped = WP_Agent_Routine_Action_Identity::with_generation( $logical, 'gen-1' );
durable_assert( array( 'routine_id' => 'alpha' ), WP_Agent_Routine_Action_Identity::logical_args( $stamped ), 'identity: logical_args strips the generation marker' );
durable_assert( 'gen-1', WP_Agent_Routine_Action_Identity::generation_from_args( $stamped ), 'identity: generation_from_args reads the stamp' );
durable_assert( null, WP_Agent_Routine_Action_Identity::generation_from_args( $logical ), 'identity: unstamped args have no generation' );
durable_assert( $logical, WP_Agent_Routine_Action_Identity::logical_args( $logical ), 'identity: unstamped args pass through unchanged' );
durable_assert( 2, count( $stamped ), 'identity: stamp appends exactly one element' );

// ---------------------------------------------------------------------------
// 2. Stagger offsets on the value object.
// ---------------------------------------------------------------------------

$stag_alpha = new WP_Agent_Routine( 'stag-alpha', array( 'agent' => 'commander', 'interval' => 3600 ) );
$stag_beta  = new WP_Agent_Routine( 'stag-beta', array( 'agent' => 'commander', 'interval' => 3600 ) );
durable_assert( 1517, $stag_alpha->stagger_offset(), 'stagger: stag-alpha lands in its deterministic slot' );
durable_assert( 1073, $stag_beta->stagger_offset(), 'stagger: stag-beta lands in a different deterministic slot' );
durable_assert( 1517, ( new WP_Agent_Routine( 'stag-alpha', array( 'agent' => 'commander', 'interval' => 3600 ) ) )->stagger_offset(), 'stagger: same id yields the same slot across constructions' );

$no_stagger = new WP_Agent_Routine( 'stag-alpha', array( 'agent' => 'commander', 'interval' => 3600, 'stagger' => false ) );
durable_assert( 0, $no_stagger->stagger_offset(), 'stagger: stagger=false disables the offset' );

$bounded = new WP_Agent_Routine( 'stag-alpha', array( 'agent' => 'commander', 'interval' => 3600, 'stagger' => 120 ) );
durable_assert( 77, $bounded->stagger_offset(), 'stagger: an int sets an explicit max window' );

$short_interval = new WP_Agent_Routine( 'stag-alpha', array( 'agent' => 'commander', 'interval' => 300 ) );
durable_assert( 17, $short_interval->stagger_offset(), 'stagger: the window is capped by the interval' );

$cron_routine = new WP_Agent_Routine( 'stag-alpha', array( 'agent' => 'commander', 'expression' => '0 9 * * *' ) );
durable_assert( 0, $cron_routine->stagger_offset(), 'stagger: cron expressions are not staggered' );

// ---------------------------------------------------------------------------
// 3. Registration stamps a generation and schedules with stagger.
// ---------------------------------------------------------------------------

smoke_reset_state();
WP_Agent_Routine_Registry::register( 'stag-alpha', array( 'agent' => 'commander', 'interval' => 3600 ) );
WP_Agent_Routine_Registry::register( 'stag-beta', array( 'agent' => 'commander', 'interval' => 3600 ) );

$rows = array_values( smoke_pending_rows() );
durable_assert( 2, count( $rows ), 'bridge: two interval routines scheduled' );
durable_assert( true, isset( $rows[0]['timestamp'], $rows[1]['timestamp'] ), 'bridge: first-run timestamps recorded' );
$delta = $rows[1]['timestamp'] - $rows[0]['timestamp'];
durable_assert( true, abs( $delta - ( 1073 - 1517 ) ) <= 1, 'bridge: first-run timestamps differ by the stagger delta' );

$gen_alpha = get_option( 'agents_routine_generation_stag-alpha', '' );
durable_assert( true, is_string( $gen_alpha ) && '' !== $gen_alpha, 'bridge: register persists the generation option' );
durable_assert( $gen_alpha, WP_Agent_Routine_Registry::current_generation( 'stag-alpha' ), 'registry: current_generation reads the persisted generation' );
durable_assert( $gen_alpha, WP_Agent_Routine_Action_Identity::generation_from_args( $rows[0]['args'] ), 'bridge: scheduled args carry the generation stamp' );
durable_assert( array( 'routine_id' => 'stag-alpha' ), WP_Agent_Routine_Action_Identity::logical_args( $rows[0]['args'] ), 'bridge: logical args stay stable under the stamp' );

// ---------------------------------------------------------------------------
// 4. Re-registration fences the superseded action instance.
// ---------------------------------------------------------------------------

smoke_reset_state();
WP_Agent_Routine_Registry::register( 'alpha', array( 'agent' => 'commander', 'interval' => 600, 'prompt' => 'Tick.' ) );
$first_id = $GLOBALS['smoke_as_next_id'];
$gen_one  = get_option( 'agents_routine_generation_alpha', '' );

// A worker claims the old action, then the routine is re-registered with a
// new interval (new generation) before the claimed instance executes.
WP_Agent_Routine_Registry::register( 'alpha', array( 'agent' => 'commander', 'interval' => 1200, 'prompt' => 'Tick.' ) );
$gen_two = get_option( 'agents_routine_generation_alpha', '' );

durable_assert( true, '' !== $gen_one && '' !== $gen_two && $gen_one !== $gen_two, 'fencing: re-registration advances the generation' );
durable_assert( ActionScheduler_Store::STATUS_CANCELED, $GLOBALS['smoke_as'][ $first_id ]['status'], 'fencing: re-registration unschedules the superseded action' );

$claimed = ActionScheduler_Store::instance()->fetch_action( $first_id );
durable_assert( true, $claimed instanceof WP_Agent_Generation_Fenced_Action, 'fencing: fetched routine action is wrapped' );
durable_assert( false, $claimed->get_schedule()->is_recurring(), 'fencing: a stale action reports a non-recurring schedule' );

$before = count( $GLOBALS['smoke_chat_ability']->calls );
$claimed->execute();
durable_assert( $before, count( $GLOBALS['smoke_chat_ability']->calls ), 'fencing: a stale action does not fire the routine callback' );
durable_assert( true, smoke_hook_fired( 'agents_routine_action_fenced' ), 'fencing: the stale execution is reported via agents_routine_action_fenced' );

// The live (current-generation) action executes normally.
$live_rows = array_keys( smoke_pending_rows() );
durable_assert( 1, count( $live_rows ), 'fencing: exactly one live chain remains after re-registration' );
$live = ActionScheduler_Store::instance()->fetch_action( $live_rows[0] );
$live->execute();
durable_assert( $before + 1, count( $GLOBALS['smoke_chat_ability']->calls ), 'fencing: the current-generation action fires the routine callback' );
durable_assert( 'commander', $GLOBALS['smoke_chat_ability']->calls[0]['agent'], 'listener: dispatched through the routine agent' );
durable_assert( 'routine:alpha', $GLOBALS['smoke_chat_ability']->calls[0]['session_id'], 'listener: dispatched with the persistent routine session' );

// The listener also resolves stamped args handed over as a full array.
$listener = 'AgentsAPI\AI\Routines\dispatch_scheduled_routine_run';
$listener( WP_Agent_Routine_Action_Identity::with_generation( array( 'routine_id' => 'alpha' ), $gen_two ) );
durable_assert( $before + 2, count( $GLOBALS['smoke_chat_ability']->calls ), 'listener: stamped array args resolve via logical identity' );

// ---------------------------------------------------------------------------
// 5. The stored-successor reconciler cancels stale recurrence clones.
// ---------------------------------------------------------------------------

smoke_reset_state();
WP_Agent_Routine_Registry::register( 'beta', array( 'agent' => 'commander', 'interval' => 600 ) );
$gen_beta = get_option( 'agents_routine_generation_beta', '' );

// AS repeat() clones the executed action's args into the successor; simulate
// a successor stored with a stale generation (old chain finishing late).
$stale_successor = smoke_as_save(
	WP_Agent_Routine_Action_Scheduler_Bridge::SCHEDULED_HOOK,
	WP_Agent_Routine_Action_Identity::with_generation( array( 'routine_id' => 'beta' ), 'stale-gen-0' ),
	WP_Agent_Routine_Action_Scheduler_Bridge::GROUP,
	time() + 600,
	600
);
durable_assert( ActionScheduler_Store::STATUS_CANCELED, $GLOBALS['smoke_as'][ $stale_successor ]['status'], 'successor: a stale-generation recurrence clone is cancelled on store' );

// A successor carrying the current generation is left alone.
$live_successor = smoke_as_save(
	WP_Agent_Routine_Action_Scheduler_Bridge::SCHEDULED_HOOK,
	WP_Agent_Routine_Action_Identity::with_generation( array( 'routine_id' => 'beta' ), (string) $gen_beta ),
	WP_Agent_Routine_Action_Scheduler_Bridge::GROUP,
	time() + 600,
	600
);
durable_assert( ActionScheduler_Store::STATUS_PENDING, $GLOBALS['smoke_as'][ $live_successor ]['status'], 'successor: a current-generation recurrence clone is preserved' );

// ---------------------------------------------------------------------------
// 6. Reconcile: missing coverage, orphans, dry runs, pause, and the lock.
// ---------------------------------------------------------------------------

smoke_reset_state();
WP_Agent_Routine_Registry::register( 'alpha', array( 'agent' => 'commander', 'interval' => 600 ) );
WP_Agent_Routine_Registry::register( 'beta', array( 'agent' => 'commander', 'interval' => 600 ) );

$result = WP_Agent_Routine_Registry::reconcile();
durable_assert( array(), $result['enqueued'], 'reconcile: fully-covered registry enqueues nothing' );
durable_assert( array( 'alpha', 'beta' ), $result['unchanged'], 'reconcile: covered routines report unchanged' );
durable_assert( array(), $result['errors'], 'reconcile: no errors on a healthy registry' );
durable_assert( false, get_option( 'agents_routine_reconcile_lock', false ), 'reconcile: the lock is released after the run' );

// Delete the pending action (AS table pruned) → reconcile restores it.
foreach ( smoke_pending_rows() as $id => $row ) {
	if ( 'alpha' === ( $row['args']['routine_id'] ?? '' ) ) {
		unset( $GLOBALS['smoke_as'][ $id ] );
	}
}
$result = WP_Agent_Routine_Registry::reconcile();
durable_assert( array( 'alpha' ), $result['enqueued'], 'reconcile: a missing routine schedule is re-enqueued' );
durable_assert( array( 'beta' ), $result['unchanged'], 'reconcile: untouched routines stay unchanged' );
durable_assert( 2, count( smoke_pending_rows() ), 'reconcile: the restored schedule is pending again' );

$result = WP_Agent_Routine_Registry::reconcile();
durable_assert( array( 'alpha', 'beta' ), $result['unchanged'], 'reconcile: a second run is idempotent' );

// Orphan: a pending action whose routine_id is not registered is removed.
smoke_as_save(
	WP_Agent_Routine_Action_Scheduler_Bridge::SCHEDULED_HOOK,
	array( 'routine_id' => 'ghost' ),
	WP_Agent_Routine_Action_Scheduler_Bridge::GROUP,
	time() + 600,
	600
);
$result = WP_Agent_Routine_Registry::reconcile();
durable_assert( array( 'ghost' ), $result['removed'], 'reconcile: an orphaned routine action is removed' );
durable_assert( 2, count( smoke_pending_rows() ), 'reconcile: registered routines survive orphan cleanup' );

// Dry run: report without writing.
foreach ( smoke_pending_rows() as $id => $row ) {
	if ( 'beta' === ( $row['args']['routine_id'] ?? '' ) ) {
		unset( $GLOBALS['smoke_as'][ $id ] );
	}
}
$gen_before = get_option( 'agents_routine_generation_beta', '' );
$result     = WP_Agent_Routine_Registry::reconcile( array( 'dry_run' => true ) );
durable_assert( array( 'beta' ), $result['enqueued'], 'reconcile: dry run reports the missing schedule' );
durable_assert( 1, count( smoke_pending_rows() ), 'reconcile: dry run writes no scheduled action' );
durable_assert( $gen_before, get_option( 'agents_routine_generation_beta', '' ), 'reconcile: dry run does not advance the generation' );
durable_assert( false, get_option( 'agents_routine_reconcile_lock', false ), 'reconcile: dry run takes no lock' );

// Paused routines are intentionally uncovered: reconcile must not re-enqueue.
WP_Agent_Routine_Registry::register( 'delta-ops', array( 'agent' => 'commander', 'interval' => 600 ) );
WP_Agent_Routine_Registry::pause( 'delta-ops' );
$result = WP_Agent_Routine_Registry::reconcile();
durable_assert( false, in_array( 'delta-ops', $result['enqueued'], true ), 'reconcile: a paused routine is not re-enqueued' );
durable_assert( array( 'beta' ), $result['enqueued'], 'reconcile: the still-missing active routine is enqueued' );

// Lock: a fresh held lock blocks; a stale lock is taken over.
add_option( 'agents_routine_reconcile_lock', time(), '', false );
$result = WP_Agent_Routine_Registry::reconcile();
durable_assert( true, isset( $result['errors']['_lock'] ), 'reconcile: a held lock blocks a concurrent run' );

update_option( 'agents_routine_reconcile_lock', time() - 400 );
$result = WP_Agent_Routine_Registry::reconcile();
durable_assert( array(), $result['errors'], 'reconcile: a stale lock is taken over' );

// ---------------------------------------------------------------------------
// 7. Unregister tears down the schedule and the generation tombstone.
// ---------------------------------------------------------------------------

smoke_reset_state();
WP_Agent_Routine_Registry::register( 'alpha', array( 'agent' => 'commander', 'interval' => 600 ) );
WP_Agent_Routine_Registry::unregister( 'alpha' );
durable_assert( '', get_option( 'agents_routine_generation_alpha', '' ), 'unregister: the generation option is deleted' );
durable_assert( 0, count( smoke_pending_rows() ), 'unregister: the scheduled action is cancelled' );

// ---------------------------------------------------------------------------

if ( count( $failures ) > 0 ) {
	echo 'FAIL ' . count( $failures ) . " failures\n";
	exit( 1 );
}
echo "OK {$passes} passed\n";

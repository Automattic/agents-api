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
		$GLOBALS['smoke_as_fetches'][] = (int) $action_id;
		$row                           = $GLOBALS['smoke_as'][ (int) $action_id ] ?? null;
		if ( null === $row ) {
			throw new RuntimeException( 'unknown action' );
		}

		$schedule = new ActionScheduler_Schedule( $row['timestamp'], $row['interval'], $row['cron'] );
		$action   = new ActionScheduler_Action( $row['hook'], $row['args'], $schedule, $row['group'] );
		return apply_filters( 'action_scheduler_stored_action_instance', $action, $row['hook'], $row['args'], $schedule, $row['group'], 10 );
	}

	/** @param int|string $action_id Action id. */
	public function get_status( $action_id ): string {
		return $GLOBALS['smoke_as'][ (int) $action_id ]['status'] ?? '';
	}

	/** @param int|string $action_id Action id. */
	public function cancel_action( $action_id ): void {
		$GLOBALS['smoke_as_cancel_calls'][] = (int) $action_id;
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

/**
 * Mirror ActionScheduler_Abstract_QueueRunner::process_action(): fire
 * before_execute, re-check pending, then execute. Returns true when the
 * action body ran.
 */
function smoke_as_run( int $action_id ): bool {
	do_action( 'action_scheduler_before_execute', $action_id, 'smoke' );
	if ( ActionScheduler_Store::STATUS_PENDING !== ActionScheduler_Store::instance()->get_status( $action_id ) ) {
		do_action( 'action_scheduler_execution_ignored', $action_id, 'smoke' );
		return false;
	}
	ActionScheduler_Store::instance()->fetch_action( $action_id )->execute();
	return true;
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
// Fake object cache and $wpdb — deliberately separate stores, so a test can
// model the exact production bug: the object cache saying an option exists
// while the backing DB row does not.
// ---------------------------------------------------------------------------

$GLOBALS['smoke_object_cache'] = array();
if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( string $key, $value, string $group = '', int $expire = 0 ): bool {
		unset( $expire );
		$GLOBALS['smoke_object_cache'][ $group ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( string $key, string $group = '' ) {
		return $GLOBALS['smoke_object_cache'][ $group ][ $key ] ?? false;
	}
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( string $key, string $group = '' ): bool {
		unset( $GLOBALS['smoke_object_cache'][ $group ][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'wp_cache_supports' ) ) {
	function wp_cache_supports( string $feature ): bool {
		return 'flush_group' === $feature;
	}
}
if ( ! function_exists( 'wp_cache_flush_group' ) ) {
	function wp_cache_flush_group( string $group ): bool {
		unset( $GLOBALS['smoke_object_cache'][ $group ] );
		return true;
	}
}

/**
 * Minimal $wpdb double, named to match the real global `wpdb` class so the
 * substrate's `$wpdb instanceof \wpdb` narrowing (required for the code to
 * type-check under PHPStan against the real wordpress-stubs `wpdb` class)
 * also resolves correctly in this pure-PHP harness, which loads no such
 * class otherwise.
 *
 * It does not parse SQL generically — it recognises the three fixed
 * queries the routines substrate issues (the reconcile lock's upsert and
 * delete, and the legacy-option purge's LIKE delete) and reproduces their
 * real MySQL affected-rows semantics against its own `rows` store, which is
 * intentionally independent of the `$GLOBALS['smoke_options']` /
 * object-cache shims above.
 */
if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $options = 'wp_options';

		/** @var array<string,string> */
		public array $rows = array();

		/** @var list<mixed> */
		private array $last_prepare_args = array();

		private string $last_query_kind = '';

		/** @param mixed ...$args */
		public function prepare( string $query, ...$args ): string {
			$this->last_prepare_args = $args;
			if ( str_contains( $query, 'ON DUPLICATE KEY UPDATE' ) ) {
				$this->last_query_kind = 'lock_upsert';
			} elseif ( str_contains( $query, 'DELETE FROM' ) && str_contains( $query, 'LIKE' ) ) {
				$this->last_query_kind = 'legacy_purge';
			} elseif ( str_contains( $query, 'DELETE FROM' ) ) {
				$this->last_query_kind = 'lock_delete';
			} else {
				$this->last_query_kind = '';
			}
			return $query;
		}

		/** @param string $sql Already-prepared query (ignored; kind + args were captured by prepare()). */
		public function query( string $sql ) {
			unset( $sql );
			switch ( $this->last_query_kind ) {
				case 'lock_upsert':
					return $this->upsert_lock();
				case 'lock_delete':
					return $this->delete_row();
				case 'legacy_purge':
					return $this->purge_legacy();
				default:
					return 0;
			}
		}

		public function esc_like( string $text ): string {
			// Real wpdb::esc_like() backslash-escapes SQL wildcard characters
			// so they match literally; this fake only needs the plain prefix
			// back out again for its own string-prefix comparison, so it is
			// a no-op.
			return $text;
		}

		private function upsert_lock(): int {
			[ $table, $option_name, $new_value, $autoload, $stale_before ] = $this->last_prepare_args;
			unset( $table, $autoload );
			if ( ! array_key_exists( $option_name, $this->rows ) ) {
				$this->rows[ $option_name ] = (string) $new_value;
				return 1;
			}
			if ( (int) $this->rows[ $option_name ] <= (int) $stale_before ) {
				$this->rows[ $option_name ] = (string) $new_value;
				return 2;
			}
			return 0;
		}

		private function delete_row(): int {
			[ $table, $option_name ] = $this->last_prepare_args;
			unset( $table );
			if ( array_key_exists( $option_name, $this->rows ) ) {
				unset( $this->rows[ $option_name ] );
				return 1;
			}
			return 0;
		}

		private function purge_legacy(): int {
			[ $table, $like ] = $this->last_prepare_args;
			unset( $table );
			$prefix = rtrim( (string) $like, '%' );
			$deleted_ids = array();
			foreach ( array_keys( $this->rows ) as $name ) {
				if ( str_starts_with( $name, $prefix ) ) {
					$deleted_ids[] = $name;
				}
			}
			foreach ( $deleted_ids as $name ) {
				unset( $this->rows[ $name ] );
			}
			return count( $deleted_ids );
		}
	}
}
$GLOBALS['wpdb'] = new wpdb();

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
use AgentsAPI\AI\Routines\WP_Agent_Routine_Action_Scheduler_Bridge;
use AgentsAPI\AI\Routines\WP_Agent_Routine_Registry;

function smoke_reset_state(): void {
	$GLOBALS['smoke_as_fetches']      = array();
	$GLOBALS['smoke_as_cancel_calls'] = array();
	WP_Agent_Routine_Registry::reset();
	WP_Agent_Routine_Registry::reset_backend();
	WP_Agent_Routine_Action_Scheduler_Bridge::instance()->reset_pending_cache_for_tests();
	$GLOBALS['smoke_as']           = array();
	$GLOBALS['smoke_as_next_id']   = 0;
	$GLOBALS['smoke_options']      = array();
	$GLOBALS['smoke_object_cache'] = array();
	$GLOBALS['wpdb']->rows         = array();
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
// 1. Scheduled args stay logical; the routine's generation + watermark live
//    in ONE option row — never a per-action option.
// ---------------------------------------------------------------------------

smoke_reset_state();
WP_Agent_Routine_Registry::register( 'alpha', array( 'agent' => 'commander', 'interval' => 600 ) );
$alpha_id    = $GLOBALS['smoke_as_next_id'];
$alpha_state = get_option( 'agents_routine_generation_alpha', array() );
durable_assert( array( 'routine_id' => 'alpha' ), $GLOBALS['smoke_as'][ $alpha_id ]['args'], 'identity: scheduled args are purely logical' );
durable_assert( true, is_array( $alpha_state ) && isset( $alpha_state['generation'], $alpha_state['watermark'] ), 'identity: the per-routine option carries a generation and a watermark' );
durable_assert( $alpha_id, $alpha_state['watermark'], "identity: the watermark is the new chain's own first action id" );
durable_assert( false, array_key_exists( 'agents_routine_action_generation_' . $alpha_id, $GLOBALS['smoke_options'] ), 'identity: no per-action option row is written when an action is stored (the leak this fixes)' );

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
// 3. Registration persists a generation + watermark and schedules with
//    stagger. The stagger offset only shifts the first-run timestamp; it
//    never causes a later, unchanged re-register to look like a mismatch.
// ---------------------------------------------------------------------------

smoke_reset_state();
WP_Agent_Routine_Registry::register( 'stag-alpha', array( 'agent' => 'commander', 'interval' => 3600 ) );
WP_Agent_Routine_Registry::register( 'stag-beta', array( 'agent' => 'commander', 'interval' => 3600 ) );

$rows = array_values( smoke_pending_rows() );
durable_assert( 2, count( $rows ), 'bridge: two interval routines scheduled' );
durable_assert( true, isset( $rows[0]['timestamp'], $rows[1]['timestamp'] ), 'bridge: first-run timestamps recorded' );
$delta = $rows[1]['timestamp'] - $rows[0]['timestamp'];
durable_assert( true, abs( $delta - ( 1073 - 1517 ) ) <= 1, 'bridge: first-run timestamps differ by the stagger delta' );

$state_alpha = get_option( 'agents_routine_generation_stag-alpha', array() );
durable_assert( true, is_array( $state_alpha ) && is_string( $state_alpha['generation'] ?? null ) && '' !== $state_alpha['generation'], 'bridge: register persists a generation' );
durable_assert( $state_alpha['generation'], WP_Agent_Routine_Registry::current_generation( 'stag-alpha' ), 'registry: current_generation reads the persisted generation' );
durable_assert( array( 'routine_id' => 'stag-alpha' ), $rows[0]['args'], 'bridge: scheduled args are purely logical' );
durable_assert( array_keys( smoke_pending_rows() )[0], $state_alpha['watermark'], "bridge: the watermark is the stored action's own id" );

// Re-registering the exact same routine (same interval, hence same stagger
// slot too) a second time is a pure no-op: no new action, no new
// generation/watermark. This is the core of the idempotent-register fix —
// a consumer with hundreds of persisted routines calling register() on
// every boot must not pay for an unschedule + reschedule when nothing
// changed.
$as_next_before                  = $GLOBALS['smoke_as_next_id'];
$GLOBALS['smoke_as_cancel_calls'] = array();
$noop = WP_Agent_Routine_Registry::register( 'stag-alpha', array( 'agent' => 'commander', 'interval' => 3600 ) );
durable_assert( true, $noop instanceof WP_Agent_Routine, 'idempotent register: re-registering an unchanged routine still succeeds' );
durable_assert( $as_next_before, $GLOBALS['smoke_as_next_id'], 'idempotent register: schedules no new action' );
durable_assert( array(), $GLOBALS['smoke_as_cancel_calls'], 'idempotent register: cancels nothing' );
durable_assert( $state_alpha, get_option( 'agents_routine_generation_stag-alpha', array() ), 'idempotent register: mints no new generation/watermark' );

// Changing the interval is a genuine mismatch: exactly one cancel (the
// superseded action) and exactly one new action.
$as_next_before                  = $GLOBALS['smoke_as_next_id'];
$GLOBALS['smoke_as_cancel_calls'] = array();
WP_Agent_Routine_Registry::register( 'stag-alpha', array( 'agent' => 'commander', 'interval' => 7200 ) );
durable_assert( $as_next_before + 1, $GLOBALS['smoke_as_next_id'], 'changed register: schedules exactly one new action' );
durable_assert( 1, count( $GLOBALS['smoke_as_cancel_calls'] ), 'changed register: cancels exactly the superseded action' );
durable_assert( true, get_option( 'agents_routine_generation_stag-alpha', array() )['watermark'] > $state_alpha['watermark'], 'changed register: the watermark advances' );

// ---------------------------------------------------------------------------
// 4. Re-registration fences a claimed superseded action at before_execute —
//    purely by comparing the action's own id to the routine's watermark, no
//    per-action stamping involved.
// ---------------------------------------------------------------------------

smoke_reset_state();
WP_Agent_Routine_Registry::register( 'alpha', array( 'agent' => 'commander', 'interval' => 600, 'prompt' => 'Tick.' ) );
$first_id  = $GLOBALS['smoke_as_next_id'];
$state_one = get_option( 'agents_routine_generation_alpha', array() );

// Simulate a worker that already claimed the old action (it is still pending
// in its view) while the routine is re-registered with a new interval.
$claimed_row = $GLOBALS['smoke_as'][ $first_id ];
WP_Agent_Routine_Registry::register( 'alpha', array( 'agent' => 'commander', 'interval' => 1200, 'prompt' => 'Tick.' ) );
$state_two = get_option( 'agents_routine_generation_alpha', array() );
durable_assert( true, '' !== $state_one['generation'] && '' !== $state_two['generation'] && $state_one['generation'] !== $state_two['generation'], 'fencing: a genuine schedule change advances the generation' );
durable_assert( true, $state_two['watermark'] > $state_one['watermark'], 'fencing: a genuine schedule change advances the watermark' );
durable_assert( ActionScheduler_Store::STATUS_CANCELED, $GLOBALS['smoke_as'][ $first_id ]['status'], 'fencing: re-registration cancels the superseded action directly by id — no group-wide unschedule scan' );

// Resurrect the claimed row as pending to model the race: the claim happened
// before the cancel landed, so the runner still tries to execute it. Its id
// ($first_id) is below the new watermark — no stamp is needed for the fence
// to catch it.
$GLOBALS['smoke_as'][ $first_id ]           = $claimed_row;
$GLOBALS['smoke_as'][ $first_id ]['status'] = ActionScheduler_Store::STATUS_PENDING;
durable_assert( true, $first_id < $state_two['watermark'], 'fencing: the claimed action id is below the new watermark' );

$before = count( $GLOBALS['smoke_chat_ability']->calls );
durable_assert( false, smoke_as_run( $first_id ), 'fencing: the runner skips an action below the current watermark' );
durable_assert( $before, count( $GLOBALS['smoke_chat_ability']->calls ), 'fencing: a stale action does not fire the routine callback' );
durable_assert( ActionScheduler_Store::STATUS_CANCELED, $GLOBALS['smoke_as'][ $first_id ]['status'], 'fencing: the stale action is cancelled, so no recurrence successor is spawned' );
durable_assert( true, smoke_hook_fired( 'agents_routine_action_fenced' ), 'fencing: the fence is reported via agents_routine_action_fenced' );

// The live (current-watermark) action executes normally.
$live_rows = array_keys( smoke_pending_rows() );
durable_assert( 1, count( $live_rows ), 'fencing: exactly one live chain remains after re-registration' );
durable_assert( true, $live_rows[0] >= $state_two['watermark'], 'fencing: the live action id is at or above the current watermark' );
durable_assert( true, smoke_as_run( $live_rows[0] ), 'fencing: the runner executes the current-watermark action' );
durable_assert( $before + 1, count( $GLOBALS['smoke_chat_ability']->calls ), 'fencing: the current action fires the routine callback' );
durable_assert( 'commander', $GLOBALS['smoke_chat_ability']->calls[0]['agent'], 'listener: dispatched through the routine agent' );
durable_assert( 'routine:alpha', $GLOBALS['smoke_chat_ability']->calls[0]['session_id'], 'listener: dispatched with the persistent routine session' );

// A pending action for a routine with no recorded watermark (never
// re-registered this process, or a pre-fix legacy action) is allowed to run.
$legacy_id = smoke_as_save( WP_Agent_Routine_Action_Scheduler_Bridge::SCHEDULED_HOOK, array( 'routine_id' => 'never-registered-this-process' ), WP_Agent_Routine_Action_Scheduler_Bridge::GROUP, time(), 1200 );
durable_assert( true, smoke_as_run( $legacy_id ), 'fencing: an action for a routine with no recorded watermark is not fenced' );

// Caching: after the first register() call populates the request-scoped
// bulk pending map, a second register() for a DIFFERENT routine must not
// re-fetch the first routine's action — this is what makes register() cheap
// to call hundreds of times per request instead of once per routine.
$GLOBALS['smoke_as_fetches'] = array();
WP_Agent_Routine_Registry::register( 'beta', array( 'agent' => 'commander', 'interval' => 600 ) );
$beta_id = $GLOBALS['smoke_as_next_id'];
durable_assert( array( $beta_id ), array_values( array_unique( $GLOBALS['smoke_as_fetches'] ) ), 'register: the cached pending map is reused across routines in one request; only the newly stored action is fetched' );

// ---------------------------------------------------------------------------
// 5. Recurrence successors need no stamping at all: Action Scheduler ids are
//    a global increasing sequence, so any action id at or above the
//    routine's current watermark is live and any id below it is fenced —
//    including a successor AS spawns after the initial schedule.
// ---------------------------------------------------------------------------

smoke_reset_state();
WP_Agent_Routine_Registry::register( 'beta', array( 'agent' => 'commander', 'interval' => 600 ) );
$state_beta = get_option( 'agents_routine_generation_beta', array() );

// AS's own repeat() stores the next occurrence with the same logical args
// and a fresh (higher) id — no hook, no per-action write. It just runs
// because its id is already above the current watermark.
$successor = smoke_as_save(
	WP_Agent_Routine_Action_Scheduler_Bridge::SCHEDULED_HOOK,
	array( 'routine_id' => 'beta' ),
	WP_Agent_Routine_Action_Scheduler_Bridge::GROUP,
	time() + 600,
	600
);
durable_assert( true, $successor > $state_beta['watermark'], 'successor: a recurrence clone gets an id above the current watermark' );
durable_assert( true, smoke_as_run( $successor ), 'successor: the recurrence clone runs unfenced' );

// A second successor lands, then the routine is re-registered with a
// genuinely different schedule — advancing the watermark past it, exactly
// as if it belonged to the now-superseded chain.
$late_successor = smoke_as_save(
	WP_Agent_Routine_Action_Scheduler_Bridge::SCHEDULED_HOOK,
	array( 'routine_id' => 'beta' ),
	WP_Agent_Routine_Action_Scheduler_Bridge::GROUP,
	time() + 600,
	600
);
WP_Agent_Routine_Registry::register( 'beta', array( 'agent' => 'commander', 'interval' => 900 ) );
$state_beta_two = get_option( 'agents_routine_generation_beta', array() );
durable_assert( true, $late_successor < $state_beta_two['watermark'], 'successor: the pre-existing clone is now below the advanced watermark' );
durable_assert( false, smoke_as_run( $late_successor ), 'successor: a clone below the new watermark is fenced at execution' );
durable_assert( ActionScheduler_Store::STATUS_CANCELED, $GLOBALS['smoke_as'][ $late_successor ]['status'], 'successor: the stale clone is cancelled' );

// Actions outside the routine hook/group are never fenced, regardless of id.
$foreign = smoke_as_save( 'some_other_hook', array( 'x' => 1 ), 'other', time(), 0 );
durable_assert( true, smoke_as_run( $foreign ), 'successor: foreign actions (different hook/group) are never fenced' );

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
durable_assert( false, array_key_exists( 'agents_routine_reconcile_lock', $GLOBALS['wpdb']->rows ), 'reconcile: the $wpdb lock row is deleted after the run' );

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
durable_assert( false, array_key_exists( 'agents_routine_reconcile_lock', $GLOBALS['wpdb']->rows ), 'reconcile: dry run takes no lock' );

// Paused routines are intentionally uncovered: reconcile must not re-enqueue.
WP_Agent_Routine_Registry::register( 'delta-ops', array( 'agent' => 'commander', 'interval' => 600 ) );
WP_Agent_Routine_Registry::pause( 'delta-ops' );
$result = WP_Agent_Routine_Registry::reconcile();
durable_assert( false, in_array( 'delta-ops', $result['enqueued'], true ), 'reconcile: a paused routine is not re-enqueued' );
durable_assert( array( 'beta' ), $result['enqueued'], 'reconcile: the still-missing active routine is enqueued' );

// Lock: a fresh held lock blocks; a stale lock is taken over. The lock now
// lives only in $wpdb — never in get_option()/the options cache — so it is
// seeded and inspected directly against the fake DB row store.
$GLOBALS['wpdb']->rows['agents_routine_reconcile_lock'] = (string) time();
$result = WP_Agent_Routine_Registry::reconcile();
durable_assert( true, isset( $result['errors']['_lock'] ), 'reconcile: a held lock blocks a concurrent run' );

$GLOBALS['wpdb']->rows['agents_routine_reconcile_lock'] = (string) ( time() - 400 );
$result = WP_Agent_Routine_Registry::reconcile();
durable_assert( array(), $result['errors'], 'reconcile: a stale lock is taken over' );

// Self-heal: a stale/dangling object-cache entry for the lock option must
// never block acquisition when the backing DB row is actually absent — the
// exact production failure this replaces. A killed request left
// `agents_routine_reconcile_lock` as a persistent Redis "locked" value with
// no DB row: add_option() always failed (the cache said it existed), the
// old stale check read that same cached value, and delete_option() found
// nothing in the DB to delete, so the lock stayed stuck for 25+ minutes.
// Reading and writing the lock exclusively through $wpdb means a cache
// entry with no backing row simply cannot influence the decision.
unset( $GLOBALS['wpdb']->rows['agents_routine_reconcile_lock'] );
wp_cache_set( 'agents_routine_reconcile_lock', time(), 'options' );
$result = WP_Agent_Routine_Registry::reconcile();
durable_assert( array(), $result['errors'], 'reconcile: a stale cache entry with no backing DB row does not block acquisition' );
wp_cache_delete( 'agents_routine_reconcile_lock', 'options' );

// ---------------------------------------------------------------------------
// 7. Unregister tears down the schedule and the generation tombstone.
// ---------------------------------------------------------------------------

smoke_reset_state();
WP_Agent_Routine_Registry::register( 'alpha', array( 'agent' => 'commander', 'interval' => 600 ) );
WP_Agent_Routine_Registry::unregister( 'alpha' );
durable_assert( '', get_option( 'agents_routine_generation_alpha', '' ), 'unregister: the generation option is deleted' );
durable_assert( 0, count( smoke_pending_rows() ), 'unregister: the scheduled action is cancelled' );

// ---------------------------------------------------------------------------
// 8. One-shot cleanup of legacy per-action option rows left by a pre-fix
//    version. Single DELETE, gated to run at most once per site.
// ---------------------------------------------------------------------------

smoke_reset_state();
$GLOBALS['wpdb']->rows['agents_routine_action_generation_101'] = 'gen-a';
$GLOBALS['wpdb']->rows['agents_routine_action_generation_102'] = 'gen-a';
$GLOBALS['wpdb']->rows['agents_routine_action_generation_9']   = 'gen-b';
$GLOBALS['wpdb']->rows['agents_routine_generation_alpha']      = 'unrelated-should-survive';
$GLOBALS['wpdb']->rows['some_other_plugin_option']             = 'unrelated-should-survive';

$deleted = WP_Agent_Routine_Action_Scheduler_Bridge::purge_legacy_action_generation_options();
durable_assert( 3, $deleted, 'purge: deletes every agents_routine_action_generation_* row in one query' );
durable_assert( false, array_key_exists( 'agents_routine_action_generation_101', $GLOBALS['wpdb']->rows ), 'purge: a legacy row is gone' );
durable_assert( true, array_key_exists( 'agents_routine_generation_alpha', $GLOBALS['wpdb']->rows ), 'purge: an unrelated per-routine option survives' );
durable_assert( true, array_key_exists( 'some_other_plugin_option', $GLOBALS['wpdb']->rows ), 'purge: an unrelated option from another plugin survives' );
durable_assert( 0, WP_Agent_Routine_Action_Scheduler_Bridge::purge_legacy_action_generation_options(), 'purge: calling it again deletes nothing (idempotent)' );

// maybe_purge...() gates the purge behind a persisted marker: it runs once,
// then never again, even if legacy rows reappear (e.g. a downgrade+re-fix).
smoke_reset_state();
$GLOBALS['wpdb']->rows['agents_routine_action_generation_5'] = 'gen-c';
WP_Agent_Routine_Action_Scheduler_Bridge::maybe_purge_legacy_action_generation_options();
durable_assert( false, array_key_exists( 'agents_routine_action_generation_5', $GLOBALS['wpdb']->rows ), 'maybe_purge: the first call purges legacy rows' );
durable_assert( true, is_numeric( get_option( 'agents_routine_action_generation_purged', false ) ), 'maybe_purge: a marker option records that the purge ran' );

$GLOBALS['wpdb']->rows['agents_routine_action_generation_6'] = 'gen-d';
WP_Agent_Routine_Action_Scheduler_Bridge::maybe_purge_legacy_action_generation_options();
durable_assert( true, array_key_exists( 'agents_routine_action_generation_6', $GLOBALS['wpdb']->rows ), 'maybe_purge: a second call is gated by the marker and does not run again' );

// ---------------------------------------------------------------------------

if ( count( $failures ) > 0 ) {
	echo 'FAIL ' . count( $failures ) . " failures\n";
	exit( 1 );
}
echo "OK {$passes} passed\n";

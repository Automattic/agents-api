<?php
/**
 * Pure-PHP regression test for the option-backed bridge queue lost-update race.
 *
 * Run with: php tests/bridge-store-concurrency-smoke.php
 *
 * THE BUG. WP_Agent_Option_Bridge_Store::enqueue() and ::ack() both do a naive
 * read-modify-write on ONE shared option row (the whole queue):
 *
 *     $queue = read_queue();            // READ whole queue
 *     $queue[$id] = ...; // or unset()  // MODIFY my item
 *     update_option( QUEUE, $queue );   // WRITE whole queue back
 *
 * update_option() is a plain write, not an application-level compare-and-set, so
 * two concurrent requests both read the SAME snapshot before either writes and
 * the later write CLOBBERS the earlier one (a lost update). Two enqueues each
 * read {A}, write {A,B} and {A,C}, and last-writer-wins silently drops an item;
 * an ack that read a pre-enqueue snapshot writes back a queue missing the item a
 * concurrent enqueue just committed.
 *
 * This deterministic harness pins get_option() to the shared pre-write snapshot
 * that two simultaneous processes could both observe. While the mocked advisory
 * lock is held, reads instead see committed state, modeling the ordering contract
 * imposed by MySQL GET_LOCK(). It verifies the store's critical-section boundary
 * and fail-closed behavior; it is not a live multi-process MySQL integration test.
 *
 * It FAILS before the fix (an item is lost) and PASSES after (the critical
 * section is serialized cross-process, so every write survives).
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "bridge-store-concurrency-smoke\n";

const BRIDGE_QUEUE_OPTION = 'wp_agent_bridge_queue';

$GLOBALS['__bridge_options']      = array();
$GLOBALS['__bridge_lock_held']    = false;
$GLOBALS['__bridge_lock_available'] = true;
// When set, get_option() serves this frozen snapshot for the queue option — the
// stale pre-write read that two simultaneous processes would both observe — but
// only while no advisory lock is held (a held lock means a real second process
// would have blocked and then read fresh).
$GLOBALS['__bridge_frozen_queue'] = null;

function bridge_lock_held(): bool {
	return $GLOBALS['__bridge_lock_held'];
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public function prepare( string $query, ...$args ): string {
			return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $query ), $args );
		}

		public function get_var( string $query ) {
			if ( str_contains( $query, 'GET_LOCK' ) ) {
				if ( ! $GLOBALS['__bridge_lock_available'] || $GLOBALS['__bridge_lock_held'] ) {
					return '0';
				}
				$GLOBALS['__bridge_lock_held'] = true;
				return '1';
			}
			if ( str_contains( $query, 'RELEASE_LOCK' ) ) {
				$GLOBALS['__bridge_lock_held'] = false;
				return '1';
			}
			return null;
		}
	}
}
$GLOBALS['wpdb'] = new wpdb();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) {
		if (
			BRIDGE_QUEUE_OPTION === $option
			&& null !== $GLOBALS['__bridge_frozen_queue']
			&& ! bridge_lock_held()
		) {
			return $GLOBALS['__bridge_frozen_queue'];
		}
		return array_key_exists( $option, $GLOBALS['__bridge_options'] ) ? $GLOBALS['__bridge_options'][ $option ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		unset( $autoload );
		$GLOBALS['__bridge_options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $option, $value = '', $deprecated = '', $autoload = null ): bool {
		unset( $deprecated, $autoload );
		if ( array_key_exists( $option, $GLOBALS['__bridge_options'] ) ) {
			return false; // Atomic INSERT semantics: the row already exists.
		}
		$GLOBALS['__bridge_options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		if ( ! array_key_exists( $option, $GLOBALS['__bridge_options'] ) ) {
			return false;
		}
		unset( $GLOBALS['__bridge_options'][ $option ] );
		return true;
	}
}

function bridge_assert( $expected, $actual, string $name, array &$failures, int &$passes ): void {
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

require_once __DIR__ . '/../src/Channels/class-wp-agent-bridge-client.php';
require_once __DIR__ . '/../src/Channels/class-wp-agent-bridge-queue-item.php';
require_once __DIR__ . '/../src/Channels/class-wp-agent-bridge-store.php';
require_once __DIR__ . '/../src/Channels/class-wp-agent-bridge-store-lock.php';
require_once __DIR__ . '/../src/Channels/class-wp-agent-option-bridge-store.php';

use AgentsAPI\AI\Channels\WP_Agent_Bridge_Queue_Item;
use AgentsAPI\AI\Channels\WP_Agent_Option_Bridge_Store;

/** Freeze the queue read to the current committed snapshot. */
function bridge_freeze_reads(): void {
	$GLOBALS['__bridge_frozen_queue'] = array_key_exists( BRIDGE_QUEUE_OPTION, $GLOBALS['__bridge_options'] )
		? $GLOBALS['__bridge_options'][ BRIDGE_QUEUE_OPTION ]
		: array();
}
function bridge_unfreeze_reads(): void {
	$GLOBALS['__bridge_frozen_queue'] = null;
}

function bridge_item( string $queue_id, string $content ): WP_Agent_Bridge_Queue_Item {
	return new WP_Agent_Bridge_Queue_Item(
		array(
			'queue_id'  => $queue_id,
			'client_id' => 'relay',
			'agent'     => 'support-agent',
			'content'   => $content,
		)
	);
}

// ═══════════════════════════════════════════════════════════════════════════
// RACE 1 — two concurrent enqueues of DIFFERENT items must both survive.
// Both read the pre-write snapshot {A}; without the lock the later write
// clobbers the earlier ({A,B} then {A,C} → C wins, B lost). With the lock the
// second enqueue reads the committed {A,B} and writes {A,B,C}.
// ═══════════════════════════════════════════════════════════════════════════
$GLOBALS['__bridge_options']      = array();
$GLOBALS['__bridge_frozen_queue'] = null;
$store                            = new WP_Agent_Option_Bridge_Store();

$store->enqueue( bridge_item( 'q-a', 'A' ) ); // committed alone → {A}
bridge_freeze_reads();                        // both processes observe {A}
$store->enqueue( bridge_item( 'q-b', 'B' ) ); // process 1: read {A} → write {A,B}
$store->enqueue( bridge_item( 'q-c', 'C' ) ); // process 2: read {A} → write {A,?}
bridge_unfreeze_reads();

$pending = $store->pending( 'relay', 100 );
$ids     = array_map( static fn( $i ) => $i->queue_id, $pending );
sort( $ids );
bridge_assert( 3, count( $pending ), 'concurrent enqueues: all three items survive (no lost update)', $failures, $passes );
bridge_assert( array( 'q-a', 'q-b', 'q-c' ), $ids, 'concurrent enqueues: A, B and C all pending', $failures, $passes );

// ═══════════════════════════════════════════════════════════════════════════
// RACE 2 — an ack racing an enqueue must not delete the freshly queued item.
// Queue is {A}. A concurrent enqueue of C and ack of A both read {A}. The
// enqueue commits {A,C}; the ack, having read the stale {A}, would write back a
// queue with A removed → {} → deleting C too. With the lock the ack reads the
// committed {A,C} and writes {C}, so C survives.
// ═══════════════════════════════════════════════════════════════════════════
$GLOBALS['__bridge_options']      = array();
$GLOBALS['__bridge_frozen_queue'] = null;
$store                            = new WP_Agent_Option_Bridge_Store();

$store->enqueue( bridge_item( 'q-a', 'A' ) ); // committed → {A}
bridge_freeze_reads();                        // both processes observe {A}
$store->enqueue( bridge_item( 'q-c', 'C' ) ); // enqueue commits {A,C}
$store->ack( 'relay', array( 'q-a' ) );       // ack read stale {A}; must not drop C
bridge_unfreeze_reads();

$pending = $store->pending( 'relay', 100 );
$ids     = array_map( static fn( $i ) => $i->queue_id, $pending );
bridge_assert( array( 'q-c' ), $ids, 'ack racing enqueue: acked item gone, freshly queued item survives', $failures, $passes );

// ═══════════════════════════════════════════════════════════════════════════
// Sanity — the lock leaves no residual lock row behind after each call.
// ═══════════════════════════════════════════════════════════════════════════
bridge_assert( false, bridge_lock_held(), 'lock is released after each critical section', $failures, $passes );

// Acquisition failure must not run an unprotected queue mutation.
$GLOBALS['__bridge_lock_available'] = false;
$before                              = $GLOBALS['__bridge_options'][ BRIDGE_QUEUE_OPTION ];
$timed_out                           = false;
try {
	$store->enqueue( bridge_item( 'q-d', 'D' ) );
} catch ( RuntimeException $error ) {
	$timed_out = true;
}
$GLOBALS['__bridge_lock_available'] = true;
bridge_assert( true, $timed_out, 'lock timeout fails closed', $failures, $passes );
bridge_assert( $before, $GLOBALS['__bridge_options'][ BRIDGE_QUEUE_OPTION ], 'lock timeout does not mutate the queue', $failures, $passes );

echo "\nPassed: {$passes}, Failed: " . count( $failures ) . "\n";
exit( count( $failures ) > 0 ? 1 : 0 );

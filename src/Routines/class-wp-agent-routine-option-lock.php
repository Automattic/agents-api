<?php
/**
 * Atomic, TTL-based option lock shared by the routines subsystem.
 *
 * One small internal helper for the two single-flight paths in `src/Routines/`:
 * {@see WP_Agent_Routine_Registry}'s whole-registry reconcile lock and the
 * Action Scheduler bridge's per-routine schedule lock. Both need the same
 * guarantee — at most one process inside the critical section at a time,
 * with a crashed holder's lock taken over after a TTL — and both must make
 * the decision atomically against the real `wp_options` row.
 *
 * @package AgentsAPI
 * @since   0.17.0
 */

namespace AgentsAPI\AI\Routines;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Routine_Option_Lock {

	/**
	 * Acquire a named lock via a single atomic `$wpdb` compare-and-set —
	 * `INSERT ... ON DUPLICATE KEY UPDATE` against `wp_options` directly,
	 * never through `get_option()`/`add_option()`.
	 *
	 * This closes a production failure mode: a killed request can leave a
	 * lock option as a persistent object-cache entry (e.g. Redis) with no
	 * backing DB row. `add_option()` then always fails (the cache says the
	 * option exists), a stale check reading through the cache sees the same
	 * cached value, and `delete_option()` finds nothing in the DB to delete
	 * — the lock stays stuck "held" indefinitely. Reading and writing
	 * exclusively through `$wpdb` — and never consulting
	 * `get_option()`/the options cache for the decision — means a stale
	 * cache entry with no DB row cannot block acquisition: MySQL's
	 * `ON DUPLICATE KEY` path only ever sees the real row (or its absence).
	 *
	 * The single query handles all three cases atomically against
	 * `option_name`'s unique key:
	 *  - no row exists: plain insert, 1 affected row;
	 *  - row exists and is stale (older than `$ttl`): `ON DUPLICATE KEY
	 *    UPDATE` overwrites it, MySQL reports 2 affected rows for a changed
	 *    row — and because the takeover itself is the atomic compare-and-set,
	 *    two processes that both observe the same stale lock cannot both take
	 *    it over: only the first write changes the row, and the second sees a
	 *    fresh value and reports 0 affected rows;
	 *  - row exists and is fresh: the `IF()` leaves the value unchanged,
	 *    MySQL reports 0 affected rows for a no-op update.
	 *
	 * `$wpdb->query()` returns the raw affected-rows count for a non-SELECT
	 * query, so `> 0` is "we now hold the lock" and `0` is "someone else
	 * holds it, and it is not stale".
	 *
	 * When there is no `$wpdb` layer at all (pure-PHP test harnesses) the
	 * caller is already single-process and the lock is a no-op success.
	 *
	 * @since 0.17.0
	 *
	 * @param string $option_name wp_options row holding the lock timestamp.
	 * @param int    $ttl         Seconds after which a held lock is treated
	 *                            as stale and may be taken over.
	 * @return bool True when the lock is now held by this process.
	 */
	public static function acquire( string $option_name, int $ttl ): bool {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return true;
		}

		$now          = time();
		$stale_before = $now - $ttl;

		$query = $wpdb->prepare(
			'INSERT INTO %i (option_name, option_value, autoload)
			VALUES (%s, %s, %s)
			ON DUPLICATE KEY UPDATE option_value = IF( CAST( option_value AS UNSIGNED ) <= %d, VALUES(option_value), option_value )',
			$wpdb->options,
			$option_name,
			(string) $now,
			'no',
			$stale_before
		);
		if ( ! is_string( $query ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query is built by $wpdb->prepare() above.
		$affected = $wpdb->query( $query );

		self::flush_cache( $option_name );

		return is_int( $affected ) && $affected > 0;
	}

	/**
	 * Release a named lock previously taken by {@see acquire()}: delete the
	 * backing row directly (bypassing the options cache) and evict any cache
	 * entry, so a later read through `get_option()` never observes a value
	 * the DB no longer has.
	 *
	 * @since 0.17.0
	 *
	 * @param string $option_name wp_options row holding the lock timestamp.
	 */
	public static function release( string $option_name ): void {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$query = $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $wpdb->options, $option_name );
		if ( is_string( $query ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query is built by $wpdb->prepare() above.
			$wpdb->query( $query );
		}

		self::flush_cache( $option_name );
	}

	/**
	 * Explicitly evict the lock option from the object cache after every
	 * direct `$wpdb` write, so anything that does happen to read it via
	 * `get_option()` never observes a value the DB no longer has.
	 */
	private static function flush_cache( string $option_name ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $option_name, 'options' );
		}
	}
}

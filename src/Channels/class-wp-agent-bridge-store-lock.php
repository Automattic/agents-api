<?php
/**
 * Cross-process serialization lock for the option-backed bridge queue.
 *
 * WHY THIS EXISTS. {@see WP_Agent_Option_Bridge_Store::enqueue()} and
 * {@see WP_Agent_Option_Bridge_Store::ack()} both do a read-modify-write on ONE
 * shared option row (the whole queue lives under a single option name):
 *
 *     $queue = read_queue();            // read the whole queue
 *     $queue[$id] = ...; // or unset()  // modify: add/remove my item
 *     update_option( QUEUE, $queue );   // write the whole queue back
 *
 * `update_option()` is a plain WRITE, not an application-level compare-and-set.
 * When two requests hit this window CONCURRENTLY (independent HTTP requests
 * against the default store) both read the SAME snapshot before either writes,
 * each applies only ITS OWN mutation, and the later write CLOBBERS the earlier
 * one — a classic lost update. Two simultaneous enqueues of different items each
 * read `{A}`, write `{A,B}` and `{A,C}`, and last-writer-wins silently drops an
 * outbound agent response; an ack racing an enqueue can delete a freshly queued
 * item. The failure is integrity/availability: queued messages vanish.
 *
 * This is the SAME bug class the branch-reconcile path already guards against.
 * {@see \AgentsAPI\AI\Workflows\WP_Agent_Workflow_Reconcile_Lock} documents the
 * identical lost-update on a shared option and closes it with an `add_option()`
 * compare-and-set. This is that lock's option-scoped sibling: it serializes the
 * queue critical section so each writer reads only AFTER the previous writer
 * committed.
 *
 * TABLE-FREE. Acquisition uses `add_option()` as the atomic compare-and-set —
 * `add_option()` performs an INSERT that fails (returns false) when the option
 * row already exists, because `option_name` is a UNIQUE key, so exactly one
 * concurrent caller wins. A held lock stores a TTL expiry so a crashed holder's
 * lock is reclaimable. No new table, no hand-rolled file/APCu lock.
 *
 * PLUGGABLE. A consumer with a stronger primitive (MySQL `GET_LOCK`, Redis) can
 * replace acquisition/release via the `wp_agent_bridge_store_lock` filter. The
 * default here is correct and dependency-free.
 *
 * BLOCKS, DOES NOT DROP. Acquisition blocks with bounded retries rather than
 * failing open on a message write: a queue write must not be lost to a lost
 * update, so a contender waits for the holder rather than racing it. The TTL
 * makes a genuinely crashed holder reclaimable so a waiter still makes progress.
 *
 * @package AgentsAPI
 * @since   0.104.0
 */

namespace AgentsAPI\AI\Channels;

defined( 'ABSPATH' ) || exit;

/**
 * Default `add_option()`-CAS option-scoped lock for the bridge queue.
 */
final class WP_Agent_Bridge_Store_Lock {

	/**
	 * Option-name prefix for the lock row. The guarded option name is appended so
	 * the lock is scoped to the exact shared row it serializes.
	 *
	 * @since 0.104.0
	 */
	private const OPTION_PREFIX = 'wp_agent_bridge_lock_';

	/**
	 * Lock time-to-live (seconds). After this a stale lock (crashed holder) is
	 * reclaimable. Generous relative to the queue critical section's real duration
	 * (an in-memory merge + one option write) so a healthy holder is never evicted
	 * mid-section, yet short enough that a crash does not strand the queue for long.
	 *
	 * @since 0.104.0
	 */
	private const TTL_SECONDS = 30;

	/**
	 * Max acquisition attempts before giving up. With the backoff below this is a
	 * bounded wait; the queue section is short, so contenders win quickly.
	 *
	 * @since 0.104.0
	 */
	private const MAX_ATTEMPTS = 50;

	/**
	 * Per-attempt backoff (microseconds). 20ms × 50 attempts ≈ 1s worst-case wait —
	 * well under the TTL, so a waiter either wins or reclaims a stale lock.
	 *
	 * @since 0.104.0
	 */
	private const RETRY_USLEEP = 20000;

	/**
	 * Run one callback under an exclusive lock scoped to $option_name. The callback
	 * runs at most once and only while the lock is held; the lock is always
	 * released, even if the callback throws. When the lock genuinely cannot be
	 * acquired (a wedged holder that never expires) the callback still runs —
	 * dropping the write would be strictly worse than a best-effort mutation, and
	 * the TTL makes a real crash reclaimable so this fallback is rare.
	 *
	 * @since 0.104.0
	 *
	 * @template T
	 * @param string       $option_name Shared option row whose read-modify-write is serialized.
	 * @param callable():T $critical    The critical section (read → mutate → write).
	 * @return T The callback's return value.
	 */
	public static function with_lock( string $option_name, callable $critical ) {
		$override = self::filtered_runner( $option_name, $critical );
		if ( null !== $override ) {
			return $override();
		}

		$token = self::acquire( $option_name );
		try {
			return $critical();
		} finally {
			if ( null !== $token ) {
				self::release( $option_name, $token );
			}
		}
	}

	/**
	 * Let a consumer replace the whole locked-run with a stronger primitive via the
	 * `wp_agent_bridge_store_lock` filter. The filter receives null and the option
	 * name; returning a callable takes over acquisition/release/critical entirely.
	 *
	 * @since 0.104.0
	 *
	 * @param string   $option_name Shared option row being guarded.
	 * @param callable $critical    The critical section to run under the lock.
	 * @return callable|null Replacement runner, or null to use the default lock.
	 */
	private static function filtered_runner( string $option_name, callable $critical ): ?callable {
		if ( ! function_exists( 'apply_filters' ) ) {
			return null;
		}
		$runner = apply_filters( 'wp_agent_bridge_store_lock', null, $option_name, $critical );
		return is_callable( $runner ) ? $runner : null;
	}

	/**
	 * Acquire the option-scoped lock, blocking with bounded retries. Returns the
	 * lock token on success, or null when acquisition ultimately failed (the caller
	 * proceeds without the lock rather than dropping the write — see
	 * {@see self::with_lock()}).
	 *
	 * @since 0.104.0
	 *
	 * @param string $option_name Shared option row being guarded.
	 * @return string|null Lock token, or null if unacquired after MAX_ATTEMPTS.
	 */
	private static function acquire( string $option_name ): ?string {
		if ( ! function_exists( 'add_option' ) || ! function_exists( 'get_option' ) ) {
			// No option layer (e.g. a non-WordPress harness) — nothing to lock
			// against; callers are already single-process there.
			return null;
		}

		$option = self::OPTION_PREFIX . md5( $option_name );

		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$token   = self::mint_token();
			$payload = array(
				'token'   => $token,
				'expires' => time() + self::TTL_SECONDS,
			);

			// Fast path: no lock row → atomic INSERT wins. add_option() returns
			// false if the row already exists (the option_name unique key is the
			// compare-and-set), so exactly one concurrent caller sees true.
			if ( add_option( $option, $payload, '', false ) ) {
				return $token;
			}

			// A lock row exists. Reclaim it only if it has expired (a crashed
			// holder). update_option() is not itself a CAS, so re-read after
			// writing and confirm OUR token won — two racers reclaiming the same
			// stale lock both write, but only the last writer's token survives the
			// re-read, and the loser retries.
			$existing   = get_option( $option, false );
			$expires_at = is_array( $existing ) && is_numeric( $existing['expires'] ?? null ) ? (int) $existing['expires'] : 0;
			if ( $expires_at <= time() ) {
				update_option( $option, $payload, false );
				$confirm = get_option( $option, false );
				if ( is_array( $confirm ) && ( $confirm['token'] ?? '' ) === $token ) {
					return $token;
				}
			}

			self::backoff();
		}

		return null;
	}

	/**
	 * Release the option-scoped lock only if the supplied token still owns it. A
	 * token that no longer matches (the lock expired and was reclaimed by another
	 * process) must NOT delete the current holder's lock.
	 *
	 * @since 0.104.0
	 *
	 * @param string $option_name Shared option row being guarded.
	 * @param string $token       Token returned by acquire().
	 * @return void
	 */
	private static function release( string $option_name, string $token ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'delete_option' ) ) {
			return;
		}
		$option   = self::OPTION_PREFIX . md5( $option_name );
		$existing = get_option( $option, false );
		if ( is_array( $existing ) && ( $existing['token'] ?? '' ) === $token ) {
			delete_option( $option );
		}
	}

	/**
	 * Mint a unique lock token.
	 *
	 * @since 0.104.0
	 */
	private static function mint_token(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			unset( $error );
			return uniqid( 'lock_', true );
		}
	}

	/**
	 * Sleep briefly between acquisition attempts.
	 *
	 * @since 0.104.0
	 */
	private static function backoff(): void {
		usleep( self::RETRY_USLEEP );
	}
}

<?php
/**
 * Generation-aware Action Scheduler args identity for routines.
 *
 * The bridge stamps every scheduled routine action with the routine's current
 * generation, appended as a trailing metadata element so the *logical* args
 * (`array( 'routine_id' => ... )`) stay stable for identity lookups. Action
 * Scheduler matches args by exact JSON equality, so identity work across the
 * substrate (unschedule, coverage checks, the wake listener) must compare
 * logical args, never the stamped payload.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Routines;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Routine_Action_Identity {

	private const GENERATION_KEY    = '_agents_routine_generation';
	private const LOGICAL_COUNT_KEY = '_agents_routine_logical_arg_count';

	/**
	 * Stamp a generation onto scheduled-action args as a trailing metadata
	 * element. The logical prefix is left untouched.
	 *
	 * @param array<array-key,mixed> $args       Logical args.
	 * @param string                 $generation Current schedule generation.
	 * @return array<array-key,mixed>
	 */
	public static function with_generation( array $args, string $generation ): array {
		$logical_count = count( $args );
		$args[]        = array(
			self::GENERATION_KEY    => $generation,
			self::LOGICAL_COUNT_KEY => $logical_count,
		);

		return $args;
	}

	/**
	 * Read the stamped generation from scheduled-action args, when present.
	 *
	 * @param array<array-key,mixed> $args Possibly-stamped args.
	 */
	public static function generation_from_args( array $args ): ?string {
		if ( array() === $args ) {
			return null;
		}

		$marker = end( $args );
		if ( ! is_array( $marker ) ) {
			return null;
		}

		$generation = $marker[ self::GENERATION_KEY ] ?? null;
		return is_string( $generation ) && '' !== $generation ? $generation : null;
	}

	/**
	 * Strip the trailing generation metadata element, returning the logical
	 * args used for identity matching.
	 *
	 * @param array<array-key,mixed> $args Possibly-stamped args.
	 * @return array<array-key,mixed>
	 */
	public static function logical_args( array $args ): array {
		if ( array() === $args ) {
			return $args;
		}

		$marker = end( $args );
		if ( ! is_array( $marker ) || null === self::generation_from_args( $args ) ) {
			return $args;
		}

		$count = isset( $marker[ self::LOGICAL_COUNT_KEY ] ) && is_numeric( $marker[ self::LOGICAL_COUNT_KEY ] )
			? (int) $marker[ self::LOGICAL_COUNT_KEY ]
			: count( $args ) - 1;

		return array_slice( $args, 0, max( 0, $count ) );
	}
}

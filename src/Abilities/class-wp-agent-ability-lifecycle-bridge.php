<?php
/**
 * Bridges Abilities API execution lifecycle filters into substrate observers.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges WP_Ability execution lifecycle filters into substrate-level actions.
 *
 * WordPress 7.1 introduced four execution lifecycle filters on `WP_Ability`:
 * `wp_pre_execute_ability`, `wp_ability_normalize_input`,
 * `wp_ability_permission_result`, and `wp_ability_execute_result`. This bridge
 * forwards them onto substrate-level observable actions so consumers can record
 * ability calls without each ability author opting in and without coupling
 * observers to the conversation loop's event surface.
 *
 * The bridge is a passive observer: handlers return the filter input unchanged.
 * Only the post-execute `wp_ability_execute_result` hook is wired in this slice
 * (issue #94 telemetry adoption); the remaining three hooks land in follow-up
 * slices that add their own substrate envelopes.
 *
 * On WordPress < 7.1 the underlying filters are never applied, so registered
 * handlers stay idle.
 */
class WP_Agent_Ability_Lifecycle_Bridge {

	public const ACTION_ABILITY_EXECUTED = 'agents_api_ability_executed';

	/**
	 * Register lifecycle-filter handlers with WordPress.
	 *
	 * Idempotent for repeated calls when WordPress de-duplicates filter
	 * registration through `has_filter()`. Callers that wire this from a host
	 * adapter should still call it once at bootstrap.
	 */
	public static function register(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		add_filter( 'wp_ability_execute_result', array( __CLASS__, 'observe_execute_result' ), 10, 4 );
	}

	/**
	 * Observer for the `wp_ability_execute_result` filter.
	 *
	 * Emits `agents_api_ability_executed` with the same shape exposed by the
	 * underlying filter, and returns the result unchanged so other handlers
	 * downstream see exactly what the registered execute_callback produced.
	 *
	 * @param mixed  $result       Result returned by the ability's execute_callback, or `WP_Error`.
	 * @param string $ability_name Ability name.
	 * @param mixed  $input        Normalized input passed to the ability.
	 * @param object $ability      `WP_Ability` instance.
	 * @return mixed The original result, unchanged.
	 */
	public static function observe_execute_result( $result, string $ability_name, $input, $ability ) {
		if ( function_exists( 'do_action' ) ) {
			do_action( self::ACTION_ABILITY_EXECUTED, $ability_name, $result, $input, $ability );
		}

		return $result;
	}
}

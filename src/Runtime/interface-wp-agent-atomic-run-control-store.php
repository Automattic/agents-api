<?php
/**
 * Atomic run-control store capability.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( WP_Agent_Run_Control_Store::class ) ) {
	require_once __DIR__ . '/interface-wp-agent-run-control-store.php';
}

/**
 * Optional capability for atomically creating state only when it is absent.
 */
if ( ! interface_exists( WP_Agent_Atomic_Run_Control_Store::class ) ) {
	interface WP_Agent_Atomic_Run_Control_Store extends WP_Agent_Run_Control_Store {

		/**
		 * @param array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} $state Initial state envelope.
		 */
		public function create_state_if_absent( string $store_key, array $state ): bool;
	}
}

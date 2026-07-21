<?php
/**
 * Workspace-aware atomic run-control store capability.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( WP_Agent_Atomic_Run_Control_Store::class ) ) {
	require_once __DIR__ . '/interface-wp-agent-atomic-run-control-store.php';
}
if ( ! interface_exists( WP_Agent_Workspace_Run_Control_Store::class ) ) {
	require_once __DIR__ . '/interface-wp-agent-workspace-run-control-store.php';
}

/**
 * Atomically creates state scoped to an explicit workspace.
 */
if ( ! interface_exists( WP_Agent_Atomic_Workspace_Run_Control_Store::class ) ) {
	interface WP_Agent_Atomic_Workspace_Run_Control_Store extends WP_Agent_Atomic_Run_Control_Store, WP_Agent_Workspace_Run_Control_Store {

		/**
		 * @param array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} $state Initial state envelope.
		 */
		public function create_workspace_state_if_absent( string $store_key, WP_Agent_Workspace_Scope $workspace, array $state ): bool;
	}
}

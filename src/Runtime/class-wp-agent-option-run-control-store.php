<?php
/**
 * Option-backed run-control store.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( WP_Agent_Workspace_Run_Control_Store::class ) ) {
	require_once __DIR__ . '/interface-wp-agent-workspace-run-control-store.php';
}

/**
 * Persists run-control state in WordPress options.
 */
class WP_Agent_Option_Run_Control_Store implements WP_Agent_Workspace_Run_Control_Store {

	/**
	 * @param string $store_key Store key.
	 * @return array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>}
	 */
	public function get_state( string $store_key ): array {
		$state = function_exists( 'get_option' ) ? get_option( $store_key, array() ) : array();
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return array(
			'runs'   => $this->stored_runs( $state['runs'] ?? array() ),
			'queues' => $this->stored_queues( $state['queues'] ?? array() ),
			'events' => $this->stored_queues( $state['events'] ?? array() ),
		);
	}

	/**
	 * @param string $store_key Store key.
	 * @param array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} $state State envelope.
	 */
	public function save_state( string $store_key, array $state ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( $store_key, $state, false );
		}
	}

	/**
	 * Read explicit workspace state from the network-wide option table.
	 *
	 * Omitted workspaces continue through get_state() and remain site-local.
	 *
	 * @return array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>}
	 */
	public function get_workspace_state( string $store_key, WP_Agent_Workspace_Scope $workspace ): array {
		if ( ! function_exists( 'get_site_option' ) ) {
			throw new \RuntimeException( 'The run-control store cannot share explicit workspace state.' );
		}

		$state = get_site_option( $this->workspace_option_key( $store_key, $workspace ), array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return array(
			'runs'   => $this->stored_runs( $state['runs'] ?? array() ),
			'queues' => $this->stored_queues( $state['queues'] ?? array() ),
			'events' => $this->stored_queues( $state['events'] ?? array() ),
		);
	}

	/**
	 * Save explicit workspace state in the network-wide option table.
	 *
	 * @param array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} $state State envelope.
	 */
	public function save_workspace_state( string $store_key, WP_Agent_Workspace_Scope $workspace, array $state ): void {
		if ( ! function_exists( 'update_site_option' ) ) {
			throw new \RuntimeException( 'The run-control store cannot share explicit workspace state.' );
		}

		update_site_option( $this->workspace_option_key( $store_key, $workspace ), $state );
	}

	private function workspace_option_key( string $store_key, WP_Agent_Workspace_Scope $workspace ): string {
		return $store_key . '_workspace_' . hash( 'sha256', $workspace->key() );
	}

	/**
	 * @param mixed $runs Raw stored runs.
	 * @return array<string,array<string,mixed>>
	 */
	private function stored_runs( mixed $runs ): array {
		if ( ! is_array( $runs ) ) {
			return array();
		}

		$stored = array();
		foreach ( $runs as $run_id => $run ) {
			if ( is_string( $run_id ) && is_array( $run ) ) {
				$stored[ $run_id ] = $this->assoc_array( $run );
			}
		}

		return $stored;
	}

	/**
	 * @param mixed $queues Raw stored queues.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function stored_queues( mixed $queues ): array {
		if ( ! is_array( $queues ) ) {
			return array();
		}

		$stored = array();
		foreach ( $queues as $scope => $items ) {
			if ( ! is_string( $scope ) || ! is_array( $items ) ) {
				continue;
			}

			$stored[ $scope ] = array();
			foreach ( $items as $item ) {
				if ( is_array( $item ) ) {
					$stored[ $scope ][] = $this->assoc_array( $item );
				}
			}
		}

		return $stored;
	}

	/**
	 * @param array<mixed> $value Raw array.
	 * @return array<string,mixed>
	 */
	private function assoc_array( array $value ): array {
		$result = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $item;
			}
		}
		return $result;
	}
}

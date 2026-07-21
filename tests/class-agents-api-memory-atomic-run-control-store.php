<?php
/**
 * In-memory atomic run-control store for pure-PHP smoke tests.
 *
 * @package AgentsAPI\Tests
 */

use AgentsAPI\AI\WP_Agent_Atomic_Workspace_Run_Control_Store;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

final class Agents_API_Memory_Atomic_Run_Control_Store implements WP_Agent_Atomic_Workspace_Run_Control_Store {

	/** @var array<string,array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>}> */
	private array $states = array();

	public function get_state( string $store_key ): array {
		return $this->states[ $this->site_key( $store_key ) ] ?? $this->empty_state();
	}

	public function save_state( string $store_key, array $state ): void {
		$this->states[ $this->site_key( $store_key ) ] = $state;
	}

	public function mutate_state( string $store_key, callable $mutation ): mixed {
		$mutated = $mutation( $this->get_state( $store_key ) );
		$this->save_state( $store_key, $mutated['state'] );
		return $mutated['result'];
	}

	public function get_workspace_state( string $store_key, WP_Agent_Workspace_Scope $workspace ): array {
		return $this->states[ $this->workspace_key( $store_key, $workspace ) ] ?? $this->empty_state();
	}

	public function save_workspace_state( string $store_key, WP_Agent_Workspace_Scope $workspace, array $state ): void {
		$this->states[ $this->workspace_key( $store_key, $workspace ) ] = $state;
	}

	public function mutate_workspace_state( string $store_key, WP_Agent_Workspace_Scope $workspace, callable $mutation ): mixed {
		$mutated = $mutation( $this->get_workspace_state( $store_key, $workspace ) );
		$this->save_workspace_state( $store_key, $workspace, $mutated['state'] );
		return $mutated['result'];
	}

	/** @return array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} */
	private function empty_state(): array {
		return array( 'runs' => array(), 'queues' => array(), 'events' => array() );
	}

	private function site_key( string $store_key ): string {
		$site_id = function_exists( 'get_current_blog_id' ) ? (string) get_current_blog_id() : 'default';
		return 'site:' . $site_id . ':' . $store_key;
	}

	private function workspace_key( string $store_key, WP_Agent_Workspace_Scope $workspace ): string {
		return 'workspace:' . $workspace->key() . ':' . $store_key;
	}
}

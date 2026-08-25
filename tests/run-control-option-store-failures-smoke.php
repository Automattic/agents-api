<?php
/** Run with: php tests/run-control-option-store-failures-smoke.php */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $options = 'wp_options';
		public string $sitemeta = 'wp_sitemeta';
		public int $siteid = 1;
		public string $last_error = '';
		/** @var array<string,string> */
		public array $rows = array();
		/** @var array<string,mixed> */
		public array $api_options = array();
		public bool $veto_option_write = false;
		public bool $veto_site_option_write = false;
		/** @var array<string,true> */
		public array $claims = array();
		private string $fault = '';
		/** @var array<string,array{query:string,args:array<int,mixed>}> */
		private array $prepared = array();

		public function fail_next( string $operation ): void { $this->fault = $operation; }
		public function prepare( string $query, ...$args ): string {
			$key = 'prepared:' . count( $this->prepared );
			$this->prepared[ $key ] = array( 'query' => $query, 'args' => $args );
			return $key;
		}
		public function query( string $query ): int|bool {
			if ( 'START TRANSACTION' === $query ) { return $this->result( 'start', 1 ); }
			if ( 'COMMIT' === $query ) { return $this->result( 'commit', 1 ); }
			if ( 'ROLLBACK' === $query ) { return 1; }
			$prepared = $this->prepared[ $query ]; $sql = $prepared['query']; $args = $prepared['args'];
			if ( str_starts_with( $sql, 'INSERT IGNORE' ) ) {
				if ( false === $this->result( 'lock_insert', 1 ) ) { return false; }
				if ( isset( $this->rows[ (string) $args[1] ] ) ) { return 0; }
				$this->rows[ (string) $args[1] ] = (string) $args[2]; return 1;
			}
			if ( str_starts_with( $sql, 'UPDATE %i SET option_value' ) ) {
				if ( false === $this->result( 'lock_update', 1 ) ) { return false; }
				$key = (string) $args[2];
				if ( ! isset( $this->rows[ $key ] ) || (string) $args[3] !== $this->rows[ $key ] ) { return 0; }
				$this->rows[ $key ] = (string) $args[1]; return 1;
			}
			if ( str_starts_with( $sql, 'DELETE FROM' ) ) {
				if ( false === $this->result( 'lock_release', 1 ) ) { return false; }
				$key = (string) $args[1];
				if ( isset( $this->rows[ $key ] ) && (string) $args[2] === $this->rows[ $key ] ) { unset( $this->rows[ $key ] ); return 1; }
				return 0;
			}
			if ( str_starts_with( $sql, 'INSERT INTO %i (option_name' ) ) {
				if ( false === $this->result( 'state_write', 1 ) ) { return false; }
				$this->rows[ (string) $args[1] ] = (string) $args[2]; return 1;
			}
			throw new RuntimeException( 'Unexpected test query: ' . $sql );
		}
		public function get_var( string $query ): mixed {
			$prepared = $this->prepared[ $query ]; $sql = $prepared['query']; $args = $prepared['args'];
			if ( str_starts_with( $sql, 'SELECT GET_LOCK' ) ) { if ( false === $this->result( 'claim_acquire', 1 ) ) { return null; } $key = (string) $args[0]; if ( isset( $this->claims[ $key ] ) ) { return 0; } $this->claims[ $key ] = true; return 1; }
			if ( str_starts_with( $sql, 'SELECT RELEASE_LOCK' ) ) { if ( false === $this->result( 'claim_release', 1 ) ) { return null; } unset( $this->claims[ (string) $args[0] ] ); return 1; }
			$operation = str_contains( $sql, 'option_value' ) && str_starts_with( (string) $args[1], '_agents_api_run_lock_' ) ? 'lock_read' : 'state_read';
			if ( false === $this->result( $operation, 1 ) ) { return null; }
			$key = str_contains( $sql, 'meta_value' ) ? (string) $args[2] : (string) $args[1];
			return $this->rows[ $key ] ?? null;
		}
		public function suppress_errors( bool $suppress ): bool { unset( $suppress ); return false; }
		public function get_blog_prefix( int $blog_id = 1 ): string { unset( $blog_id ); return 'wp_'; }
		public function api_result( string $operation, mixed $success ): mixed { return $this->result( $operation, $success ); }
		private function result( string $operation, mixed $success ): mixed {
			$this->last_error = '';
			if ( $operation === $this->fault ) { $this->fault = ''; $this->last_error = 'Injected database failure.'; return false; }
			return $success;
		}
	}
}

function get_option( string $key, mixed $default = false ): mixed {
	global $wpdb; $db = $wpdb;
	if ( false === $db->api_result( 'option_read', true ) ) { return $default; }
	return $db->api_options[ $key ] ?? $default;
}
function update_option( string $key, mixed $value, mixed $autoload = null ): bool {
	global $wpdb; unset( $autoload ); $db = $wpdb;
	if ( false === $db->api_result( 'option_write', true ) ) { return false; }
	if ( $db->veto_option_write ) { return false; }
	if ( array_key_exists( $key, $db->api_options ) && $value === $db->api_options[ $key ] ) { return false; }
	$db->api_options[ $key ] = $value; $db->rows[ $key ] = serialize( $value ); return true;
}
function get_site_option( string $key, mixed $default = false ): mixed { return get_option( $key, $default ); }
function update_site_option( string $key, mixed $value ): bool { global $wpdb; if ( $wpdb->veto_site_option_write ) { return false; } return update_option( $key, $value ); }

require_once __DIR__ . '/../src/Runtime/interface-wp-agent-run-control-store.php';
require_once __DIR__ . '/../src/Runtime/interface-wp-agent-workspace-run-control-store.php';
require_once __DIR__ . '/../src/Runtime/interface-wp-agent-atomic-run-control-store.php';
require_once __DIR__ . '/../src/Runtime/interface-wp-agent-atomic-workspace-run-control-store.php';
require_once __DIR__ . '/../src/Runtime/interface-wp-agent-exclusive-run-control-store.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-run-control-store-exception.php';
require_once __DIR__ . '/../src/Workspace/class-wp-agent-workspace-scope.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-option-run-control-store.php';

use AgentsAPI\AI\WP_Agent_Option_Run_Control_Store;
use AgentsAPI\AI\WP_Agent_Run_Control_Store_Exception;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

$fails = array(); $passes = 0;
function option_store_assert( bool $ok, string $name ): void { global $fails, $passes; if ( $ok ) { ++$passes; echo "  PASS $name\n"; } else { $fails[] = $name; echo "  FAIL $name\n"; } }
function option_store_fixture(): array { $db = new wpdb(); $GLOBALS['wpdb'] = $db; return array( $db, new WP_Agent_Option_Run_Control_Store( $db ) ); }
function option_store_mutation( array $state ): array { $state['runs']['operation'] = array( 'run_id' => 'deterministic-run' ); return array( 'state' => $state, 'result' => 'stored' ); }

echo "run-control-option-store-failures-smoke\n";

list( $db, $store ) = option_store_fixture();
$db->fail_next( 'option_read' );
try { $store->get_state( 'state' ); $typed_read = false; } catch ( WP_Agent_Run_Control_Store_Exception $error ) { $typed_read = true; }
option_store_assert( $typed_read, 'options API database read failure uses the retryable store exception' );

list( $db, $store ) = option_store_fixture();
$db->fail_next( 'option_write' );
try { $store->save_state( 'state', array( 'runs' => array(), 'queues' => array(), 'events' => array() ) ); $typed_write = false; } catch ( WP_Agent_Run_Control_Store_Exception $error ) { $typed_write = true; }
option_store_assert( $typed_write, 'options API database write failure is not silently ignored' );
$state = array( 'runs' => array(), 'queues' => array(), 'events' => array() );
$store->save_state( 'unchanged', $state ); $store->save_state( 'unchanged', $state );
option_store_assert( $state === $store->get_state( 'unchanged' ), 'unchanged update_option false is preserved as a successful no-op' );

list( $db, $store ) = option_store_fixture();
$db->veto_option_write = true;
$store->save_state( 'vetoed', $state );
option_store_assert( array() === $db->api_options, 'intentional update_option veto is not classified as a temporary database outage' );

list( $db, $store ) = option_store_fixture();
$db->veto_site_option_write = true;
$store->save_workspace_state( 'vetoed', WP_Agent_Workspace_Scope::from_parts( 'site', '1' ), $state );
option_store_assert( array() === $db->api_options, 'intentional update_site_option veto is not classified as a temporary database outage' );

$injected = new wpdb(); $global = new wpdb();
$injected->api_options['authority'] = array( 'runs' => array( 'injected' => array( 'run_id' => 'injected' ) ), 'queues' => array(), 'events' => array() );
$global->api_options['authority'] = array( 'runs' => array( 'global' => array( 'run_id' => 'global' ) ), 'queues' => array(), 'events' => array() );
$global->last_error = 'Unrelated connection error.'; $GLOBALS['wpdb'] = $global;
$authority_store = new WP_Agent_Option_Run_Control_Store( $injected );
$authority_state = $authority_store->get_state( 'authority' );
option_store_assert( isset( $authority_state['runs']['injected'] ) && ! isset( $authority_state['runs']['global'] ) && $global === $GLOBALS['wpdb'], 'injected connection is the coherent option API and error authority' );

foreach ( array( 'lock_insert', 'state_read', 'start', 'state_write', 'commit', 'lock_release' ) as $operation ) {
	list( $db, $store ) = option_store_fixture(); $db->fail_next( $operation );
	try { $store->mutate_state( 'atomic-state', 'option_store_mutation' ); $typed = false; } catch ( WP_Agent_Run_Control_Store_Exception $error ) { $typed = true; }
	option_store_assert( $typed, "atomic {$operation} failure uses the retryable store exception" );
}

list( $db, $store ) = option_store_fixture();
try {
	$store->mutate_state( 'primary-error', static function ( array $state ) use ( $db ): array { unset( $state ); $db->fail_next( 'lock_release' ); throw new LogicException( 'Primary mutation bug.' ); } );
	$primary_preserved = false;
} catch ( LogicException $error ) {
	$primary_preserved = 'Primary mutation bug.' === $error->getMessage();
}
option_store_assert( $primary_preserved, 'lock release failure does not replace an in-flight programmer exception' );

list( $db, $store ) = option_store_fixture();
$nested_claimed = true;
$claimed = $store->execute_claimed( 'terminal-operation', static function () use ( $store, &$nested_claimed ): void { $nested_claimed = $store->execute_claimed( 'terminal-operation', static function (): void {} ); } );
$recovered_claim = $store->execute_claimed( 'terminal-operation', static function (): void {} );
option_store_assert( $claimed && ! $nested_claimed && $recovered_claim, 'connection-lifetime claim rejects live overlap and is recoverable after release' );

foreach ( array( 'claim_acquire', 'claim_release' ) as $operation ) {
	list( $db, $store ) = option_store_fixture(); $db->fail_next( $operation );
	try { $store->execute_claimed( 'failed-claim', static function (): void {} ); $typed = false; } catch ( WP_Agent_Run_Control_Store_Exception $error ) { $typed = true; }
	option_store_assert( $typed, "{$operation} failure uses the retryable store exception" );
}

list( $db, $store ) = option_store_fixture();
$stored = $store->mutate_state( 'normalized-state', static function ( array $state ): array { $state['runs']['operation'] = array( 'run_id' => 'deterministic-run' ); $state['idempotency'] = array( 'operation' => 'other-run' ); return array( 'state' => $state, 'result' => null ); } );
unset( $stored );
$normalized = $store->mutate_state( 'normalized-state', static fn( array $state ): array => array( 'state' => $state, 'result' => $state ) );
option_store_assert( 'deterministic-run' === ( $normalized['runs']['operation']['run_id'] ?? '' ) && ! isset( $normalized['idempotency'] ), 'production normalization relies on the authoritative operation record, not a false idempotency map' );

echo 'Passed: ' . $passes . ', Failed: ' . count( $fails ) . "\n";
exit( empty( $fails ) ? 0 : 1 );

<?php
/**
 * MariaDB integration coverage for atomic option-store mutations.
 *
 * Requires AGENTS_API_WP_ROOT and AGENTS_API_DB_* environment variables.
 *
 * @package AgentsAPI\Tests
 */

$wp_root = rtrim( (string) getenv( 'AGENTS_API_WP_ROOT' ), '/' );
if ( '' === $wp_root || ! is_file( $wp_root . '/wp-includes/class-wpdb.php' ) ) {
	fwrite( STDERR, "AGENTS_API_WP_ROOT must point to a WordPress checkout.\n" );
	exit( 1 );
}
if ( ! function_exists( 'pcntl_fork' ) ) {
	fwrite( STDERR, "pcntl is required for two-connection integration tests.\n" );
	exit( 1 );
}

define( 'ABSPATH', $wp_root . '/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_DISPLAY', false );
define( 'SAVEQUERIES', false );
define( 'MULTISITE', true );

require_once $wp_root . '/wp-includes/class-wp-error.php';
require_once $wp_root . '/wp-includes/plugin.php';
require_once $wp_root . '/wp-includes/class-wpdb.php';
require_once __DIR__ . '/../src/Workspace/class-wp-agent-workspace-scope.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-execution-principal.php';
require_once __DIR__ . '/../src/Transcripts/class-wp-agent-conversation-store.php';
require_once __DIR__ . '/../src/Transcripts/class-wp-agent-principal-conversation-store.php';
require_once __DIR__ . '/../src/Transcripts/class-wp-agent-principal-conversation-session-reader.php';
require_once __DIR__ . '/../src/Transcripts/class-wp-agent-conversation-sessions.php';
require_once __DIR__ . '/../src/Runtime/interface-wp-agent-run-control-store.php';
require_once __DIR__ . '/../src/Runtime/interface-wp-agent-workspace-run-control-store.php';
require_once __DIR__ . '/../src/Runtime/interface-wp-agent-atomic-run-control-store.php';
require_once __DIR__ . '/../src/Runtime/interface-wp-agent-atomic-workspace-run-control-store.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-option-run-control-store.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-run-control.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-message.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-chat-run-control.php';

use AgentsAPI\AI\WP_Agent_Chat_Run_Control;
use AgentsAPI\AI\WP_Agent_Option_Run_Control_Store;
use AgentsAPI\AI\WP_Agent_Run_Control;
use AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Store;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

function is_multisite(): bool {
	return (bool) ( $GLOBALS['__agents_api_integration_multisite'] ?? true );
}

function sanitize_title( string $value ): string {
	return strtolower( (string) preg_replace( '/[^a-z0-9_-]+/i', '-', $value ) );
}

function get_main_site_id( int $network_id = 1 ): int {
	unset( $network_id );
	return 1;
}

function wp_cache_delete( string $key, string $group = '' ): bool {
	$GLOBALS['__agents_api_cache_deletes'][] = $group . ':' . $key;
	return true;
}

function wp_cache_set( string $key, mixed $value, string $group = '', int $expire = 0 ): bool {
	unset( $value, $expire );
	$GLOBALS['__agents_api_cache_sets'][] = $group . ':' . $key;
	return true;
}

function wp_debug_backtrace_summary( string $ignore_class = '', int $skip_frames = 0, bool $pretty = true ): string {
	unset( $ignore_class, $skip_frames, $pretty );
	return '';
}

function integration_db(): wpdb {
	$db = new wpdb(
		(string) getenv( 'AGENTS_API_DB_USER' ),
		(string) getenv( 'AGENTS_API_DB_PASSWORD' ),
		(string) getenv( 'AGENTS_API_DB_NAME' ),
		(string) getenv( 'AGENTS_API_DB_HOST' )
	);
	$db->set_prefix( 'aatest_' );
	$db->set_blog_id( 1 );
	$db->siteid = 1;
	if ( ! $db->dbh ) {
		throw new RuntimeException( 'Could not connect to the integration database.' );
	}
	return $db;
}

function integration_schema( wpdb $db ): void {
	$db->query( 'DROP TABLE IF EXISTS aatest_sitemeta' );
	$db->query( 'DROP TABLE IF EXISTS aatest_options' );
	$db->query( "CREATE TABLE aatest_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT, option_name varchar(191) NOT NULL DEFAULT '', option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT 'yes', PRIMARY KEY (option_id), UNIQUE KEY option_name (option_name)) ENGINE=InnoDB" );
	$db->query( "CREATE TABLE aatest_sitemeta (meta_id bigint unsigned NOT NULL AUTO_INCREMENT, site_id bigint unsigned NOT NULL DEFAULT 0, meta_key varchar(255) DEFAULT NULL, meta_value longtext DEFAULT NULL, PRIMARY KEY (meta_id), KEY meta_key (meta_key(191)), KEY site_id (site_id)) ENGINE=InnoDB" );
}

function integration_store(): WP_Agent_Option_Run_Control_Store {
	$db = integration_db();
	$store = new WP_Agent_Option_Run_Control_Store( $db );
	WP_Agent_Run_Control::set_store( $store );
	return $store;
}

function integration_conversation_store( WP_Agent_Workspace_Scope $workspace ): WP_Agent_Conversation_Store {
	return new class( $workspace ) implements WP_Agent_Conversation_Store {
		public function __construct( private WP_Agent_Workspace_Scope $workspace ) {}
		public function create_session( WP_Agent_Workspace_Scope $workspace, int $user_id, string $agent_slug = '', array $metadata = array(), string $context = 'chat' ): string {
			unset( $workspace, $user_id, $agent_slug, $metadata, $context );
			return '';
		}
		public function list_sessions( WP_Agent_Workspace_Scope $workspace, int $user_id, array $args = array() ): array {
			unset( $workspace, $user_id, $args );
			return array();
		}
		public function get_session( string $session_id ): ?array {
			$owners = array( 'race-a' => '101', 'race-b' => '202', 'queue-session' => '101' );
			if ( ! isset( $owners[ $session_id ] ) ) {
				return null;
			}
			return array(
				'session_id'     => $session_id,
				'workspace_type' => $this->workspace->workspace_type,
				'workspace_id'   => $this->workspace->workspace_id,
				'owner_type'     => 'user',
				'owner_key'      => $owners[ $session_id ],
			);
		}
		public function update_session( string $session_id, array $messages, array $metadata = array(), string $provider = '', string $model = '', ?string $provider_response_id = null ): bool {
			unset( $session_id, $messages, $metadata, $provider, $model, $provider_response_id );
			return true;
		}
		public function delete_session( string $session_id ): bool {
			unset( $session_id );
			return true;
		}
		public function get_recent_pending_session( WP_Agent_Workspace_Scope $workspace, int $user_id, int $seconds = 600, string $context = 'chat', ?int $token_id = null ): ?array {
			unset( $workspace, $user_id, $seconds, $context, $token_id );
			return null;
		}
		public function update_title( string $session_id, string $title ): bool {
			unset( $session_id, $title );
			return true;
		}
	};
}

/** @return array<int,array<string,mixed>> */
function integration_pair( callable $worker ): array {
	$dir = sys_get_temp_dir() . '/agents-api-race-' . bin2hex( random_bytes( 6 ) );
	mkdir( $dir, 0700 );
	$pids = array();
	for ( $index = 0; $index < 2; ++$index ) {
		$pid = pcntl_fork();
		if ( 0 === $pid ) {
			file_put_contents( $dir . '/ready-' . $index, '1' );
			while ( ! is_file( $dir . '/go' ) ) {
				usleep( 1000 );
			}
			try {
				$result = $worker( $index );
			} catch ( Throwable $error ) {
				$result = array( 'exception' => $error->getMessage() );
			}
			file_put_contents( $dir . '/result-' . $index, json_encode( $result ) );
			exit( 0 );
		}
		$pids[] = $pid;
	}
	$deadline = microtime( true ) + 10;
	while ( ( ! is_file( $dir . '/ready-0' ) || ! is_file( $dir . '/ready-1' ) ) && microtime( true ) < $deadline ) {
		usleep( 1000 );
	}
	touch( $dir . '/go' );
	foreach ( $pids as $pid ) {
		pcntl_waitpid( $pid, $status );
	}
	$results = array();
	for ( $index = 0; $index < 2; ++$index ) {
		$decoded = json_decode( (string) file_get_contents( $dir . '/result-' . $index ), true );
		$results[] = is_array( $decoded ) ? $decoded : array();
	}
	foreach ( glob( $dir . '/*' ) ?: array() as $file ) {
		unlink( $file );
	}
	rmdir( $dir );
	return $results;
}

function integration_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	echo "  PASS {$message}\n";
}

function integration_snapshot( WP_Agent_Option_Run_Control_Store $store, WP_Agent_Workspace_Scope $workspace ): array {
	$result = $store->mutate_workspace_state(
		'agents_api_chat_run_control',
		$workspace,
		static fn( array $state ): array => array( 'state' => $state, 'result' => $state )
	);
	return is_array( $result ) ? $result : array();
}

echo "run-control-option-store-integration\n";
$GLOBALS['__agents_api_integration_multisite'] = true;
$GLOBALS['__agents_api_cache_deletes'] = array();
$GLOBALS['__agents_api_cache_sets'] = array();
$setup = integration_db();
integration_schema( $setup );
$setup->close();

$workspace = WP_Agent_Workspace_Scope::from_parts( 'network', 'integration-' . bin2hex( random_bytes( 6 ) ) );

integration_pair(
	static function ( int $index ): array {
		unset( $index );
		$store = integration_store();
		$count = $store->mutate_state(
			'integration_site_counter',
			static function ( array $state ): array {
				$current = (int) ( $state['runs']['counter']['value'] ?? 0 );
				usleep( 100000 );
				$state['runs']['counter'] = array( 'value' => $current + 1 );
				return array( 'state' => $state, 'result' => $current + 1 );
			}
		);
		return array( 'count' => $count );
	}
);
$site_store = integration_store();
$site_state = $site_store->mutate_state( 'integration_site_counter', static fn( array $state ): array => array( 'state' => $state, 'result' => $state ) );
integration_assert( 2 === ( $site_state['runs']['counter']['value'] ?? 0 ), 'site option mutex serializes two independent connection mutations' );

$lease_race = integration_pair(
	static function ( int $index ): array {
		$store = integration_store();
		if ( 1 === $index ) {
			usleep( 15200000 );
		}
		try {
			$value = $store->mutate_state(
				'integration_lease_race',
				static function ( array $state ) use ( $index ): array {
					if ( 0 === $index ) {
						usleep( 16500000 );
					}
					$state['runs']['winner'] = array( 'value' => $index );
					return array( 'state' => $state, 'result' => $index );
				}
			);
			return array( 'value' => $value, 'error' => '' );
		} catch ( RuntimeException $error ) {
			return array( 'value' => null, 'error' => $error->getMessage() );
		}
	}
);
$lease_state = $site_store->mutate_state( 'integration_lease_race', static fn( array $state ): array => array( 'state' => $state, 'result' => $state ) );
integration_assert( 1 === ( $lease_state['runs']['winner']['value'] ?? null ), 'expired lease holder cannot overwrite a conditional takeover winner' );
integration_assert( str_contains( (string) ( $lease_race[0]['error'] ?? '' ), 'ownership was lost' ), 'expired lease holder fails closed before stale state write' );

$race = integration_pair(
	static function ( int $index ) use ( $workspace ): array {
		integration_store();
		$conversation_store = integration_conversation_store( $workspace );
		$owner   = 0 === $index ? array( 'type' => 'user', 'key' => '101' ) : array( 'type' => 'user', 'key' => '202' );
		$session = 0 === $index ? 'race-a' : 'race-b';
		$result  = WP_Agent_Chat_Run_Control::start_run( 'shared-run', $session, array(), $workspace, $owner, $conversation_store );
		return array(
			'error'      => $result instanceof WP_Error ? $result->get_error_code() : '',
			'executions' => $result instanceof WP_Error ? 0 : 1,
		);
	}
);
if ( 1 !== array_sum( array_column( $race, 'executions' ) ) ) {
	echo '  race diagnostics: ' . json_encode( $race ) . "\n";
}
integration_assert( 1 === array_sum( array_column( $race, 'executions' ) ), 'same run_id race admits exactly one runner execution' );
integration_assert( 1 === count( array_filter( array_column( $race, 'error' ), static fn( string $error ): bool => 'agents_chat_run_owner_forbidden' === $error ) ), 'same run_id race rejects the losing owner' );

$same_owner_workspace = WP_Agent_Workspace_Scope::from_parts( 'network', 'same-owner-' . bin2hex( random_bytes( 6 ) ) );
$same_owner_race = integration_pair(
	static function ( int $index ) use ( $same_owner_workspace ): array {
		unset( $index );
		integration_store();
		$result = WP_Agent_Chat_Run_Control::start_run( 'same-owner-run', 'race-a', array(), $same_owner_workspace, array( 'type' => 'user', 'key' => '101' ), integration_conversation_store( $same_owner_workspace ) );
		return array(
			'error'      => $result instanceof WP_Error ? $result->get_error_code() : '',
			'executions' => $result instanceof WP_Error ? 0 : 1,
		);
	}
);
integration_assert( 1 === array_sum( array_column( $same_owner_race, 'executions' ) ), 'same-owner duplicate run_id race executes only one runner' );
integration_assert( 1 === count( array_filter( array_column( $same_owner_race, 'error' ), static fn( string $error ): bool => 'agents_chat_run_already_started' === $error ) ), 'same-owner duplicate run_id race rejects the losing request' );

$pending_workspace = WP_Agent_Workspace_Scope::from_parts( 'network', 'pending-' . bin2hex( random_bytes( 6 ) ) );
$pending_race = integration_pair(
	static function ( int $index ) use ( $pending_workspace ): array {
		integration_store();
		$token = 'pending-claim-' . $index;
		$result = WP_Agent_Chat_Run_Control::claim_pending_run( 'pending-run', $token, $pending_workspace, array( 'type' => 'user', 'key' => '101' ) );
		return array(
			'token'      => $token,
			'error'      => $result instanceof WP_Error ? $result->get_error_code() : '',
			'executions' => $result instanceof WP_Error ? 0 : 1,
		);
	}
);
integration_assert( 1 === array_sum( array_column( $pending_race, 'executions' ) ), 'pre-session run reservation admits exactly one arbitrary handler execution' );
$pending_winner = current( array_filter( $pending_race, static fn( array $item ): bool => 1 === $item['executions'] ) );
$pending_token = is_array( $pending_winner ) ? (string) ( $pending_winner['token'] ?? '' ) : '';
integration_store();
$pending_bound = WP_Agent_Chat_Run_Control::start_run( 'pending-run', 'race-a', array( '_claim_token' => $pending_token ), $pending_workspace, array( 'type' => 'user', 'key' => '101' ), integration_conversation_store( $pending_workspace ) );
integration_assert( is_array( $pending_bound ) && 'race-a' === ( $pending_bound['session_id'] ?? '' ), 'winning pre-session reservation binds its canonical session' );

$store = integration_store();
$conversation_store = integration_conversation_store( $workspace );
$owner = array( 'type' => 'user', 'key' => '101' );
WP_Agent_Chat_Run_Control::start_run( 'queue-run', 'queue-session', array(), $workspace, $owner, $conversation_store );
foreach ( array( 'first', 'second' ) as $message ) {
	WP_Agent_Chat_Run_Control::queue_message( array( 'session_id' => 'queue-session', 'run_id' => 'queue-run', 'message' => $message ), $workspace, $owner, $conversation_store );
}

$claims = integration_pair(
	static function ( int $index ) use ( $workspace, $owner ): array {
		unset( $index );
		integration_store();
		$claimed = WP_Agent_Chat_Run_Control::claim_queued_messages( 'queue-session', $workspace, $owner, integration_conversation_store( $workspace ) );
		return array( 'messages' => array_column( $claimed, 'message' ) );
	}
);
$claimed_messages = array_merge( $claims[0]['messages'] ?? array(), $claims[1]['messages'] ?? array() );
sort( $claimed_messages );
integration_assert( array( 'first', 'second' ) === $claimed_messages, 'double claim returns each queued message exactly once' );
integration_assert( ! isset( integration_snapshot( integration_store(), $workspace )['queues']['queue-session'] ), 'double claim leaves no resurrected queue' );

WP_Agent_Chat_Run_Control::queue_message( array( 'session_id' => 'queue-session', 'run_id' => 'queue-run', 'message' => 'before' ), $workspace, $owner, $conversation_store );
$enqueue_claim = integration_pair(
	static function ( int $index ) use ( $workspace, $owner ): array {
		integration_store();
		$conversation_store = integration_conversation_store( $workspace );
		if ( 0 === $index ) {
			$result = WP_Agent_Chat_Run_Control::queue_message( array( 'session_id' => 'queue-session', 'run_id' => 'queue-run', 'message' => 'during' ), $workspace, $owner, $conversation_store );
			return array( 'queued' => ! $result instanceof WP_Error );
		}
		$claimed = WP_Agent_Chat_Run_Control::claim_queued_messages( 'queue-session', $workspace, $owner, $conversation_store );
		return array( 'messages' => array_column( $claimed, 'message' ) );
	}
);
$remaining = WP_Agent_Chat_Run_Control::claim_queued_messages( 'queue-session', $workspace, $owner, $conversation_store );
$all_messages = array_merge( $enqueue_claim[1]['messages'] ?? array(), array_column( $remaining, 'message' ) );
sort( $all_messages );
integration_assert( ! empty( $enqueue_claim[0]['queued'] ), 'enqueue concurrent with claim succeeds' );
integration_assert( array( 'before', 'during' ) === $all_messages, 'enqueue concurrent with claim loses or resurrects no messages' );

$db = integration_db();
$option_key = 'agents_api_chat_run_control_workspace_' . hash( 'sha256', $workspace->key() );
$lock_key = '_agents_api_run_lock_' . hash( 'sha256', 'workspace:' . $db->sitemeta . ':1:' . $option_key );
$expired = json_encode( array( 'token' => 'expired', 'expires_at' => microtime( true ) - 60 ) );
$db->query( $db->prepare( "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $db->options, $lock_key, $expired ) );
$stale_store = new WP_Agent_Option_Run_Control_Store( $db );
$stale_result = $stale_store->mutate_workspace_state( 'agents_api_chat_run_control', $workspace, static fn( array $state ): array => array( 'state' => $state, 'result' => 'recovered' ) );
integration_assert( 'recovered' === $stale_result, 'expired mutex is taken over conditionally' );
integration_assert( null === $db->get_var( $db->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $db->options, $lock_key ) ), 'token-checked release removes the recovered mutex' );

$active = json_encode( array( 'token' => 'active', 'expires_at' => microtime( true ) + 60 ) );
$db->query( $db->prepare( "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $db->options, $lock_key, $active ) );
$mutation_ran = false;
try {
	$stale_store->mutate_workspace_state(
		'agents_api_chat_run_control',
		$workspace,
		static function ( array $state ) use ( &$mutation_ran ): array {
			$mutation_ran = true;
			return array( 'state' => $state, 'result' => null );
		}
	);
	$timed_out = false;
} catch ( RuntimeException $error ) {
	$timed_out = str_contains( $error->getMessage(), 'timed out' );
}
integration_assert( $timed_out && ! $mutation_ran, 'active mutex contention fails closed before mutation' );
$db->query( $db->prepare( 'DELETE FROM %i WHERE option_name = %s', $db->options, $lock_key ) );

try {
	$stale_store->mutate_workspace_state(
		'agents_api_chat_run_control',
		$workspace,
		static function ( array $state ): array {
			unset( $state );
			throw new RuntimeException( 'mutation failed' );
		}
	);
} catch ( RuntimeException $error ) {
	$callback_failed = 'mutation failed' === $error->getMessage();
}
integration_assert( ! empty( $callback_failed ) && null === $db->get_var( $db->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $db->options, $lock_key ) ), 'exceptional mutation releases only its own mutex token' );

$corrupt_key = 'integration_corrupt_state';
$db->query( $db->prepare( "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $db->options, $corrupt_key, 'not-serialized-state' ) );
try {
	$stale_store->mutate_state( $corrupt_key, static fn( array $state ): array => array( 'state' => $state, 'result' => null ) );
	$corrupt_failed = false;
} catch ( RuntimeException $error ) {
	$corrupt_failed = str_contains( $error->getMessage(), 'corrupt' );
}
integration_assert( $corrupt_failed && 'not-serialized-state' === $db->get_var( $db->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $db->options, $corrupt_key ) ), 'corrupt stored state fails closed without overwrite' );
$malformed_key = 'integration_malformed_state';
$malformed_value = serialize( array( 'runs' => 'invalid', 'queues' => array(), 'events' => array() ) );
$db->query( $db->prepare( "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $db->options, $malformed_key, $malformed_value ) );
try {
	$stale_store->mutate_state( $malformed_key, static fn( array $state ): array => array( 'state' => $state, 'result' => null ) );
	$malformed_failed = false;
} catch ( RuntimeException $error ) {
	$malformed_failed = str_contains( $error->getMessage(), 'corrupt' );
}
integration_assert( $malformed_failed && $malformed_value === $db->get_var( $db->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $db->options, $malformed_key ) ), 'malformed state members fail closed without partial normalization' );

$GLOBALS['__agents_api_integration_multisite'] = false;
$single_workspace = WP_Agent_Workspace_Scope::from_parts( 'site', 'single-site-workspace' );
$single_key = 'single_site_state_workspace_' . hash( 'sha256', $single_workspace->key() );
$single_result = $stale_store->mutate_workspace_state(
	'single_site_state',
	$single_workspace,
	static function ( array $state ): array {
		$state['runs']['single'] = array( 'value' => 'stored' );
		return array( 'state' => $state, 'result' => 'stored' );
	}
);
$single_value = $db->get_var( $db->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $db->options, $single_key ) );
$single_network_value = $db->get_var( $db->prepare( 'SELECT meta_value FROM %i WHERE site_id = 1 AND meta_key = %s', $db->sitemeta, $single_key ) );
integration_assert( 'stored' === $single_result && is_string( $single_value ) && null === $single_network_value, 'single-site workspace mutation uses the existing site options table' );
integration_assert( in_array( 'options:alloptions', $GLOBALS['__agents_api_cache_deletes'], true ) && in_array( 'options:notoptions', $GLOBALS['__agents_api_cache_deletes'], true ) && in_array( 'options:' . $single_key, $GLOBALS['__agents_api_cache_sets'], true ), 'direct option writes invalidate aggregate caches and publish fresh state' );
$GLOBALS['__agents_api_integration_multisite'] = true;

$db->query( 'DROP TABLE IF EXISTS aatest_sitemeta' );
$db->query( 'DROP TABLE IF EXISTS aatest_options' );
$db->close();
echo "All option-store integration assertions passed.\n";

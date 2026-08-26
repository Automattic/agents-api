<?php
/**
 * Permission parity across direct, REST, and JSON-RPC agents/chat transports.
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "chat-permission-parity-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

$GLOBALS['__agents_api_smoke_current_user_id'] = 7;
$GLOBALS['__agents_api_smoke_can_manage']      = false;
$GLOBALS['__agents_api_smoke_chat_role']       = null;

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		public function __construct( private array $params = array(), private array $json = array() ) {}
		public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
		public function get_json_params(): array { return $this->json; }
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) $GLOBALS['__agents_api_smoke_current_user_id'];
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		unset( $capability );
		return (bool) $GLOBALS['__agents_api_smoke_can_manage'];
	}
} else {
	add_filter(
		'user_has_cap',
		static function ( array $allcaps ): array {
			$allcaps['manage_options'] = (bool) $GLOBALS['__agents_api_smoke_can_manage'];
			return $allcaps;
		}
	);
}

agents_api_smoke_require_module();

$access_store = new class() implements WP_Agent_Access_Store, WP_Agent_Principal_Access_Store {
	public function grant_access( WP_Agent_Access_Grant $grant ): WP_Agent_Access_Grant { return $grant; }
	public function revoke_access( string $agent_id, int $user_id, ?string $workspace_id = null ): bool { return false; }
	public function get_access( string $agent_id, int $user_id, ?string $workspace_id = null ): ?WP_Agent_Access_Grant { return null; }
	public function get_agent_ids_for_user( int $user_id, ?string $minimum_role = null, ?string $workspace_id = null ): array { return array(); }
	public function get_users_for_agent( string $agent_id, ?string $workspace_id = null ): array { return array(); }
	public function get_access_for_principal( string $agent_id, AgentsAPI\AI\WP_Agent_Execution_Principal $principal, ?string $workspace_id = null ): ?WP_Agent_Access_Grant {
		$role = $GLOBALS['__agents_api_smoke_chat_role'];
		if ( ! is_string( $role ) || 'support-agent' !== $agent_id || 'audience:public' !== $principal->audience_id || 'site:42' !== $workspace_id ) {
			return null;
		}

		return new WP_Agent_Access_Grant( $agent_id, 0, $role, $workspace_id, null, null, null, array(), 'audience:public' );
	}
	public function get_agent_ids_for_principal( AgentsAPI\AI\WP_Agent_Execution_Principal $principal, ?string $minimum_role = null, ?string $workspace_id = null ): array {
		unset( $minimum_role );
		return 'audience:public' === $principal->audience_id && 'site:42' === $workspace_id ? array( 'support-agent' ) : array();
	}
};

add_filter(
	'wp_agent_access_store',
	static fn( $store ) => $store instanceof WP_Agent_Access_Store ? $store : $access_store
);

$direct_input = array(
	'agent'        => 'support-agent',
	'message'      => 'Hello',
	'workspace_id' => 'site:42',
	'client_id'    => 'native-1',
);
$rest_request = new WP_REST_Request(
	array(
		'agent'        => 'support-agent',
		'message'      => 'Hello',
		'workspace_id' => 'site:42',
		'client_id'    => 'browser-1',
	)
);
$jsonrpc_request = new WP_REST_Request(
	array(
		'agent_id'     => 'support-agent',
		'workspace_id' => 'site:42',
		'client_id'    => 'rpc-1',
	),
	array(
		'jsonrpc' => '2.0',
		'id'      => 'permission-check',
		'method'  => 'message/send',
		'params'  => array(
			'id'      => 'permission-check',
			'message' => array(
				'role'  => 'user',
				'parts' => array( array( 'type' => 'text', 'text' => 'Hello' ) ),
			),
		),
	)
);

$is_allowed = static fn( $result ): bool => true === $result;
$assert_matrix = static function ( string $label, bool $expected ) use ( &$failures, &$passes, $direct_input, $rest_request, $jsonrpc_request, $is_allowed ): void {
	agents_api_smoke_assert_equals( $expected, AgentsAPI\AI\Channels\agents_chat_permission( $direct_input ), $label . ' direct ability permission', $failures, $passes );
	agents_api_smoke_assert_equals( $expected, $is_allowed( AgentsAPI\AI\Channels\agents_frontend_chat_rest_permission( $rest_request ) ), $label . ' REST permission', $failures, $passes );
	agents_api_smoke_assert_equals( $expected, $is_allowed( AgentsAPI\AI\Channels\agents_chat_jsonrpc_permission( $jsonrpc_request ) ), $label . ' JSON-RPC permission', $failures, $passes );
};

$GLOBALS['__agents_api_smoke_chat_role'] = WP_Agent_Access_Grant::ROLE_OPERATOR;
$assert_matrix( 'operator', true );

$GLOBALS['__agents_api_smoke_chat_role'] = WP_Agent_Access_Grant::ROLE_VIEWER;
$assert_matrix( 'viewer', false );

$GLOBALS['__agents_api_smoke_chat_role'] = null;
$assert_matrix( 'ungranted principal', false );

$GLOBALS['__agents_api_smoke_can_manage'] = true;
$assert_matrix( 'administrator', true );

$GLOBALS['__agents_api_smoke_can_manage'] = false;
$GLOBALS['__agents_api_smoke_chat_role']  = WP_Agent_Access_Grant::ROLE_OPERATOR;
$runtime_principal = AgentsAPI\AI\WP_Agent_Execution_Principal::runtime( 'runtime-1', 'support-agent' )->to_array();
agents_api_smoke_assert_equals( false, AgentsAPI\AI\Channels\agents_chat_permission( $direct_input + array( 'principal' => $runtime_principal ) ), 'operator grant cannot attest a caller-supplied runtime principal', $failures, $passes );
add_filter( 'agents_chat_runtime_principal_permission', static fn() => true );
agents_api_smoke_assert_equals( true, AgentsAPI\AI\Channels\agents_chat_permission( $direct_input + array( 'principal' => $runtime_principal ) ), 'runtime principal filter can attest an explicit runtime principal', $failures, $passes );

$GLOBALS['__agents_api_smoke_chat_role'] = null;
add_filter( 'agents_chat_permission', static fn() => true );
$assert_matrix( 'host filter', true );

add_filter( 'agents_frontend_chat_rest_permission', static fn() => false );
add_filter( 'agents_chat_jsonrpc_permission', static fn() => false );
agents_api_smoke_assert_equals( true, AgentsAPI\AI\Channels\agents_chat_permission( $direct_input ), 'canonical host grant remains allowed', $failures, $passes );
agents_api_smoke_assert_equals( false, $is_allowed( AgentsAPI\AI\Channels\agents_frontend_chat_rest_permission( $rest_request ) ), 'REST filter can narrow canonical permission', $failures, $passes );
agents_api_smoke_assert_equals( false, $is_allowed( AgentsAPI\AI\Channels\agents_chat_jsonrpc_permission( $jsonrpc_request ) ), 'JSON-RPC filter can narrow canonical permission', $failures, $passes );

agents_api_smoke_finish( 'chat permission parity', $failures, $passes );

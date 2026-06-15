<?php
/**
 * Pure-PHP smoke test for the frontend chat REST adapter.
 *
 * Run with: php tests/frontend-chat-rest-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "frontend-chat-rest-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

$GLOBALS['__agents_api_smoke_current_user_id'] = 7;
$GLOBALS['__agents_api_smoke_routes']          = array();
$GLOBALS['__agents_api_smoke_abilities']       = array();
$GLOBALS['__agents_api_smoke_categories']      = array();
$GLOBALS['__agents_api_smoke_allow_chat']      = false;

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
		private array $params = array();

		public function __construct( $method_or_params = array(), string $route = '' ) {
			unset( $route );
			$this->params = is_array( $method_or_params ) ? $method_or_params : array();
		}
		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}
		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public function __construct( public mixed $data ) {}
		public function get_data() { return $this->data; }
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $value ): WP_REST_Response {
		return $value instanceof WP_REST_Response ? $value : new WP_REST_Response( $value );
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args ): void {
		$GLOBALS['__agents_api_smoke_routes'][ $namespace . $route ] = $args;
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
		return (bool) ( $GLOBALS['__agents_api_smoke_can_manage'] ?? false );
	}
}

if ( ! function_exists( 'wp_has_ability_category' ) ) {
	function wp_has_ability_category( string $category ): bool {
		return isset( $GLOBALS['__agents_api_smoke_categories'][ $category ] );
	}
}

if ( ! function_exists( 'wp_register_ability_category' ) ) {
	function wp_register_ability_category( string $category, array $args ): void {
		$GLOBALS['__agents_api_smoke_categories'][ $category ] = $args;
	}
}

if ( ! function_exists( 'wp_has_ability' ) ) {
	function wp_has_ability( string $ability ): bool {
		return isset( $GLOBALS['__agents_api_smoke_abilities'][ $ability ] );
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( string $ability, array $args ): void {
		$GLOBALS['__agents_api_smoke_abilities'][ $ability ] = $args;
	}
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( string $name ) {
		return $GLOBALS['__agents_api_smoke_abilities'][ $name ] ?? null;
	}
}

function agents_api_smoke_rest_request( array $params ): WP_REST_Request {
	$request = new WP_REST_Request( 'POST', '/agents-api/v1/chat' );
	foreach ( $params as $key => $value ) {
		$request->set_param( (string) $key, $value );
	}
	return $request;
}

agents_api_smoke_require_module();

$grant = new WP_Agent_Access_Grant( 'support-agent', 0, WP_Agent_Access_Grant::ROLE_OPERATOR, 'site:42', null, null, null, array(), 'audience:public' );

$access_store = new class( $grant ) implements WP_Agent_Access_Store, WP_Agent_Principal_Access_Store {
	public function __construct( private WP_Agent_Access_Grant $grant ) {}
	public function grant_access( WP_Agent_Access_Grant $grant ): WP_Agent_Access_Grant { $this->grant = $grant; return $grant; }
	public function revoke_access( string $agent_id, int $user_id, ?string $workspace_id = null ): bool { return false; }
	public function get_access( string $agent_id, int $user_id, ?string $workspace_id = null ): ?WP_Agent_Access_Grant {
		return $this->grant->agent_id === $agent_id && $this->grant->user_id === $user_id && $this->grant->workspace_id === $workspace_id ? $this->grant : null;
	}
	public function get_agent_ids_for_user( int $user_id, ?string $minimum_role = null, ?string $workspace_id = null ): array { return array(); }
	public function get_users_for_agent( string $agent_id, ?string $workspace_id = null ): array { return array(); }
	public function get_access_for_principal( string $agent_id, AgentsAPI\AI\WP_Agent_Execution_Principal $principal, ?string $workspace_id = null ): ?WP_Agent_Access_Grant {
		return $this->grant->agent_id === $agent_id && $this->grant->audience_id === $principal->audience_id && $this->grant->workspace_id === $workspace_id ? $this->grant : null;
	}
	public function get_agent_ids_for_principal( AgentsAPI\AI\WP_Agent_Execution_Principal $principal, ?string $minimum_role = null, ?string $workspace_id = null ): array {
		unset( $minimum_role );
		return $this->grant->audience_id === $principal->audience_id && $this->grant->workspace_id === $workspace_id ? array( $this->grant->agent_id ) : array();
	}
};

$access_contexts = array();
add_filter(
	'wp_agent_access_store',
	static function ( $store, array $context = array() ) use ( $access_store, &$access_contexts ) {
		$access_contexts[] = $context;
		return $store instanceof WP_Agent_Access_Store ? $store : $access_store;
	}
);
add_filter(
	'agents_chat_permission',
	static function (): bool {
		return (bool) $GLOBALS['__agents_api_smoke_allow_chat'];
	}
);

do_action( 'rest_api_init' );

$chat_route = $GLOBALS['__agents_api_smoke_routes']['agents-api/v1/chat'] ?? null;
if ( null === $chat_route && function_exists( 'rest_get_server' ) ) {
	$routes     = rest_get_server()->get_routes();
	$chat_route = $routes['/agents-api/v1/chat'][0] ?? null;
}

agents_api_smoke_assert_equals( true, is_array( $chat_route ), 'chat REST route registers', $failures, $passes );
$route_methods = $chat_route['methods'] ?? null;
if ( is_array( $route_methods ) ) {
	$route_methods = isset( $route_methods['POST'] ) ? 'POST' : $route_methods;
}
agents_api_smoke_assert_equals( 'POST', $route_methods, 'chat REST route uses POST', $failures, $passes );
agents_api_smoke_assert_equals( true, isset( $chat_route['args']['attachments']['items'] ), 'chat REST args derive attachment schema', $failures, $passes );

$captured = array();
$ability  = new class( $captured ) {
	private array $captured;

	public function __construct( array &$captured ) {
		$this->captured =& $captured;
	}

	public function execute( array $input ): array {
		$this->captured = $input;
		return array(
			'session_id' => 's-1',
			'reply'      => 'hello from adapter',
			'metadata'   => array(
				'citations'    => array( array( 'url' => 'https://example.test/rest-source' ) ),
				'source_items' => array( array( 'id' => 'rest-source-item-1' ) ),
			),
		);
	}
};

$GLOBALS['__agents_api_smoke_abilities'][ AgentsAPI\AI\Channels\AGENTS_CHAT_ABILITY ] = $ability;

add_filter(
	'wp_agent_chat_handler',
	static function ( $handler, array $input ) use ( $ability ) {
		unset( $handler );
		return static function () use ( $ability, $input ): array {
			return $ability->execute( $input );
		};
	},
	10,
	2
);

$request = agents_api_smoke_rest_request(
	array(
		'agent'          => 'Support Agent',
		'message'        => 'Hi there',
		'session_id'     => 'existing-session',
		'attachments'    => array( array( 'type' => 'image' ) ),
		'client_context' => array(
			'client_name'  => 'block-chat',
			'host_context' => array(
				'opaque_id' => 'selected-material-123',
				'hints'     => array( 'current-item' ),
			),
		),
		'workspace_id'   => 'site:42',
		'client_id'      => 'browser-1',
	)
);

$permission = AgentsAPI\AI\Channels\agents_frontend_chat_rest_permission( $request );
agents_api_smoke_assert_equals( true, $permission, 'permission allows operator grant', $failures, $passes );

$GLOBALS['__agents_api_smoke_allow_chat'] = true;
$response = AgentsAPI\AI\Channels\agents_frontend_chat_rest_dispatch( $request );
$GLOBALS['__agents_api_smoke_allow_chat'] = false;
$response_data = $response instanceof WP_REST_Response && method_exists( $response, 'get_data' ) ? $response->get_data() : ( $response->data ?? null );
agents_api_smoke_assert_equals( true, $response instanceof WP_REST_Response, 'dispatch returns REST response', $failures, $passes );
agents_api_smoke_assert_equals( 'hello from adapter', $response_data['reply'] ?? null, 'dispatch returns ability reply', $failures, $passes );
agents_api_smoke_assert_equals( 'https://example.test/rest-source', $response_data['metadata']['citations'][0]['url'] ?? null, 'dispatch preserves citation metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'rest-source-item-1', $response_data['metadata']['source_items'][0]['id'] ?? null, 'dispatch preserves caller-owned result metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'support-agent', $captured['agent'] ?? null, 'dispatch sanitizes agent slug', $failures, $passes );
agents_api_smoke_assert_equals( 'Hi there', $captured['message'] ?? null, 'dispatch forwards message', $failures, $passes );
agents_api_smoke_assert_equals( 'rest', $captured['client_context']['source'] ?? null, 'dispatch marks REST source', $failures, $passes );
agents_api_smoke_assert_equals( 'block-chat', $captured['client_context']['client_name'] ?? null, 'dispatch preserves client name', $failures, $passes );
agents_api_smoke_assert_equals( 'selected-material-123', $captured['client_context']['host_context']['opaque_id'] ?? null, 'dispatch preserves caller-owned context metadata', $failures, $passes );
agents_api_smoke_assert_equals( array( 'current-item' ), $captured['client_context']['host_context']['hints'] ?? null, 'dispatch preserves nested caller-owned context metadata', $failures, $passes );
$rest_access_scope = AgentsAPI\AI\Channels\agents_frontend_chat_rest_scope( $request );
agents_api_smoke_assert_equals( 'selected-material-123', $rest_access_scope['client_context']['host_context']['opaque_id'] ?? null, 'access scope receives caller-owned context metadata', $failures, $passes );
agents_api_smoke_assert_equals( array( 'current-item' ), $rest_access_scope['request_metadata']['client_context']['host_context']['hints'] ?? null, 'access metadata receives nested caller-owned context metadata', $failures, $passes );

$blocked = AgentsAPI\AI\Channels\agents_frontend_chat_rest_permission(
	agents_api_smoke_rest_request(
		array(
			'agent'        => 'other-agent',
			'message'      => 'Nope',
			'workspace_id' => 'site:42',
		)
	)
);
agents_api_smoke_assert_equals( true, $blocked instanceof WP_Error, 'permission blocks ungranted agent', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_frontend_chat_forbidden', $blocked->get_error_code(), 'permission returns forbidden code', $failures, $passes );

add_filter( 'agents_frontend_chat_rest_permission', static fn() => true );
$filtered = AgentsAPI\AI\Channels\agents_frontend_chat_rest_permission(
	agents_api_smoke_rest_request( array( 'agent' => 'other-agent', 'message' => 'Allowed by host' ) )
);
agents_api_smoke_assert_equals( true, $filtered, 'permission is filterable for hosts', $failures, $passes );

$invalid = AgentsAPI\AI\Channels\agents_frontend_chat_rest_dispatch( agents_api_smoke_rest_request( array( 'agent' => '', 'message' => '' ) ) );
agents_api_smoke_assert_equals( true, $invalid instanceof WP_Error, 'dispatch rejects empty input', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_frontend_chat_invalid_input', $invalid->get_error_code(), 'dispatch rejects empty input code', $failures, $passes );

agents_api_smoke_finish( 'frontend chat REST adapter', $failures, $passes );

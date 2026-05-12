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

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): array { return $this->data; }
}

class WP_REST_Request {
	public function __construct( private array $params = array() ) {}
	public function get_param( string $key ) {
		return $this->params[ $key ] ?? null;
	}
}

class WP_REST_Response {
	public function __construct( public mixed $data ) {}
}

function rest_ensure_response( $value ): WP_REST_Response {
	return $value instanceof WP_REST_Response ? $value : new WP_REST_Response( $value );
}

function register_rest_route( string $namespace, string $route, array $args ): void {
	$GLOBALS['__agents_api_smoke_routes'][ $namespace . $route ] = $args;
}

function get_current_user_id(): int {
	return (int) $GLOBALS['__agents_api_smoke_current_user_id'];
}

function current_user_can( string $capability ): bool {
	unset( $capability );
	return (bool) ( $GLOBALS['__agents_api_smoke_can_manage'] ?? false );
}

function wp_has_ability_category( string $category ): bool {
	return isset( $GLOBALS['__agents_api_smoke_categories'][ $category ] );
}

function wp_register_ability_category( string $category, array $args ): void {
	$GLOBALS['__agents_api_smoke_categories'][ $category ] = $args;
}

function wp_has_ability( string $ability ): bool {
	return isset( $GLOBALS['__agents_api_smoke_abilities'][ $ability ] );
}

function wp_register_ability( string $ability, array $args ): void {
	$GLOBALS['__agents_api_smoke_abilities'][ $ability ] = $args;
}

function wp_get_ability( string $name ) {
	return $GLOBALS['__agents_api_smoke_abilities'][ $name ] ?? null;
}

agents_api_smoke_require_module();

$grant = new WP_Agent_Access_Grant( 'support-agent', 7, WP_Agent_Access_Grant::ROLE_OPERATOR, 'site:42' );

$access_store = new class( $grant ) implements WP_Agent_Access_Store {
	public function __construct( private WP_Agent_Access_Grant $grant ) {}
	public function grant_access( WP_Agent_Access_Grant $grant ): WP_Agent_Access_Grant { $this->grant = $grant; return $grant; }
	public function revoke_access( string $agent_id, int $user_id, ?string $workspace_id = null ): bool { return false; }
	public function get_access( string $agent_id, int $user_id, ?string $workspace_id = null ): ?WP_Agent_Access_Grant {
		return $this->grant->agent_id === $agent_id && $this->grant->user_id === $user_id && $this->grant->workspace_id === $workspace_id ? $this->grant : null;
	}
	public function get_agent_ids_for_user( int $user_id, ?string $minimum_role = null, ?string $workspace_id = null ): array { return array(); }
	public function get_users_for_agent( string $agent_id, ?string $workspace_id = null ): array { return array(); }
};

add_filter( 'wp_agent_access_store', static fn( $store ) => $store instanceof WP_Agent_Access_Store ? $store : $access_store );

do_action( 'rest_api_init' );

agents_api_smoke_assert_equals( true, isset( $GLOBALS['__agents_api_smoke_routes']['agents-api/v1/chat'] ), 'chat REST route registers', $failures, $passes );
agents_api_smoke_assert_equals( 'POST', $GLOBALS['__agents_api_smoke_routes']['agents-api/v1/chat']['methods'] ?? null, 'chat REST route uses POST', $failures, $passes );
agents_api_smoke_assert_equals( true, isset( $GLOBALS['__agents_api_smoke_routes']['agents-api/v1/chat']['args']['attachments']['items'] ), 'chat REST args derive attachment schema', $failures, $passes );

$captured = array();
$ability  = new class( $captured ) {
	private array $captured;

	public function __construct( array &$captured ) {
		$this->captured =& $captured;
	}

	public function execute( array $input ): array {
		$this->captured = $input;
		return array( 'session_id' => 's-1', 'reply' => 'hello from adapter' );
	}
};

$GLOBALS['__agents_api_smoke_abilities'][ AgentsAPI\AI\Channels\AGENTS_CHAT_ABILITY ] = $ability;

$request = new WP_REST_Request(
	array(
		'agent'          => 'Support Agent',
		'message'        => 'Hi there',
		'session_id'     => 'existing-session',
		'attachments'    => array( array( 'type' => 'image' ) ),
		'client_context' => array( 'client_name' => 'block-chat' ),
		'workspace_id'   => 'site:42',
		'client_id'      => 'browser-1',
	)
);

$permission = AgentsAPI\AI\Channels\agents_frontend_chat_rest_permission( $request );
agents_api_smoke_assert_equals( true, $permission, 'permission allows operator grant', $failures, $passes );

$response = AgentsAPI\AI\Channels\agents_frontend_chat_rest_dispatch( $request );
agents_api_smoke_assert_equals( true, $response instanceof WP_REST_Response, 'dispatch returns REST response', $failures, $passes );
agents_api_smoke_assert_equals( 'hello from adapter', $response->data['reply'] ?? null, 'dispatch returns ability reply', $failures, $passes );
agents_api_smoke_assert_equals( 'support-agent', $captured['agent'] ?? null, 'dispatch sanitizes agent slug', $failures, $passes );
agents_api_smoke_assert_equals( 'Hi there', $captured['message'] ?? null, 'dispatch forwards message', $failures, $passes );
agents_api_smoke_assert_equals( 'rest', $captured['client_context']['source'] ?? null, 'dispatch marks REST source', $failures, $passes );
agents_api_smoke_assert_equals( 'block-chat', $captured['client_context']['client_name'] ?? null, 'dispatch preserves client name', $failures, $passes );

$blocked = AgentsAPI\AI\Channels\agents_frontend_chat_rest_permission(
	new WP_REST_Request(
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
	new WP_REST_Request( array( 'agent' => 'other-agent', 'message' => 'Allowed by host' ) )
);
agents_api_smoke_assert_equals( true, $filtered, 'permission is filterable for hosts', $failures, $passes );

$invalid = AgentsAPI\AI\Channels\agents_frontend_chat_rest_dispatch( new WP_REST_Request( array( 'agent' => '', 'message' => '' ) ) );
agents_api_smoke_assert_equals( true, $invalid instanceof WP_Error, 'dispatch rejects empty input', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_frontend_chat_invalid_input', $invalid->get_error_code(), 'dispatch rejects empty input code', $failures, $passes );

agents_api_smoke_finish( 'frontend chat REST adapter', $failures, $passes );

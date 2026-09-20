<?php
/**
 * Pure-PHP smoke test for the frontend conversation session list REST adapter.
 *
 * Run with: php tests/frontend-chat-session-list-rest-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "frontend-chat-session-list-rest-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

$GLOBALS['__agents_api_smoke_current_user_id'] = 7;
$GLOBALS['__agents_api_smoke_routes']          = array();
$GLOBALS['__agents_api_smoke_abilities']       = array();
$GLOBALS['__agents_api_smoke_categories']      = array();
$GLOBALS['__agents_api_smoke_can_manage']      = false;

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

function agents_api_session_list_smoke_rest_request( array $params ): WP_REST_Request {
	$request = new WP_REST_Request( 'GET', '/agents-api/v1/sessions' );
	foreach ( $params as $key => $value ) {
		$request->set_param( (string) $key, $value );
	}
	return $request;
}

agents_api_smoke_require_module();

$captured = array();
$ability  = new class( $captured ) {
	private array $captured;

	public function __construct( array &$captured ) {
		$this->captured =& $captured;
	}

	public function execute( array $input ) {
		$this->captured = $input;
		return array(
			'sessions' => array(
				array(
					'session_id' => 's-1',
					'title'      => 'From the ability',
				),
			),
		);
	}
};

$GLOBALS['__agents_api_smoke_abilities'][ AgentsAPI\Core\Database\Chat\AGENTS_LIST_CONVERSATION_SESSIONS_ABILITY ] = $ability;

do_action( 'rest_api_init' );

$sessions_route = $GLOBALS['__agents_api_smoke_routes']['agents-api/v1/sessions'] ?? null;
if ( null === $sessions_route && function_exists( 'rest_get_server' ) ) {
	$routes         = rest_get_server()->get_routes();
	$sessions_route = $routes['/agents-api/v1/sessions'][0] ?? null;
}

agents_api_smoke_assert_equals( true, is_array( $sessions_route ), 'sessions REST route registers', $failures, $passes );
agents_api_smoke_assert_equals( 'GET', $sessions_route['methods'] ?? null, 'sessions REST route uses GET', $failures, $passes );
agents_api_smoke_assert_equals( true, isset( $sessions_route['args']['workspace_id'] ), 'sessions REST args expose workspace_id', $failures, $passes );
agents_api_smoke_assert_equals( true, isset( $sessions_route['args']['session_owner'] ), 'sessions REST args derive session_owner from the ability input schema', $failures, $passes );

// Permission denied: no capability and no resolvable session owner (logged out).
$GLOBALS['__agents_api_smoke_can_manage']      = false;
$GLOBALS['__agents_api_smoke_current_user_id'] = 0;
$denied                                        = AgentsAPI\AI\Channels\agents_frontend_chat_rest_session_list_permission(
	agents_api_session_list_smoke_rest_request( array() )
);
agents_api_smoke_assert_equals( true, $denied instanceof WP_Error, 'permission denies without read capability or owner', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_frontend_chat_session_list_forbidden', $denied instanceof WP_Error ? $denied->get_error_code() : null, 'permission denial carries forbidden code', $failures, $passes );
agents_api_smoke_assert_equals( 403, $denied instanceof WP_Error ? ( $denied->get_error_data()['status'] ?? null ) : null, 'permission denial carries REST status', $failures, $passes );
$GLOBALS['__agents_api_smoke_current_user_id'] = 7;

// Permission allowed: caller has the generic read capability.
$GLOBALS['__agents_api_smoke_can_manage'] = true;
$allowed                                  = AgentsAPI\AI\Channels\agents_frontend_chat_rest_session_list_permission(
	agents_api_session_list_smoke_rest_request( array() )
);
agents_api_smoke_assert_equals( true, $allowed, 'permission allows caller with read capability', $failures, $passes );

// The transport filter can narrow the canonical decision but never widen it.
add_filter( 'agents_frontend_chat_rest_session_list_permission', static fn() => false );
$narrowed = AgentsAPI\AI\Channels\agents_frontend_chat_rest_session_list_permission(
	agents_api_session_list_smoke_rest_request( array() )
);
agents_api_smoke_assert_equals( true, $narrowed instanceof WP_Error, 'transport filter can narrow the canonical permission decision', $failures, $passes );

$GLOBALS['__agents_api_smoke_can_manage']      = false;
$GLOBALS['__agents_api_smoke_current_user_id'] = 0;
add_filter( 'agents_frontend_chat_rest_session_list_permission', static fn() => true );
$still_denied = AgentsAPI\AI\Channels\agents_frontend_chat_rest_session_list_permission(
	agents_api_session_list_smoke_rest_request( array() )
);
agents_api_smoke_assert_equals( true, $still_denied instanceof WP_Error, 'transport filter cannot widen the canonical permission decision', $failures, $passes );
$GLOBALS['__agents_api_smoke_can_manage']      = true;
$GLOBALS['__agents_api_smoke_current_user_id'] = 7;

// Dispatch builds canonical input and forwards it to the ability.
$request = agents_api_session_list_smoke_rest_request(
	array(
		'agent'          => 'Support Agent',
		'context'        => 'chat',
		'limit'          => '10',
		'offset'         => '5',
		'workspace_type' => 'site',
		'workspace_id'   => '42',
		'session_owner'  => array(
			'type' => 'audience',
			'key'  => 'audience:widget-1',
		),
	)
);

$response      = AgentsAPI\AI\Channels\agents_frontend_chat_rest_session_list_dispatch( $request );
$response_data = $response instanceof WP_REST_Response && method_exists( $response, 'get_data' ) ? $response->get_data() : ( $response->data ?? null );
agents_api_smoke_assert_equals( true, $response instanceof WP_REST_Response, 'dispatch returns REST response', $failures, $passes );
agents_api_smoke_assert_equals( 'From the ability', $response_data['sessions'][0]['title'] ?? null, 'dispatch returns ability session list', $failures, $passes );
agents_api_smoke_assert_equals( 'support-agent', $captured['agent'] ?? null, 'dispatch sanitizes agent slug', $failures, $passes );
agents_api_smoke_assert_equals( 'chat', $captured['context'] ?? null, 'dispatch forwards context', $failures, $passes );
agents_api_smoke_assert_equals( 10, $captured['limit'] ?? null, 'dispatch coerces limit to int', $failures, $passes );
agents_api_smoke_assert_equals( 5, $captured['offset'] ?? null, 'dispatch coerces offset to int', $failures, $passes );
agents_api_smoke_assert_equals( 'site', $captured['workspace']['workspace_type'] ?? null, 'dispatch assembles the canonical workspace object' , $failures, $passes );
agents_api_smoke_assert_equals( '42', $captured['workspace']['workspace_id'] ?? null, 'dispatch preserves the workspace id', $failures, $passes );
agents_api_smoke_assert_equals( 'audience:widget-1', $captured['session_owner']['key'] ?? null, 'dispatch forwards the caller-supplied session owner', $failures, $passes );

// Ability unavailability surfaces as a 500, not a fatal. Run this before the
// input-filter tests below so it exercises the unmodified canonical input.
unset( $GLOBALS['__agents_api_smoke_abilities'][ AgentsAPI\Core\Database\Chat\AGENTS_LIST_CONVERSATION_SESSIONS_ABILITY ] );
$unavailable = AgentsAPI\AI\Channels\agents_frontend_chat_rest_session_list_dispatch(
	agents_api_session_list_smoke_rest_request( array( 'agent' => 'demo' ) )
);
agents_api_smoke_assert_equals( true, $unavailable instanceof WP_Error, 'dispatch reports a missing ability as an error', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_frontend_chat_session_list_ability_unavailable', $unavailable instanceof WP_Error ? $unavailable->get_error_code() : null, 'missing ability error carries the expected code', $failures, $passes );
$GLOBALS['__agents_api_smoke_abilities'][ AgentsAPI\Core\Database\Chat\AGENTS_LIST_CONVERSATION_SESSIONS_ABILITY ] = $ability;

// The input filter can reshape the canonical input (e.g. force a workspace).
add_filter(
	'agents_frontend_chat_rest_session_list_input',
	static function ( array $input ) {
		$input['context'] = 'reshaped-by-host';
		return $input;
	}
);
AgentsAPI\AI\Channels\agents_frontend_chat_rest_session_list_dispatch( agents_api_session_list_smoke_rest_request( array() ) );
agents_api_smoke_assert_equals( 'reshaped-by-host', $captured['context'] ?? null, 'input filter can reshape canonical input', $failures, $passes );

// A non-array filter return is rejected as a 400, not a fatal.
add_filter( 'agents_frontend_chat_rest_session_list_input', static fn() => 'not-an-array' );
$invalid = AgentsAPI\AI\Channels\agents_frontend_chat_rest_session_list_dispatch( agents_api_session_list_smoke_rest_request( array() ) );
agents_api_smoke_assert_equals( true, $invalid instanceof WP_Error, 'dispatch rejects a non-array filter return', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_frontend_chat_session_list_invalid_input', $invalid instanceof WP_Error ? $invalid->get_error_code() : null, 'invalid input carries the expected error code', $failures, $passes );
agents_api_smoke_assert_equals( 400, $invalid instanceof WP_Error ? ( $invalid->get_error_data()['status'] ?? null ) : null, 'invalid input error carries a 400 status', $failures, $passes );

agents_api_smoke_finish( 'frontend conversation session list REST adapter', $failures, $passes );

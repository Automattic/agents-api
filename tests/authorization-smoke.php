<?php
/**
 * Pure-PHP smoke test for generic agent authorization primitives.
 *
 * Run with: php tests/authorization-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-authorization-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Access_Grant' ), 'access grant value object is available', $failures, $passes );
agents_api_smoke_assert_equals( true, interface_exists( 'WP_Agent_Access_Store_Interface' ), 'access store interface is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Token' ), 'token value object is available', $failures, $passes );
agents_api_smoke_assert_equals( true, interface_exists( 'WP_Agent_Token_Store_Interface' ), 'token store interface is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Token_Authenticator' ), 'token authenticator is available', $failures, $passes );
agents_api_smoke_assert_equals( true, interface_exists( 'WP_Agent_Authorization_Policy_Interface' ), 'authorization policy interface is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Capability_Ceiling' ), 'capability ceiling value object is available', $failures, $passes );

$grant = new WP_Agent_Access_Grant( 'editor-agent', 7, WP_Agent_Access_Grant::ROLE_OPERATOR, 'site:42', 5, 1, '2026-05-04 00:00:00' );
agents_api_smoke_assert_equals( true, $grant->role_meets( WP_Agent_Access_Grant::ROLE_VIEWER ), 'operator grant meets viewer role', $failures, $passes );
agents_api_smoke_assert_equals( true, $grant->role_meets( WP_Agent_Access_Grant::ROLE_OPERATOR ), 'operator grant meets operator role', $failures, $passes );
agents_api_smoke_assert_equals( false, $grant->role_meets( WP_Agent_Access_Grant::ROLE_ADMIN ), 'operator grant does not meet admin role', $failures, $passes );
agents_api_smoke_assert_equals( 'site:42', WP_Agent_Access_Grant::from_array( $grant->to_array() )->workspace_id, 'access grant round-trips workspace scope', $failures, $passes );

$raw_token  = 'wp_agent_editor-agent_test-secret';
$token_hash = WP_Agent_Token::hash_token( $raw_token );
$token      = new WP_Agent_Token(
	33,
	'editor-agent',
	7,
	$token_hash,
	'wp_agent_ed',
	'CI login',
	array( 'edit_posts', 'read' ),
	'2099-01-01 00:00:00',
	null,
	'2026-05-04 00:00:00',
	'ci',
	'site:42'
);

$metadata = $token->to_metadata_array();
agents_api_smoke_assert_equals( false, array_key_exists( 'token_hash', $metadata ), 'token metadata excludes token hash', $failures, $passes );
agents_api_smoke_assert_equals( false, in_array( $raw_token, $metadata, true ), 'token metadata excludes raw token', $failures, $passes );
agents_api_smoke_assert_equals( false, $token->is_expired( strtotime( '2026-05-04 00:00:00' ) ), 'future token is not expired', $failures, $passes );

$expired = new WP_Agent_Token( 34, 'editor-agent', 7, WP_Agent_Token::hash_token( 'expired' ), 'wp_agent_ex', 'Expired', null, '2020-01-01 00:00:00' );
agents_api_smoke_assert_equals( true, $expired->is_expired( strtotime( '2026-05-04 00:00:00' ) ), 'expired token is expired', $failures, $passes );

$token_store = new class( $token ) implements WP_Agent_Token_Store_Interface {
	public int $touches = 0;

	public function __construct( private WP_Agent_Token $token ) {}

	public function create_token( WP_Agent_Token $token ): WP_Agent_Token {
		$this->token = $token;
		return $token;
	}

	public function resolve_token_hash( string $token_hash ): ?WP_Agent_Token {
		return hash_equals( $this->token->token_hash, $token_hash ) ? $this->token : null;
	}

	public function touch_token( int $token_id, ?string $used_at = null ): void {
		unset( $used_at );
		if ( $token_id === $this->token->token_id ) {
			++$this->touches;
		}
	}

	public function revoke_token( int $token_id, string $agent_id ): bool {
		return $token_id === $this->token->token_id && $agent_id === $this->token->agent_id;
	}

	public function revoke_all_tokens_for_agent( string $agent_id ): int {
		return $agent_id === $this->token->agent_id ? 1 : 0;
	}

	public function get_token( int $token_id ): ?WP_Agent_Token {
		return $token_id === $this->token->token_id ? $this->token : null;
	}

	public function list_tokens( string $agent_id ): array {
		return $agent_id === $this->token->agent_id ? array( $this->token ) : array();
	}
};

$authenticator = new WP_Agent_Token_Authenticator( $token_store, 'wp_agent_' );
$principal     = $authenticator->authenticate_bearer_token( $raw_token );

agents_api_smoke_assert_equals( 7, $principal->acting_user_id, 'authenticator returns token owner as acting user', $failures, $passes );
agents_api_smoke_assert_equals( 'editor-agent', $principal->effective_agent_id, 'authenticator returns token agent', $failures, $passes );
agents_api_smoke_assert_equals( 33, $principal->token_id, 'authenticator records token id', $failures, $passes );
agents_api_smoke_assert_equals( 'site:42', $principal->workspace_id, 'authenticator records workspace id', $failures, $passes );
agents_api_smoke_assert_equals( 'ci', $principal->client_id, 'authenticator records client id', $failures, $passes );
agents_api_smoke_assert_equals( 0, $principal->caller_context->chain_depth, 'authenticator records top-of-chain caller context by default', $failures, $passes );
agents_api_smoke_assert_equals( 1, $token_store->touches, 'authenticator touches successful token', $failures, $passes );
agents_api_smoke_assert_equals( null, $authenticator->authenticate_bearer_token( 'other_prefix_secret' ), 'authenticator ignores non-owned token prefix', $failures, $passes );

$chain_principal = $authenticator->authenticate_bearer_token(
	$raw_token,
	AgentsAPI\AI\AgentExecutionPrincipal::REQUEST_CONTEXT_REST,
	array(),
	array(
		WP_Agent_Caller_Context::HEADER_CALLER_AGENT => 'source-agent',
		WP_Agent_Caller_Context::HEADER_CALLER_USER  => '42',
		WP_Agent_Caller_Context::HEADER_CALLER_HOST  => 'https://source.example',
		WP_Agent_Caller_Context::HEADER_CHAIN_DEPTH  => '2',
		WP_Agent_Caller_Context::HEADER_CHAIN_ROOT   => 'root-request-123',
	)
);
agents_api_smoke_assert_equals( 'source-agent', $chain_principal->caller_context->caller_agent_id, 'authenticator records caller agent from headers', $failures, $passes );
agents_api_smoke_assert_equals( 42, $chain_principal->caller_context->caller_user_id, 'authenticator records caller user from headers', $failures, $passes );
agents_api_smoke_assert_equals( 'https://source.example', $chain_principal->caller_context->caller_host, 'authenticator records caller host from headers', $failures, $passes );
agents_api_smoke_assert_equals( 2, $chain_principal->caller_context->chain_depth, 'authenticator records caller chain depth from headers', $failures, $passes );
agents_api_smoke_assert_equals( 'root-request-123', $chain_principal->caller_context->chain_root_request_id, 'authenticator records caller chain root from headers', $failures, $passes );

$touches_before_malformed = $token_store->touches;
$malformed_principal      = $authenticator->authenticate_bearer_token(
	$raw_token,
	AgentsAPI\AI\AgentExecutionPrincipal::REQUEST_CONTEXT_REST,
	array(),
	array(
		WP_Agent_Caller_Context::HEADER_CALLER_AGENT => 'source-agent',
		WP_Agent_Caller_Context::HEADER_CALLER_HOST  => 'https://source.example',
		WP_Agent_Caller_Context::HEADER_CHAIN_DEPTH  => 'bogus',
		WP_Agent_Caller_Context::HEADER_CHAIN_ROOT   => 'root-request-123',
	)
);
agents_api_smoke_assert_equals( null, $malformed_principal, 'authenticator fails closed on malformed caller headers', $failures, $passes );
agents_api_smoke_assert_equals( $touches_before_malformed, $token_store->touches, 'authenticator does not touch token after malformed caller headers', $failures, $passes );

$policy = new WP_Agent_WordPress_Authorization_Policy(
	null,
	static function ( int $user_id, string $capability ): bool {
		return 7 === $user_id && in_array( $capability, array( 'edit_posts', 'delete_posts' ), true );
	}
);

agents_api_smoke_assert_equals( true, $policy->can( $principal, 'edit_posts' ), 'policy allows capability present in token ceiling and WordPress capabilities', $failures, $passes );
agents_api_smoke_assert_equals( false, $policy->can( $principal, 'delete_posts' ), 'policy denies WordPress capability outside token ceiling', $failures, $passes );
agents_api_smoke_assert_equals( false, $policy->can( $principal, 'read' ), 'policy denies token capability absent from WordPress user capabilities', $failures, $passes );

$access_store = new class( $grant ) implements WP_Agent_Access_Store_Interface {
	public function __construct( private WP_Agent_Access_Grant $grant ) {}

	public function grant_access( WP_Agent_Access_Grant $grant ): WP_Agent_Access_Grant {
		$this->grant = $grant;
		return $grant;
	}

	public function revoke_access( string $agent_id, int $user_id, ?string $workspace_id = null ): bool {
		return $this->grant->agent_id === $agent_id && $this->grant->user_id === $user_id && $this->grant->workspace_id === $workspace_id;
	}

	public function get_access( string $agent_id, int $user_id, ?string $workspace_id = null ): ?WP_Agent_Access_Grant {
		return $this->grant->agent_id === $agent_id && $this->grant->user_id === $user_id && $this->grant->workspace_id === $workspace_id ? $this->grant : null;
	}

	public function get_agent_ids_for_user( int $user_id, ?string $minimum_role = null, ?string $workspace_id = null ): array {
		if ( $this->grant->user_id !== $user_id || $this->grant->workspace_id !== $workspace_id ) {
			return array();
		}

		return null === $minimum_role || $this->grant->role_meets( $minimum_role ) ? array( $this->grant->agent_id ) : array();
	}

	public function get_users_for_agent( string $agent_id, ?string $workspace_id = null ): array {
		return $this->grant->agent_id === $agent_id && $this->grant->workspace_id === $workspace_id ? array( $this->grant ) : array();
	}
};

$access_policy = new WP_Agent_WordPress_Authorization_Policy( $access_store );
$other_agent   = AgentsAPI\AI\AgentExecutionPrincipal::user_session( 7, 'other-agent', AgentsAPI\AI\AgentExecutionPrincipal::REQUEST_CONTEXT_REST, array(), 'site:42' );

agents_api_smoke_assert_equals( true, $access_policy->can_access_agent( $other_agent, 'editor-agent', WP_Agent_Access_Grant::ROLE_VIEWER ), 'policy accepts access grant at viewer level', $failures, $passes );
agents_api_smoke_assert_equals( true, $access_policy->can_access_agent( $other_agent, 'editor-agent', WP_Agent_Access_Grant::ROLE_OPERATOR ), 'policy accepts access grant at operator level', $failures, $passes );
agents_api_smoke_assert_equals( false, $access_policy->can_access_agent( $other_agent, 'editor-agent', WP_Agent_Access_Grant::ROLE_ADMIN ), 'policy rejects access grant below admin level', $failures, $passes );

agents_api_smoke_finish( 'Agents API authorization', $failures, $passes );

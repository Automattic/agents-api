<?php
/**
 * Pure-PHP smoke test for agent access write abilities.
 *
 * Run with: php tests/agents-access-write-ability-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-access-write-ability-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}
}

$GLOBALS['__agents_api_smoke_current_user_id']       = 1;
$GLOBALS['__agents_api_smoke_abilities']             = array();
$GLOBALS['__agents_api_smoke_categories']            = array();
$GLOBALS['__agents_access_write_smoke_store']        = null;
$GLOBALS['__agents_access_write_smoke_store_hidden'] = false;

/**
 * Switch the acting user for both backends. The pure-PHP shim reads the smoke
 * global directly; under real WordPress the execution-principal filter below
 * synthesizes the user-session principal from this id.
 */
function agents_access_write_smoke_set_user( int $user_id ): void {
	$GLOBALS['__agents_api_smoke_current_user_id'] = $user_id;
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) $GLOBALS['__agents_api_smoke_current_user_id'];
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return get_current_user_id() > 0;
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

agents_access_write_smoke_set_user( 1 );

agents_api_smoke_require_module();

// Under real WordPress, synthesize the user-session principal from the smoke
// user id through the resolution filter; the pure-PHP shim already follows
// the smoke global through get_current_user_id().
if ( function_exists( 'wp_set_current_user' ) ) {
	add_filter(
		'agents_api_execution_principal',
		static function ( $principal, array $context ) {
			$smoke_user = (int) ( $GLOBALS['__agents_api_smoke_current_user_id'] ?? 0 );
			if ( $smoke_user <= 0 ) {
				return $principal;
			}

			return AgentsAPI\AI\WP_Agent_Execution_Principal::user_session(
				$smoke_user,
				'current-user',
				$context['request_context'] ?? AgentsAPI\AI\WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST,
				array( 'source' => 'smoke-test' ),
				$context['workspace_id'] ?? null,
				$context['client_id'] ?? null
			);
		},
		5,
		2
	);
}

add_action(
	'wp_agents_api_init',
	static function (): void {
		wp_register_agent(
			'editor-agent',
			array(
				'label'       => 'Editor Agent',
				'description' => 'Edits posts.',
			)
		);

		wp_register_agent(
			'solo-agent',
			array(
				'label'       => 'Solo Agent',
				'description' => 'Has a single admin.',
			)
		);
	}
);

do_action( 'init' );

$GLOBALS['__agents_access_write_smoke_store'] = new class implements WP_Agent_Access_Store {
	/** @var array<string, WP_Agent_Access_Grant> */
	private array $grants = array();

	/**
	 * @param string      $agent_id     Agent slug.
	 * @param int         $user_id      User ID.
	 * @param string|null $workspace_id Workspace scope.
	 */
	private function key( string $agent_id, int $user_id, ?string $workspace_id ): string {
		return $agent_id . '|' . $user_id . '|' . ( $workspace_id ?? '' );
	}

	public function grant_access( WP_Agent_Access_Grant $grant ): WP_Agent_Access_Grant {
		$this->grants[ $this->key( $grant->agent_id, $grant->user_id, $grant->workspace_id ) ] = $grant;
		return $grant;
	}

	public function revoke_access( string $agent_id, int $user_id, ?string $workspace_id = null ): bool {
		$key = $this->key( $agent_id, $user_id, $workspace_id );
		if ( ! isset( $this->grants[ $key ] ) ) {
			return false;
		}

		unset( $this->grants[ $key ] );
		return true;
	}

	public function get_access( string $agent_id, int $user_id, ?string $workspace_id = null ): ?WP_Agent_Access_Grant {
		return $this->grants[ $this->key( $agent_id, $user_id, $workspace_id ) ] ?? null;
	}

	public function get_agent_ids_for_user( int $user_id, ?string $minimum_role = null, ?string $workspace_id = null ): array {
		$agent_ids = array();
		foreach ( $this->grants as $grant ) {
			if ( $grant->user_id !== $user_id ) {
				continue;
			}

			if ( null !== $minimum_role && ! $grant->role_meets( $minimum_role ) ) {
				continue;
			}

			$agent_ids[] = $grant->agent_id;
		}

		return $agent_ids;
	}

	public function get_users_for_agent( string $agent_id, ?string $workspace_id = null ): array {
		$grants = array();
		foreach ( $this->grants as $grant ) {
			if ( $grant->agent_id === $agent_id && $grant->workspace_id === $workspace_id ) {
				$grants[] = $grant;
			}
		}

		return $grants;
	}
};

$access_store = $GLOBALS['__agents_access_write_smoke_store'];

add_filter(
	'wp_agent_access_store',
	static function ( $store ) {
		if ( ! empty( $GLOBALS['__agents_access_write_smoke_store_hidden'] ) ) {
			return null;
		}

		return $store instanceof WP_Agent_Access_Store ? $store : $GLOBALS['__agents_access_write_smoke_store'];
	}
);

do_action( 'wp_abilities_api_categories_init' );
do_action( 'wp_abilities_api_init' );

agents_api_smoke_assert_equals( true, wp_has_ability( AgentsAPI\AI\Auth\AGENTS_GRANT_AGENT_ACCESS_ABILITY ), 'grant ability registers with Abilities API', $failures, $passes );
agents_api_smoke_assert_equals( true, wp_has_ability( AgentsAPI\AI\Auth\AGENTS_REVOKE_AGENT_ACCESS_ABILITY ), 'revoke ability registers with Abilities API', $failures, $passes );
agents_api_smoke_assert_equals( true, wp_has_ability( AgentsAPI\AI\Auth\AGENTS_LIST_AGENT_USERS_ABILITY ), 'list-agent-users ability registers with Abilities API', $failures, $passes );

// Seed: user 1 is admin on both agents, user 2 is operator on editor-agent.
$access_store->grant_access( new WP_Agent_Access_Grant( 'editor-agent', 1, WP_Agent_Access_Grant::ROLE_ADMIN ) );
$access_store->grant_access( new WP_Agent_Access_Grant( 'solo-agent', 1, WP_Agent_Access_Grant::ROLE_ADMIN ) );
$access_store->grant_access( new WP_Agent_Access_Grant( 'editor-agent', 2, WP_Agent_Access_Grant::ROLE_OPERATOR ) );

// Permission model: agent-role, not WordPress capabilities.
agents_access_write_smoke_set_user( 1 );
agents_api_smoke_assert_equals( true, AgentsAPI\AI\Auth\agents_access_admin_permission( array( 'agent' => 'editor-agent' ) ), 'admin principal passes admin permission gate', $failures, $passes );
agents_api_smoke_assert_equals( true, AgentsAPI\AI\Auth\agents_access_operator_permission( array( 'agent' => 'editor-agent' ) ), 'admin principal passes operator permission gate', $failures, $passes );

agents_access_write_smoke_set_user( 2 );
agents_api_smoke_assert_equals( false, AgentsAPI\AI\Auth\agents_access_admin_permission( array( 'agent' => 'editor-agent' ) ), 'non-admin principal denied on grant permission gate', $failures, $passes );
agents_api_smoke_assert_equals( true, AgentsAPI\AI\Auth\agents_access_operator_permission( array( 'agent' => 'editor-agent' ) ), 'operator principal passes operator permission gate for list-agent-users', $failures, $passes );

agents_access_write_smoke_set_user( 3 );
agents_api_smoke_assert_equals( false, AgentsAPI\AI\Auth\agents_access_admin_permission( array( 'agent' => 'editor-agent' ) ), 'principal without any grant denied on both write gates', $failures, $passes );

// Grant as admin (user 1).
agents_access_write_smoke_set_user( 1 );
$grant_result = AgentsAPI\AI\Auth\agents_grant_agent_access(
	array(
		'agent'    => 'editor-agent',
		'user_id'  => 9,
		'role'     => WP_Agent_Access_Grant::ROLE_OPERATOR,
		'metadata' => array( 'source' => 'smoke-test' ),
	)
);
agents_api_smoke_assert_equals( true, is_array( $grant_result ) && true === ( $grant_result['granted'] ?? null ), 'grant ability returns granted true', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Access_Grant::ROLE_OPERATOR, is_array( $grant_result ) ? ( $grant_result['grant']['role'] ?? null ) : null, 'granted grant carries requested role', $failures, $passes );
agents_api_smoke_assert_equals( 1, is_array( $grant_result ) ? ( $grant_result['grant']['granted_by_user_id'] ?? null ) : null, 'granted grant records granting principal user id', $failures, $passes );
agents_api_smoke_assert_equals( array( 'source' => 'smoke-test' ), is_array( $grant_result ) ? ( $grant_result['grant']['metadata'] ?? null ) : null, 'granted grant carries metadata', $failures, $passes );

// Granting without a role defaults to viewer.
$default_role_result = AgentsAPI\AI\Auth\agents_grant_agent_access(
	array(
		'agent'   => 'editor-agent',
		'user_id' => 11,
	)
);
agents_api_smoke_assert_equals( WP_Agent_Access_Grant::ROLE_VIEWER, is_array( $default_role_result ) ? ( $default_role_result['grant']['role'] ?? null ) : null, 'grant ability defaults to viewer role', $failures, $passes );

// List shows the new grant.
$list_result = AgentsAPI\AI\Auth\agents_list_agent_users( array( 'agent' => 'editor-agent' ) );
$list_users  = is_array( $list_result ) ? ( $list_result['users'] ?? array() ) : array();
agents_api_smoke_assert_equals( 4, count( $list_users ), 'list-agent-users returns all grants for the agent', $failures, $passes );
agents_api_smoke_assert_equals( true, in_array( 9, array_column( $list_users, 'user_id' ), true ), 'list-agent-users shows the granted user', $failures, $passes );

// Re-granting the same user upserts instead of duplicating.
AgentsAPI\AI\Auth\agents_grant_agent_access(
	array(
		'agent'   => 'editor-agent',
		'user_id' => 9,
		'role'    => WP_Agent_Access_Grant::ROLE_ADMIN,
	)
);
$list_after_upsert = AgentsAPI\AI\Auth\agents_list_agent_users( array( 'agent' => 'editor-agent' ) );
$upsert_users      = is_array( $list_after_upsert ) ? ( $list_after_upsert['users'] ?? array() ) : array();
agents_api_smoke_assert_equals( 4, count( $upsert_users ), 're-granting an existing user upserts instead of duplicating', $failures, $passes );

$upsert_roles = array_column( $upsert_users, 'role', 'user_id' );
agents_api_smoke_assert_equals( WP_Agent_Access_Grant::ROLE_ADMIN, $upsert_roles[9] ?? null, 're-grant updates the stored role', $failures, $passes );

// Revoke the updated grant and confirm the list empties for that user.
$revoke_result = AgentsAPI\AI\Auth\agents_revoke_agent_access(
	array(
		'agent'   => 'editor-agent',
		'user_id' => 9,
	)
);
agents_api_smoke_assert_equals( array( 'revoked' => true ), $revoke_result, 'revoke ability returns revoked true', $failures, $passes );

$list_after_revoke = AgentsAPI\AI\Auth\agents_list_agent_users( array( 'agent' => 'editor-agent' ) );
$remaining_users   = is_array( $list_after_revoke ) ? ( $list_after_revoke['users'] ?? array() ) : array();
agents_api_smoke_assert_equals( false, in_array( 9, array_column( $remaining_users, 'user_id' ), true ), 'list-agent-users no longer shows the revoked user', $failures, $passes );

// Revoking a grant that does not exist reports revoked false.
$revoke_missing = AgentsAPI\AI\Auth\agents_revoke_agent_access(
	array(
		'agent'   => 'editor-agent',
		'user_id' => 9,
	)
);
agents_api_smoke_assert_equals( array( 'revoked' => false ), $revoke_missing, 'revoke of a missing grant returns revoked false', $failures, $passes );

// Last-admin protection: user 1 is the only admin on solo-agent.
$last_admin_revoke = AgentsAPI\AI\Auth\agents_revoke_agent_access(
	array(
		'agent'   => 'solo-agent',
		'user_id' => 1,
	)
);
agents_api_smoke_assert_equals( true, is_wp_error( $last_admin_revoke ), 'revoking the last admin grant returns WP_Error', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_access_last_admin', is_wp_error( $last_admin_revoke ) ? $last_admin_revoke->get_error_code() : '', 'last-admin revoke uses agents_access_last_admin code', $failures, $passes );

// Once a second admin exists, the first can be revoked.
AgentsAPI\AI\Auth\agents_grant_agent_access(
	array(
		'agent'   => 'solo-agent',
		'user_id' => 5,
		'role'    => WP_Agent_Access_Grant::ROLE_ADMIN,
	)
);
$second_admin_revoke = AgentsAPI\AI\Auth\agents_revoke_agent_access(
	array(
		'agent'   => 'solo-agent',
		'user_id' => 1,
	)
);
agents_api_smoke_assert_equals( array( 'revoked' => true ), $second_admin_revoke, 'revoking one of several admins succeeds', $failures, $passes );

// Revoking a non-admin grant is never blocked by last-admin protection.
$operator_revoke = AgentsAPI\AI\Auth\agents_revoke_agent_access(
	array(
		'agent'   => 'editor-agent',
		'user_id' => 2,
	)
);
agents_api_smoke_assert_equals( array( 'revoked' => true ), $operator_revoke, 'revoking a non-admin grant is allowed', $failures, $passes );

// Workspace scoping.
$workspace_grant = AgentsAPI\AI\Auth\agents_grant_agent_access(
	array(
		'agent'        => 'editor-agent',
		'user_id'      => 21,
		'role'         => WP_Agent_Access_Grant::ROLE_VIEWER,
		'workspace_id' => 'site:42',
	)
);
agents_api_smoke_assert_equals( 'site:42', is_array( $workspace_grant ) ? ( $workspace_grant['grant']['workspace_id'] ?? null ) : null, 'workspace grant carries workspace scope', $failures, $passes );

$unscoped_list = AgentsAPI\AI\Auth\agents_list_agent_users( array( 'agent' => 'editor-agent' ) );
agents_api_smoke_assert_equals( false, in_array( 21, array_column( is_array( $unscoped_list ) ? ( $unscoped_list['users'] ?? array() ) : array(), 'user_id' ), true ), 'unscoped list omits workspace-scoped grant', $failures, $passes );

$scoped_list  = AgentsAPI\AI\Auth\agents_list_agent_users(
	array(
		'agent'        => 'editor-agent',
		'workspace_id' => 'site:42',
	)
);
$scoped_users = is_array( $scoped_list ) ? ( $scoped_list['users'] ?? array() ) : array();
agents_api_smoke_assert_equals( array( 21 ), array_column( $scoped_users, 'user_id' ), 'workspace-scoped list returns only that workspace grant', $failures, $passes );

// Error paths.
$invalid_role = AgentsAPI\AI\Auth\agents_grant_agent_access(
	array(
		'agent'   => 'editor-agent',
		'user_id' => 9,
		'role'    => 'superadmin',
	)
);
agents_api_smoke_assert_equals( true, is_wp_error( $invalid_role ), 'invalid role returns WP_Error', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_access_invalid_role', is_wp_error( $invalid_role ) ? $invalid_role->get_error_code() : '', 'invalid role uses agents_access_invalid_role code', $failures, $passes );

$invalid_user = AgentsAPI\AI\Auth\agents_grant_agent_access(
	array(
		'agent'   => 'editor-agent',
		'user_id' => 0,
	)
);
agents_api_smoke_assert_equals( 'agents_access_invalid_user', is_wp_error( $invalid_user ) ? $invalid_user->get_error_code() : '', 'non-positive user_id returns agents_access_invalid_user', $failures, $passes );

$unknown_agent = AgentsAPI\AI\Auth\agents_grant_agent_access(
	array(
		'agent'   => 'missing-agent',
		'user_id' => 9,
	)
);
agents_api_smoke_assert_equals( 'agents_access_unknown_agent', is_wp_error( $unknown_agent ) ? $unknown_agent->get_error_code() : '', 'unknown agent returns agents_access_unknown_agent', $failures, $passes );

$GLOBALS['__agents_access_write_smoke_store_hidden'] = true;
$missing_store                                       = AgentsAPI\AI\Auth\agents_grant_agent_access(
	array(
		'agent'   => 'editor-agent',
		'user_id' => 9,
	)
);
agents_api_smoke_assert_equals( 'agents_access_store_missing', is_wp_error( $missing_store ) ? $missing_store->get_error_code() : '', 'missing store returns agents_access_store_missing on grant', $failures, $passes );

$missing_store_list = AgentsAPI\AI\Auth\agents_list_agent_users( array( 'agent' => 'editor-agent' ) );
agents_api_smoke_assert_equals( 'agents_access_store_missing', is_wp_error( $missing_store_list ) ? $missing_store_list->get_error_code() : '', 'missing store returns agents_access_store_missing on list', $failures, $passes );
$GLOBALS['__agents_access_write_smoke_store_hidden'] = false;

agents_api_smoke_finish( 'Agents API access write abilities', $failures, $passes );

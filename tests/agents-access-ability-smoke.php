<?php
/**
 * Pure-PHP smoke test for agent access helpers and abilities.
 *
 * Run with: php tests/agents-access-ability-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-access-ability-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

$GLOBALS['__agents_api_smoke_current_user_id'] = 7;
$GLOBALS['__agents_api_smoke_abilities']       = array();
$GLOBALS['__agents_api_smoke_categories']      = array();

function get_current_user_id(): int {
	return (int) $GLOBALS['__agents_api_smoke_current_user_id'];
}

function is_user_logged_in(): bool {
	return get_current_user_id() > 0;
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

agents_api_smoke_require_module();

add_action(
	'wp_agents_api_init',
	static function (): void {
		wp_register_agent(
			'editor-agent',
			array(
				'label'       => 'Editor Agent',
				'description' => 'Edits posts.',
				'meta'        => array( 'source_plugin' => 'tests' ),
			)
		);

		wp_register_agent(
			'admin-agent',
			array(
				'label'       => 'Admin Agent',
				'description' => 'Administers the site.',
			)
		);
	}
);

do_action( 'init' );

$grant = new WP_Agent_Access_Grant( 'editor-agent', 7, WP_Agent_Access_Grant::ROLE_OPERATOR, 'site:42' );

$access_store = new class( $grant ) implements WP_Agent_Access_Store {
	/**
	 * @param WP_Agent_Access_Grant $grant Test grant.
	 */
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

add_filter(
	'wp_agent_access_store',
	static function ( $store ) use ( $access_store ) {
		return $store instanceof WP_Agent_Access_Store ? $store : $access_store;
	}
);

agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Access' ), 'access helper class is available', $failures, $passes );
agents_api_smoke_assert_equals( $access_store, WP_Agent_Access::get_store(), 'access store resolves through filter', $failures, $passes );

$principal = WP_Agent_Access::get_current_principal( array( 'workspace_id' => 'site:42' ) );
agents_api_smoke_assert_equals( 7, $principal->acting_user_id, 'current principal falls back to current user', $failures, $passes );
agents_api_smoke_assert_equals( 'site:42', $principal->workspace_id, 'current principal carries workspace scope', $failures, $passes );

agents_api_smoke_assert_equals( true, WP_Agent_Access::can_current_principal_access_agent( 'editor-agent', WP_Agent_Access_Grant::ROLE_VIEWER, array( 'workspace_id' => 'site:42' ) ), 'current principal can access granted agent at viewer role', $failures, $passes );
agents_api_smoke_assert_equals( true, WP_Agent_Access::can_current_principal_access_agent( 'editor-agent', WP_Agent_Access_Grant::ROLE_OPERATOR, array( 'workspace_id' => 'site:42' ) ), 'current principal can access granted agent at operator role', $failures, $passes );
agents_api_smoke_assert_equals( false, WP_Agent_Access::can_current_principal_access_agent( 'editor-agent', WP_Agent_Access_Grant::ROLE_ADMIN, array( 'workspace_id' => 'site:42' ) ), 'current principal cannot access granted agent above grant role', $failures, $passes );

$agents = WP_Agent_Access::list_accessible_agents_for_current_principal( WP_Agent_Access_Grant::ROLE_VIEWER, array( 'workspace_id' => 'site:42' ) );
agents_api_smoke_assert_equals( 'editor-agent', $agents[0]['slug'] ?? null, 'accessible agents list contains granted registered agent', $failures, $passes );
agents_api_smoke_assert_equals( 'Editor Agent', $agents[0]['label'] ?? null, 'accessible agents include agent label', $failures, $passes );

$can_access = AgentsAPI\AI\Auth\agents_can_access_agent(
	array(
		'agent'        => 'editor-agent',
		'minimum_role' => WP_Agent_Access_Grant::ROLE_OPERATOR,
		'workspace_id' => 'site:42',
	)
);
agents_api_smoke_assert_equals( true, $can_access['allowed'] ?? false, 'can-access ability returns allowed true for grant', $failures, $passes );

$cannot_access = AgentsAPI\AI\Auth\agents_can_access_agent(
	array(
		'agent'        => 'editor-agent',
		'minimum_role' => WP_Agent_Access_Grant::ROLE_ADMIN,
		'workspace_id' => 'site:42',
	)
);
agents_api_smoke_assert_equals( false, $cannot_access['allowed'] ?? true, 'can-access ability returns allowed false below minimum role', $failures, $passes );

$ability_list = AgentsAPI\AI\Auth\agents_list_accessible_agents( array( 'workspace_id' => 'site:42' ) );
agents_api_smoke_assert_equals( 'editor-agent', $ability_list['agents'][0]['slug'] ?? null, 'list-accessible ability returns granted registered agent', $failures, $passes );

do_action( 'wp_abilities_api_categories_init' );
do_action( 'wp_abilities_api_init' );

agents_api_smoke_assert_equals( true, wp_has_ability( AgentsAPI\AI\Auth\AGENTS_CAN_ACCESS_AGENT_ABILITY ), 'can-access ability registers with Abilities API', $failures, $passes );
agents_api_smoke_assert_equals( true, wp_has_ability( AgentsAPI\AI\Auth\AGENTS_LIST_ACCESSIBLE_AGENTS_ABILITY ), 'list-accessible ability registers with Abilities API', $failures, $passes );

agents_api_smoke_finish( 'Agents API access abilities', $failures, $passes );

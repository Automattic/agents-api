<?php
/**
 * Pure-PHP smoke test for the wp_agent_can_access_agent host access filter.
 *
 * Covers:
 *  - Default behavior unchanged when nothing hooks the filter.
 *  - A hooked filter can grant access with no store row.
 *  - A hooked filter can deny despite a store grant.
 *  - List and check agree in both directions.
 *  - The filter can tighten the effective-agent short-circuit.
 *
 * Run with: php tests/access-decision-filter-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "access-decision-filter-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

$GLOBALS['__agents_api_smoke_current_user_id'] = 7;

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

agents_api_smoke_require_module();

// -----------------------------------------------------------------------
// Register three test agents.
// -----------------------------------------------------------------------
add_action(
	'wp_agents_api_init',
	static function (): void {
		wp_register_agent(
			'alpha-agent',
			array(
				'label'       => 'Alpha Agent',
				'description' => 'Has a store grant.',
			)
		);
		wp_register_agent(
			'beta-agent',
			array(
				'label'       => 'Beta Agent',
				'description' => 'No store grant; filter-granted.',
			)
		);
		wp_register_agent(
			'gamma-agent',
			array(
				'label'       => 'Gamma Agent',
				'description' => 'No store grant; filter-granted.',
			)
		);
	}
);

do_action( 'init' );

// -----------------------------------------------------------------------
// Store with a single grant for alpha-agent (user 7, operator, site:42).
// -----------------------------------------------------------------------
$grant = new WP_Agent_Access_Grant( 'alpha-agent', 7, WP_Agent_Access_Grant::ROLE_OPERATOR, 'site:42' );

$access_store = new class( $grant ) implements WP_Agent_Access_Store {
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

$context = array( 'workspace_id' => 'site:42', 'access_store' => $access_store );

$principal = AgentsAPI\AI\WP_Agent_Execution_Principal::user_session(
	7,
	'test-user',
	AgentsAPI\AI\WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST,
	array( 'source' => 'smoke-test' ),
	'site:42'
);

// Helper: extract slugs from an accessible-agents list.
function access_decision_filter_slugs( array $agents ): array {
	return array_column( $agents, 'slug' );
}

// Helper: assert list/check agreement for a single agent.
function access_decision_filter_assert_agreement( AgentsAPI\AI\WP_Agent_Execution_Principal $principal, string $agent_id, array $context, string $label, array &$failures, int &$passes ): void {
	$can    = WP_Agent_Access::can_principal_access_agent( $principal, $agent_id, WP_Agent_Access_Grant::ROLE_VIEWER, $context );
	$list   = WP_Agent_Access::list_accessible_agents_for_principal( $principal, WP_Agent_Access_Grant::ROLE_VIEWER, $context );
	$listed = in_array( $agent_id, access_decision_filter_slugs( $list ), true );
	agents_api_smoke_assert_equals( $can, $listed, $label, $failures, $passes );
}

// =======================================================================
// Phase 1: Default behavior — no wp_agent_can_access_agent filter hooked.
// =======================================================================

agents_api_smoke_assert_equals( true, WP_Agent_Access::can_principal_access_agent( $principal, 'alpha-agent', WP_Agent_Access_Grant::ROLE_VIEWER, $context ), 'default: store grant allows alpha at viewer', $failures, $passes );
agents_api_smoke_assert_equals( true, WP_Agent_Access::can_principal_access_agent( $principal, 'alpha-agent', WP_Agent_Access_Grant::ROLE_OPERATOR, $context ), 'default: store grant allows alpha at operator', $failures, $passes );
agents_api_smoke_assert_equals( false, WP_Agent_Access::can_principal_access_agent( $principal, 'alpha-agent', WP_Agent_Access_Grant::ROLE_ADMIN, $context ), 'default: store grant denies alpha above role', $failures, $passes );
agents_api_smoke_assert_equals( false, WP_Agent_Access::can_principal_access_agent( $principal, 'beta-agent', WP_Agent_Access_Grant::ROLE_VIEWER, $context ), 'default: no grant denies beta', $failures, $passes );
agents_api_smoke_assert_equals( false, WP_Agent_Access::can_principal_access_agent( $principal, 'gamma-agent', WP_Agent_Access_Grant::ROLE_VIEWER, $context ), 'default: no grant denies gamma', $failures, $passes );

$default_list   = WP_Agent_Access::list_accessible_agents_for_principal( $principal, WP_Agent_Access_Grant::ROLE_VIEWER, $context );
$default_slugs  = access_decision_filter_slugs( $default_list );
agents_api_smoke_assert_equals( true, in_array( 'alpha-agent', $default_slugs, true ), 'default: list includes alpha', $failures, $passes );
agents_api_smoke_assert_equals( false, in_array( 'beta-agent', $default_slugs, true ), 'default: list excludes beta', $failures, $passes );
agents_api_smoke_assert_equals( false, in_array( 'gamma-agent', $default_slugs, true ), 'default: list excludes gamma', $failures, $passes );

// =======================================================================
// Hook the filter with a mutable override map.
// =======================================================================

$filter_overrides = array();

add_filter(
	'wp_agent_can_access_agent',
	static function ( $allowed, $p, string $agent_id, string $minimum_role, array $ctx ) use ( &$filter_overrides ) {
		if ( array_key_exists( $agent_id, $filter_overrides ) ) {
			return $filter_overrides[ $agent_id ];
		}
		return $allowed;
	},
	10,
	5
);

// =======================================================================
// Phase 2: Filter grants beta (no store row).
// =======================================================================

$filter_overrides = array( 'beta-agent' => true );

agents_api_smoke_assert_equals( true, WP_Agent_Access::can_principal_access_agent( $principal, 'beta-agent', WP_Agent_Access_Grant::ROLE_VIEWER, $context ), 'filter grants beta: check returns true with no store row', $failures, $passes );
agents_api_smoke_assert_equals( true, WP_Agent_Access::can_principal_access_agent( $principal, 'alpha-agent', WP_Agent_Access_Grant::ROLE_VIEWER, $context ), 'filter grants beta: alpha still allowed (passthrough)', $failures, $passes );
agents_api_smoke_assert_equals( false, WP_Agent_Access::can_principal_access_agent( $principal, 'gamma-agent', WP_Agent_Access_Grant::ROLE_VIEWER, $context ), 'filter grants beta: gamma still denied (passthrough)', $failures, $passes );

$grant_list  = WP_Agent_Access::list_accessible_agents_for_principal( $principal, WP_Agent_Access_Grant::ROLE_VIEWER, $context );
$grant_slugs = access_decision_filter_slugs( $grant_list );
agents_api_smoke_assert_equals( true, in_array( 'alpha-agent', $grant_slugs, true ), 'filter grants beta: list includes alpha', $failures, $passes );
agents_api_smoke_assert_equals( true, in_array( 'beta-agent', $grant_slugs, true ), 'filter grants beta: list includes beta', $failures, $passes );
agents_api_smoke_assert_equals( false, in_array( 'gamma-agent', $grant_slugs, true ), 'filter grants beta: list excludes gamma', $failures, $passes );

access_decision_filter_assert_agreement( $principal, 'beta-agent', $context, 'list/check agree: beta granted by filter', $failures, $passes );

// =======================================================================
// Phase 3: Filter denies alpha (despite store grant).
// =======================================================================

$filter_overrides = array( 'alpha-agent' => false );

agents_api_smoke_assert_equals( false, WP_Agent_Access::can_principal_access_agent( $principal, 'alpha-agent', WP_Agent_Access_Grant::ROLE_VIEWER, $context ), 'filter denies alpha: check returns false despite store grant', $failures, $passes );
agents_api_smoke_assert_equals( false, WP_Agent_Access::can_principal_access_agent( $principal, 'alpha-agent', WP_Agent_Access_Grant::ROLE_OPERATOR, $context ), 'filter denies alpha: check returns false at operator too', $failures, $passes );

$deny_list  = WP_Agent_Access::list_accessible_agents_for_principal( $principal, WP_Agent_Access_Grant::ROLE_VIEWER, $context );
$deny_slugs = access_decision_filter_slugs( $deny_list );
agents_api_smoke_assert_equals( false, in_array( 'alpha-agent', $deny_slugs, true ), 'filter denies alpha: list excludes alpha despite store grant', $failures, $passes );

access_decision_filter_assert_agreement( $principal, 'alpha-agent', $context, 'list/check agree: alpha denied by filter', $failures, $passes );

// =======================================================================
// Phase 4: Bidirectional agreement — grant beta+gamma, deny alpha.
// =======================================================================

$filter_overrides = array(
	'alpha-agent' => false,
	'beta-agent'  => true,
	'gamma-agent' => true,
);

$bidir_list  = WP_Agent_Access::list_accessible_agents_for_principal( $principal, WP_Agent_Access_Grant::ROLE_VIEWER, $context );
$bidir_slugs = access_decision_filter_slugs( $bidir_list );

foreach ( array( 'alpha-agent', 'beta-agent', 'gamma-agent' ) as $agent_id ) {
	access_decision_filter_assert_agreement( $principal, $agent_id, $context, "bidirectional agreement: list/check match for {$agent_id}", $failures, $passes );
}

agents_api_smoke_assert_equals( false, in_array( 'alpha-agent', $bidir_slugs, true ), 'bidirectional: alpha excluded', $failures, $passes );
agents_api_smoke_assert_equals( true, in_array( 'beta-agent', $bidir_slugs, true ), 'bidirectional: beta included', $failures, $passes );
agents_api_smoke_assert_equals( true, in_array( 'gamma-agent', $bidir_slugs, true ), 'bidirectional: gamma included', $failures, $passes );

// =======================================================================
// Phase 5: Filter can tighten the effective-agent short-circuit.
// =======================================================================

$sc_principal = AgentsAPI\AI\WP_Agent_Execution_Principal::user_session(
	7,
	'beta-agent',
	AgentsAPI\AI\WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST,
	array( 'source' => 'smoke-test' ),
	'site:42'
);

// Without override, short-circuit grants access to beta (effective agent).
$filter_overrides = array();
agents_api_smoke_assert_equals( true, WP_Agent_Access::can_principal_access_agent( $sc_principal, 'beta-agent', WP_Agent_Access_Grant::ROLE_VIEWER, $context ), 'short-circuit: effective agent id grants access by default', $failures, $passes );

$sc_default_list  = WP_Agent_Access::list_accessible_agents_for_principal( $sc_principal, WP_Agent_Access_Grant::ROLE_VIEWER, $context );
$sc_default_slugs = access_decision_filter_slugs( $sc_default_list );
agents_api_smoke_assert_equals( true, in_array( 'beta-agent', $sc_default_slugs, true ), 'short-circuit: list includes effective agent by default', $failures, $passes );

// With override, host can deny the short-circuit.
$filter_overrides = array( 'beta-agent' => false );
agents_api_smoke_assert_equals( false, WP_Agent_Access::can_principal_access_agent( $sc_principal, 'beta-agent', WP_Agent_Access_Grant::ROLE_VIEWER, $context ), 'short-circuit: filter can deny effective agent id', $failures, $passes );

$sc_deny_list  = WP_Agent_Access::list_accessible_agents_for_principal( $sc_principal, WP_Agent_Access_Grant::ROLE_VIEWER, $context );
$sc_deny_slugs = access_decision_filter_slugs( $sc_deny_list );
agents_api_smoke_assert_equals( false, in_array( 'beta-agent', $sc_deny_slugs, true ), 'short-circuit: list excludes denied effective agent', $failures, $passes );

// =======================================================================
// Phase 6: Ability functions agree through the full path.
// =======================================================================

$filter_overrides = array(
	'alpha-agent' => false,
	'beta-agent'  => true,
	'gamma-agent' => true,
);

$GLOBALS['__agents_api_smoke_current_user_id'] = 7;

do_action( 'wp_abilities_api_categories_init' );
do_action( 'wp_abilities_api_init' );

$ability_input = array(
	'minimum_role' => WP_Agent_Access_Grant::ROLE_VIEWER,
	'workspace_id' => 'site:42',
);

$ability_list = AgentsAPI\AI\Auth\agents_list_accessible_agents( $ability_input );
$ability_slugs = array_column( $ability_list['agents'] ?? array(), 'slug' );

foreach ( array( 'alpha-agent', 'beta-agent', 'gamma-agent' ) as $agent_id ) {
	$can_result  = AgentsAPI\AI\Auth\agents_can_access_agent(
		array(
			'agent'        => $agent_id,
			'minimum_role' => WP_Agent_Access_Grant::ROLE_VIEWER,
			'workspace_id' => 'site:42',
		)
	);
	$can_allowed = (bool) ( $can_result['allowed'] ?? false );
	$listed      = in_array( $agent_id, $ability_slugs, true );
	agents_api_smoke_assert_equals( $can_allowed, $listed, "ability list/check agree: {$agent_id}", $failures, $passes );
}

agents_api_smoke_assert_equals( false, in_array( 'alpha-agent', $ability_slugs, true ), 'ability: alpha excluded', $failures, $passes );
agents_api_smoke_assert_equals( true, in_array( 'beta-agent', $ability_slugs, true ), 'ability: beta included', $failures, $passes );
agents_api_smoke_assert_equals( true, in_array( 'gamma-agent', $ability_slugs, true ), 'ability: gamma included', $failures, $passes );

agents_api_smoke_finish( 'Access decision filter', $failures, $passes );

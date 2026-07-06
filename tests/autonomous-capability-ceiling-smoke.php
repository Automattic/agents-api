<?php
/**
 * Pure-PHP smoke test for the autonomous capability ceiling derivation.
 *
 * Run with: php tests/autonomous-capability-ceiling-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-autonomous-capability-ceiling-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

$principal_class = 'AgentsAPI\\AI\\WP_Agent_Execution_Principal';
$policy          = 'WP_Agent_Autonomous_Capability_Policy';

echo "\n[1] Capability ceiling deny-list is honored and composes with the allow-list:\n";

$deny_ceiling = new WP_Agent_Capability_Ceiling( 7, null, array(), array( 'publish_posts', 'delete_posts' ) );
agents_api_smoke_assert_equals( true, $deny_ceiling->has_denied_capabilities(), 'ceiling reports denied capability restriction', $failures, $passes );
agents_api_smoke_assert_equals( false, $deny_ceiling->allows_capability( 'publish_posts' ), 'deny-list blocks a denied capability', $failures, $passes );
agents_api_smoke_assert_equals( false, $deny_ceiling->allows_capability( 'delete_posts' ), 'deny-list blocks a second denied capability', $failures, $passes );
agents_api_smoke_assert_equals( true, $deny_ceiling->allows_capability( 'read' ), 'deny-list still permits an unrestricted capability', $failures, $passes );

$deny_with_allow = new WP_Agent_Capability_Ceiling( 7, array( 'edit_posts', 'publish_posts', 'read' ), array(), array( 'publish_posts' ) );
agents_api_smoke_assert_equals( false, $deny_with_allow->allows_capability( 'publish_posts' ), 'deny-list overrides an allowed capability', $failures, $passes );
agents_api_smoke_assert_equals( true, $deny_with_allow->allows_capability( 'edit_posts' ), 'allow-list still gates a non-denied capability', $failures, $passes );

$plain = new WP_Agent_Capability_Ceiling( 7, array( 'read' ) );
agents_api_smoke_assert_equals( false, $plain->has_denied_capabilities(), 'ceiling without deny-list reports no denied restriction', $failures, $passes );
agents_api_smoke_assert_equals( true, $plain->allows_capability( 'read' ), 'ceiling without deny-list keeps existing allow-list behavior', $failures, $passes );

$with_denied_added = $plain->with_denied_capabilities( array( 'manage_options' ) );
agents_api_smoke_assert_equals( array( 'manage_options' ), $with_denied_added->denied_capabilities, 'with_denied_capabilities records the deny-list', $failures, $passes );
agents_api_smoke_assert_equals( false, $with_denied_added->allows_capability( 'manage_options' ), 'with_denied_capabilities blocks the new deny entry', $failures, $passes );
agents_api_smoke_assert_equals( true, $with_denied_added->allows_capability( 'read' ), 'with_denied_capabilities preserves the existing allow-list', $failures, $passes );

$with_allowed_kept = $deny_ceiling->with_allowed_capabilities( array( 'edit_posts' ) );
agents_api_smoke_assert_equals( array( 'publish_posts', 'delete_posts' ), $with_allowed_kept->denied_capabilities, 'with_allowed_capabilities preserves the existing deny-list', $failures, $passes );
agents_api_smoke_assert_equals( false, $with_allowed_kept->allows_capability( 'publish_posts' ), 'with_allowed_capabilities does not widen an existing deny', $failures, $passes );

$round_trip = WP_Agent_Capability_Ceiling::from_array(
	array(
		'user_id'              => 9,
		'allowed_capabilities' => array( 'read', 'edit_posts' ),
		'denied_capabilities'  => array( 'edit_posts' ),
		'metadata'             => array( 'origin' => 'round-trip' ),
	)
);
agents_api_smoke_assert_equals( 9, $round_trip->user_id, 'from_array restores denied-ceiling user id', $failures, $passes );
agents_api_smoke_assert_equals( array( 'read', 'edit_posts' ), $round_trip->allowed_capabilities, 'from_array restores the allow-list alongside the deny-list', $failures, $passes );
agents_api_smoke_assert_equals( array( 'edit_posts' ), $round_trip->denied_capabilities, 'from_array restores the deny-list', $failures, $passes );
agents_api_smoke_assert_equals( false, $round_trip->allows_capability( 'edit_posts' ), 'round-tripped ceiling honors the deny-list over the allow-list', $failures, $passes );

$null_denied_round_trip = WP_Agent_Capability_Ceiling::from_array(
	array(
		'user_id'              => 3,
		'allowed_capabilities' => array( 'read' ),
	)
);
agents_api_smoke_assert_equals( null, $null_denied_round_trip->denied_capabilities, 'from_array leaves denied capabilities null when absent', $failures, $passes );

$exported = $deny_with_allow->to_array();
agents_api_smoke_assert_equals( array( 'publish_posts' ), $exported['denied_capabilities'], 'to_array exports the deny-list', $failures, $passes );

try {
	new WP_Agent_Capability_Ceiling( 1, null, array(), array( 'read', '' ) );
	agents_api_smoke_assert_equals( true, false, 'empty denied capability string is rejected', $failures, $passes );
} catch ( InvalidArgumentException $e ) {
	agents_api_smoke_assert_equals( true, str_contains( $e->getMessage(), 'denied_capabilities' ), 'empty denied capability string is rejected', $failures, $passes );
}

echo "\n[2] Autonomous principal without an explicit grant receives the write-excluding safe default:\n";

$system_principal = new $principal_class(
	0,
	'system-agent',
	$principal_class::AUTH_SOURCE_SYSTEM,
	$principal_class::REQUEST_CONTEXT_CRON
);
$system_ceiling = $policy::resolve_ceiling( $system_principal );
agents_api_smoke_assert_equals( true, $system_ceiling instanceof WP_Agent_Capability_Ceiling, 'system principal receives a derived ceiling', $failures, $passes );
agents_api_smoke_assert_equals( true, $system_principal->is_autonomous_execution(), 'system principal is autonomous', $failures, $passes );
agents_api_smoke_assert_equals( false, $system_ceiling->allows_capability( 'publish_posts' ), 'autonomous system principal cannot publish posts', $failures, $passes );
agents_api_smoke_assert_equals( false, $system_ceiling->allows_capability( 'edit_posts' ), 'autonomous system principal cannot edit posts', $failures, $passes );
agents_api_smoke_assert_equals( false, $system_ceiling->allows_capability( 'manage_options' ), 'autonomous system principal cannot manage options', $failures, $passes );
agents_api_smoke_assert_equals( true, $system_ceiling->allows_capability( 'read' ), 'autonomous system principal keeps read access', $failures, $passes );
agents_api_smoke_assert_equals( true, $system_ceiling->has_denied_capabilities(), 'autonomous safe default carries a deny-list', $failures, $passes );
agents_api_smoke_assert_equals( true, null === $system_ceiling->allowed_capabilities, 'autonomous safe default keeps the allow-list unrestricted', $failures, $passes );
agents_api_smoke_assert_equals( true, true === $system_ceiling->metadata['autonomous_safe_default'], 'autonomous safe default is marked in metadata', $failures, $passes );

$token_principal = $principal_class::agent_token( 7, 'automation-agent', 456, $principal_class::REQUEST_CONTEXT_REST );
agents_api_smoke_assert_equals( true, $token_principal->is_autonomous_execution(), 'agent token principal is autonomous', $failures, $passes );
$token_ceiling = $policy::resolve_ceiling( $token_principal );
agents_api_smoke_assert_equals( 7, $token_ceiling->user_id, 'autonomous token ceiling is bound to the token owner', $failures, $passes );
agents_api_smoke_assert_equals( false, $token_ceiling->allows_capability( 'publish_posts' ), 'autonomous token principal cannot publish posts by default', $failures, $passes );
agents_api_smoke_assert_equals( false, $token_ceiling->allows_capability( 'delete_others_posts' ), 'autonomous token principal cannot delete others posts by default', $failures, $passes );
agents_api_smoke_assert_equals( true, $token_ceiling->allows_capability( 'read' ), 'autonomous token principal keeps read access', $failures, $passes );

$runtime_principal = $principal_class::runtime( 'runtime-session-1', 'delegated-runtime-agent' );
agents_api_smoke_assert_equals( true, $runtime_principal->is_autonomous_execution(), 'runtime principal is autonomous', $failures, $passes );
$runtime_ceiling = $policy::resolve_ceiling( $runtime_principal );
agents_api_smoke_assert_equals( false, $runtime_ceiling->allows_capability( 'edit_pages' ), 'autonomous runtime principal cannot edit pages by default', $failures, $passes );
agents_api_smoke_assert_equals( false, $runtime_ceiling->allows_capability( 'upload_files' ), 'autonomous runtime principal cannot upload files by default', $failures, $passes );

echo "\n[3] Interactive principals are unaffected and keep their host-supplied ceiling:\n";

$interactive = $principal_class::user_session( 5, 'editor-agent', $principal_class::REQUEST_CONTEXT_REST );
agents_api_smoke_assert_equals( false, $interactive->is_autonomous_execution(), 'user session principal is not autonomous', $failures, $passes );
agents_api_smoke_assert_equals( null, $policy::resolve_ceiling( $interactive ), 'interactive principal without a ceiling stays unrestricted', $failures, $passes );

$interactive_unrestricted = new $principal_class(
	5,
	'editor-agent',
	$principal_class::AUTH_SOURCE_APPLICATION_PASSWORD,
	$principal_class::REQUEST_CONTEXT_REST
);
agents_api_smoke_assert_equals( false, $interactive_unrestricted->is_autonomous_execution(), 'application password principal is not autonomous', $failures, $passes );
agents_api_smoke_assert_equals( null, $policy::resolve_ceiling( $interactive_unrestricted ), 'application password principal without a ceiling stays unrestricted', $failures, $passes );

echo "\n[4] Explicit host grants are respected and the safe default never overrides or widens them:\n";

$explicit_allow = new WP_Agent_Capability_Ceiling( 7, array( 'edit_posts', 'read' ) );
$explicit_principal = $principal_class::agent_token( 7, 'automation-agent', 456, $principal_class::REQUEST_CONTEXT_REST );
$explicit_principal_with_ceiling = new $principal_class(
	7,
	'automation-agent',
	$principal_class::AUTH_SOURCE_AGENT_TOKEN,
	$principal_class::REQUEST_CONTEXT_REST,
	456,
	array(),
	null,
	null,
	$explicit_allow
);
$resolved_explicit = $policy::resolve_ceiling( $explicit_principal_with_ceiling );
agents_api_smoke_assert_equals( true, $resolved_explicit === $explicit_allow, 'explicit host grant is returned unchanged by identity', $failures, $passes );
agents_api_smoke_assert_equals( true, $resolved_explicit->allows_capability( 'edit_posts' ), 'explicit host grant keeps an allowed capability', $failures, $passes );
agents_api_smoke_assert_equals( false, $resolved_explicit->allows_capability( 'publish_posts' ), 'explicit host grant keeps a non-granted capability denied', $failures, $passes );
agents_api_smoke_assert_equals( false, $resolved_explicit->has_denied_capabilities(), 'explicit host grant does not receive the autonomous deny-list', $failures, $passes );

$explicit_grant_write = new WP_Agent_Capability_Ceiling( 7, array( 'publish_posts', 'read' ) );
$explicit_write_principal = new $principal_class(
	7,
	'automation-agent',
	$principal_class::AUTH_SOURCE_AGENT_TOKEN,
	$principal_class::REQUEST_CONTEXT_REST,
	456,
	array(),
	null,
	null,
	$explicit_grant_write
);
$resolved_write = $policy::resolve_ceiling( $explicit_write_principal );
agents_api_smoke_assert_equals( true, $resolved_write->allows_capability( 'publish_posts' ), 'explicit write grant to an autonomous principal is respected', $failures, $passes );

$unrestricted_attached = new WP_Agent_Capability_Ceiling( 7 );
$unrestricted_principal = new $principal_class(
	7,
	'automation-agent',
	$principal_class::AUTH_SOURCE_AGENT_TOKEN,
	$principal_class::REQUEST_CONTEXT_REST,
	456,
	array(),
	null,
	null,
	$unrestricted_attached
);
agents_api_smoke_assert_equals( false, $policy::resolve_ceiling( $unrestricted_principal )->allows_capability( 'publish_posts' ), 'unrestricted ceiling on an autonomous principal still receives the safe default', $failures, $passes );

$autonomous_no_ceiling = $policy::resolve_ceiling( $explicit_principal );
agents_api_smoke_assert_equals( false, $autonomous_no_ceiling === $explicit_allow, 'principal without attached ceiling derives a fresh ceiling', $failures, $passes );

echo "\n[5] Default content-mutating capability set is documented and conservative:\n";

$denied_defaults = $policy::denied_capabilities();
$expected_denied = array(
	'edit_posts',
	'edit_others_posts',
	'edit_published_posts',
	'publish_posts',
	'delete_posts',
	'delete_others_posts',
	'delete_published_posts',
	'edit_pages',
	'edit_others_pages',
	'edit_published_pages',
	'publish_pages',
	'delete_pages',
	'delete_others_pages',
	'delete_published_pages',
	'manage_categories',
	'manage_options',
	'upload_files',
	'unfiltered_html',
	'create_users',
	'delete_users',
	'promote_users',
);
foreach ( $expected_denied as $capability ) {
	agents_api_smoke_assert_equals( true, in_array( $capability, $denied_defaults, true ), 'default deny-list includes ' . $capability, $failures, $passes );
}
agents_api_smoke_assert_equals( false, in_array( 'read', $denied_defaults, true ), 'default deny-list excludes read access', $failures, $passes );

echo "\n[6] Filters let hosts adjust the autonomous definition and the denied capability set:\n";

add_filter(
	'agents_api_autonomous_auth_sources',
	static function ( array $sources ) use ( $principal_class ): array {
		return array_filter(
			$sources,
			static function ( string $source ) use ( $principal_class ): bool {
				return $principal_class::AUTH_SOURCE_AGENT_TOKEN !== $source;
			}
		);
	}
);
agents_api_smoke_assert_equals( false, in_array( $principal_class::AUTH_SOURCE_AGENT_TOKEN, $policy::autonomous_auth_sources(), true ), 'filter removes an auth source from the autonomous set', $failures, $passes );
$token_after_filter = $policy::resolve_ceiling( $token_principal );
agents_api_smoke_assert_equals( null, $token_after_filter, 'principal reclassified as non-autonomous is left unrestricted', $failures, $passes );

add_filter(
	'agents_api_principal_is_autonomous',
	static function ( bool $autonomous, AgentsAPI\AI\WP_Agent_Execution_Principal $principal ) use ( $principal_class ): bool {
		if ( $principal_class::AUTH_SOURCE_AUDIENCE === $principal->auth_source ) {
			return true;
		}

		return $autonomous;
	},
	10,
	2
);
$audience_principal = $principal_class::audience( 'audience:docs-readers', 'audience-gateway', $principal_class::REQUEST_CONTEXT_REST );
$audience_ceiling = $policy::resolve_ceiling( $audience_principal );
agents_api_smoke_assert_equals( true, $audience_ceiling instanceof WP_Agent_Capability_Ceiling, 'per-principal filter can mark an audience autonomous', $failures, $passes );
agents_api_smoke_assert_equals( false, $audience_ceiling->allows_capability( 'publish_posts' ), 'filter-declared autonomous principal receives the safe default', $failures, $passes );

add_filter(
	'agents_api_autonomous_denied_capabilities',
	static function ( array $denied ): array {
		return array_merge( $denied, array( 'read' ) );
	}
);
$runtime_ceiling_filtered = $policy::resolve_ceiling( $runtime_principal );
agents_api_smoke_assert_equals( true, in_array( 'read', $runtime_ceiling_filtered->denied_capabilities, true ), 'filter extends the denied capability set', $failures, $passes );
agents_api_smoke_assert_equals( false, $runtime_ceiling_filtered->allows_capability( 'read' ), 'extended deny-list blocks the added capability', $failures, $passes );

agents_api_smoke_finish( 'Agents API autonomous capability ceiling', $failures, $passes );

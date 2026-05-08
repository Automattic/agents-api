<?php
/**
 * Pure-PHP smoke test for effective agent resolution.
 *
 * Run with: php tests/effective-agent-resolver-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-effective-agent-resolver-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

$principal = AgentsAPI\AI\WP_Agent_Execution_Principal::user_session(
	7,
	'Principal Agent',
	AgentsAPI\AI\WP_Agent_Execution_Principal::REQUEST_CONTEXT_CLI
);

$resolved = AgentsAPI\AI\WP_Agent_Effective_Agent_Resolver::resolve(
	array(
		'agent_slug' => 'Explicit Agent',
		'principal'  => $principal,
	)
);
agents_api_smoke_assert_equals( 'explicit-agent', $resolved, 'explicit agent wins over principal', $failures, $passes );

$resolved = AgentsAPI\AI\WP_Agent_Effective_Agent_Resolver::resolve(
	array(
		'principal'              => $principal,
		'persisted_agent_slug'   => 'persisted-agent',
		'owner_user_id'          => 7,
		'owner_agent_slugs'      => array( 'owned-agent' ),
	)
);
agents_api_smoke_assert_equals( 'principal-agent', $resolved, 'principal wins over persisted and owner fallback', $failures, $passes );

$resolved = AgentsAPI\AI\WP_Agent_Effective_Agent_Resolver::resolve(
	array(
		'persisted_effective_agent_id' => 'Persisted Agent',
		'owner_user_id'                => 7,
		'owner_agent_slugs'            => array( 'owned-agent' ),
	)
);
agents_api_smoke_assert_equals( 'persisted-agent', $resolved, 'persisted context wins over owner fallback', $failures, $passes );

$resolved = AgentsAPI\AI\WP_Agent_Effective_Agent_Resolver::resolve(
	array(
		'owner_user_id'     => 7,
		'owner_agent_slugs' => array( 'Only Agent' ),
	)
);
agents_api_smoke_assert_equals( 'only-agent', $resolved, 'single owner candidate is accepted', $failures, $passes );

try {
	AgentsAPI\AI\WP_Agent_Effective_Agent_Resolver::resolve(
		array(
			'owner_user_id'     => 7,
			'owner_agent_slugs' => array( 'admin', 'intelligence-chubes4' ),
		)
	);
	agents_api_smoke_assert_equals( true, false, 'ambiguous owner fallback is rejected', $failures, $passes );
} catch ( InvalidArgumentException $e ) {
	agents_api_smoke_assert_equals( true, str_contains( $e->getMessage(), 'ambiguous' ), 'ambiguous owner fallback is rejected', $failures, $passes );
}

add_action(
	'wp_agents_api_init',
	static function (): void {
		wp_register_agent(
			'registered-owner-agent',
			array(
				'owner_resolver' => static fn() => 42,
			)
		);
		wp_register_agent(
			'other-owner-agent',
			array(
				'owner_resolver' => static fn() => 99,
			)
		);
	},
	10,
	0
);
do_action( 'init' );

$resolved = AgentsAPI\AI\WP_Agent_Effective_Agent_Resolver::resolve(
	array(
		'owner_user_id' => 42,
	)
);
agents_api_smoke_assert_equals( 'registered-owner-agent', $resolved, 'registered owner fallback uses owner resolvers when unambiguous', $failures, $passes );

$resolved = AgentsAPI\AI\WP_Agent_Effective_Agent_Resolver::resolve();
agents_api_smoke_assert_equals( '', $resolved, 'empty context resolves to no agent', $failures, $passes );

agents_api_smoke_finish( 'Agents API effective agent resolver', $failures, $passes );

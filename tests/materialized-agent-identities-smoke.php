<?php
/**
 * Pure-PHP smoke test for materialized agent identity contracts.
 *
 * Run with: php tests/materialized-agent-identities-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-materialized-agent-identities-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

final class Agents_API_Fake_Materialized_Agent_Identity_Store implements AgentsAPI\Identity\MaterializedAgentIdentityStoreInterface {
	/**
	 * @var array<int, AgentsAPI\Identity\MaterializedAgentIdentity>
	 */
	private array $identities = array();

	/**
	 * @param AgentsAPI\Identity\MaterializedAgentIdentity[] $identities Identities.
	 */
	public function __construct( array $identities ) {
		foreach ( $identities as $identity ) {
			$this->identities[ $identity->get_id() ] = $identity;
		}
	}

	public function get_by_id( int $id ): ?AgentsAPI\Identity\MaterializedAgentIdentity {
		return $this->identities[ $id ] ?? null;
	}

	public function get_by_slug( string $slug ): ?AgentsAPI\Identity\MaterializedAgentIdentity {
		$slug = sanitize_title( $slug );
		foreach ( $this->identities as $identity ) {
			if ( $slug === $identity->get_slug() ) {
				return $identity;
			}
		}

		return null;
	}

	public function get_by_owner_user_id( int $owner_user_id ): array {
		$matches = array();
		foreach ( $this->identities as $identity ) {
			if ( $owner_user_id === $identity->get_owner_user_id() ) {
				$matches[] = $identity;
			}
		}

		return $matches;
	}
}

do_action( 'init' );

add_action(
	'wp_agents_api_init',
	static function (): void {
		wp_register_agent(
			'Support Chef',
			array(
				'label'          => 'Support Chef',
				'default_config' => array( 'temperature' => 0.2 ),
			)
		);
	}
);

WP_Agents_Registry::reset_for_tests();
WP_Agents_Registry::init();

$identity = AgentsAPI\Identity\MaterializedAgentIdentity::from_array(
	array(
		'id'            => 123,
		'slug'          => 'support-chef',
		'label'         => 'Support Chef',
		'owner_user_id' => 45,
		'config'        => '{"temperature":0.2}',
		'created_at'    => '2026-05-01 00:00:00',
		'updated_at'    => '2026-05-01 00:00:00',
	)
);

$store = new Agents_API_Fake_Materialized_Agent_Identity_Store( array( $identity ) );
wp_register_materialized_agent_identity_store( $store );

$by_slug  = wp_get_materialized_agent_identity( 'support-chef' );
$by_owner = wp_get_materialized_agent_identities_by_owner_user_id( 45 );

agents_api_smoke_assert_equals( $store, wp_get_materialized_agent_identity_store(), 'registered identity store is returned', $failures, $passes );
agents_api_smoke_assert_equals( 123, wp_get_materialized_agent_identity_by_id( 123 )->get_id(), 'identity resolves by durable ID', $failures, $passes );
agents_api_smoke_assert_equals( 123, $by_slug instanceof AgentsAPI\Identity\MaterializedAgentIdentity ? $by_slug->get_id() : null, 'registered definition resolves to identity by slug', $failures, $passes );
agents_api_smoke_assert_equals( 45, $by_slug instanceof AgentsAPI\Identity\MaterializedAgentIdentity ? $by_slug->get_owner_user_id() : null, 'identity exposes owner user ID', $failures, $passes );
agents_api_smoke_assert_equals( array( 'temperature' => 0.2 ), $by_slug instanceof AgentsAPI\Identity\MaterializedAgentIdentity ? $by_slug->get_config() : null, 'identity normalizes persisted config', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $by_owner ), 'identity store resolves identities by owner user ID', $failures, $passes );
agents_api_smoke_assert_equals( 123, $by_owner[0]->get_id(), 'owner user ID lookup returns matching identity', $failures, $passes );
agents_api_smoke_assert_equals( null, wp_get_materialized_agent_identity( 'not-registered' ), 'unregistered slug does not resolve to materialized identity', $failures, $passes );

agents_api_smoke_finish( 'Agents API materialized agent identities', $failures, $passes );

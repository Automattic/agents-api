<?php
/**
 * Pure-PHP smoke test for registered-agent identity store materialization.
 *
 * Run with: php tests/identity-store-materialization-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-identity-store-materialization-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

final class Agents_API_Test_Identity_Store implements AgentsAPI\Core\Identity\WP_Agent_Identity_Store {
	/** @var array<string,AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity> */
	private array $identities = array();

	private int $next_id = 1;

	public function resolve( AgentsAPI\Core\Identity\WP_Agent_Identity_Scope $scope ): ?AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity {
		return $this->identities[ $scope->key() ] ?? null;
	}

	public function get( int $identity_id ): ?AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity {
		foreach ( $this->identities as $identity ) {
			if ( $identity->id === $identity_id ) {
				return $identity;
			}
		}

		return null;
	}

	public function materialize( AgentsAPI\Core\Identity\WP_Agent_Identity_Scope $scope, array $default_config = array(), array $meta = array() ): AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity {
		$normalized_scope = $scope->normalize();
		$key              = $normalized_scope->key();

		if ( isset( $this->identities[ $key ] ) ) {
			return $this->identities[ $key ];
		}

		$identity                 = new AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity( $this->next_id++, $normalized_scope, $default_config, $meta, 10, 10 );
		$this->identities[ $key ] = $identity;

		return $identity;
	}

	public function update( AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity $identity ): AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity {
		$this->identities[ $identity->scope->key() ] = $identity;
		return $identity;
	}

	public function delete( AgentsAPI\Core\Identity\WP_Agent_Identity_Scope $scope ): bool {
		unset( $this->identities[ $scope->key() ] );
		return true;
	}
}

$store = new Agents_API_Test_Identity_Store();

add_action(
	'wp_agents_api_init',
	static function (): void {
		wp_register_agent(
			'kitchen-agent',
			array(
				'label'          => 'Kitchen Agent',
				'default_config' => array( 'model' => 'gpt-test' ),
				'owner_resolver' => static fn() => 7,
				'meta'           => array(
					'source_plugin'  => 'example/example.php',
					'source_type'    => 'bundled-agent',
					'source_package' => 'chef-pack',
					'source_version' => '1.0.0',
				),
			)
		);

		wp_register_agent( 'pantry-agent', array( 'label' => 'Pantry Agent' ) );
	}
);

do_action( 'init' );

echo "\n[1] Identity store can be provided directly through context:\n";
agents_api_smoke_assert_equals( true, wp_get_agent_identity_store( array( 'identity_store' => $store ) ) === $store, 'context identity store is resolved', $failures, $passes );

echo "\n[2] A registered agent materializes through the identity store:\n";
$identity = wp_materialize_agent_identity( 'kitchen-agent', $store, array( 'instance_key' => 'Site:42' ) );
agents_api_smoke_assert_equals( true, $identity instanceof AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity, 'single registered agent materializes', $failures, $passes );
agents_api_smoke_assert_equals( 'kitchen-agent', $identity instanceof AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity ? $identity->scope->normalize()->agent_slug : null, 'identity scope uses registered slug', $failures, $passes );
agents_api_smoke_assert_equals( 7, $identity instanceof AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity ? $identity->scope->normalize()->owner_user_id : null, 'owner resolver supplies owner user ID', $failures, $passes );
agents_api_smoke_assert_equals( 'site:42', $identity instanceof AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity ? $identity->scope->normalize()->instance_key : null, 'caller instance key is normalized', $failures, $passes );
agents_api_smoke_assert_equals( array( 'model' => 'gpt-test' ), $identity instanceof AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity ? $identity->config : null, 'agent default config is passed to first materialization', $failures, $passes );
agents_api_smoke_assert_equals( 'example/example.php', $identity instanceof AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity ? ( $identity->meta['source_plugin'] ?? null ) : null, 'agent source metadata is passed to first materialization', $failures, $passes );

echo "\n[3] Registered agent materialization is idempotent for the same scope:\n";
$second_identity = wp_materialize_agent_identity( 'kitchen-agent', $store, array( 'instance_key' => 'Site:42' ) );
agents_api_smoke_assert_equals( $identity instanceof AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity ? $identity->id : null, $second_identity instanceof AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity ? $second_identity->id : null, 'same identity store row is reused', $failures, $passes );

echo "\n[4] Missing stores and unknown agents no-op cleanly:\n";
agents_api_smoke_assert_equals( array(), wp_materialize_registered_agent_identities(), 'no store means no materialization', $failures, $passes );
agents_api_smoke_assert_equals( null, wp_materialize_agent_identity( 'missing-agent', $store ), 'unknown agent returns null', $failures, $passes );

echo "\n[5] All registered agents can materialize through a filter-provided store:\n";
add_filter(
	'wp_agent_identity_store',
	static function () use ( $store ): AgentsAPI\Core\Identity\WP_Agent_Identity_Store {
		return $store;
	}
);

$identities = wp_materialize_registered_agent_identities(
	null,
	array(
		'owner_user_id' => 13,
		'instance_key'  => 'Site:42',
		'meta'          => array( 'materialized_by' => 'smoke' ),
	)
);
agents_api_smoke_assert_equals( array( 'kitchen-agent', 'pantry-agent' ), array_keys( $identities ), 'all registered agents materialize via filter-provided store', $failures, $passes );
agents_api_smoke_assert_equals( 13, $identities['pantry-agent']->scope->normalize()->owner_user_id ?? null, 'explicit owner_user_id overrides defaults', $failures, $passes );
agents_api_smoke_assert_equals( 'smoke', $identities['pantry-agent']->meta['materialized_by'] ?? null, 'caller metadata is merged into materialization metadata', $failures, $passes );
agents_api_smoke_assert_equals( false, class_exists( 'ExampleProduct_Agent_Store', false ), 'materialization lifecycle does not load product classes', $failures, $passes );

agents_api_smoke_finish( 'Agents API identity store materialization', $failures, $passes );

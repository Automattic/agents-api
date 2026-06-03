<?php
/**
 * Pure-PHP smoke test for registered-agent materialization adapters.
 *
 * Run with: php tests/registered-agent-materialization-adapter-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-registered-agent-materialization-adapter-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

final class Agents_API_Test_Registered_Agent_Materialization_Adapter implements WP_Agent_Registered_Agent_Materialization_Adapter {
	/** @var array<string,WP_Agent_Installed_Agent> */
	private array $installed = array();

	/**
	 * @param array<string,WP_Agent> $registered_agents Current registered-agent snapshot.
	 * @param array<string,mixed>    $args              Adapter args.
	 * @return array<string,WP_Agent_Materialization_Result>
	 */
	public function materialize_registered_agents( array $registered_agents, array $args = array() ): array {
		$owner_user_id = isset( $args['owner_user_id'] ) ? (int) $args['owner_user_id'] : null;
		$instance_key  = isset( $args['instance_key'] ) ? (string) $args['instance_key'] : 'default';
		$seen          = array();
		$results       = array();

		foreach ( $registered_agents as $agent ) {
			$request = new WP_Agent_Materialization_Request(
				$agent,
				array(
					'operation'     => 'reconcile',
					'owner_user_id' => $owner_user_id,
					'instance_key'  => $instance_key,
				)
			);
			$probe   = new WP_Agent_Installed_Agent(
				array(
					'id'            => 'probe',
					'agent_slug'    => $agent->get_slug(),
					'owner_user_id' => $request->get_owner_user_id(),
					'instance_key'  => $request->get_instance_key(),
				)
			);
			$key     = $probe->key();
			$status  = isset( $this->installed[ $key ] ) ? 'updated' : 'installed';

			$state = new WP_Agent_Installed_Agent(
				array(
					'id'            => 'fake:' . $key,
					'agent_slug'    => $agent->get_slug(),
					'owner_user_id' => $request->get_owner_user_id(),
					'instance_key'  => $request->get_instance_key(),
					'config'        => $request->get_config(),
					'meta'          => array(
						'source_plugin'  => $agent->get_meta()['source_plugin'] ?? null,
						'source_type'    => $agent->get_meta()['source_type'] ?? null,
						'source_package' => $agent->get_meta()['source_package'] ?? null,
						'source_version' => $agent->get_meta()['source_version'] ?? null,
					),
					'status'        => $status,
				)
			);

			$this->installed[ $key ]        = $state;
			$seen[ $key ]                   = true;
			$results[ $agent->get_slug() ]   = new WP_Agent_Materialization_Result(
				$status,
				$state,
				WP_Agent_Installed_Agent_Projector::project( $state, $agent ),
				array( 'Fake adapter reconciled ' . $agent->get_slug() . '.' ),
				array( 'identity_key' => $key )
			);
		}

		foreach ( $this->installed as $key => $state ) {
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$removed = new WP_Agent_Installed_Agent(
				array_merge(
					$state->to_array(),
					array( 'status' => 'removed' )
				)
			);

			$this->installed[ $key ] = $removed;
			$results[ 'removed:' . $state->get_agent_slug() ] = new WP_Agent_Materialization_Result( 'removed', $removed );
		}

		return $results;
	}
}

$adapter = new Agents_API_Test_Registered_Agent_Materialization_Adapter();

add_action(
	'wp_agents_api_init',
	static function (): void {
		wp_register_agent(
			'kitchen-agent',
			array(
				'label'          => 'Kitchen Agent',
				'default_config' => array( 'model' => 'gpt-test' ),
				'meta'           => array(
					'source_plugin'  => 'example/example.php',
					'source_type'    => 'bundled-agent',
					'source_package' => 'chef-pack',
					'source_version' => '1.0.0',
				),
			)
		);

		wp_register_agent( 'kitchen-agent', array( 'label' => 'Duplicate Kitchen Agent' ) );
		wp_register_agent( 'pantry-agent', array( 'label' => 'Pantry Agent' ) );
	}
);

do_action( 'init' );

echo "\n[1] Fake adapter materializes registered definitions without product storage:\n";
$results = wp_materialize_registered_agents(
	$adapter,
	array(
		'owner_user_id' => 7,
		'instance_key'  => 'Site:42',
	)
);

agents_api_smoke_assert_equals( true, interface_exists( 'WP_Agent_Registered_Agent_Materialization_Adapter' ), 'registered-agent adapter interface is available', $failures, $passes );
agents_api_smoke_assert_equals( array( 'kitchen-agent', 'pantry-agent' ), array_keys( $results ), 'adapter receives only unique registered slugs', $failures, $passes );
agents_api_smoke_assert_equals( 'installed', $results['kitchen-agent']->get_status(), 'first materialization installs the agent identity', $failures, $passes );
agents_api_smoke_assert_equals( 'kitchen-agent:7:site:42', $results['kitchen-agent']->get_meta()['identity_key'] ?? null, 'identity tuple is stable and normalized', $failures, $passes );
agents_api_smoke_assert_equals( 'example/example.php', $results['kitchen-agent']->get_installed_agent()->get_meta()['source_plugin'] ?? null, 'source provenance is available to the adapter', $failures, $passes );

echo "\n[2] Repeat materialization is idempotent and updates existing identity:\n";
$second_results = wp_materialize_registered_agents(
	$adapter,
	array(
		'owner_user_id' => 7,
		'instance_key'  => 'Site:42',
	)
);

agents_api_smoke_assert_equals( 'updated', $second_results['kitchen-agent']->get_status(), 'second materialization updates instead of duplicating', $failures, $passes );
agents_api_smoke_assert_equals( 'fake:kitchen-agent:7:site:42', $second_results['kitchen-agent']->get_installed_agent()->get_id(), 'same durable identity is reused', $failures, $passes );

echo "\n[3] Removed definitions are adapter-owned reconciliation, not Agents API storage:\n";
wp_unregister_agent( 'pantry-agent' );
$removed_results = wp_materialize_registered_agents(
	$adapter,
	array(
		'owner_user_id' => 7,
		'instance_key'  => 'Site:42',
	)
);

agents_api_smoke_assert_equals( true, isset( $removed_results['removed:pantry-agent'] ), 'adapter may report removed definitions absent from registry snapshot', $failures, $passes );
agents_api_smoke_assert_equals( 'removed', $removed_results['removed:pantry-agent']->get_status(), 'removed definition result uses explicit status', $failures, $passes );

echo "\n[4] Filter hook can provide the adapter lazily:\n";
add_filter(
	'wp_agent_registered_agent_materialization_adapter',
	static function () use ( $adapter ): WP_Agent_Registered_Agent_Materialization_Adapter {
		return $adapter;
	}
);

$filtered_results = wp_materialize_registered_agents(
	null,
	array(
		'owner_user_id' => 7,
		'instance_key'  => 'Site:42',
	)
);

agents_api_smoke_assert_equals( true, isset( $filtered_results['kitchen-agent'] ), 'filter-provided adapter is used when no adapter is passed', $failures, $passes );
agents_api_smoke_assert_equals( false, class_exists( 'DataMachine_Agent_Store', false ), 'materialization contract does not load Data Machine classes', $failures, $passes );

agents_api_smoke_finish( 'Agents API registered-agent materialization adapter', $failures, $passes );

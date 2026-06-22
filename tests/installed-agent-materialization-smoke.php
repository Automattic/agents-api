<?php
/**
 * Pure-PHP smoke test for installed-agent materialization contracts.
 *
 * Run with: php tests/installed-agent-materialization-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-installed-agent-materialization-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

final class Agents_API_Test_Installed_Agent_State_Store implements WP_Agent_Installed_Agent_State_Store {
	/** @var array<string,WP_Agent_Installed_Agent> */
	private array $installed = array();

	public function resolve( string $agent_slug, ?int $owner_user_id = null, string $instance_key = 'default', array $context = array() ): ?WP_Agent_Installed_Agent {
		unset( $context );
		$probe = new WP_Agent_Installed_Agent(
			array(
				'id'            => 'probe',
				'agent_slug'    => $agent_slug,
				'owner_user_id' => $owner_user_id,
				'instance_key'  => $instance_key,
			)
		);

		return $this->installed[ $probe->key() ] ?? null;
	}

	public function materialize( WP_Agent_Materialization_Request $request ): WP_Agent_Materialization_Result {
		$package = $request->get_package();
		$agent   = $request->get_agent();
		$state   = new WP_Agent_Installed_Agent(
			array(
				'id'              => 'store:' . $agent->get_slug() . ':1',
				'agent_slug'      => $agent->get_slug(),
				'owner_user_id'   => $request->get_owner_user_id(),
				'instance_key'    => $request->get_instance_key(),
				'config'          => $request->get_config(),
				'meta'            => array( 'store' => 'smoke' ),
				'package_slug'    => null === $package ? null : $package->get_slug(),
				'package_version' => null === $package ? null : $package->get_version(),
				'created_at'      => '2026-06-01T00:00:00Z',
				'updated_at'      => '2026-06-01T00:00:00Z',
			)
		);

		$this->installed[ $state->key() ] = $state;

		return new WP_Agent_Materialization_Result(
			'installed',
			$state,
			WP_Agent_Installed_Agent_Projector::project( $state, $agent ),
			array( 'Installed by smoke store.' ),
			array( 'operation' => $request->get_operation() )
		);
	}

	public function delete( WP_Agent_Installed_Agent $installed_agent, array $context = array() ): bool {
		unset( $context );
		unset( $this->installed[ $installed_agent->key() ] );
		return true;
	}
}

$agent = new WP_Agent(
	'Demo Agent',
	array(
		'label'          => 'Demo Agent',
		'description'    => 'A package-shipped agent.',
		'default_config' => array(
			'model'       => 'gpt-test',
			'temperature' => 0.2,
		),
		'meta'           => array( 'source_type' => 'package' ),
	)
);

$package = WP_Agent_Package::from_array(
	array(
		'slug'    => 'demo-package',
		'version' => '1.2.3',
		'agent'   => $agent->to_array(),
	)
);

echo "\n[1] Materialization request adopts default config without storage coupling:\n";
$request = new WP_Agent_Materialization_Request(
	$agent,
	array(
		'operation'     => 'install',
		'owner_user_id' => 42,
		'instance_key'  => ' Site:99 / Primary ',
		'config'        => array( 'temperature' => 0.7 ),
		'package'       => $package,
		'context'       => array( 'actor' => 'smoke' ),
	)
);

agents_api_smoke_assert_equals( 'install', $request->get_operation(), 'operation is normalized', $failures, $passes );
agents_api_smoke_assert_equals( 'site:99/primary', $request->get_instance_key(), 'instance key is normalized but host-owned', $failures, $passes );
agents_api_smoke_assert_equals( array( 'model' => 'gpt-test', 'temperature' => 0.7 ), $request->get_config(), 'default config is adopted and caller overrides win', $failures, $passes );

echo "\n[2] Installed state store contract materializes and resolves durable state:\n";
$store  = new Agents_API_Test_Installed_Agent_State_Store();
$result = $store->materialize( $request );
$state  = $result->get_installed_agent();

agents_api_smoke_assert_equals( 'installed', $result->get_status(), 'materialization result reports installed', $failures, $passes );
agents_api_smoke_assert_equals( true, $state instanceof WP_Agent_Installed_Agent, 'result contains installed state value', $failures, $passes );
agents_api_smoke_assert_equals( 'demo-agent:42:site:99/primary', $state->key(), 'installed state key is stable', $failures, $passes );
agents_api_smoke_assert_equals( 'demo-package', $state->get_package_slug(), 'installed state carries package provenance', $failures, $passes );
agents_api_smoke_assert_equals( true, $store->resolve( 'demo-agent', 42, 'site:99/primary' ) instanceof WP_Agent_Installed_Agent, 'store resolves by logical tuple', $failures, $passes );

echo "\n[3] Projector returns request-local WP_Agent definition from durable state:\n";
$projected = $result->get_projected_agent();
agents_api_smoke_assert_equals( true, $projected instanceof WP_Agent, 'result contains projected WP_Agent', $failures, $passes );
agents_api_smoke_assert_equals( 'demo-agent', $projected->get_slug(), 'projected agent keeps installed slug', $failures, $passes );
agents_api_smoke_assert_equals( array( 'model' => 'gpt-test', 'temperature' => 0.7 ), $projected->get_default_config(), 'projected agent uses installed config', $failures, $passes );
agents_api_smoke_assert_equals( 'store:demo-agent:1', $projected->get_meta()['installed_agent_id'] ?? null, 'projected metadata includes installed identity', $failures, $passes );
agents_api_smoke_assert_equals( '1.2.3', $projected->get_meta()['source_version'] ?? null, 'projected metadata includes package version', $failures, $passes );

echo "\n[4] Contract stays storage-neutral:\n";
agents_api_smoke_assert_equals( true, interface_exists( 'WP_Agent_Installed_Agent_State_Store' ), 'installed-agent store interface is available', $failures, $passes );
agents_api_smoke_assert_equals( false, class_exists( 'WP_Agent_Option_Installed_Agent_State_Store', false ), 'no option-backed installed-agent store is loaded', $failures, $passes );
agents_api_smoke_assert_equals( false, class_exists( 'WP_Agent_Log_Installed_Agent_State_Store', false ), 'no log-backed installed-agent store is loaded', $failures, $passes );

agents_api_smoke_finish( 'Agents API installed-agent materialization', $failures, $passes );

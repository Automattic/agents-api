<?php
/**
 * Pure-PHP smoke test for agent runtime profile resolution.
 *
 * Run with: php tests/runtime-profile-smoke.php
 *
 * @package AgentsAPI\Tests
 */

use AgentsAPI\AI\WP_Agent_Runtime_Profile;
use AgentsAPI\AI\WP_Agent_Runtime_Profile_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-runtime-profile-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

final class Agents_API_Smoke_Runtime_Profile_Provider implements WP_Agent_Runtime_Profile_Provider {
	public function resolve_agent_runtime_profile( WP_Agent $agent, array $context ): ?WP_Agent_Runtime_Profile {
		unset( $context );

		return new WP_Agent_Runtime_Profile(
			$agent->get_slug(),
			'host-provider',
			'host-model',
			null,
			array(
				'provider_id'    => array( 'source' => 'host', 'path' => 'provider_id' ),
				'model_id'       => array( 'source' => 'host', 'path' => 'model_id' ),
				'config_sources' => array( array( 'source' => 'host', 'path' => 'profile' ) ),
			)
		);
	}
}

add_action(
	'wp_agents_api_init',
	static function () {
		wp_register_agent(
			'runtime-profile-agent',
			array(
				'default_config' => array(
					'mode_models'      => array(
						'design' => array(
							'provider_id' => 'mode-provider',
							'model_id'    => 'mode-model',
						),
					),
					'default_provider' => 'fallback-provider',
					'default_model'    => 'fallback-model',
				),
			)
		);
	}
);

do_action( 'init' );

$agent = wp_get_agent( 'runtime-profile-agent' );
agents_api_smoke_assert_equals( true, $agent instanceof WP_Agent, 'agent registered for runtime profile smoke', $failures, $passes );

$explicit = wp_resolve_agent_runtime_profile(
	$agent,
	array(
		'provider_id' => 'context-provider',
		'model'       => array( 'model_id' => 'context-model' ),
		'mode'        => 'design',
	)
);
agents_api_smoke_assert_equals( 'context-provider', $explicit instanceof WP_Agent_Runtime_Profile ? $explicit->provider_id() : null, 'explicit provider override wins', $failures, $passes );
agents_api_smoke_assert_equals( 'context-model', $explicit instanceof WP_Agent_Runtime_Profile ? $explicit->model_id() : null, 'explicit nested model override wins', $failures, $passes );
agents_api_smoke_assert_equals( array( 'source' => 'context', 'path' => 'provider_id' ), $explicit instanceof WP_Agent_Runtime_Profile ? $explicit->provenance()['provider_id'] : null, 'explicit provider provenance records context override', $failures, $passes );

$mode_profile = wp_resolve_agent_runtime_profile( 'runtime-profile-agent', array( 'mode' => 'design' ) );
agents_api_smoke_assert_equals( 'mode-provider', $mode_profile instanceof WP_Agent_Runtime_Profile ? $mode_profile->provider_id() : null, 'mode provider resolves from default config', $failures, $passes );
agents_api_smoke_assert_equals( 'mode-model', $mode_profile instanceof WP_Agent_Runtime_Profile ? $mode_profile->model_id() : null, 'mode model resolves from default config', $failures, $passes );
agents_api_smoke_assert_equals( 'mode_models.design.provider_id', $mode_profile instanceof WP_Agent_Runtime_Profile ? $mode_profile->provenance()['provider_id']['path'] : null, 'mode provider provenance path is stable', $failures, $passes );
agents_api_smoke_assert_equals( 'mode_models.design.model_id', $mode_profile instanceof WP_Agent_Runtime_Profile ? $mode_profile->provenance()['model_id']['path'] : null, 'mode model provenance path is stable', $failures, $passes );

$default_profile = wp_resolve_agent_runtime_profile( $agent, array( 'mode' => 'unknown' ) );
agents_api_smoke_assert_equals( 'fallback-provider', $default_profile instanceof WP_Agent_Runtime_Profile ? $default_profile->provider_id() : null, 'default provider resolves without mode match', $failures, $passes );
agents_api_smoke_assert_equals( 'fallback-model', $default_profile instanceof WP_Agent_Runtime_Profile ? $default_profile->model_id() : null, 'default model resolves without mode match', $failures, $passes );

$host_profile = wp_resolve_agent_runtime_profile(
	$agent,
	array(
		'runtime_profile_providers' => array( new Agents_API_Smoke_Runtime_Profile_Provider() ),
	)
);
agents_api_smoke_assert_equals( 'host-provider', $host_profile instanceof WP_Agent_Runtime_Profile ? $host_profile->provider_id() : null, 'host profile provider is honored before config', $failures, $passes );
agents_api_smoke_assert_equals( 'host-model', $host_profile instanceof WP_Agent_Runtime_Profile ? $host_profile->model_id() : null, 'host profile model is honored before config', $failures, $passes );

agents_api_smoke_assert_equals(
	array(
		'agent_slug'  => 'runtime-profile-agent',
		'provider_id' => 'host-provider',
		'model_id'    => 'host-model',
		'identity'    => null,
		'provenance'  => $host_profile instanceof WP_Agent_Runtime_Profile ? $host_profile->provenance() : array(),
	),
	$host_profile instanceof WP_Agent_Runtime_Profile ? $host_profile->to_array() : null,
	'runtime profile to_array shape is stable',
	$failures,
	$passes
);

agents_api_smoke_finish( 'Agents API runtime profile', $failures, $passes );

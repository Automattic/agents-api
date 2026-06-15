<?php
/**
 * Pure-PHP smoke test for agent registry behavior.
 *
 * Run with: php tests/registry-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-registry-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

// Under real WordPress _doing_it_wrong() is native, so the duplicate-agent
// notice is delivered through the doing_it_wrong_run action rather than the
// pure-PHP shim. Capture it into the same log the assertions read, and silence
// the would-be error so the smoke does not abort.
if ( function_exists( 'add_action' ) && function_exists( 'remove_all_filters' ) ) {
	add_action(
		'doing_it_wrong_run',
		static function ( $function_name, $message, $version ): void {
			$GLOBALS['__agents_api_smoke_wrong'][] = array(
				'function' => (string) $function_name,
				'message'  => (string) $message,
				'version'  => (string) $version,
			);
		},
		10,
		3
	);
	add_filter( 'doing_it_wrong_trigger_error', '__return_false' );
}

add_action(
	'wp_agents_api_init',
	static function () {
		wp_register_agent(
			'collision-agent',
			array(
				'label' => 'Collision Agent',
				'meta'  => array(
					'source_plugin'  => 'example-plugin/example-plugin.php',
					'source_type'    => 'bundled-agent',
					'source_package' => 'example-package',
					'source_version' => '1.2.3',
				),
			)
		);

		wp_register_agent(
			'collision-agent',
			array(
				'label' => 'Duplicate Collision Agent',
			)
		);
	}
);

do_action( 'init' );

$registered_agent = wp_get_agent( 'collision-agent' );
$duplicate_notice = end( $GLOBALS['__agents_api_smoke_wrong'] );

agents_api_smoke_assert_equals( true, $registered_agent instanceof WP_Agent, 'first agent remains registered after duplicate attempt', $failures, $passes );
agents_api_smoke_assert_equals(
	array(
		'source_plugin'  => 'example-plugin/example-plugin.php',
		'source_type'    => 'bundled-agent',
		'source_package' => 'example-package',
		'source_version' => '1.2.3',
	),
	$registered_agent instanceof WP_Agent ? $registered_agent->get_meta() : array(),
	'agent provenance metadata is preserved',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	true,
	is_array( $duplicate_notice ) && str_contains( $duplicate_notice['message'], 'Existing source: plugin=example-plugin/example-plugin.php, type=bundled-agent, package=example-package, version=1.2.3.' ),
	'duplicate agent notice includes existing provenance',
	$failures,
	$passes
);

$override_agent = new WP_Agent(
	'persona-agent',
	array(
		'runtime_overrides' => array(
			'system_prompt'  => 'Answer as the site concierge.',
			'provider_id'    => 'openai',
			'model_id'       => 'gpt-5.5',
			'temperature'    => '0.2',
			'max_iterations' => 4,
			'tier_1_tools'   => array( 'agents/search', '', 'agents/search' ),
			'greeting'       => 'How can I help?',
		),
	)
);

agents_api_smoke_assert_equals( true, $override_agent->runtime_overrides() instanceof WP_Agent_Runtime_Overrides, 'runtime overrides normalize to value object', $failures, $passes );
agents_api_smoke_assert_equals( 'openai', $override_agent->runtime_overrides()->provider_id(), 'runtime overrides preserve provider', $failures, $passes );
agents_api_smoke_assert_equals( 'gpt-5.5', $override_agent->runtime_overrides()->model_id(), 'runtime overrides preserve model', $failures, $passes );
agents_api_smoke_assert_equals( 0.2, $override_agent->runtime_overrides()->temperature(), 'runtime overrides normalize temperature', $failures, $passes );
agents_api_smoke_assert_equals( 4, $override_agent->runtime_overrides()->max_iterations(), 'runtime overrides preserve max_iterations', $failures, $passes );
agents_api_smoke_assert_equals( array( 'agents/search' ), $override_agent->runtime_overrides()->tier_1_tools(), 'runtime overrides normalize tier_1_tools', $failures, $passes );
agents_api_smoke_assert_equals( null, ( new WP_Agent( 'empty-overrides' ) )->runtime_overrides()->model_id(), 'empty runtime overrides default to null fields', $failures, $passes );

agents_api_smoke_finish( 'Agents API registry', $failures, $passes );

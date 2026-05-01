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

agents_api_smoke_finish( 'Agents API registry', $failures, $passes );

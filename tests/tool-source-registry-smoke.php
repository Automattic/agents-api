<?php
/**
 * Pure-PHP smoke test for tool source registry composition.
 *
 * Run with: php tests/tool-source-registry-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-tool-source-registry-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

$priority_registry = new AgentsAPI\AI\Tools\WP_Agent_Tool_Source_Registry();
$priority_registry->registerSource(
	'late',
	static fn(): array => array(
		'agent/shared' => array(
			'description' => 'Late declaration.',
		),
	),
	20
);
$priority_registry->registerSource(
	'early',
	static fn(): array => array(
		'agent/shared' => array(
			'description' => 'Early declaration.',
		),
	),
	10
);
$priority_tools = $priority_registry->gather();
agents_api_smoke_assert_equals(
	'early',
	$priority_tools['agent/shared']['source'] ?? null,
	'source priority controls default duplicate precedence',
	$failures,
	$passes
);

$seen_contexts = array();
$registry      = new AgentsAPI\AI\Tools\WP_Agent_Tool_Source_Registry();

$registry->registerSource(
	'static',
	static function ( array $context ) use ( &$seen_contexts ): array {
		$seen_contexts['static'] = $context;

		return array(
			'agent/shared' => array(
				'description' => 'Static shared declaration.',
			),
			'static/only'  => array(
				'description' => 'Static-only declaration.',
			),
			'static/minimal' => array(),
		);
	},
	30
);

$registry->registerSource(
	'adjacent',
	static function ( array $context ): array {
		$modes = is_array( $context['modes'] ?? null ) ? $context['modes'] : array();
		if ( ! in_array( 'pipeline', $modes, true ) ) {
			return array();
		}

		return array(
			'agent/shared'  => array(
				'description' => 'Pipeline-adjacent declaration.',
			),
			'adjacent/only' => array(
				'description' => 'Adjacent-only declaration.',
			),
		);
	},
	20
);

$registry->registerSource(
	'runtime',
	static function (
		array $context,
		AgentsAPI\AI\Tools\WP_Agent_Tool_Source_Registry $source_registry
	) use ( $registry, &$failures, &$passes ): array {
		agents_api_smoke_assert_equals(
			$registry,
			$source_registry,
			'source callbacks receive the active registry',
			$failures,
			$passes
		);

		return array(
			'agent/shared' => array(
				'name'        => 'agent/shared',
				'source'      => 'runtime',
				'description' => 'Runtime declaration.',
			),
			'client/pick'  => array(
				'description' => 'Client runtime declaration.',
				'executor'    => 'client',
				'external_executor' => true,
				'runtime_tool' => true,
				'scope'       => 'run',
			),
			'runtime/only' => array(
				'description' => 'Runtime-only declaration.',
			),
		);
	},
	10
);

add_filter(
	'agents_api_tool_sources',
	static function ( array $sources, array $context ): array {
		if ( 'writer' !== ( $context['agent_id'] ?? '' ) ) {
			return $sources;
		}

		$sources['filtered'] = static function (): array {
			return array(
				'filtered/only' => array(
					'description' => 'Filter-injected declaration.',
				),
			);
		};

		return $sources;
	},
	10,
	2
);

add_filter(
	'agents_api_tool_source_order',
	static function (
		array $order,
		array $context,
		AgentsAPI\AI\Tools\WP_Agent_Tool_Source_Registry $source_registry,
		array $sources
	) use ( $registry, &$failures, &$passes ): array {
		agents_api_smoke_assert_equals(
			$registry,
			$source_registry,
			'source order filters receive the active registry',
			$failures,
			$passes
		);
		agents_api_smoke_assert_equals(
			true,
			isset( $sources['runtime'], $sources['static'] ),
			'source order filters receive registered sources',
			$failures,
			$passes
		);

		$modes = is_array( $context['modes'] ?? null ) ? $context['modes'] : array();
		if ( in_array( 'pipeline', $modes, true ) ) {
			return array( 'adjacent', 'runtime', 'filtered', 'static' );
		}

		return $order;
	},
	10,
	4
);

add_filter(
	'agents_api_tool_source_tools',
	static function ( $source_tools, string $source_slug, array $context ) {
		if (
			'runtime' === $source_slug
			&& 'writer' === ( $context['agent_id'] ?? '' )
			&& is_array( $source_tools )
		) {
			$source_tools['runtime/filtered'] = array(
				'description' => 'Filtered runtime declaration.',
			);
		}

		return $source_tools;
	},
	10,
	3
);

$tools = $registry->gather(
	array(
		'agent_id' => 'writer',
		'modes'    => array( 'pipeline' ),
	)
);

agents_api_smoke_assert_equals(
	array(
		'agent/shared',
		'adjacent/only',
		'client/pick',
		'runtime/only',
		'runtime/filtered',
		'filtered/only',
		'static/only',
		'static/minimal',
	),
	array_keys( $tools ),
	'registry gathers multiple ordered sources with duplicate-name precedence',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	'adjacent',
	$tools['agent/shared']['source'],
	'earlier ordered source wins duplicate tool names',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	'static/only',
	$tools['static/only']['name'],
	'registry normalizes missing tool name from array key',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	array(),
	$tools['static/only']['parameters'],
	'registry normalizes missing parameters to an array',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	'static/minimal',
	$tools['static/minimal']['description'],
	'registry defaults missing source descriptions from the tool name',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	'host',
	$tools['static/only']['executor'] ?? null,
	'registry normalizes gathered tools to host executor declarations',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	'run',
	$tools['static/only']['scope'] ?? null,
	'registry normalizes gathered tools to run scope declarations',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	'client',
	$tools['client/pick']['executor'] ?? null,
	'registry preserves client runtime tool executor declarations',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	'client',
	$tools['client/pick']['source'] ?? null,
	'registry preserves client runtime tool source declarations',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	true,
	$tools['client/pick']['external_executor'] ?? false,
	'registry preserves client runtime extension metadata',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	array( 'pipeline' ),
	$seen_contexts['static']['modes'] ?? null,
	'source callbacks receive runtime context including modes',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	true,
	isset( $tools['filtered/only'] ),
	'source filter can inject product/runtime sources',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	true,
	isset( $tools['runtime/filtered'] ),
	'source tools filter can adjust gathered declarations per context',
	$failures,
	$passes
);

agents_api_smoke_finish( 'Agents API tool source registry', $failures, $passes );

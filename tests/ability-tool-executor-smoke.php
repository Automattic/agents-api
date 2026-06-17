<?php
/**
 * Pure-PHP smoke test for ability-backed tool execution.
 *
 * Run with: php tests/ability-tool-executor-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;

		public function __construct( string $code, string $message ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! class_exists( 'WP_Ability' ) ) {
	class WP_Ability {
		private string $name;
		/** @var callable */
		private $callback;

		public function __construct( string $name, callable $callback ) {
			$this->name     = $name;
			$this->callback = $callback;
		}

		public function get_name(): string {
			return $this->name;
		}

		public function execute( $input = null ) {
			return call_user_func( $this->callback, $input );
		}
	}
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( string $name ): ?WP_Ability {
		return $GLOBALS['__agents_api_smoke_abilities'][ $name ] ?? null;
	}
}

/**
 * Create an ability using the loaded runtime contract or the local smoke stub.
 *
 * @param string   $name     Ability name.
 * @param callable $callback Ability execution callback.
 * @return WP_Ability Ability instance.
 */
function agents_api_smoke_create_ability( string $name, callable $callback ): WP_Ability {
	$constructor = new ReflectionMethod( WP_Ability::class, '__construct' );
	$parameters  = $constructor->getParameters();

	if ( isset( $parameters[1] ) && $parameters[1]->hasType() && 'array' === (string) $parameters[1]->getType() ) {
		return new WP_Ability(
			$name,
			array(
				'label'               => $name,
				'description'         => 'Smoke test ability.',
				'category'            => 'agents-api-smoke',
				'execute_callback'    => $callback,
				'permission_callback' => '__return_true',
			)
		);
	}

	return new WP_Ability( $name, $callback );
}

/**
 * Build runtime ability arguments for the WordPress Abilities API.
 *
 * @param string   $name     Ability name.
 * @param callable $callback Ability execution callback.
 * @return array<string, mixed> Ability registration arguments.
 */
function agents_api_smoke_ability_args( string $name, callable $callback ): array {
	return array(
		'label'               => $name,
		'description'         => 'Smoke test ability.',
		'category'            => 'agents-api-smoke',
		'execute_callback'    => $callback,
		'permission_callback' => '__return_true',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => true,
		),
	);
}

/**
 * Register smoke abilities through the loaded runtime when available.
 *
 * @param array<string, callable> $definitions Ability callbacks keyed by ability name.
 */
function agents_api_smoke_register_abilities( array $definitions ): void {
	if ( class_exists( 'WP_Abilities_Registry' ) && class_exists( 'WP_Ability_Categories_Registry' ) ) {
		$category_registry = WP_Ability_Categories_Registry::get_instance();
		if ( null !== $category_registry && ! $category_registry->is_registered( 'agents-api-smoke' ) ) {
			$category_registry->register(
				'agents-api-smoke',
				array(
					'label'       => 'Agents API smoke',
					'description' => 'Smoke test abilities.',
				)
			);
		}

		$ability_registry = WP_Abilities_Registry::get_instance();
		if ( null !== $ability_registry ) {
			foreach ( $definitions as $name => $callback ) {
				if ( ! $ability_registry->is_registered( $name ) ) {
					$ability_registry->register( $name, agents_api_smoke_ability_args( $name, $callback ) );
				}
			}

			return;
		}
	}

	$GLOBALS['__agents_api_smoke_abilities'] = array();
	foreach ( $definitions as $name => $callback ) {
		$GLOBALS['__agents_api_smoke_abilities'][ $name ] = agents_api_smoke_create_ability( $name, $callback );
	}
}

$failures = array();
$passes   = 0;

echo "agents-api-ability-tool-executor-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();


agents_api_smoke_register_abilities(
	array(
		'local/search-posts' => static function ( array $input ): array {
			return array(
				'query' => $input['query'] ?? '',
				'limit' => $input['limit'] ?? 10,
			);
		},
		'local/fail'         => static function (): WP_Error {
			return new WP_Error( 'local_failed', 'The local ability failed.' );
		},
	)
);

$executor = new AgentsAPI\AI\Tools\WP_Agent_Ability_Tool_Executor();
$core     = new AgentsAPI\AI\Tools\WP_Agent_Tool_Execution_Core();

echo "\n[1] Ability executor invokes mapped abilities through the generic tool core:\n";
$tools = array(
	'host/search' => array(
		'name'        => 'host/search',
		'source'      => 'ability',
		'description' => 'Search posts through the host runtime.',
		'executor'    => AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::EXECUTOR_HOST,
		'ability'     => 'local/search-posts',
		'parameters'  => array(
			'type'       => 'object',
			'required'   => array( 'query' ),
			'properties' => array(
				'query' => array( 'type' => 'string' ),
				'limit' => array( 'type' => 'integer' ),
			),
		),
	),
);

$result = $core->executeTool(
	'host/search',
	array( 'query' => 'agents api' ),
	$tools,
	$executor,
	array( 'tool_call_id' => 'call-ability-1' )
);

agents_api_smoke_assert_equals( true, $result['success'] ?? false, 'ability-backed tool execution succeeds', $failures, $passes );
agents_api_smoke_assert_equals( 'host/search', $result['tool_name'] ?? '', 'result keeps model-facing tool name', $failures, $passes );
agents_api_smoke_assert_equals( 'local/search-posts', $result['metadata']['ability_name'] ?? '', 'result records invoked ability name', $failures, $passes );
agents_api_smoke_assert_equals( 'agents api', $result['result']['query'] ?? '', 'ability receives prepared tool parameters', $failures, $passes );
agents_api_smoke_assert_equals( 10, $result['result']['limit'] ?? null, 'ability result payload is preserved', $failures, $passes );

echo "\n[1b] Provider-safe tool aliases resolve back to canonical tool declarations:\n";
$normalized_tool = AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::normalizeForConversationRequest( $tools['host/search'] );
$alias_tools     = array( 'host/search' => $normalized_tool );
$alias_result    = $core->executeTool(
	(string) $normalized_tool['provider_safe_name'],
	array( 'query' => 'safe alias' ),
	$alias_tools,
	$executor,
	array( 'tool_call_id' => 'call-ability-alias-1' )
);

agents_api_smoke_assert_equals( 'host__search', $normalized_tool['provider_safe_name'] ?? '', 'namespaced tool has provider-safe alias', $failures, $passes );
agents_api_smoke_assert_equals( true, $alias_result['success'] ?? false, 'provider-safe alias executes canonical tool', $failures, $passes );
agents_api_smoke_assert_equals( 'host/search', $alias_result['tool_name'] ?? '', 'alias result reports canonical tool name', $failures, $passes );
agents_api_smoke_assert_equals( 'safe alias', $alias_result['result']['query'] ?? '', 'alias execution preserves parameters', $failures, $passes );

echo "\n[2] Ability executor reports registered ability failures without throwing:\n";
$error_result = $executor->executeWP_Agent_Tool_Call(
	array(
		'tool_name'  => 'host/fail',
		'parameters' => array(),
	),
	array( 'ability_name' => 'local/fail' )
);

agents_api_smoke_assert_equals( false, $error_result['success'] ?? true, 'ability WP_Error becomes failed tool result', $failures, $passes );
agents_api_smoke_assert_equals( 'The local ability failed.', $error_result['error'] ?? '', 'ability error message is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'local_failed', $error_result['metadata']['error_code'] ?? '', 'ability error code is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'ability_error', $error_result['metadata']['error_type'] ?? '', 'ability error type is stable', $failures, $passes );

echo "\n[3] Missing ability names produce stable tool errors:\n";
$missing_result = $executor->executeWP_Agent_Tool_Call(
	array(
		'tool_name'  => 'missing/ability',
		'parameters' => array(),
	),
	array()
);

agents_api_smoke_assert_equals( false, $missing_result['success'] ?? true, 'missing ability returns failed tool result', $failures, $passes );
agents_api_smoke_assert_equals( 'ability_not_found', $missing_result['metadata']['error_type'] ?? '', 'missing ability error type is stable', $failures, $passes );

agents_api_smoke_finish( 'ability tool executor smoke', $failures, $passes );

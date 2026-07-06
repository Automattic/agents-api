<?php
/**
 * Pure-PHP smoke test for capability-ceiling enforcement at ability tool execution.
 *
 * Covers issue #412 Stages B+C: an ability-backed tool that declares a
 * `required_capability` is denied before its ability runs when the execution
 * principal's capability ceiling does not permit that capability, and executes
 * unchanged when the ceiling permits it or when no capability is declared.
 *
 * Run with: php tests/ability-tool-ceiling-smoke.php
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

		public function get_input_schema(): array {
			return array(
				'type'       => 'object',
				'properties' => array(
					'draft' => array( 'type' => 'string' ),
				),
			);
		}

		public function get_meta_item( string $key, $default = null ) {
			return $default;
		}

		public function execute( $input = null ) {
			return call_user_func( $this->callback, $input );
		}
	}
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( string $name ): ?WP_Ability {
		return $GLOBALS['__agents_api_ceiling_abilities'][ $name ] ?? null;
	}
}

/**
 * Deterministic WordPress capability check for the default-policy path.
 *
 * Mirrors a small user/capability matrix so the substrate default
 * WP_Agent_WordPress_Authorization_Policy can be exercised without WordPress.
 */
if ( ! function_exists( 'user_can' ) ) {
	function user_can( int $user_id, string $capability ): bool {
		$matrix = $GLOBALS['__agents_api_ceiling_user_can'] ?? array();
		$caps   = $matrix[ $user_id ] ?? array();

		return in_array( $capability, $caps, true );
	}
}

$failures = array();
$passes   = 0;

echo "agents-api-ability-tool-ceiling-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

use AgentsAPI\AI\Tools\WP_Agent_Ability_Tool_Executor;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Execution_Core;
use AgentsAPI\AI\WP_Agent_Execution_Principal;

/**
 * Register a smoke ability that records each dispatch.
 *
 * @param string $name Ability name.
 */
function ceiling_smoke_register_ability( string $name ): void {
	$GLOBALS['__agents_api_ceiling_abilities'][ $name ] = new WP_Ability(
		$name,
		static function ( $input ) use ( $name ): array {
			$GLOBALS['__agents_api_ceiling_dispatched'][ $name ] = ( $GLOBALS['__agents_api_ceiling_dispatched'][ $name ] ?? 0 ) + 1;

			return array(
				'published' => true,
				'input'     => is_array( $input ) ? $input : array(),
			);
		}
	);
}

/**
 * Reset per-scenario dispatch tracking.
 */
function ceiling_smoke_reset_dispatch(): void {
	$GLOBALS['__agents_api_ceiling_dispatched'] = array();
}

/**
 * Build a host tool declaration with a required capability.
 *
 * @param string $tool_name     Model-facing tool name.
 * @param string $ability_name  Mapped ability name.
 * @param string $required_cap  Required WordPress capability.
 * @return array<string, mixed> Normalized server tool declaration.
 */
function ceiling_smoke_publish_tool( string $tool_name, string $ability_name, string $required_cap ): array {
	return WP_Agent_Tool_Declaration::normalizeForServer(
		array(
			'name'                => $tool_name,
			'source'              => 'ability',
			'description'         => 'Publish a draft through the host runtime.',
			'executor'            => WP_Agent_Tool_Declaration::EXECUTOR_HOST,
			'ability'             => $ability_name,
			'required_capability' => $required_cap,
			'parameters'          => array(
				'type'       => 'object',
				'properties' => array(
					'draft' => array( 'type' => 'string' ),
				),
			),
		)
	);
}

/**
 * Build an ability tool declaration with no required capability.
 *
 * @param string $tool_name    Model-facing tool name.
 * @param string $ability_name Mapped ability name.
 * @return array<string, mixed> Normalized server tool declaration.
 */
function ceiling_smoke_open_tool( string $tool_name, string $ability_name ): array {
	return WP_Agent_Tool_Declaration::normalizeForServer(
		array(
			'name'        => $tool_name,
			'source'      => 'ability',
			'description' => 'Open tool with no capability gate.',
			'executor'    => WP_Agent_Tool_Declaration::EXECUTOR_HOST,
			'ability'     => $ability_name,
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'draft' => array( 'type' => 'string' ),
				),
			),
		)
	);
}

ceiling_smoke_register_ability( 'local/publish-draft' );
ceiling_smoke_register_ability( 'local/open-info' );

$GLOBALS['__agents_api_ceiling_user_can'] = array(
	7 => array( 'publish_posts', 'edit_posts' ),
	9 => array( 'edit_posts' ),
);

$executor = new WP_Agent_Ability_Tool_Executor();
$core     = new WP_Agent_Tool_Execution_Core();

echo "\n[1] Declarative required_capability is resolved and normalized on server declarations:\n";
$publish_tool = ceiling_smoke_publish_tool( 'host/publish', 'local/publish-draft', 'publish_posts' );

agents_api_smoke_assert_equals( 'publish_posts', WP_Agent_Tool_Declaration::requiredCapability( $publish_tool ), 'normalized server declaration exposes required capability', $failures, $passes );
agents_api_smoke_assert_equals( 'publish_posts', $publish_tool[ WP_Agent_Tool_Declaration::REQUIRED_CAPABILITY ] ?? '', 'normalized server declaration carries required capability field', $failures, $passes );

$open_tool = ceiling_smoke_open_tool( 'host/info', 'local/open-info' );
agents_api_smoke_assert_equals( '', WP_Agent_Tool_Declaration::requiredCapability( $open_tool ), 'declaration without required capability resolves to empty string', $failures, $passes );
agents_api_smoke_assert_equals( false, array_key_exists( WP_Agent_Tool_Declaration::REQUIRED_CAPABILITY, $open_tool ), 'normalized declaration omits the field when no capability is declared', $failures, $passes );

$non_string_capability = WP_Agent_Tool_Declaration::normalizeForServer(
	array(
		'name'                => 'host/bad-cap',
		'source'              => 'ability',
		'description'         => 'Bad capability shape.',
		'executor'            => WP_Agent_Tool_Declaration::EXECUTOR_HOST,
		'required_capability' => array( 'not', 'a', 'string' ),
	)
);
agents_api_smoke_assert_equals( '', WP_Agent_Tool_Declaration::requiredCapability( $non_string_capability ), 'non-string required capability is rejected to empty string', $failures, $passes );
agents_api_smoke_assert_equals( false, array_key_exists( WP_Agent_Tool_Declaration::REQUIRED_CAPABILITY, $non_string_capability ), 'non-string required capability is dropped from normalized declaration', $failures, $passes );

echo "\n[2] Ceiling denies the required capability -> ability is NOT dispatched:\n";
ceiling_smoke_reset_dispatch();
$denied_principal = WP_Agent_Execution_Principal::agent_token(
	7,
	'publishing-agent',
	901,
	WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST,
	array(),
	'site:42',
	'ci',
	new WP_Agent_Capability_Ceiling( 7, array( 'edit_posts' ) )
);

$denied_result = $core->executeTool(
	'host/publish',
	array( 'draft' => 'hello-world' ),
	array( 'host/publish' => $publish_tool ),
	$executor,
	array(
		'principal' => $denied_principal,
		'tool_call_id' => 'call-denied-1',
	)
);

agents_api_smoke_assert_equals( false, $denied_result['success'] ?? true, 'capability denial becomes failed tool result', $failures, $passes );
agents_api_smoke_assert_equals( 'capability_denied', $denied_result['metadata']['error_type'] ?? '', 'denial records capability_denied error type', $failures, $passes );
agents_api_smoke_assert_equals( 'publish_posts', $denied_result['metadata']['required_capability'] ?? '', 'denial records the required capability', $failures, $passes );
agents_api_smoke_assert_equals( 'local/publish-draft', $denied_result['metadata']['ability_name'] ?? '', 'denial records the mapped ability name', $failures, $passes );
agents_api_smoke_assert_equals( 'capability_not_permitted', $denied_result['metadata']['denial']['reason'] ?? '', 'denial records stable reason code', $failures, $passes );
agents_api_smoke_assert_equals( false, $denied_result['metadata']['denial']['allowed'] ?? true, 'denial envelope marks operation not allowed', $failures, $passes );
agents_api_smoke_assert_equals( 'publishing-agent', $denied_result['metadata']['denial']['principal']['effective_agent_id'] ?? '', 'denial carries audit-safe principal metadata', $failures, $passes );
agents_api_smoke_assert_equals( true, ( $GLOBALS['__agents_api_ceiling_dispatched']['local/publish-draft'] ?? 0 ) === 0, 'denied ability is never dispatched', $failures, $passes );

echo "\n[3] Ceiling permits the required capability and WordPress user allows it -> ability EXECUTES:\n";
ceiling_smoke_reset_dispatch();
$allowed_principal = WP_Agent_Execution_Principal::agent_token(
	7,
	'publishing-agent',
	902,
	WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST,
	array(),
	'site:42',
	'ci',
	new WP_Agent_Capability_Ceiling( 7, array( 'publish_posts', 'edit_posts' ) )
);

$allowed_result = $core->executeTool(
	'host/publish',
	array( 'draft' => 'hello-world' ),
	array( 'host/publish' => $publish_tool ),
	$executor,
	array(
		'principal' => $allowed_principal,
		'tool_call_id' => 'call-allowed-1',
	)
);

agents_api_smoke_assert_equals( true, $allowed_result['success'] ?? false, 'permitted capability executes the ability', $failures, $passes );
agents_api_smoke_assert_equals( 'local/publish-draft', $allowed_result['metadata']['ability_name'] ?? '', 'permitted execution records ability name', $failures, $passes );
agents_api_smoke_assert_equals( 1, $GLOBALS['__agents_api_ceiling_dispatched']['local/publish-draft'] ?? 0, 'permitted ability is dispatched exactly once', $failures, $passes );

echo "\n[4] Ceiling permits the capability but WordPress user lacks it -> DENIED:\n";
ceiling_smoke_reset_dispatch();
$no_wp_cap_principal = WP_Agent_Execution_Principal::agent_token(
	9,
	'publishing-agent',
	903,
	WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST,
	array(),
	'site:42',
	'ci',
	new WP_Agent_Capability_Ceiling( 9, array( 'publish_posts', 'edit_posts' ) )
);

$no_wp_cap_result = $core->executeTool(
	'host/publish',
	array( 'draft' => 'hello-world' ),
	array( 'host/publish' => $publish_tool ),
	$executor,
	array(
		'principal' => $no_wp_cap_principal,
		'tool_call_id' => 'call-no-wp-cap-1',
	)
);

agents_api_smoke_assert_equals( false, $no_wp_cap_result['success'] ?? true, 'ceiling permits but WordPress user lacks capability -> denied', $failures, $passes );
agents_api_smoke_assert_equals( 'capability_denied', $no_wp_cap_result['metadata']['error_type'] ?? '', 'WordPress-user denial records capability_denied', $failures, $passes );
agents_api_smoke_assert_equals( 0, $GLOBALS['__agents_api_ceiling_dispatched']['local/publish-draft'] ?? 0, 'WordPress-user denial never dispatches ability', $failures, $passes );

echo "\n[5] Required capability declared but no principal threaded -> DENIED (fail closed):\n";
ceiling_smoke_reset_dispatch();
$no_principal_result = $core->executeTool(
	'host/publish',
	array( 'draft' => 'hello-world' ),
	array( 'host/publish' => $publish_tool ),
	$executor,
	array( 'tool_call_id' => 'call-no-principal-1' )
);

agents_api_smoke_assert_equals( false, $no_principal_result['success'] ?? true, 'missing principal with required capability fails closed', $failures, $passes );
agents_api_smoke_assert_equals( 'capability_denied', $no_principal_result['metadata']['error_type'] ?? '', 'missing principal denial records capability_denied', $failures, $passes );
agents_api_smoke_assert_equals( 'principal_unavailable', $no_principal_result['metadata']['denial']['reason'] ?? '', 'missing principal denial records principal_unavailable reason', $failures, $passes );
$no_principal_meta = $no_principal_result['metadata']['denial']['principal'] ?? null;
agents_api_smoke_assert_equals( true, null === $no_principal_meta || ( is_array( $no_principal_meta ) && array() === $no_principal_meta ), 'missing principal denial carries no principal metadata', $failures, $passes );
agents_api_smoke_assert_equals( 0, $GLOBALS['__agents_api_ceiling_dispatched']['local/publish-draft'] ?? 0, 'missing principal denial never dispatches ability', $failures, $passes );

echo "\n[6] No required capability declared -> unchanged, executes without a principal:\n";
ceiling_smoke_reset_dispatch();
$open_result = $core->executeTool(
	'host/info',
	array( 'draft' => 'context' ),
	array( 'host/info' => $open_tool ),
	$executor,
	array( 'tool_call_id' => 'call-open-1' )
);

agents_api_smoke_assert_equals( true, $open_result['success'] ?? false, 'tool without required capability executes without a principal', $failures, $passes );
agents_api_smoke_assert_equals( 1, $GLOBALS['__agents_api_ceiling_dispatched']['local/open-info'] ?? 0, 'ungated ability is dispatched exactly once', $failures, $passes );

echo "\n[7] Default substrate policy is used when context omits an authorization policy:\n";
ceiling_smoke_reset_dispatch();
$default_policy_result = $executor->executeWP_Agent_Tool_Call(
	array(
		'tool_name'  => 'host/publish',
		'parameters' => array( 'draft' => 'direct-call' ),
	),
	$publish_tool,
	array(
		'principal' => $allowed_principal,
	)
);

agents_api_smoke_assert_equals( true, $default_policy_result['success'] ?? false, 'default substrate policy permits allowed capability', $failures, $passes );
agents_api_smoke_assert_equals( 1, $GLOBALS['__agents_api_ceiling_dispatched']['local/publish-draft'] ?? 0, 'default policy path dispatches the ability', $failures, $passes );

echo "\n[8] Host-supplied authorization policy in context overrides the substrate default:\n";
ceiling_smoke_reset_dispatch();
$stub_policy = new WP_Agent_WordPress_Authorization_Policy(
	null,
	static function ( int $user_id, string $capability ): bool {
		return false;
	}
);

$override_result = $executor->executeWP_Agent_Tool_Call(
	array(
		'tool_name'  => 'host/publish',
		'parameters' => array( 'draft' => 'override' ),
	),
	$publish_tool,
	array(
		'principal'           => $allowed_principal,
		'authorization_policy' => $stub_policy,
	)
);

agents_api_smoke_assert_equals( false, $override_result['success'] ?? true, 'host-supplied policy overrides substrate default and denies', $failures, $passes );
agents_api_smoke_assert_equals( 'capability_denied', $override_result['metadata']['error_type'] ?? '', 'host policy denial records capability_denied', $failures, $passes );
agents_api_smoke_assert_equals( 0, $GLOBALS['__agents_api_ceiling_dispatched']['local/publish-draft'] ?? 0, 'host policy denial never dispatches ability', $failures, $passes );

echo "\n[9] Principal with no ceiling restriction falls back to WordPress user capabilities:\n";
ceiling_smoke_reset_dispatch();
$unrestricted_principal = WP_Agent_Execution_Principal::user_session(
	7,
	'publishing-agent',
	WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST,
	array(),
	'site:42'
);

$unrestricted_result = $core->executeTool(
	'host/publish',
	array( 'draft' => 'no-ceiling' ),
	array( 'host/publish' => $publish_tool ),
	$executor,
	array(
		'principal' => $unrestricted_principal,
		'tool_call_id' => 'call-unrestricted-1',
	)
);

agents_api_smoke_assert_equals( true, $unrestricted_result['success'] ?? false, 'principal without ceiling restriction executes when WordPress user allows capability', $failures, $passes );
agents_api_smoke_assert_equals( 1, $GLOBALS['__agents_api_ceiling_dispatched']['local/publish-draft'] ?? 0, 'unrestricted ceiling dispatches the ability', $failures, $passes );

agents_api_smoke_finish( 'ability tool ceiling smoke', $failures, $passes );

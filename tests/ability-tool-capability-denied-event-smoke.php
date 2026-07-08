<?php
/**
 * Pure-PHP smoke test for the capability-denied audit action hook.
 *
 * Covers issue #412 Stage D: when an ability-backed tool call is denied on
 * capability grounds, the `agents_api_tool_capability_denied` action fires
 * exactly once with a redaction-safe, JSON-friendly payload, and the returned
 * denial tool result is unchanged (the hook is a pure notification). An allowed
 * call must NOT fire the denial event.
 *
 * No-listener safety (the hook is a plain do_action) is exercised by
 * ability-tool-ceiling-smoke.php, which runs the denial path without attaching
 * a listener. This file attaches a listener and asserts the payload contract.
 *
 * Run with: php tests/ability-tool-capability-denied-event-smoke.php
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
		return $GLOBALS['__agents_api_denied_event_abilities'][ $name ] ?? null;
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
		$matrix = $GLOBALS['__agents_api_denied_event_user_can'] ?? array();
		$caps   = $matrix[ $user_id ] ?? array();

		return in_array( $capability, $caps, true );
	}
}

$failures = array();
$passes   = 0;

echo "agents-api-ability-tool-capability-denied-event-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

use AgentsAPI\AI\Tools\WP_Agent_Ability_Tool_Executor;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Execution_Core;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Parameters;
use AgentsAPI\AI\WP_Agent_Execution_Principal;

/**
 * Register a smoke ability that records each dispatch.
 *
 * @param string $name Ability name.
 */
function denied_event_smoke_register_ability( string $name ): void {
	$GLOBALS['__agents_api_denied_event_abilities'][ $name ] = new WP_Ability(
		$name,
		static function ( $input ) use ( $name ): array {
			$GLOBALS['__agents_api_denied_event_dispatched'][ $name ] = ( $GLOBALS['__agents_api_denied_event_dispatched'][ $name ] ?? 0 ) + 1;

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
function denied_event_smoke_reset_dispatch(): void {
	$GLOBALS['__agents_api_denied_event_dispatched'] = array();
}

/**
 * Attach a fresh capture listener for the denial event.
 *
 * Resets the captured payload/count and returns a reset helper closure.
 */
function denied_event_smoke_attach_listener(): void {
	$GLOBALS['__agents_api_denied_event_captures'] = array();
	$GLOBALS['__agents_api_denied_event_count']    = 0;

	add_action(
		'agents_api_tool_capability_denied',
		static function ( $denial, $context ): void {
			$GLOBALS['__agents_api_denied_event_captures'][] = array(
				'denial'  => $denial,
				'context' => $context,
			);
			++$GLOBALS['__agents_api_denied_event_count'];
		},
		10,
		2
	);
}

/**
 * Reset the global action registry for a single hook (no remove_action stub).
 *
 * @param string $hook Hook name.
 */
function denied_event_smoke_clear_hook( string $hook ): void {
	unset( $GLOBALS['__agents_api_smoke_actions'][ $hook ] );
	unset( $GLOBALS['__agents_api_smoke_done'][ $hook ] );
	$GLOBALS['__agents_api_denied_event_count'] = 0;
	$GLOBALS['__agents_api_denied_event_captures'] = array();
}

/**
 * Build a host tool declaration with a required capability.
 *
 * @param string $tool_name     Model-facing tool name.
 * @param string $ability_name  Mapped ability name.
 * @param string $required_cap  Required WordPress capability.
 * @return array<string, mixed> Normalized server tool declaration.
 */
function denied_event_smoke_publish_tool( string $tool_name, string $ability_name, string $required_cap ): array {
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

denied_event_smoke_register_ability( 'local/publish-draft' );

$GLOBALS['__agents_api_denied_event_user_can'] = array(
	7 => array( 'publish_posts', 'edit_posts' ),
	9 => array( 'edit_posts' ),
);

$executor = new WP_Agent_Ability_Tool_Executor();
$core     = new WP_Agent_Tool_Execution_Core();

$publish_tool = denied_event_smoke_publish_tool( 'host/publish', 'local/publish-draft', 'publish_posts' );

echo "\n[1] Capability-denied call fires the audit event exactly once with a redaction-safe payload:\n";
denied_event_smoke_reset_dispatch();
denied_event_smoke_attach_listener();

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

$denied_context = array(
	'principal'     => $denied_principal,
	'tool_call_id'  => 'call-denied-event-1',
);

$denied_result = $core->executeTool(
	'host/publish',
	array(
		'draft'   => 'hello-world',
		'api_key' => 'leaked-secret-value',
	),
	array( 'host/publish' => $publish_tool ),
	$executor,
	$denied_context
);

agents_api_smoke_assert_equals( 1, $GLOBALS['__agents_api_denied_event_count'] ?? 0, 'denied call fires the audit event exactly once', $failures, $passes );
agents_api_smoke_assert_equals( 1, did_action( 'agents_api_tool_capability_denied' ), 'did_action reports a single firing for the denied call', $failures, $passes );

$denial_payload = $GLOBALS['__agents_api_denied_event_captures'][0]['denial'] ?? null;
$denial_context = $GLOBALS['__agents_api_denied_event_captures'][0]['context'] ?? null;

agents_api_smoke_assert_equals( true, is_array( $denial_payload ), 'denial payload is an array', $failures, $passes );
agents_api_smoke_assert_equals( 1, $denial_payload['schema_version'] ?? null, 'denial payload carries schema_version', $failures, $passes );
agents_api_smoke_assert_equals( 'tool_execution', $denial_payload['operation'] ?? null, 'denial payload carries tool_execution operation', $failures, $passes );
agents_api_smoke_assert_equals( 'host/publish', $denial_payload['tool_name'] ?? null, 'denial payload carries the model-facing tool name', $failures, $passes );
agents_api_smoke_assert_equals( 'local/publish-draft', $denial_payload['ability_name'] ?? null, 'denial payload carries the resolved ability name', $failures, $passes );
agents_api_smoke_assert_equals( 'publish_posts', $denial_payload['required_capability'] ?? null, 'denial payload carries the required capability', $failures, $passes );
agents_api_smoke_assert_equals( 'capability_not_permitted', $denial_payload['reason'] ?? null, 'denial payload carries the stable reason code', $failures, $passes );
agents_api_smoke_assert_equals( true, is_array( $denial_payload['principal'] ?? null ), 'denial payload carries safe principal metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'publishing-agent', $denial_payload['principal']['effective_agent_id'] ?? null, 'denial principal metadata exposes effective agent id', $failures, $passes );
agents_api_smoke_assert_equals( true, $denial_payload['parameters_redacted'] ?? false, 'denial payload marks parameters as redacted', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Tool_Parameters::REDACTED_VALUE, $denial_payload['parameters']['api_key'] ?? null, 'denial payload redacts the sensitive api_key parameter', $failures, $passes );
agents_api_smoke_assert_equals( 'hello-world', $denial_payload['parameters']['draft'] ?? null, 'denial payload preserves the non-sensitive draft parameter', $failures, $passes );
agents_api_smoke_assert_equals( false, false !== strpos( (string) wp_json_encode( $denial_payload ), 'leaked-secret-value' ), 'denial payload never exposes the raw secret value', $failures, $passes );
agents_api_smoke_assert_equals( $denied_context, $denial_context, 'denial event receives the host runtime context unchanged', $failures, $passes );
agents_api_smoke_assert_equals( 0, $GLOBALS['__agents_api_denied_event_dispatched']['local/publish-draft'] ?? 0, 'denied ability is never dispatched', $failures, $passes );

echo "\n[2] The hook is a pure notification: the returned denial result is unchanged:\n";
agents_api_smoke_assert_equals( false, $denied_result['success'] ?? true, 'denied call returns a failed tool result', $failures, $passes );
agents_api_smoke_assert_equals( 'capability_denied', $denied_result['metadata']['error_type'] ?? '', 'returned result records capability_denied error type', $failures, $passes );
agents_api_smoke_assert_equals( 'publish_posts', $denied_result['metadata']['required_capability'] ?? '', 'returned result records the required capability', $failures, $passes );
agents_api_smoke_assert_equals( 'local/publish-draft', $denied_result['metadata']['ability_name'] ?? '', 'returned result records the mapped ability name', $failures, $passes );
agents_api_smoke_assert_equals( 'capability_not_permitted', $denied_result['metadata']['denial']['reason'] ?? '', 'returned result denial envelope carries the reason code', $failures, $passes );
agents_api_smoke_assert_equals( false, $denied_result['metadata']['denial']['allowed'] ?? true, 'returned result denial envelope marks operation not allowed', $failures, $passes );
agents_api_smoke_assert_equals( 'publishing-agent', $denied_result['metadata']['denial']['principal']['effective_agent_id'] ?? '', 'returned result denial envelope carries safe principal metadata', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Tool_Parameters::REDACTED_VALUE, $denied_result['metadata']['parameters']['api_key'] ?? null, 'returned result parameters are redacted', $failures, $passes );

echo "\n[3] An allowed call does NOT fire the denial event:\n";
denied_event_smoke_reset_dispatch();
denied_event_smoke_clear_hook( 'agents_api_tool_capability_denied' );
denied_event_smoke_attach_listener();

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
	array(
		'draft'   => 'hello-world',
		'api_key' => 'leaked-secret-value',
	),
	array( 'host/publish' => $publish_tool ),
	$executor,
	array(
		'principal'    => $allowed_principal,
		'tool_call_id' => 'call-allowed-event-1',
	)
);

agents_api_smoke_assert_equals( 0, $GLOBALS['__agents_api_denied_event_count'] ?? 0, 'allowed call does not fire the denial event', $failures, $passes );
agents_api_smoke_assert_equals( 0, did_action( 'agents_api_tool_capability_denied' ), 'did_action reports zero firings for the allowed call', $failures, $passes );
agents_api_smoke_assert_equals( true, $allowed_result['success'] ?? false, 'allowed call executes the ability', $failures, $passes );
agents_api_smoke_assert_equals( 1, $GLOBALS['__agents_api_denied_event_dispatched']['local/publish-draft'] ?? 0, 'allowed ability is dispatched exactly once', $failures, $passes );

echo "\n[4] Missing principal fires the event with principal null (principal_unavailable):\n";
denied_event_smoke_reset_dispatch();
denied_event_smoke_clear_hook( 'agents_api_tool_capability_denied' );
denied_event_smoke_attach_listener();

$no_principal_result = $core->executeTool(
	'host/publish',
	array(
		'draft'   => 'no-principal',
		'api_key' => 'leaked-secret-value',
	),
	array( 'host/publish' => $publish_tool ),
	$executor,
	array( 'tool_call_id' => 'call-no-principal-event-1' )
);

agents_api_smoke_assert_equals( 1, $GLOBALS['__agents_api_denied_event_count'] ?? 0, 'missing principal fires the audit event once', $failures, $passes );
$no_principal_payload = $GLOBALS['__agents_api_denied_event_captures'][0]['denial'] ?? array();
agents_api_smoke_assert_equals( 'principal_unavailable', $no_principal_payload['reason'] ?? '', 'missing principal denial carries principal_unavailable reason', $failures, $passes );
agents_api_smoke_assert_equals( true, array_key_exists( 'principal', $no_principal_payload ) && null === $no_principal_payload['principal'], 'missing principal denial carries null principal metadata', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Tool_Parameters::REDACTED_VALUE, $no_principal_payload['parameters']['api_key'] ?? null, 'missing principal denial still redacts sensitive parameters', $failures, $passes );
agents_api_smoke_assert_equals( false, $no_principal_result['success'] ?? true, 'missing principal denial returns a failed tool result', $failures, $passes );
agents_api_smoke_assert_equals( 'principal_unavailable', $no_principal_result['metadata']['denial']['reason'] ?? '', 'missing principal returned result carries principal_unavailable reason', $failures, $passes );
agents_api_smoke_assert_equals( 0, $GLOBALS['__agents_api_denied_event_dispatched']['local/publish-draft'] ?? 0, 'missing principal denial never dispatches ability', $failures, $passes );

echo "\n[5] The hook is safe with no listener attached (control flow unchanged):\n";
denied_event_smoke_reset_dispatch();
denied_event_smoke_clear_hook( 'agents_api_tool_capability_denied' );

$no_listener_result = $core->executeTool(
	'host/publish',
	array(
		'draft'   => 'no-listener',
		'api_key' => 'leaked-secret-value',
	),
	array( 'host/publish' => $publish_tool ),
	$executor,
	array(
		'principal'    => $denied_principal,
		'tool_call_id' => 'call-no-listener-1',
	)
);

agents_api_smoke_assert_equals( 1, did_action( 'agents_api_tool_capability_denied' ), 'do_action fires once safely even with no listener attached', $failures, $passes );
agents_api_smoke_assert_equals( false, $no_listener_result['success'] ?? true, 'denial still returns a failed result with no listener attached', $failures, $passes );
agents_api_smoke_assert_equals( 'capability_denied', $no_listener_result['metadata']['error_type'] ?? '', 'denial error type is unchanged with no listener attached', $failures, $passes );
agents_api_smoke_assert_equals( 'capability_not_permitted', $no_listener_result['metadata']['denial']['reason'] ?? '', 'denial reason is unchanged with no listener attached', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Tool_Parameters::REDACTED_VALUE, $no_listener_result['metadata']['parameters']['api_key'] ?? null, 'denial parameters are still redacted with no listener attached', $failures, $passes );
agents_api_smoke_assert_equals( 0, $GLOBALS['__agents_api_denied_event_dispatched']['local/publish-draft'] ?? 0, 'denial never dispatches ability with no listener attached', $failures, $passes );

agents_api_smoke_finish( 'capability denied event smoke', $failures, $passes );

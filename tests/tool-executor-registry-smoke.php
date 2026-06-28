<?php
/**
 * Pure-PHP smoke test for per-target tool-executor dispatch.
 *
 * Proves that a tool declaration naming a registered executor target dispatches
 * to that executor, while any tool without a registered target falls back to the
 * caller-provided default executor exactly as before (backward compatibility).
 *
 * Run with: php tests/tool-executor-registry-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

use AgentsAPI\AI\Tools\WP_Agent_Tool_Execution_Core;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Executor;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Executor_Registry;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Result;

/**
 * Recording executor that captures the calls it receives.
 */
class Agents_Api_Recording_Executor implements WP_Agent_Tool_Executor {

	/** @var array<int, array<string, mixed>> */
	public array $calls = array();

	public function __construct( private string $label ) {}

	/**
	 * @param array<mixed> $tool_call       Normalized prepared tool call.
	 * @param array<mixed> $tool_definition Tool declaration selected for the call.
	 * @param array<mixed> $context         Host runtime context.
	 * @return array<mixed> Tool execution result.
	 */
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		$this->calls[] = array(
			'tool_name'  => $tool_call['tool_name'] ?? '',
			'parameters' => $tool_call['parameters'] ?? array(),
		);

		return WP_Agent_Tool_Result::success(
			is_string( $tool_call['tool_name'] ?? null ) ? $tool_call['tool_name'] : '',
			array( 'handled_by' => $this->label ),
			array( 'executor_label' => $this->label )
		);
	}
}

$failures = array();
$passes   = 0;

echo "agents-api-tool-executor-registry-smoke\n";

$default_executor = new Agents_Api_Recording_Executor( 'default' );
$target_executor  = new Agents_Api_Recording_Executor( 'sandbox-target' );

// Generic, consumer-neutral target id and tool source. Core never knows what a
// consumer registers behind this target.
$target_id = 'example/sandbox-runner';

add_filter(
	WP_Agent_Tool_Executor_Registry::EXECUTORS_FILTER,
	static function ( array $executors ) use ( $target_id, $target_executor ): array {
		$executors[ $target_id ] = $target_executor;
		return $executors;
	},
	10,
	2
);

$core = new WP_Agent_Tool_Execution_Core();

$tools = array(
	'sandbox/write_file' => array(
		'name'        => 'sandbox/write_file',
		'source'      => 'sandbox',
		'description' => 'Write a file through a registered execution target.',
		'executor'    => 'host',
		'parameters'  => array(
			'type'       => 'object',
			'required'   => array( 'path' ),
			'properties' => array(
				'path'    => array( 'type' => 'string' ),
				'content' => array( 'type' => 'string' ),
			),
		),
		'runtime'     => array(
			WP_Agent_Tool_Executor_Registry::RUNTIME_EXECUTOR_TARGET => $target_id,
		),
	),
	'host/search'        => array(
		'name'        => 'host/search',
		'source'      => 'host',
		'description' => 'A tool with no registered executor target.',
		'executor'    => 'host',
		'parameters'  => array(
			'type'       => 'object',
			'required'   => array( 'query' ),
			'properties' => array(
				'query' => array( 'type' => 'string' ),
			),
		),
	),
);

echo "\n[1] Tool with a registered target dispatches to that executor (not the default):\n";
$target_result = $core->executeTool(
	'sandbox/write_file',
	array(
		'path'    => 'README.md',
		'content' => 'hello',
	),
	$tools,
	$default_executor,
	array( 'tool_call_id' => 'call-target-1' )
);

agents_api_smoke_assert_equals( true, $target_result['success'] ?? false, 'targeted tool execution succeeds', $failures, $passes );
agents_api_smoke_assert_equals( 'sandbox-target', $target_result['result']['handled_by'] ?? '', 'registered target executor handled the call', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $target_executor->calls ), 'target executor received exactly one call', $failures, $passes );
agents_api_smoke_assert_equals( 'sandbox/write_file', $target_executor->calls[0]['tool_name'] ?? '', 'target executor received the targeted tool', $failures, $passes );
agents_api_smoke_assert_equals( 'README.md', $target_executor->calls[0]['parameters']['path'] ?? '', 'target executor received prepared parameters', $failures, $passes );
agents_api_smoke_assert_equals( 0, count( $default_executor->calls ), 'default executor was not invoked for the targeted tool', $failures, $passes );

echo "\n[2] Backward compatibility: a tool with no registered target uses the default executor:\n";
$default_result = $core->executeTool(
	'host/search',
	array( 'query' => 'agents api' ),
	$tools,
	$default_executor,
	array( 'tool_call_id' => 'call-default-1' )
);

agents_api_smoke_assert_equals( true, $default_result['success'] ?? false, 'untargeted tool execution succeeds', $failures, $passes );
agents_api_smoke_assert_equals( 'default', $default_result['result']['handled_by'] ?? '', 'default executor handled the untargeted call', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $default_executor->calls ), 'default executor received the untargeted call', $failures, $passes );
agents_api_smoke_assert_equals( 'host/search', $default_executor->calls[0]['tool_name'] ?? '', 'default executor received the untargeted tool', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $target_executor->calls ), 'target executor was not invoked for the untargeted tool', $failures, $passes );

echo "\n[3] Unregistered target id falls back to the default executor:\n";
$unmatched_tools = array(
	'sandbox/orphan' => array(
		'name'        => 'sandbox/orphan',
		'source'      => 'sandbox',
		'description' => 'Names a target id that no consumer registered.',
		'executor'    => 'host',
		'parameters'  => array(
			'type'       => 'object',
			'properties' => array(),
		),
		'runtime'     => array(
			WP_Agent_Tool_Executor_Registry::RUNTIME_EXECUTOR_TARGET => 'example/never-registered',
		),
	),
);

$orphan_result = $core->executeTool(
	'sandbox/orphan',
	array(),
	$unmatched_tools,
	$default_executor,
	array( 'tool_call_id' => 'call-orphan-1' )
);

agents_api_smoke_assert_equals( true, $orphan_result['success'] ?? false, 'unregistered-target tool execution succeeds', $failures, $passes );
agents_api_smoke_assert_equals( 'default', $orphan_result['result']['handled_by'] ?? '', 'unregistered target falls back to default executor', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $default_executor->calls ), 'default executor handled the orphan-target tool', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $target_executor->calls ), 'target executor untouched by the orphan-target tool', $failures, $passes );

echo "\n[4] Registry helpers resolve targets generically:\n";
$registry = WP_Agent_Tool_Executor_Registry::fromFilters();
agents_api_smoke_assert_equals( true, $registry->hasExecutors(), 'registry built from filter exposes registered executors', $failures, $passes );
agents_api_smoke_assert_equals( $target_executor, $registry->executorForTarget( $target_id ), 'registry resolves the registered target executor', $failures, $passes );
agents_api_smoke_assert_equals( null, $registry->executorForTarget( 'example/never-registered' ), 'registry returns null for an unregistered target', $failures, $passes );
agents_api_smoke_assert_equals( $default_executor, $registry->resolveForTool( $tools['host/search'], $default_executor ), 'resolveForTool falls back to default when no target is declared', $failures, $passes );
agents_api_smoke_assert_equals( $target_executor, $registry->resolveForTool( $tools['sandbox/write_file'], $default_executor ), 'resolveForTool routes a declared target to its executor', $failures, $passes );
agents_api_smoke_assert_equals( $target_id, WP_Agent_Tool_Executor_Registry::targetIdFromDeclaration( $tools['sandbox/write_file'] ), 'targetIdFromDeclaration reads runtime.executor_target', $failures, $passes );
agents_api_smoke_assert_equals( '', WP_Agent_Tool_Executor_Registry::targetIdFromDeclaration( $tools['host/search'] ), 'targetIdFromDeclaration is empty without a target', $failures, $passes );

agents_api_smoke_finish( 'tool executor registry smoke', $failures, $passes );

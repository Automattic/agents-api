<?php
/**
 * Pure-PHP smoke test for machine-readable error-code preservation.
 *
 * Proves that a tool executor's machine-readable error code (error_type)
 * survives result normalization and the full executePreparedTool() path,
 * whether the executor returns it under metadata or as a top-level field,
 * while results without an error code are unchanged (backward compatibility).
 *
 * Run with: php tests/tool-result-error-type-smoke.php
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
use AgentsAPI\AI\Tools\WP_Agent_Tool_Result;

/**
 * Executor that returns a raw failure shape carrying a top-level error_type,
 * mirroring an executor that reports a stable machine-readable failure code.
 */
class Agents_Api_Error_Code_Executor implements WP_Agent_Tool_Executor {

	public function __construct( private string $error_type, private bool $top_level = true ) {}

	/**
	 * @param array<mixed> $tool_call       Normalized prepared tool call.
	 * @param array<mixed> $tool_definition Tool declaration selected for the call.
	 * @param array<mixed> $context         Host runtime context.
	 * @return array<mixed> Raw tool execution result.
	 */
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		$tool_name = is_string( $tool_call['tool_name'] ?? null ) ? $tool_call['tool_name'] : '';
		$result    = array(
			'success'   => false,
			'tool_name' => $tool_name,
			'error'     => 'Operation rejected.',
		);

		if ( $this->top_level ) {
			$result['error_type'] = $this->error_type;
		} else {
			$result['metadata'] = array( 'error_type' => $this->error_type );
		}

		return $result;
	}
}

$failures = array();
$passes   = 0;

echo "agents-api-tool-result-error-type-smoke\n";

echo "\n[1] normalize() promotes a top-level error_type into metadata:\n";
$normalized_top = WP_Agent_Tool_Result::normalize(
	array(
		'success'    => false,
		'tool_name'  => 'sandbox/write_file',
		'error'      => 'Path escapes the workspace root.',
		'error_type' => 'path_escape',
	)
);
agents_api_smoke_assert_equals( false, $normalized_top['success'], 'top-level error result stays a failure', $failures, $passes );
agents_api_smoke_assert_equals( 'path_escape', $normalized_top['metadata']['error_type'] ?? '', 'top-level error_type survives normalization under metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'Path escapes the workspace root.', $normalized_top['error'] ?? '', 'human-readable error message is unchanged', $failures, $passes );

echo "\n[2] An explicit metadata error_type is preserved and not clobbered:\n";
$normalized_meta = WP_Agent_Tool_Result::normalize(
	array(
		'success'    => false,
		'tool_name'  => 'sandbox/write_file',
		'error'      => 'Operation rejected.',
		'error_type' => 'ignored_top_level',
		'metadata'   => array( 'error_type' => 'explicit_metadata_code' ),
	)
);
agents_api_smoke_assert_equals( 'explicit_metadata_code', $normalized_meta['metadata']['error_type'] ?? '', 'explicit metadata error_type wins over a top-level field', $failures, $passes );

echo "\n[3] Backward compatibility: results with no error code are unchanged:\n";
$normalized_success = WP_Agent_Tool_Result::success( 'host/search', array( 'hits' => 3 ), array( 'source' => 'index' ) );
agents_api_smoke_assert_equals( true, $normalized_success['success'], 'success result stays successful', $failures, $passes );
agents_api_smoke_assert_equals( false, array_key_exists( 'error_type', $normalized_success['metadata'] ?? array() ), 'success result gains no error_type', $failures, $passes );
agents_api_smoke_assert_equals( false, array_key_exists( 'error_type', $normalized_success ?? array() ), 'success result gains no top-level error_type', $failures, $passes );

$normalized_plain_error = WP_Agent_Tool_Result::error( 'host/search', 'Nothing found.' );
agents_api_smoke_assert_equals( false, $normalized_plain_error['success'], 'plain error result stays a failure', $failures, $passes );
agents_api_smoke_assert_equals( 'Nothing found.', $normalized_plain_error['error'] ?? '', 'plain error message is unchanged', $failures, $passes );
agents_api_smoke_assert_equals( false, array_key_exists( 'error_type', $normalized_plain_error['metadata'] ?? array() ), 'plain error result invents no error_type', $failures, $passes );

echo "\n[4] error_type survives the full executePreparedTool() path (top-level executor):\n";
$core = new WP_Agent_Tool_Execution_Core();
$tool_definition = array(
	'name'        => 'sandbox/write_file',
	'source'      => 'sandbox',
	'description' => 'Write a file through an execution target.',
	'executor'    => 'host',
	'parameters'  => array(
		'type'       => 'object',
		'required'   => array( 'path' ),
		'properties' => array(
			'path' => array( 'type' => 'string' ),
		),
	),
);

$prepared = $core->prepareWP_Agent_Tool_Call(
	'sandbox/write_file',
	array( 'path' => '../escape' ),
	array( 'sandbox/write_file' => $tool_definition ),
	array( 'tool_call_id' => 'call-error-1' )
);
agents_api_smoke_assert_equals( true, $prepared['ready'] ?? false, 'tool call prepares cleanly for execution', $failures, $passes );

$end_to_end = $core->executePreparedTool(
	$prepared['tool_call'],
	$tool_definition,
	new Agents_Api_Error_Code_Executor( 'path_escape', true )
);
agents_api_smoke_assert_equals( false, $end_to_end['success'] ?? true, 'executePreparedTool surfaces the executor failure', $failures, $passes );
agents_api_smoke_assert_equals( 'path_escape', $end_to_end['metadata']['error_type'] ?? '', 'top-level executor error_type survives executePreparedTool', $failures, $passes );

echo "\n[5] error_type survives the full path when the executor uses metadata directly:\n";
$end_to_end_meta = $core->executePreparedTool(
	$prepared['tool_call'],
	$tool_definition,
	new Agents_Api_Error_Code_Executor( 'path_escape', false )
);
agents_api_smoke_assert_equals( 'path_escape', $end_to_end_meta['metadata']['error_type'] ?? '', 'metadata executor error_type survives executePreparedTool', $failures, $passes );

agents_api_smoke_finish( 'tool result error type smoke', $failures, $passes );

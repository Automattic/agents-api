<?php
/**
 * Pure-PHP smoke tests for the content-redacted tool observability contract.
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-tool-observability-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
require_once __DIR__ . '/../agents-api.php';

$call_event = static function ( string $id, string $name, int $turn, array $parameters ): array {
	return array(
		'type'         => 'tool_call',
		'tool_name'    => $name,
		'tool_call_id' => $id,
		'turn_count'   => $turn,
		'status'       => 'called',
		'metadata'     => array( 'status' => 'called', 'parameters' => $parameters, 'parameters_sha256' => 'sha256:parameter-secret' ),
	);
};
$terminal_event = static function ( string $id, string $name, int $turn, bool $success, bool $rejected = false ): array {
	return array(
		'type'         => 'tool_result',
		'tool_name'    => $name,
		'tool_call_id' => $id,
		'turn_count'   => $turn,
		'status'       => $success ? 'success' : 'error',
		'metadata'     => array( 'status' => $success ? 'success' : 'error', 'success' => $success, 'rejected' => $rejected, 'raw_error' => 'RAW_ERROR_SENTINEL' ),
	);
};
$execution_result = static function ( string $id, string $event_name, string $canonical_name, int $turn, bool $success, $payload ): array {
	$result = array(
		'success'   => $success,
		'tool_name' => $canonical_name,
		'metadata'  => array( 'extension_secret' => 'ARBITRARY_METADATA_SENTINEL' ),
	);
	if ( $success ) {
		$result['result'] = $payload;
	} else {
		$result['error'] = 'RAW_EXECUTOR_ERROR_SENTINEL';
	}

	return array(
		'tool_name'           => $event_name,
		'tool_call_id'        => $id,
		'result'              => $result,
		'parameters'          => array( 'path' => 'RAW_PATH_SENTINEL', 'token' => 'RAW_PARAMETER_SENTINEL' ),
		'parameters_sha256'   => 'sha256:parameter-secret',
		'parameters_redacted' => true,
		'turn_count'          => $turn,
	);
};

$provider_id = 'provider-call-id';
$fallback_id = 'tool-call-2-1';
$result      = AgentsAPI\AI\WP_Agent_Conversation_Result::normalize(
	array(
		'messages'               => array( AgentsAPI\AI\WP_Agent_Message::text( 'assistant', 'safe reply' ) ),
		'tool_events'            => array(
			$call_event( $provider_id, 'provider_alias', 1, array( 'query' => 'RAW_PARAMETER_SENTINEL', 'limit' => 10 ) ),
			$terminal_event( $provider_id, 'provider_alias', 1, true ),
			$call_event( $fallback_id, 'workspace/read', 2, array( 'path' => 'RAW_PATH_SENTINEL' ) ),
			$terminal_event( $fallback_id, 'workspace/read', 2, false ),
			$call_event( 'rejected-id', 'workspace/write', 2, array( 'content' => 'RAW_CONTENT_SENTINEL' ) ),
			$terminal_event( 'rejected-id', 'workspace/write', 2, false, true ),
			$call_event( 'pending-id', 'browser/open', 3, array( 'url' => 'RAW_URL_SENTINEL' ) ),
			array(
				'type'         => 'pending',
				'tool_name'    => 'browser/open',
				'tool_call_id' => 'pending-id',
				'turn_count'   => 3,
				'status'       => 'pending',
				'metadata'     => array( 'status' => 'pending', 'request_id' => 'PRIVATE_REQUEST_SENTINEL' ),
			),
		),
		'tool_execution_results' => array(
			$execution_result( $provider_id, 'provider_alias', 'catalog/search', 1, true, array( 'items' => array( 'RAW_RESULT_SENTINEL' ), 'source' => 'RAW_SOURCE_SENTINEL' ) ),
			$execution_result( $fallback_id, 'workspace/read', 'workspace/read', 2, false, null ),
			$execution_result( 'rejected-id', 'workspace/write', 'workspace/write', 2, false, null ),
		),
		'tool_observability'     => array( 'version' => 999, 'calls' => array( 'MALICIOUS_INPUT_SENTINEL' ) ),
	)
);

$observability = $result['tool_observability'] ?? array();
$calls         = $observability['calls'] ?? array();

agents_api_smoke_assert_equals( 1, $observability['version'] ?? null, 'contract is version 1', $failures, $passes );
agents_api_smoke_assert_equals( array( 1, 2, 3, 4 ), array_column( $calls, 'sequence' ), 'calls retain global order across turns', $failures, $passes );
agents_api_smoke_assert_equals( array( 1, 2, 2, 3 ), array_column( $calls, 'turn' ), 'calls retain source turn numbers', $failures, $passes );
agents_api_smoke_assert_equals( array( $provider_id, $fallback_id, 'rejected-id', 'pending-id' ), array_column( $calls, 'tool_call_id' ), 'provider and fallback ids are preserved', $failures, $passes );
agents_api_smoke_assert_equals( array( 'succeeded', 'failed', 'rejected', 'pending' ), array_column( $calls, 'status' ), 'all terminal and pending statuses normalize once per call', $failures, $passes );
agents_api_smoke_assert_equals( 'catalog/search', $calls[0]['tool_name'] ?? '', 'successful result supplies the canonical tool name', $failures, $passes );
agents_api_smoke_assert_equals( array( 'keys' => array( 'query', 'limit' ), 'count' => 2, 'redacted' => true ), $calls[0]['arguments'] ?? array(), 'arguments expose keys, count, and redaction marker only', $failures, $passes );
agents_api_smoke_assert_equals( array( 'type' => 'object', 'count' => 2 ), $calls[0]['result'] ?? array(), 'successful results expose shape only', $failures, $passes );
agents_api_smoke_assert_equals( array( 'code' => 'agents_api_tool_execution_failed', 'message' => 'Tool execution failed.' ), $calls[1]['error'] ?? array(), 'failure uses an Agents API-owned safe error', $failures, $passes );
agents_api_smoke_assert_equals( array( 'code' => 'agents_api_tool_call_rejected', 'message' => 'Tool call was rejected.' ), $calls[2]['error'] ?? array(), 'rejection uses an Agents API-owned safe error', $failures, $passes );
agents_api_smoke_assert_equals( false, isset( $calls[3]['error'] ), 'pending calls do not fabricate an error', $failures, $passes );

$public_json = json_encode( $observability );
foreach ( array( 'RAW_', 'SENTINEL', 'sha256:', 'parameters_sha256', 'extension_secret', 'request_id', 'MALICIOUS_INPUT' ) as $forbidden ) {
	agents_api_smoke_assert_equals( false, is_string( $public_json ) && str_contains( $public_json, $forbidden ), 'contract omits forbidden content: ' . $forbidden, $failures, $passes );
}

agents_api_smoke_finish( 'Agents API tool observability', $failures, $passes );

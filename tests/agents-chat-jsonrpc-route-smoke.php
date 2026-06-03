<?php
/**
 * Pure-PHP smoke test for the JSON-RPC chat adapter (message/send + message/stream).
 *
 * Run with: php tests/agents-chat-jsonrpc-route-smoke.php
 *
 * Covers the route registration, the request/response mappers, the StreamDelta
 * wire translation, and the streaming-handler contract (input + emit -> deltas
 * + final Task). The SSE I/O wrapper (headers/flush/exit) is thin glue and is
 * exercised indirectly through the frame builders it calls.
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-chat-jsonrpc-route-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

$GLOBALS['__agents_api_smoke_current_user_id'] = 7;
$GLOBALS['__agents_api_smoke_routes']          = array();
$GLOBALS['__agents_api_smoke_abilities']       = array();
$GLOBALS['__agents_api_smoke_categories']      = array();

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): array { return $this->data; }
}

class WP_REST_Request {
	public function __construct( private array $params = array(), private array $json = array() ) {}
	public function get_param( string $key ) {
		return $this->params[ $key ] ?? null;
	}
	public function get_json_params(): array {
		return $this->json;
	}
}

class WP_REST_Response {
	public function __construct( public mixed $data ) {}
}

function rest_ensure_response( $value ): WP_REST_Response {
	return $value instanceof WP_REST_Response ? $value : new WP_REST_Response( $value );
}

function register_rest_route( string $namespace, string $route, array $args ): void {
	$GLOBALS['__agents_api_smoke_routes'][ $namespace . $route ] = $args;
}

function get_current_user_id(): int {
	return (int) $GLOBALS['__agents_api_smoke_current_user_id'];
}

function current_user_can( string $capability ): bool {
	unset( $capability );
	return (bool) ( $GLOBALS['__agents_api_smoke_can_manage'] ?? false );
}

function wp_has_ability_category( string $category ): bool {
	return isset( $GLOBALS['__agents_api_smoke_categories'][ $category ] );
}

function wp_register_ability_category( string $category, array $args ): void {
	$GLOBALS['__agents_api_smoke_categories'][ $category ] = $args;
}

function wp_has_ability( string $ability ): bool {
	return isset( $GLOBALS['__agents_api_smoke_abilities'][ $ability ] );
}

function wp_register_ability( string $ability, array $args ): void {
	$GLOBALS['__agents_api_smoke_abilities'][ $ability ] = $args;
}

function wp_get_ability( string $name ) {
	return $GLOBALS['__agents_api_smoke_abilities'][ $name ] ?? null;
}

agents_api_smoke_require_module();

use function AgentsAPI\AI\Channels\agents_chat_jsonrpc_input_from_params;
use function AgentsAPI\AI\Channels\agents_chat_jsonrpc_task_from_output;
use function AgentsAPI\AI\Channels\agents_chat_jsonrpc_delta_to_wire;
use function AgentsAPI\AI\Channels\agents_chat_jsonrpc_delta_frame;
use function AgentsAPI\AI\Channels\agents_chat_jsonrpc_result_frame;
use function AgentsAPI\AI\Channels\agents_chat_jsonrpc_error_frame;
use function AgentsAPI\AI\Channels\agents_chat_jsonrpc_extract_text;
use function AgentsAPI\AI\Channels\register_chat_stream_handler;

do_action( 'rest_api_init' );

// --- Route registration -----------------------------------------------------
$route_key = 'agents-api/v1/agent/(?P<agent_id>[A-Za-z0-9._-]+)';
agents_api_smoke_assert_equals( true, isset( $GLOBALS['__agents_api_smoke_routes'][ $route_key ] ), 'JSON-RPC agent route registers', $failures, $passes );
agents_api_smoke_assert_equals( 'POST', $GLOBALS['__agents_api_smoke_routes'][ $route_key ]['methods'] ?? null, 'JSON-RPC route uses POST', $failures, $passes );

// --- Input mapping: MessageSendParams -> canonical agents/chat input --------
$params = array(
	'id'        => 'rpc-1',
	'sessionId' => 'sess-9',
	'message'   => array(
		'role'      => 'user',
		'kind'      => 'message',
		'messageId' => 'm-1',
		'parts'     => array(
			array( 'type' => 'text', 'text' => 'Hello ' ),
			array( 'type' => 'text', 'text' => 'world' ),
			array( 'type' => 'text', 'text' => 'SECRET', 'contentType' => 'context' ),
			array( 'type' => 'file', 'file' => array( 'name' => 'a.png', 'mimeType' => 'image/png' ) ),
		),
	),
	'metadata'  => array( 'locale' => 'es' ),
);

$input = agents_chat_jsonrpc_input_from_params( $params, 'support-agent' );
agents_api_smoke_assert_equals( false, $input instanceof WP_Error, 'input mapping succeeds', $failures, $passes );
agents_api_smoke_assert_equals( 'support-agent', $input['agent'] ?? null, 'input carries agent slug from URL', $failures, $passes );
agents_api_smoke_assert_equals( 'Hello world', $input['message'] ?? null, 'input concatenates text parts and skips context parts', $failures, $passes );
agents_api_smoke_assert_equals( 'sess-9', $input['session_id'] ?? null, 'input carries sessionId', $failures, $passes );
agents_api_smoke_assert_equals( 'rpc-1', $input['run_id'] ?? null, 'input maps JSON-RPC id to run_id', $failures, $passes );
agents_api_smoke_assert_equals( 'jsonrpc', $input['client_context']['source'] ?? null, 'input marks jsonrpc source', $failures, $passes );
agents_api_smoke_assert_equals( 'a.png', $input['attachments'][0]['name'] ?? null, 'input extracts file parts as attachments', $failures, $passes );

$empty = agents_chat_jsonrpc_input_from_params( array( 'message' => array( 'parts' => array() ) ), 'support-agent' );
agents_api_smoke_assert_equals( true, $empty instanceof WP_Error, 'input rejects empty message', $failures, $passes );

// extract_text directly
agents_api_smoke_assert_equals( 'ab', agents_chat_jsonrpc_extract_text( array( 'parts' => array( array( 'type' => 'text', 'text' => 'a' ), array( 'type' => 'text', 'text' => 'b' ) ) ) ), 'extract_text concatenates', $failures, $passes );

// --- Output mapping: canonical agents/chat output -> Task --------------------
$task = agents_chat_jsonrpc_task_from_output( array( 'session_id' => 'sess-9', 'reply' => 'hi there', 'run_id' => 'rpc-1' ) );
agents_api_smoke_assert_equals( 'rpc-1', $task['id'] ?? null, 'task id derives from run_id', $failures, $passes );
agents_api_smoke_assert_equals( 'sess-9', $task['sessionId'] ?? null, 'task carries sessionId', $failures, $passes );
agents_api_smoke_assert_equals( 'completed', $task['status']['state'] ?? null, 'task state completed by default', $failures, $passes );
agents_api_smoke_assert_equals( 'agent', $task['status']['message']['role'] ?? null, 'task message role is agent', $failures, $passes );
agents_api_smoke_assert_equals( 'message', $task['status']['message']['kind'] ?? null, 'task message kind is message', $failures, $passes );
agents_api_smoke_assert_equals( 'hi there', $task['status']['message']['parts'][0]['text'] ?? null, 'task message carries reply text', $failures, $passes );

$pending = agents_chat_jsonrpc_task_from_output( array( 'session_id' => 's', 'reply' => 'r', 'run_id' => 'x', 'completed' => false ) );
agents_api_smoke_assert_equals( 'input-required', $pending['status']['state'] ?? null, 'task state input-required when not completed', $failures, $passes );

// --- StreamDelta wire translation ------------------------------------------
$content_wire = agents_chat_jsonrpc_delta_to_wire( array( 'type' => 'content', 'text' => 'tok' ) );
agents_api_smoke_assert_equals( 'content', $content_wire['deltaType'] ?? null, 'content delta -> deltaType content', $failures, $passes );
agents_api_smoke_assert_equals( 'tok', $content_wire['content'] ?? null, 'content delta carries content', $failures, $passes );

$tool_name_wire = agents_chat_jsonrpc_delta_to_wire( array( 'type' => 'tool_call', 'tool_call_id' => 'tc1', 'tool_name' => 'search', 'index' => 2 ) );
agents_api_smoke_assert_equals( 'tool_name', $tool_name_wire['deltaType'] ?? null, 'tool_call -> deltaType tool_name', $failures, $passes );
agents_api_smoke_assert_equals( 'tc1', $tool_name_wire['toolCallId'] ?? null, 'tool_call carries toolCallId', $failures, $passes );
agents_api_smoke_assert_equals( 2, $tool_name_wire['toolCallIndex'] ?? null, 'tool_call carries toolCallIndex', $failures, $passes );

$tool_arg_wire = agents_chat_jsonrpc_delta_to_wire( array( 'type' => 'tool_argument', 'tool_call_id' => 'tc1', 'text' => '{"q":', 'index' => 2 ) );
agents_api_smoke_assert_equals( 'tool_argument', $tool_arg_wire['deltaType'] ?? null, 'tool_argument -> deltaType tool_argument', $failures, $passes );
agents_api_smoke_assert_equals( '{"q":', $tool_arg_wire['content'] ?? null, 'tool_argument carries JSON fragment', $failures, $passes );

// --- Frame builders ---------------------------------------------------------
$delta_frame = agents_chat_jsonrpc_delta_frame( 'task-1', array( 'type' => 'content', 'text' => 'x' ) );
agents_api_smoke_assert_equals( '2.0', $delta_frame['jsonrpc'] ?? null, 'delta frame is jsonrpc 2.0', $failures, $passes );
agents_api_smoke_assert_equals( 'message/delta', $delta_frame['method'] ?? null, 'delta frame method is message/delta', $failures, $passes );
agents_api_smoke_assert_equals( 'task-1', $delta_frame['params']['id'] ?? null, 'delta frame carries task id', $failures, $passes );

$result_frame = agents_chat_jsonrpc_result_frame( 'rpc-1', $task );
agents_api_smoke_assert_equals( 'rpc-1', $result_frame['id'] ?? null, 'result frame echoes rpc id', $failures, $passes );
agents_api_smoke_assert_equals( true, isset( $result_frame['result']['status'] ), 'result frame wraps Task', $failures, $passes );

$error_frame = agents_chat_jsonrpc_error_frame( 'rpc-1', -32601, 'nope' );
agents_api_smoke_assert_equals( -32601, $error_frame['error']['code'] ?? null, 'error frame carries code', $failures, $passes );
agents_api_smoke_assert_equals( 'nope', $error_frame['error']['message'] ?? null, 'error frame carries message', $failures, $passes );

// --- Streaming-handler contract: input + emit -> deltas + final output ------
$emitted = array();
register_chat_stream_handler(
	static function ( array $in, callable $emit ): array {
		$emit( array( 'type' => 'content', 'text' => 'Hel' ) );
		$emit( array( 'type' => 'content', 'text' => 'lo' ) );
		return array( 'session_id' => $in['session_id'] ?? 's', 'reply' => 'Hello', 'run_id' => $in['run_id'] ?? 'r', 'completed' => true );
	}
);

$stream_handler = apply_filters( 'wp_agent_chat_stream_handler', null, $input );
agents_api_smoke_assert_equals( true, is_callable( $stream_handler ), 'stream handler registers via filter', $failures, $passes );

// Drive the handler exactly as the SSE wrapper does: collect delta frames + final task.
$collected_frames = array();
$final_output     = call_user_func(
	$stream_handler,
	$input,
	static function ( array $delta ) use ( &$collected_frames ): void {
		$collected_frames[] = agents_chat_jsonrpc_delta_frame( 'rpc-1', $delta );
	}
);
agents_api_smoke_assert_equals( 2, count( $collected_frames ), 'stream handler emits one frame per token', $failures, $passes );
agents_api_smoke_assert_equals( 'Hel', $collected_frames[0]['params']['delta']['content'] ?? null, 'first delta frame carries first token', $failures, $passes );
$final_task = agents_chat_jsonrpc_task_from_output( $final_output );
agents_api_smoke_assert_equals( 'Hello', $final_task['status']['message']['parts'][0]['text'] ?? null, 'final task carries full reply', $failures, $passes );

agents_api_smoke_finish( 'JSON-RPC chat adapter', $failures, $passes );

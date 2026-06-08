<?php
/**
 * Pure-PHP smoke test for canonical chat run-control abilities.
 *
 * Run with: php tests/chat-run-control-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "chat-run-control-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

$GLOBALS['__agents_api_smoke_abilities']  = array();
$GLOBALS['__agents_api_smoke_categories'] = array();
$GLOBALS['__agents_api_smoke_options']    = array();

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		unset( $capability );
		return true;
	}
}

if ( ! function_exists( 'wp_has_ability_category' ) ) {
	function wp_has_ability_category( string $category ): bool {
		return isset( $GLOBALS['__agents_api_smoke_categories'][ $category ] );
	}
}

if ( ! function_exists( 'wp_register_ability_category' ) ) {
	function wp_register_ability_category( string $category, array $args ): void {
		$GLOBALS['__agents_api_smoke_categories'][ $category ] = $args;
	}
}

if ( ! function_exists( 'wp_has_ability' ) ) {
	function wp_has_ability( string $ability ): bool {
		return isset( $GLOBALS['__agents_api_smoke_abilities'][ $ability ] );
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( string $ability, array $args ): void {
		$GLOBALS['__agents_api_smoke_abilities'][ $ability ] = $args;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) {
		return $GLOBALS['__agents_api_smoke_options'][ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		unset( $autoload );
		$GLOBALS['__agents_api_smoke_options'][ $option ] = $value;
		return true;
	}
}

agents_api_smoke_require_module();

do_action( 'wp_abilities_api_categories_init' );
do_action( 'wp_abilities_api_init' );

agents_api_smoke_assert_equals( true, wp_has_ability( AgentsAPI\AI\Channels\AGENTS_GET_CHAT_RUN_ABILITY ), 'get-run ability registers', $failures, $passes );
agents_api_smoke_assert_equals( true, wp_has_ability( AgentsAPI\AI\Channels\AGENTS_CANCEL_CHAT_RUN_ABILITY ), 'cancel-run ability registers', $failures, $passes );
agents_api_smoke_assert_equals( true, wp_has_ability( AgentsAPI\AI\Channels\AGENTS_QUEUE_CHAT_MESSAGE_ABILITY ), 'queue-message ability registers', $failures, $passes );
agents_api_smoke_assert_equals( true, wp_has_ability( AgentsAPI\AI\Channels\AGENTS_LIST_CHAT_RUN_EVENTS_ABILITY ), 'list-run-events ability registers', $failures, $passes );
agents_api_smoke_assert_equals( true, in_array( 'run_id', AgentsAPI\AI\Channels\agents_chat_output_schema()['required'] ?? array(), true ) || isset( AgentsAPI\AI\Channels\agents_chat_output_schema()['properties']['run_id'] ), 'chat output schema exposes run_id', $failures, $passes );

$captured_chat_input = array();
add_filter(
	'wp_agent_chat_handler',
	static function ( $handler, array $input ) use ( &$captured_chat_input ) {
		unset( $handler );
		$captured_chat_input = $input;
		return static function ( array $runtime_input ): array {
			return array(
				'session_id' => 'session-1',
				'reply'      => 'hello',
				'metadata'   => array( 'runtime' => 'smoke' ),
			);
		};
	},
	10,
	2
);

$chat = AgentsAPI\AI\Channels\agents_chat_dispatch(
	array(
		'agent'      => 'demo-agent',
		'message'    => 'Hello',
		'session_id' => 'session-1',
	)
);

agents_api_smoke_assert_equals( true, is_array( $chat ), 'chat dispatch succeeds', $failures, $passes );
agents_api_smoke_assert_equals( true, isset( $captured_chat_input['run_id'] ) && '' !== $captured_chat_input['run_id'], 'chat dispatch passes generated run_id to runtime', $failures, $passes );
agents_api_smoke_assert_equals( $captured_chat_input['run_id'], $chat['run_id'] ?? null, 'chat dispatch returns generated run_id', $failures, $passes );
$stored = AgentsAPI\AI\Channels\agents_get_chat_run( array( 'session_id' => 'session-1', 'run_id' => $chat['run_id'] ) );
agents_api_smoke_assert_equals( 'completed', $stored['status'] ?? null, 'chat dispatch records completed run by default', $failures, $passes );

AgentsAPI\AI\WP_Agent_Chat_Run_Control::start_run( 'run-default-cancel', 'session-1' );
$default_cancelled = AgentsAPI\AI\Channels\agents_cancel_chat_run( array( 'session_id' => 'session-1', 'run_id' => 'run-default-cancel' ) );
agents_api_smoke_assert_equals( 'cancelling', $default_cancelled['status'] ?? null, 'default cancel marks running runs as cancelling', $failures, $passes );

$default_queued = AgentsAPI\AI\Channels\agents_queue_chat_message(
	array(
		'agent'      => 'demo-agent',
		'session_id' => 'session-default',
		'message'    => 'Default queue',
	)
);
agents_api_smoke_assert_equals( 'queued', $default_queued['status'] ?? null, 'default queue handler accepts messages', $failures, $passes );

add_filter(
	'wp_agent_chat_run_status_handler',
	static fn() => static fn( array $input ): array => array(
		'run_id'     => $input['run_id'],
		'session_id' => $input['session_id'],
		'status'     => 'running',
		'started_at' => '2026-01-01T00:00:00Z',
		'updated_at' => '2026-01-01T00:00:01Z',
		'metadata'   => array( 'provider' => 'test' ),
	),
	10,
	2
);

$status = AgentsAPI\AI\Channels\agents_get_chat_run( array( 'session_id' => 'session-1', 'run_id' => 'run-1' ) );
agents_api_smoke_assert_equals( 'running', $status['status'] ?? null, 'get-run normalizes status payload', $failures, $passes );
agents_api_smoke_assert_equals( 'test', $status['metadata']['provider'] ?? null, 'get-run preserves metadata', $failures, $passes );

add_filter(
	'wp_agent_chat_run_cancel_handler',
	static fn() => static fn( array $input ): array => array(
		'run_id'     => $input['run_id'],
		'session_id' => $input['session_id'],
		'status'     => 'cancelling',
	),
	10,
	2
);

$cancelled = AgentsAPI\AI\Channels\agents_cancel_chat_run( array( 'session_id' => 'session-1', 'run_id' => 'run-1' ) );
agents_api_smoke_assert_equals( true, $cancelled['cancelled'] ?? null, 'cancel-run marks cancelling as cancelled request accepted', $failures, $passes );

add_filter(
	'wp_agent_chat_message_queue_handler',
	static fn() => static fn( array $input ): array => array(
		'queued_message_id' => 'queued-1',
		'run_id'            => 'run-next',
		'session_id'        => $input['session_id'],
		'position'          => 1,
		'status'            => 'queued',
	),
	10,
	2
);

$queued = AgentsAPI\AI\Channels\agents_queue_chat_message(
	array(
		'agent'      => 'demo-agent',
		'session_id' => 'session-1',
		'message'    => 'Next',
	)
);
agents_api_smoke_assert_equals( 'queued-1', $queued['queued_message_id'] ?? null, 'queue-message returns queued message id', $failures, $passes );
agents_api_smoke_assert_equals( 1, $queued['position'] ?? null, 'queue-message returns queue position', $failures, $passes );

$interrupt = AgentsAPI\AI\WP_Agent_Chat_Run_Control::cancellation_interrupt_message( 'run-1', 'session-1' );
agents_api_smoke_assert_equals( 'cancel', $interrupt['metadata']['interrupt_action'] ?? null, 'cancellation helper maps to loop interrupt action', $failures, $passes );
agents_api_smoke_assert_equals( 'run-1', $interrupt['metadata']['run_id'] ?? null, 'cancellation helper carries run id', $failures, $passes );

$no_events_handler = AgentsAPI\AI\Channels\agents_list_chat_run_events( array( 'session_id' => 'session-events-1', 'run_id' => 'run-events-1' ) );
agents_api_smoke_assert_equals( true, $no_events_handler instanceof WP_Error, 'run events require host handler', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_chat_run_events_no_handler', $no_events_handler->get_error_code(), 'run events no-handler error is explicit', $failures, $passes );

add_filter(
	'wp_agent_chat_run_events_handler',
	static fn() => static fn( array $input ): array => array(
		'run_id'     => $input['run_id'],
		'session_id' => $input['session_id'],
		'status'     => 'running',
		'events'     => array(
			array(
				'id'         => 'evt_1',
				'type'       => 'tool_call',
				'message'    => 'Calling client/tool...',
				'created_at' => '2026-01-01T00:00:00Z',
				'metadata'   => array(
					'turn'         => 1,
					'tool_name'    => 'client/tool',
					'tool_call_id' => 'call-1',
				),
			),
		),
		'cursor'     => 'evt_1',
		'has_more'   => false,
	),
	10,
	2
);

$event_page = AgentsAPI\AI\Channels\agents_list_chat_run_events(
	array(
		'session_id' => 'session-events-1',
		'run_id'     => 'run-events-1',
		'cursor'     => 'evt_0',
	)
);
agents_api_smoke_assert_equals( 'run-events-1', $event_page['run_id'] ?? null, 'run events handler preserves run id', $failures, $passes );
agents_api_smoke_assert_equals( 'session-events-1', $event_page['session_id'] ?? null, 'run events handler preserves session id', $failures, $passes );
agents_api_smoke_assert_equals( 'running', $event_page['status'] ?? null, 'run events handler normalizes status', $failures, $passes );
agents_api_smoke_assert_equals( 'evt_1', $event_page['cursor'] ?? null, 'run events handler returns cursor', $failures, $passes );
agents_api_smoke_assert_equals( 'client/tool', $event_page['events'][0]['metadata']['tool_name'] ?? null, 'run events handler returns safe metadata', $failures, $passes );

agents_api_smoke_finish( 'chat run-control', $failures, $passes );

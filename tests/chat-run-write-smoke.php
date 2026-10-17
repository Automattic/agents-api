<?php
/**
 * Pure-PHP smoke test for claim-bound chat run write abilities.
 *
 * Covers the external-runtime write surface: claim-bound authorization
 * (foreign run rejected, principals never grant), transcript message append
 * and in-place update, event append, status transitions, the input_required
 * round trip, and cancellation visibility.
 *
 * Run with: php tests/chat-run-write-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "chat-run-write-smoke\n";

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
		return ! empty( $GLOBALS['__agents_api_smoke_caps'][ $capability ] );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['__agents_api_smoke_user_id'] ?? 0 );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $value ): string {
		return (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) );
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
require_once __DIR__ . '/class-agents-api-memory-atomic-run-control-store.php';
AgentsAPI\AI\WP_Agent_Run_Control::set_store( new Agents_API_Memory_Atomic_Run_Control_Store() );

/**
 * In-memory conversation store that persists transcripts so append/update
 * writes are observable, mirroring the canonical session-row shape.
 */
final class Agents_API_Memory_Conversation_Store implements AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Store {
	/** @var array<string,array<string,mixed>> */
	public array $sessions = array();

	public function seed( string $session_id ): void {
		$this->sessions[ $session_id ] = array(
			'session_id'           => $session_id,
			'workspace_type'       => 'site',
			'workspace_id'         => 'default',
			'owner_type'           => 'user',
			'owner_key'            => '123',
			'user_id'              => 123,
			'agent_slug'           => 'demo-agent',
			'title'                => '',
			'messages'             => array(),
			'metadata'             => array( 'seeded' => true ),
			'provider'             => 'test-provider',
			'model'                => 'test-model',
			'provider_response_id' => null,
			'context'              => 'chat',
		);
	}

	public function create_session( AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope $workspace, int $user_id, string $agent_slug = '', array $metadata = array(), string $context = 'chat' ): string {
		unset( $workspace, $user_id, $agent_slug, $metadata, $context );
		$session_id = 'session-' . ( count( $this->sessions ) + 1 );
		$this->seed( $session_id );
		return $session_id;
	}

	public function list_sessions( AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope $workspace, int $user_id, array $args = array() ): array {
		unset( $workspace, $user_id, $args );
		return array_values( $this->sessions );
	}

	public function get_session( string $session_id ): ?array {
		return $this->sessions[ $session_id ] ?? null;
	}

	public function update_session( string $session_id, array $messages, array $metadata = array(), string $provider = '', string $model = '', ?string $provider_response_id = null ): bool {
		if ( ! isset( $this->sessions[ $session_id ] ) ) {
			return false;
		}
		$this->sessions[ $session_id ]['messages']             = $messages;
		$this->sessions[ $session_id ]['metadata']             = $metadata;
		$this->sessions[ $session_id ]['provider']             = $provider;
		$this->sessions[ $session_id ]['model']                = $model;
		$this->sessions[ $session_id ]['provider_response_id'] = $provider_response_id;
		return true;
	}

	public function delete_session( string $session_id ): bool {
		unset( $this->sessions[ $session_id ] );
		return true;
	}

	public function get_recent_pending_session( AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope $workspace, int $user_id, int $seconds = 600, string $context = 'chat', ?int $token_id = null ): ?array {
		unset( $workspace, $user_id, $seconds, $context, $token_id );
		return null;
	}

	public function update_title( string $session_id, string $title ): bool {
		if ( ! isset( $this->sessions[ $session_id ] ) ) {
			return false;
		}
		$this->sessions[ $session_id ]['title'] = $title;
		return true;
	}
}

$conversation_store = new Agents_API_Memory_Conversation_Store();
$conversation_store->seed( 'session-ext' );
add_filter( 'wp_agent_conversation_store', static fn() => $conversation_store );

do_action( 'wp_abilities_api_categories_init' );
do_action( 'wp_abilities_api_init' );

$GLOBALS['__agents_api_smoke_caps']    = array( 'read' => true );
$GLOBALS['__agents_api_smoke_user_id'] = 123;

// --- Registration -----------------------------------------------------------
agents_api_smoke_assert_equals( true, wp_has_ability( AgentsAPI\AI\Channels\AGENTS_APPEND_CHAT_RUN_MESSAGES_ABILITY ), 'append-messages ability registers', $failures, $passes );
agents_api_smoke_assert_equals( true, wp_has_ability( AgentsAPI\AI\Channels\AGENTS_UPDATE_CHAT_RUN_MESSAGE_ABILITY ), 'update-message ability registers', $failures, $passes );
agents_api_smoke_assert_equals( true, wp_has_ability( AgentsAPI\AI\Channels\AGENTS_APPEND_CHAT_RUN_EVENTS_ABILITY ), 'append-events ability registers', $failures, $passes );
agents_api_smoke_assert_equals( true, wp_has_ability( AgentsAPI\AI\Channels\AGENTS_SET_CHAT_RUN_STATUS_ABILITY ), 'set-status ability registers', $failures, $passes );
agents_api_smoke_assert_equals( true, wp_has_ability( AgentsAPI\AI\Channels\AGENTS_CLAIM_CHAT_RUN_INPUT_ABILITY ), 'claim-input ability registers', $failures, $passes );
agents_api_smoke_assert_equals( true, in_array( 'pending', AgentsAPI\AI\WP_Agent_Run_Outcome::statuses(), true ), 'run outcome vocabulary includes accepted-pending', $failures, $passes );

// --- agents/chat returns immediately with an accepted-pending run -----------
$captured_claim_token = '';
add_filter(
	'wp_agent_chat_handler',
	static function ( $handler, array $input ) use ( &$captured_claim_token ) {
		unset( $handler, $input );
		return static function ( array $runtime_input ) use ( &$captured_claim_token ): array {
			// An external handler hands the turn (and the run claim) to an
			// out-of-process runtime and returns accepted-pending immediately.
			$captured_claim_token = (string) ( $runtime_input['_agents_run_claim_token'] ?? '' );
			return array(
				'session_id' => $runtime_input['session_id'],
				'run_id'     => $runtime_input['run_id'],
				'reply'      => '',
				'completed'  => false,
				'status'     => 'pending',
			);
		};
	},
	10,
	2
);

$pending = AgentsAPI\AI\Channels\agents_chat_dispatch(
	array(
		'agent'      => 'demo-agent',
		'message'    => 'Start work',
		'session_id' => 'session-ext',
	)
);

agents_api_smoke_assert_equals( true, is_array( $pending ), 'external dispatch returns the accepted-pending result', $failures, $passes );
agents_api_smoke_assert_equals( 'pending', $pending['status'] ?? null, 'accepted-pending status survives output normalization', $failures, $passes );
agents_api_smoke_assert_equals( true, '' !== $captured_claim_token, 'handler receives the run claim token', $failures, $passes );
$run_id = (string) ( $pending['run_id'] ?? '' );

$GLOBALS['__agents_api_smoke_caps']['manage_options'] = true;
$observed = AgentsAPI\AI\Channels\agents_get_chat_run( array( 'session_id' => 'session-ext', 'run_id' => $run_id ) );
agents_api_smoke_assert_equals( 'running', $observed['status'] ?? null, 'accepted-pending run stays open (not terminalized by dispatch)', $failures, $passes );

// --- Claim-bound authorization ----------------------------------------------
$permission = 'AgentsAPI\AI\Channels\agents_chat_run_write_claim_permission';

agents_api_smoke_assert_equals( true, call_user_func( $permission, array( 'run_id' => $run_id, 'claim_token' => $captured_claim_token ) ), 'valid run claim grants write permission', $failures, $passes );
agents_api_smoke_assert_equals( false, call_user_func( $permission, array( 'run_id' => $run_id, 'claim_token' => 'claim_wrong' ) ), 'wrong claim token is rejected even for managers', $failures, $passes );
agents_api_smoke_assert_equals( false, call_user_func( $permission, array( 'run_id' => $run_id, 'claim_token' => '' ) ), 'empty claim token is rejected', $failures, $passes );
agents_api_smoke_assert_equals(
	false,
	call_user_func(
		$permission,
		array(
			'run_id'      => $run_id,
			'claim_token' => 'claim_wrong',
			'principal'   => array(
				'acting_user_id'     => 123,
				'effective_agent_id' => 'demo-agent',
				'auth_source'        => AgentsAPI\AI\WP_Agent_Execution_Principal::AUTH_SOURCE_RUNTIME,
				'request_context'    => AgentsAPI\AI\WP_Agent_Execution_Principal::REQUEST_CONTEXT_RUNTIME,
			),
		)
	),
	'client-supplied runtime principal never grants write access',
	$failures,
	$passes
);

$conversation_store->seed( 'session-foreign' );
AgentsAPI\AI\WP_Agent_Chat_Run_Control::start_run( 'run-foreign', 'session-foreign', array( '_claim_token' => 'claim_foreign' ), null, array( 'type' => 'user', 'key' => '123' ) );
agents_api_smoke_assert_equals( false, call_user_func( $permission, array( 'run_id' => $run_id, 'claim_token' => 'claim_foreign' ) ), 'another run\'s claim token cannot write a foreign run', $failures, $passes );
$foreign_write = AgentsAPI\AI\Channels\agents_append_chat_run_messages(
	array(
		'run_id'      => $run_id,
		'claim_token' => 'claim_foreign',
		'messages'    => array( array( 'role' => 'assistant', 'content' => 'stolen write' ) ),
	)
);
agents_api_smoke_assert_equals( true, $foreign_write instanceof WP_Error, 'execute path re-verifies the claim', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_chat_run_claim_forbidden', $foreign_write instanceof WP_Error ? $foreign_write->get_error_code() : null, 'foreign run write fails closed with claim_forbidden', $failures, $passes );
$missing_write = AgentsAPI\AI\Channels\agents_set_chat_run_status( array( 'run_id' => 'run-missing', 'claim_token' => $captured_claim_token, 'status' => 'completed' ) );
agents_api_smoke_assert_equals( 'agents_chat_run_not_found', $missing_write instanceof WP_Error ? $missing_write->get_error_code() : null, 'unknown run_id reports not found', $failures, $passes );

// --- Message append ----------------------------------------------------------
$appended = AgentsAPI\AI\Channels\agents_append_chat_run_messages(
	array(
		'run_id'      => $run_id,
		'claim_token' => $captured_claim_token,
		'messages'    => array(
			array( 'role' => 'assistant', 'content' => 'Working on it…' ),
			array(
				'role'     => 'assistant',
				'content'  => 'Ran a tool.',
				'metadata' => array( 'type' => 'tool_call', 'tool_name' => 'demo/tool', 'parameters' => array( 'q' => 1 ), 'turn' => 1 ),
			),
		),
	)
);
agents_api_smoke_assert_equals( 2, $appended['count'] ?? null, 'append writes both messages', $failures, $passes );
agents_api_smoke_assert_equals( $run_id, $appended['run_id'] ?? null, 'append echoes run id', $failures, $passes );
agents_api_smoke_assert_equals( 'session-ext', $appended['session_id'] ?? null, 'append echoes session id', $failures, $passes );
agents_api_smoke_assert_equals( false, $appended['cancelled'] ?? null, 'append reports no cancellation requested', $failures, $passes );
$first_message_id = (string) ( $appended['messages'][0]['id'] ?? '' );
agents_api_smoke_assert_equals( true, '' !== $first_message_id, 'appended messages get stable ids', $failures, $passes );
agents_api_smoke_assert_equals( 'tool_call', $appended['messages'][1]['type'] ?? null, 'append normalizes typed envelopes', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $conversation_store->sessions['session-ext']['messages'] ), 'transcript store observes appended messages', $failures, $passes );
agents_api_smoke_assert_equals( 'Working on it…', $conversation_store->sessions['session-ext']['messages'][0]['content'] ?? null, 'transcript stores appended content', $failures, $passes );
agents_api_smoke_assert_equals( 'test-provider', $conversation_store->sessions['session-ext']['provider'] ?? null, 'append preserves session provider continuity', $failures, $passes );

$bad_append = AgentsAPI\AI\Channels\agents_append_chat_run_messages( array( 'run_id' => $run_id, 'claim_token' => $captured_claim_token, 'messages' => array() ) );
agents_api_smoke_assert_equals( 'agents_chat_run_invalid_message', $bad_append instanceof WP_Error ? $bad_append->get_error_code() : null, 'empty message list is rejected', $failures, $passes );

// --- Message update (streamed text edited in place) ---------------------------
$updated = AgentsAPI\AI\Channels\agents_update_chat_run_message(
	array(
		'run_id'      => $run_id,
		'claim_token' => $captured_claim_token,
		'message_id'  => $first_message_id,
		'message'     => array( 'role' => 'assistant', 'content' => 'Done. Here is the summary.' ),
	)
);
agents_api_smoke_assert_equals( $first_message_id, $updated['message']['id'] ?? null, 'update keeps stable message identity', $failures, $passes );
agents_api_smoke_assert_equals( 'Done. Here is the summary.', $conversation_store->sessions['session-ext']['messages'][0]['content'] ?? null, 'update edits the transcript in place', $failures, $passes );
agents_api_smoke_assert_equals( 'Ran a tool.', $conversation_store->sessions['session-ext']['messages'][1]['content'] ?? null, 'update leaves other messages untouched', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $conversation_store->sessions['session-ext']['messages'] ), 'update does not append', $failures, $passes );

$missing_update = AgentsAPI\AI\Channels\agents_update_chat_run_message(
	array(
		'run_id'      => $run_id,
		'claim_token' => $captured_claim_token,
		'message_id'  => 'msg_unknown',
		'message'     => array( 'role' => 'assistant', 'content' => 'nope' ),
	)
);
agents_api_smoke_assert_equals( 'agents_chat_run_message_not_found', $missing_update instanceof WP_Error ? $missing_update->get_error_code() : null, 'unknown message_id reports not found', $failures, $passes );

// --- Event append -------------------------------------------------------------
$event_result = AgentsAPI\AI\Channels\agents_append_chat_run_events(
	array(
		'run_id'      => $run_id,
		'claim_token' => $captured_claim_token,
		'events'      => array(
			array( 'type' => 'tool_call', 'message' => 'Calling demo/tool…', 'metadata' => array( 'tool_name' => 'demo/tool' ) ),
			array( 'type' => 'progress', 'metadata' => array( 'percent' => 50 ) ),
		),
	)
);
agents_api_smoke_assert_equals( 2, $event_result['count'] ?? null, 'event append writes both events', $failures, $passes );
$event_page  = AgentsAPI\AI\Channels\agents_list_chat_run_events( array( 'session_id' => 'session-ext', 'run_id' => $run_id ) );
$event_types = array_map( static fn( array $event ): string => (string) ( $event['type'] ?? '' ), is_array( $event_page['events'] ?? null ) ? $event_page['events'] : array() );
agents_api_smoke_assert_equals( true, in_array( 'tool_call', $event_types, true ), 'appended events are observable via list-chat-run-events', $failures, $passes );
agents_api_smoke_assert_equals( true, in_array( 'progress', $event_types, true ), 'all appended events page through', $failures, $passes );
agents_api_smoke_assert_equals( true, in_array( 'run_started', $event_types, true ), 'runtime events share the canonical event stream', $failures, $passes );

$bad_event = AgentsAPI\AI\Channels\agents_append_chat_run_events( array( 'run_id' => $run_id, 'claim_token' => $captured_claim_token, 'events' => array( array( 'metadata' => array() ) ) ) );
agents_api_smoke_assert_equals( 'agents_chat_run_invalid_event', $bad_event instanceof WP_Error ? $bad_event->get_error_code() : null, 'events require a type', $failures, $passes );

// --- input_required round trip -------------------------------------------------
$question = AgentsAPI\AI\Channels\agents_append_chat_run_messages(
	array(
		'run_id'      => $run_id,
		'claim_token' => $captured_claim_token,
		'messages'    => array(
			array(
				'role'     => 'tool',
				'content'  => 'Which branch should I use?',
				'metadata' => array( 'type' => 'input_required' ),
			),
		),
	)
);
agents_api_smoke_assert_equals( 'input_required', $question['messages'][0]['type'] ?? null, 'runtime can emit canonical input_required', $failures, $passes );
agents_api_smoke_assert_equals( 'input_required', $conversation_store->sessions['session-ext']['messages'][2]['type'] ?? null, 'input_required lands in the transcript', $failures, $passes );

$queued = AgentsAPI\AI\Channels\agents_queue_chat_message(
	array(
		'agent'      => 'demo-agent',
		'session_id' => 'session-ext',
		'run_id'     => $run_id,
		'message'    => 'Use the trunk branch.',
	)
);
agents_api_smoke_assert_equals( 'queued', $queued['status'] ?? null, 'user answer queues against the pending run', $failures, $passes );

$foreign_claim_input = AgentsAPI\AI\Channels\agents_claim_chat_run_input( array( 'run_id' => $run_id, 'claim_token' => 'claim_foreign' ) );
agents_api_smoke_assert_equals( 'agents_chat_run_claim_forbidden', $foreign_claim_input instanceof WP_Error ? $foreign_claim_input->get_error_code() : null, 'foreign claim cannot drain queued input', $failures, $passes );

$claimed_input = AgentsAPI\AI\Channels\agents_claim_chat_run_input( array( 'run_id' => $run_id, 'claim_token' => $captured_claim_token ) );
agents_api_smoke_assert_equals( 1, $claimed_input['count'] ?? null, 'runtime claims the queued answer', $failures, $passes );
agents_api_smoke_assert_equals( 'Use the trunk branch.', $claimed_input['messages'][0]['message'] ?? null, 'claimed answer carries the user text', $failures, $passes );
agents_api_smoke_assert_equals( false, isset( $claimed_input['messages'][0]['_owner'] ), 'claimed answer redacts internal owner state', $failures, $passes );
$drained = AgentsAPI\AI\Channels\agents_claim_chat_run_input( array( 'run_id' => $run_id, 'claim_token' => $captured_claim_token ) );
agents_api_smoke_assert_equals( 0, $drained['count'] ?? null, 'claimed input is drained exactly once', $failures, $passes );

// --- Status transitions ---------------------------------------------------------
$invalid_status = AgentsAPI\AI\Channels\agents_set_chat_run_status( array( 'run_id' => $run_id, 'claim_token' => $captured_claim_token, 'status' => 'bogus' ) );
agents_api_smoke_assert_equals( 'agents_chat_run_invalid_status', $invalid_status instanceof WP_Error ? $invalid_status->get_error_code() : null, 'status vocabulary is bounded', $failures, $passes );

$suspended = AgentsAPI\AI\Channels\agents_set_chat_run_status( array( 'run_id' => $run_id, 'claim_token' => $captured_claim_token, 'status' => 'approval_required' ) );
agents_api_smoke_assert_equals( 'approval_required', $suspended['status'] ?? null, 'runtime can suspend a run as approval_required', $failures, $passes );

$completed = AgentsAPI\AI\Channels\agents_set_chat_run_status( array( 'run_id' => $run_id, 'claim_token' => $captured_claim_token, 'status' => 'completed' ) );
agents_api_smoke_assert_equals( 'completed', $completed['status'] ?? null, 'runtime finalizes its run as completed', $failures, $passes );
$observed_final = AgentsAPI\AI\Channels\agents_get_chat_run( array( 'session_id' => 'session-ext', 'run_id' => $run_id ) );
agents_api_smoke_assert_equals( 'completed', $observed_final['status'] ?? null, 'clients observe the terminal status', $failures, $passes );

$after_terminal = AgentsAPI\AI\Channels\agents_append_chat_run_messages(
	array(
		'run_id'      => $run_id,
		'claim_token' => $captured_claim_token,
		'messages'    => array( array( 'role' => 'assistant', 'content' => 'late write' ) ),
	)
);
agents_api_smoke_assert_equals( 'agents_chat_run_already_finished', $after_terminal instanceof WP_Error ? $after_terminal->get_error_code() : null, 'terminal runs reject transcript writes', $failures, $passes );
$terminal_events = AgentsAPI\AI\Channels\agents_append_chat_run_events( array( 'run_id' => $run_id, 'claim_token' => $captured_claim_token, 'events' => array( array( 'type' => 'late' ) ) ) );
agents_api_smoke_assert_equals( 'agents_chat_run_already_finished', $terminal_events instanceof WP_Error ? $terminal_events->get_error_code() : null, 'terminal runs reject event writes', $failures, $passes );
$idempotent_terminal = AgentsAPI\AI\Channels\agents_set_chat_run_status( array( 'run_id' => $run_id, 'claim_token' => $captured_claim_token, 'status' => 'failed' ) );
agents_api_smoke_assert_equals( 'completed', $idempotent_terminal['status'] ?? null, 'terminal status is sticky (idempotent reads back)', $failures, $passes );

// --- Failure transition ----------------------------------------------------------
$conversation_store->seed( 'session-fail' );
AgentsAPI\AI\WP_Agent_Chat_Run_Control::start_run( 'run-fail', 'session-fail', array( '_claim_token' => 'claim_fail' ), null, array( 'type' => 'user', 'key' => '123' ) );
$failed = AgentsAPI\AI\Channels\agents_set_chat_run_status( array( 'run_id' => 'run-fail', 'claim_token' => 'claim_fail', 'status' => 'failed' ) );
agents_api_smoke_assert_equals( 'failed', $failed['status'] ?? null, 'runtime finalizes its run as failed', $failures, $passes );

// --- Cancellation visibility ------------------------------------------------------
$conversation_store->seed( 'session-cancel' );
AgentsAPI\AI\WP_Agent_Chat_Run_Control::start_run( 'run-cancel', 'session-cancel', array( '_claim_token' => 'claim_cancel' ), null, array( 'type' => 'user', 'key' => '123' ) );
$cancel_requested = AgentsAPI\AI\Channels\agents_cancel_chat_run( array( 'session_id' => 'session-cancel', 'run_id' => 'run-cancel' ) );
agents_api_smoke_assert_equals( 'cancelling', $cancel_requested['status'] ?? null, 'owner-side cancel marks the run cancelling', $failures, $passes );

$heartbeat = AgentsAPI\AI\Channels\agents_set_chat_run_status( array( 'run_id' => 'run-cancel', 'claim_token' => 'claim_cancel', 'status' => 'running' ) );
agents_api_smoke_assert_equals( 'cancelling', $heartbeat['status'] ?? null, 'runtime heartbeat observes cancellation requested', $failures, $passes );
agents_api_smoke_assert_equals( true, $heartbeat['cancelled'] ?? null, 'runtime heartbeat sees the cancelled flag', $failures, $passes );

$cancel_won = AgentsAPI\AI\Channels\agents_set_chat_run_status( array( 'run_id' => 'run-cancel', 'claim_token' => 'claim_cancel', 'status' => 'completed' ) );
agents_api_smoke_assert_equals( 'cancelled', $cancel_won['status'] ?? null, 'committed cancellation wins over a success claim', $failures, $passes );

$conversation_store->seed( 'session-cancel-honor' );
AgentsAPI\AI\WP_Agent_Chat_Run_Control::start_run( 'run-cancel-honor', 'session-cancel-honor', array( '_claim_token' => 'claim_cancel_honor' ), null, array( 'type' => 'user', 'key' => '123' ) );
AgentsAPI\AI\Channels\agents_cancel_chat_run( array( 'session_id' => 'session-cancel-honor', 'run_id' => 'run-cancel-honor' ) );
$honored = AgentsAPI\AI\Channels\agents_set_chat_run_status( array( 'run_id' => 'run-cancel-honor', 'claim_token' => 'claim_cancel_honor', 'status' => 'cancelled' ) );
agents_api_smoke_assert_equals( 'cancelled', $honored['status'] ?? null, 'runtime honors cancellation with an explicit cancelled terminal', $failures, $passes );

agents_api_smoke_finish( 'chat run write', $failures, $passes );

<?php
/**
 * Pure-PHP smoke test for WP_Agent_Channel.
 *
 * Run with: php tests/channels-smoke.php
 *
 * Covers the full pipeline (validate → extract → run_agent → deliver →
 * lifecycle hooks → session persistence) using a fake chat ability and
 * an in-memory option store. No WordPress required.
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "agents-api-channels-smoke\n";

// ─── Minimal WP stubs (this test does not load the full bootstrap) ──

class WP_Error {
	public function __construct(
		private string $code = '',
		private string $message = ''
	) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

$GLOBALS['__channel_smoke_options']   = array();
$GLOBALS['__channel_smoke_scheduled'] = array();
$GLOBALS['__channel_smoke_abilities'] = array();
$GLOBALS['__channel_smoke_filters']   = array();

function get_option( string $key, $default = '' ) {
	return $GLOBALS['__channel_smoke_options'][ $key ] ?? $default;
}

function update_option( string $key, $value, $autoload = null ): bool {
	unset( $autoload );
	$GLOBALS['__channel_smoke_options'][ $key ] = $value;
	return true;
}

function delete_option( string $key ): bool {
	unset( $GLOBALS['__channel_smoke_options'][ $key ] );
	return true;
}

function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
	$GLOBALS['__channel_smoke_scheduled'][] = array(
		'timestamp' => $timestamp,
		'hook'      => $hook,
		'args'      => $args,
	);
	return true;
}

function wp_get_ability( string $name ) {
	return $GLOBALS['__channel_smoke_abilities'][ $name ] ?? null;
}

function add_filter( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
	unset( $accepted_args );
	$GLOBALS['__channel_smoke_filters'][ $hook ][ $priority ][] = $cb;
}

function apply_filters( string $hook, $value, ...$args ) {
	$callbacks = $GLOBALS['__channel_smoke_filters'][ $hook ] ?? array();
	ksort( $callbacks );
	foreach ( $callbacks as $priority_callbacks ) {
		foreach ( $priority_callbacks as $cb ) {
			$value = call_user_func_array( $cb, array_merge( array( $value ), $args ) );
		}
	}
	return $value;
}

function add_action( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
	add_filter( $hook, $cb, $priority, $accepted_args );
}

function smoke_assert( $expected, $actual, string $name, array &$failures, int &$passes ): void {
	if ( $expected === $actual ) {
		++$passes;
		echo "  PASS {$name}\n";
		return;
	}
	$failures[] = $name;
	echo "  FAIL {$name}\n";
	echo '    expected: ' . var_export( $expected, true ) . "\n";
	echo '    actual:   ' . var_export( $actual, true ) . "\n";
}

require_once __DIR__ . '/../src/Channels/register-agents-chat-ability.php';
require_once __DIR__ . '/../src/Channels/class-wp-agent-external-message.php';
require_once __DIR__ . '/../src/Channels/class-wp-agent-channel-session-store.php';
require_once __DIR__ . '/../src/Channels/class-wp-agent-option-channel-session-store.php';
require_once __DIR__ . '/../src/Channels/class-wp-agent-channel-session-map.php';
require_once __DIR__ . '/../src/Channels/class-wp-agent-channel.php';

// ─── Fakes ──────────────────────────────────────────────────────────

class Fake_Ability {
	public array $calls = array();
	public function __construct( private mixed $next_result ) {}
	public function execute( array $input ) {
		$this->calls[] = $input;
		return $this->next_result;
	}
}

class Test_Channel extends \AgentsAPI\AI\Channels\WP_Agent_Channel {

	public string $external_id;
	public array $sent      = array();
	public array $errors    = array();
	public array $lifecycle = array();

	public function __construct( string $external_id, string $agent_slug = 'test-agent' ) {
		parent::__construct( $agent_slug );
		$this->external_id = $external_id;
	}

	public function get_external_id_provider(): string { return 'test-channel'; }
	public function get_external_id(): ?string { return $this->external_id; }
	public function get_client_name(): string { return 'test-channel'; }
	protected function get_job_action(): string { return 'test_channel_handle'; }

	protected function extract_message( array $data ): string {
		return (string) ( $data['text'] ?? '' );
	}

	protected function send_response( string $text ): void {
		$this->sent[] = $text;
	}

	protected function send_error( string $text ): void {
		$this->errors[] = $text;
	}

	protected function on_processing_start(): void { $this->lifecycle[] = 'start'; }
	protected function on_processing_end(): void { $this->lifecycle[] = 'end'; }
	protected function on_complete(): void { $this->lifecycle[] = 'complete'; }
}

// ─── Tests ──────────────────────────────────────────────────────────

// 1. Happy path: chat ability returns reply + session_id.
$happy_ability = new Fake_Ability(
	array( 'reply' => 'Hello from the agent', 'session_id' => 'sess-123', 'completed' => true )
);
$GLOBALS['__channel_smoke_abilities']['agents/chat'] = $happy_ability;

$ch = new Test_Channel( 'chat-A' );
$ch->handle( array( 'text' => 'hi there' ) );

smoke_assert( array( 'Hello from the agent' ), $ch->sent, 'happy_path_send_response', $failures, $passes );
smoke_assert( array(), $ch->errors, 'happy_path_no_error', $failures, $passes );
smoke_assert( array( 'start', 'end', 'complete' ), $ch->lifecycle, 'happy_path_lifecycle_order', $failures, $passes );
$expected_first_call = array(
	array(
		'agent'          => 'test-agent',
		'message'        => 'hi there',
		'session_id'     => null,
		'attachments'    => array(),
		'client_context' => array(
			'source'                   => 'channel',
			'connector_id'             => 'test-channel',
			'client_name'              => 'test-channel',
			'external_provider'        => 'test-channel',
			'external_conversation_id' => 'chat-A',
			'external_message_id'      => null,
			'sender_id'                => null,
			'room_kind'                => null,
		),
	),
);
smoke_assert( $expected_first_call, $happy_ability->calls, 'happy_path_canonical_chat_payload', $failures, $passes );

$external_message = $ch->build_external_message( 'hi there', array( 'text' => 'hi there' ) );
smoke_assert( true, $external_message instanceof \AgentsAPI\AI\Channels\WP_Agent_External_Message, 'external_message_value_object_created', $failures, $passes );
smoke_assert(
	array(
		'text'                     => 'hi there',
		'connector_id'             => 'test-channel',
		'external_provider'        => 'test-channel',
		'external_conversation_id' => 'chat-A',
		'external_message_id'      => null,
		'sender_id'                => null,
		'from_self'                => false,
		'room_kind'                => null,
		'attachments'              => array(),
		'raw'                      => array( 'text' => 'hi there' ),
	),
	$external_message->to_array(),
	'external_message_array_shape',
	$failures,
	$passes
);

\AgentsAPI\AI\Channels\WP_Agent_Channel_Session_Map::set( 'test-channel', 'manual-chat', 'sess-manual', 'test-agent' );
smoke_assert(
	'sess-manual',
	\AgentsAPI\AI\Channels\WP_Agent_Channel_Session_Map::get( 'test-channel', 'manual-chat', 'test-agent' ),
	'session_map_static_get_set',
	$failures,
	$passes
);
smoke_assert(
	null,
	\AgentsAPI\AI\Channels\WP_Agent_Channel_Session_Map::get( 'test-channel', 'manual-chat', 'other-agent' ),
	'session_map_scopes_by_agent',
	$failures,
	$passes
);
\AgentsAPI\AI\Channels\WP_Agent_Channel_Session_Map::delete( 'test-channel', 'manual-chat', 'test-agent' );
smoke_assert(
	null,
	\AgentsAPI\AI\Channels\WP_Agent_Channel_Session_Map::get( 'test-channel', 'manual-chat', 'test-agent' ),
	'session_map_static_delete',
	$failures,
	$passes
);

// 1b. Override hooks for attachments / external_message_id / room_kind / source.
class Rich_Channel extends Test_Channel {
	protected function extract_attachments( array $data ): array {
		return array_map( static fn( $a ) => array( 'url' => $a ), (array) ( $data['attachments'] ?? array() ) );
	}
	protected function extract_external_message_id( array $data ): ?string {
		return isset( $data['msg_id'] ) ? (string) $data['msg_id'] : null;
	}
	protected function get_room_kind( array $data ): ?string {
		return $data['room_kind'] ?? null;
	}
	protected function client_context_source(): string {
		return 'bridge';
	}
}
$rich_ability = new Fake_Ability( array( 'reply' => 'rich response' ) );
$GLOBALS['__channel_smoke_abilities']['agents/chat'] = $rich_ability;
$rich = new Rich_Channel( 'chat-rich' );
$rich->handle( array(
	'text'        => 'check this out',
	'msg_id'      => 'wamid.123',
	'room_kind'   => 'group',
	'attachments' => array( 'https://example.com/img.jpg' ),
) );
smoke_assert(
	array( array( 'url' => 'https://example.com/img.jpg' ) ),
	$rich_ability->calls[0]['attachments'] ?? null,
	'rich_payload_attachments_extracted',
	$failures,
	$passes
);
smoke_assert( 'wamid.123', $rich_ability->calls[0]['client_context']['external_message_id'] ?? null, 'rich_payload_external_message_id', $failures, $passes );
smoke_assert( 'group', $rich_ability->calls[0]['client_context']['room_kind'] ?? null, 'rich_payload_room_kind', $failures, $passes );
smoke_assert( 'bridge', $rich_ability->calls[0]['client_context']['source'] ?? null, 'rich_payload_source_overridden', $failures, $passes );
smoke_assert( 'test-channel', $rich_ability->calls[0]['client_context']['connector_id'] ?? null, 'rich_payload_connector_id', $failures, $passes );

// 1c. Channels with custom session keys keep that override path.
class Custom_Key_Channel extends Test_Channel {
	protected function session_storage_key(): string {
		return 'custom_session_key_' . $this->external_id;
	}
}
$custom_key_ability = new Fake_Ability( array( 'reply' => 'custom key', 'session_id' => 'sess-custom' ) );
$GLOBALS['__channel_smoke_abilities']['agents/chat'] = $custom_key_ability;
$custom_key = new Custom_Key_Channel( 'chat-custom' );
$custom_key->handle( array( 'text' => 'use custom key' ) );
smoke_assert( 'sess-custom', get_option( 'custom_session_key_chat-custom' ), 'custom_session_key_override_is_preserved', $failures, $passes );

// 2. Session continuity: second turn passes the stored session_id.
$happy_ability2 = new Fake_Ability(
	array( 'reply' => 'second reply', 'session_id' => 'sess-123', 'completed' => true )
);
$GLOBALS['__channel_smoke_abilities']['agents/chat'] = $happy_ability2;

$ch2 = new Test_Channel( 'chat-A' );
$ch2->handle( array( 'text' => 'follow-up' ) );

smoke_assert( 'sess-123', $happy_ability2->calls[0]['session_id'] ?? 'missing', 'second_turn_passes_stored_session_id', $failures, $passes );
smoke_assert( 'follow-up', $happy_ability2->calls[0]['message'] ?? 'missing', 'second_turn_message_passed', $failures, $passes );

// 3. Different external_id gets its own session.
$other_ability = new Fake_Ability(
	array( 'reply' => 'reply for B', 'session_id' => 'sess-B-456', 'completed' => true )
);
$GLOBALS['__channel_smoke_abilities']['agents/chat'] = $other_ability;

$ch3 = new Test_Channel( 'chat-B' );
$ch3->handle( array( 'text' => 'hi' ) );

smoke_assert(
	true,
	array_key_exists( 'session_id', $other_ability->calls[0] ) && null === $other_ability->calls[0]['session_id'],
	'different_external_id_starts_fresh_session',
	$failures,
	$passes
);

// 4. Empty message short-circuits with WP_Error, no agent call.
$null_ability = new Fake_Ability( array( 'reply' => 'should not be called' ) );
$GLOBALS['__channel_smoke_abilities']['agents/chat'] = $null_ability;

$ch4    = new Test_Channel( 'chat-empty' );
$result = $ch4->handle( array( 'text' => '   ' ) );

smoke_assert( true, $result instanceof WP_Error, 'empty_message_returns_wp_error', $failures, $passes );
smoke_assert( 'empty_message', $result->get_error_code(), 'empty_message_error_code', $failures, $passes );
smoke_assert( array(), $null_ability->calls, 'empty_message_skips_agent', $failures, $passes );

// 5. Ability returns WP_Error → send_error fires.
$error_ability = new Fake_Ability( new WP_Error( 'agent_blew_up', 'something exploded' ) );
$GLOBALS['__channel_smoke_abilities']['agents/chat'] = $error_ability;

$ch5 = new Test_Channel( 'chat-err' );
$ch5->handle( array( 'text' => 'try this' ) );

smoke_assert( array( 'something exploded' ), $ch5->errors, 'agent_error_routed_to_send_error', $failures, $passes );
smoke_assert( array(), $ch5->sent, 'agent_error_no_response', $failures, $passes );

// 6. Filter overrides the chat ability slug.
$GLOBALS['__channel_smoke_abilities']['my-plugin/custom-chat'] = new Fake_Ability(
	array( 'reply' => 'from custom ability' )
);
$override_filter = static fn( $slug ) => 'my-plugin/custom-chat';
add_filter( 'wp_agent_channel_chat_ability', $override_filter );

$ch6 = new Test_Channel( 'chat-custom' );
$ch6->handle( array( 'text' => 'route me elsewhere' ) );

smoke_assert(
	array( 'from custom ability' ),
	$ch6->sent,
	'filter_overrides_chat_ability_slug',
	$failures,
	$passes
);

// 7. validate() returning 'silent_skip' WP_Error drops the message — no send_error, no agent call.
class Silent_Skip_Channel extends Test_Channel {
	protected function validate( array $data ): ?WP_Error {
		if ( ! empty( $data['from_me'] ) ) {
			return new WP_Error( \AgentsAPI\AI\Channels\WP_Agent_Channel::SILENT_SKIP_CODE, 'self_message' );
		}
		return null;
	}
}
$silent_ability = new Fake_Ability( array( 'reply' => 'should not be called' ) );
$GLOBALS['__channel_smoke_abilities']['agents/chat'] = $silent_ability;

$ch_silent = new Silent_Skip_Channel( 'chat-silent' );
$result    = $ch_silent->handle( array( 'text' => 'echo of own reply', 'from_me' => true ) );

smoke_assert( true, $result instanceof WP_Error, 'silent_skip_returns_wp_error', $failures, $passes );
smoke_assert( array(), $ch_silent->errors, 'silent_skip_no_send_error', $failures, $passes );
smoke_assert( array(), $ch_silent->sent, 'silent_skip_no_send_response', $failures, $passes );
smoke_assert( array(), $silent_ability->calls, 'silent_skip_no_agent_call', $failures, $passes );

// 8. receive() schedules an async event by default.
$GLOBALS['__channel_smoke_scheduled'] = array();
$ch7 = new Test_Channel( 'chat-async' );
$ch7->receive( array( 'text' => 'queue me' ) );

smoke_assert( 1, count( $GLOBALS['__channel_smoke_scheduled'] ), 'receive_schedules_one_event', $failures, $passes );
smoke_assert( 'test_channel_handle', $GLOBALS['__channel_smoke_scheduled'][0]['hook'], 'receive_uses_job_action', $failures, $passes );
smoke_assert( array( array( 'text' => 'queue me' ) ), $GLOBALS['__channel_smoke_scheduled'][0]['args'], 'receive_passes_payload', $failures, $passes );

// ─── Done ───────────────────────────────────────────────────────────

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " channel assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} channel assertions passed.\n";

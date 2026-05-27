<?php
/**
 * Pure-PHP smoke for the event trigger substrate.
 *
 * Run with: php tests/event-trigger-smoke.php
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "event-trigger-smoke\n";

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

function smoke_assert_throws( callable $fn, string $message_substring, string $name, array &$failures, int &$passes ): void {
	try {
		$fn();
	} catch ( \Throwable $e ) {
		if ( false === strpos( $e->getMessage(), $message_substring ) ) {
			$failures[] = $name;
			echo "  FAIL {$name}\n";
			echo '    expected substring: ' . $message_substring . "\n";
			echo '    actual message:     ' . $e->getMessage() . "\n";
			return;
		}
		++$passes;
		echo "  PASS {$name}\n";
		return;
	}
	$failures[] = $name;
	echo "  FAIL {$name} (no exception thrown)\n";
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $str ) {
		$str = strtolower( (string) $str );
		$str = preg_replace( '/[^a-z0-9_-]+/', '-', $str ) ?? '';
		return trim( $str, '-' );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return $s; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) { return json_encode( $data ); }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

$GLOBALS['smoke_actions']   = array();
$GLOBALS['smoke_scheduled'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['smoke_actions'][] = compact( 'hook', 'callback', 'priority', 'accepted_args' );
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		$GLOBALS['smoke_actions'][] = array( 'hook' => $hook, 'args' => $args );
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
		$GLOBALS['smoke_scheduled'][] = compact( 'timestamp', 'hook', 'args' );
		return true;
	}
}

require_once __DIR__ . '/../src/Triggers/class-wp-agent-event-trigger.php';
require_once __DIR__ . '/../src/Triggers/class-wp-agent-event-trigger-registry.php';
require_once __DIR__ . '/../src/Triggers/register-event-trigger-handler.php';

use AgentsAPI\AI\Triggers\WP_Agent_Event_Trigger;
use AgentsAPI\AI\Triggers\WP_Agent_Event_Trigger_Registry;
use function AgentsAPI\AI\Triggers\dispatch_event_trigger_hook;
use function AgentsAPI\AI\Triggers\register_event_trigger_handler;

WP_Agent_Event_Trigger_Registry::reset();

echo "\n[1] Event trigger value object normalizes inputs:\n";
$trigger = new WP_Agent_Event_Trigger(
	'post-published',
	array(
		'agent'           => 'editorial-agent',
		'hook_name'       => 'transition_post_status',
		'args_shape'      => array( 'new_status', 'old_status', 'post' ),
		'placeholders'    => array(
			'post.title' => 'post.title',
			'status'     => 'new_status',
		),
		'conditions'      => array( 'new_status' => 'publish' ),
		'prompt_template' => 'Review {{post.title}} now that it is {{status}}.',
	)
);
smoke_assert( 'post-published', $trigger->get_id(), 'trigger id normalizes', $failures, $passes );
smoke_assert( 'editorial-agent', $trigger->get_agent_slug(), 'trigger agent is preserved', $failures, $passes );
smoke_assert( true, $trigger->is_enabled(), 'trigger defaults to enabled', $failures, $passes );
smoke_assert( 'transition_post_status', $trigger->get_hook_name(), 'trigger hook is preserved', $failures, $passes );
smoke_assert( 'event-trigger:post-published', $trigger->get_session_id(), 'session_id defaults to event-trigger prefix', $failures, $passes );

echo "\n[2] Validation rejects incomplete triggers:\n";
smoke_assert_throws( static fn() => new WP_Agent_Event_Trigger( '', array( 'agent' => 'a', 'hook_name' => 'h' ) ), 'id cannot be empty', 'rejects empty id', $failures, $passes );
smoke_assert_throws( static fn() => new WP_Agent_Event_Trigger( 'x', array( 'hook_name' => 'h' ) ), 'must specify an agent slug', 'rejects missing agent', $failures, $passes );
smoke_assert_throws( static fn() => new WP_Agent_Event_Trigger( 'x', array( 'agent' => 'a' ) ), 'must specify a hook_name', 'rejects missing hook', $failures, $passes );

echo "\n[3] Registry round-trips triggers:\n";
$registered = WP_Agent_Event_Trigger_Registry::register(
	'post-published',
	array(
		'agent'     => 'editorial-agent',
		'hook_name' => 'transition_post_status',
	)
);
smoke_assert( true, $registered instanceof WP_Agent_Event_Trigger, 'registry returns trigger object', $failures, $passes );
smoke_assert( 1, count( WP_Agent_Event_Trigger_Registry::all() ), 'registry has one trigger', $failures, $passes );
smoke_assert( true, WP_Agent_Event_Trigger_Registry::find( 'post-published' ) instanceof WP_Agent_Event_Trigger, 'find returns trigger', $failures, $passes );
smoke_assert( null, WP_Agent_Event_Trigger_Registry::find( 'missing' ), 'find returns null for missing trigger', $failures, $passes );
smoke_assert( true, WP_Agent_Event_Trigger_Registry::unregister( 'post-published' ), 'unregister returns true', $failures, $passes );
$missing = WP_Agent_Event_Trigger_Registry::unregister( 'missing' );
smoke_assert( true, is_object( $missing ) && method_exists( $missing, 'get_error_code' ) && 'not_registered' === $missing->get_error_code(), 'unregister returns WP_Error for missing trigger', $failures, $passes );

echo "\n[4] Placeholders and conditions resolve from hook payloads:\n";
$payload = $trigger->payload_from_hook_args(
	array(
		'publish',
		'draft',
		array( 'title' => 'Launch Post' ),
	)
);
smoke_assert( true, $trigger->conditions_match( $payload ), 'condition accepts matching payload', $failures, $passes );
smoke_assert( 'Review Launch Post now that it is publish.', $trigger->render_prompt( $payload ), 'prompt renders placeholders', $failures, $passes );
$payload['new_status'] = 'draft';
smoke_assert( false, $trigger->conditions_match( $payload ), 'condition rejects non-matching payload', $failures, $passes );

echo "\n[5] Handler registers hooks and schedules async dispatch:\n";
$GLOBALS['smoke_actions']   = array();
$GLOBALS['smoke_scheduled'] = array();
register_event_trigger_handler( $trigger );
smoke_assert( 'transition_post_status', $GLOBALS['smoke_actions'][0]['hook'] ?? null, 'handler attaches source hook', $failures, $passes );
smoke_assert( PHP_INT_MAX, $GLOBALS['smoke_actions'][0]['priority'] ?? null, 'handler uses late priority', $failures, $passes );
smoke_assert( 3, $GLOBALS['smoke_actions'][0]['accepted_args'] ?? null, 'handler accepts declared hook args', $failures, $passes );

dispatch_event_trigger_hook(
	$trigger,
	array(
		'publish',
		'draft',
		array( 'title' => 'Launch Post' ),
	)
);
smoke_assert( 1, count( $GLOBALS['smoke_scheduled'] ), 'matching event schedules one async run', $failures, $passes );
smoke_assert( AgentsAPI\AI\Triggers\WP_AGENT_EVENT_TRIGGER_RUN_HOOK, $GLOBALS['smoke_scheduled'][0]['hook'] ?? null, 'async run uses event trigger hook', $failures, $passes );
$scheduled_payload = $GLOBALS['smoke_scheduled'][0]['args'][0] ?? array();
smoke_assert( 'post-published', $scheduled_payload['trigger_id'] ?? null, 'scheduled payload includes trigger id', $failures, $passes );
smoke_assert( 'editorial-agent', $scheduled_payload['agent'] ?? null, 'scheduled payload includes agent', $failures, $passes );
smoke_assert( 'Review Launch Post now that it is publish.', $scheduled_payload['message'] ?? null, 'scheduled payload includes rendered prompt', $failures, $passes );

if ( count( $failures ) > 0 ) {
	echo 'FAIL ' . count( $failures ) . " failures\n";
	exit( 1 );
}
echo "OK {$passes} passed\n";

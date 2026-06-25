<?php
/**
 * Pure-PHP smoke test for a Homeboy-shaped chat run-control bridge fixture.
 *
 * Run with: php tests/homeboy-chat-run-bridge-fixture-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "homeboy-chat-run-bridge-fixture-smoke\n";

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

$GLOBALS['__agents_api_smoke_caps']    = array(
	'read'           => true,
	'manage_options' => true,
);
$GLOBALS['__agents_api_smoke_user_id'] = 123;

/**
 * Fake in-process Homeboy bridge fixture. It accepts the shape returned by a
 * Homeboy agent-task run-status adapter without invoking Homeboy itself.
 */
final class Agents_API_Homeboy_Chat_Run_Bridge_Fixture {
	/** @param array<string,mixed> $homeboy_status Homeboy-shaped status payload. */
	public function __construct( private array $homeboy_status ) {}

	/** @param array<string,mixed> $input Ability input. */
	public function get_run( array $input ): array {
		$run = $this->homeboy_run();

		return array(
			'run_id'     => (string) ( $input['run_id'] ?? '' ),
			'session_id' => (string) ( $input['session_id'] ?? '' ),
			'status'     => self::map_state( (string) ( $run['state'] ?? '' ) ),
			'started_at' => (string) ( $run['started_at'] ?? '' ),
			'updated_at' => (string) ( $run['updated_at'] ?? '' ),
			'metadata'   => $this->metadata(),
		);
	}

	/** @param array<string,mixed> $input Ability input. */
	public function list_events( array $input ): array {
		$events = array();
		foreach ( is_array( $this->homeboy_status['normalized_events'] ?? null ) ? $this->homeboy_status['normalized_events'] : array() as $event ) {
			if ( is_array( $event ) ) {
				$events[] = $this->map_event( $event );
			}
		}

		$run = $this->get_run( $input );

		return array_merge(
			$run,
			array(
				'events'   => $events,
				'cursor'   => (string) ( $this->homeboy_status['latest_event_cursor'] ?? '' ),
				'has_more' => (bool) ( $this->homeboy_status['has_more_events'] ?? false ),
			)
		);
	}

	/** @return array<string,mixed> */
	private function homeboy_run(): array {
		return is_array( $this->homeboy_status['run'] ?? null ) ? $this->homeboy_status['run'] : array();
	}

	/** @return array<string,mixed> */
	private function metadata(): array {
		$run = $this->homeboy_run();

		return array(
			'orchestration' => array(
				'provider'     => 'homeboy',
				'ability'      => 'homeboy/agent-task-run-status/v1',
				'run_id'       => (string) ( $run['id'] ?? '' ),
				'state'        => (string) ( $run['state'] ?? '' ),
				'event_cursor' => (string) ( $this->homeboy_status['latest_event_cursor'] ?? '' ),
				'totals'       => is_array( $this->homeboy_status['totals'] ?? null ) ? $this->homeboy_status['totals'] : array(),
				'artifact_refs' => is_array( $this->homeboy_status['artifact_refs'] ?? null ) ? array_values( $this->homeboy_status['artifact_refs'] ) : array(),
			),
		);
	}

	/** @param array<string,mixed> $event Homeboy normalized event. */
	private function map_event( array $event ): array {
		$cursor = (string) ( $event['cursor'] ?? $this->homeboy_status['latest_event_cursor'] ?? '' );

		return array(
			'id'         => (string) ( $event['id'] ?? $cursor ),
			'type'       => (string) ( $event['type'] ?? 'run_event' ),
			'created_at' => (string) ( $event['created_at'] ?? '' ),
			'message'    => (string) ( $event['message'] ?? '' ),
			'metadata'   => array(
				'orchestration' => array(
					'provider'     => 'homeboy',
					'ability'      => 'homeboy/agent-task-run-status/v1',
					'run_id'       => (string) ( $this->homeboy_run()['id'] ?? '' ),
					'event_cursor' => $cursor,
				),
				'raw_type'      => (string) ( $event['raw_type'] ?? '' ),
			),
		);
	}

	private static function map_state( string $state ): string {
		return match ( strtolower( trim( $state ) ) ) {
			'queued', 'pending' => 'queued',
			'running', 'active' => 'running',
			'cancelling'        => 'cancelling',
			'cancelled'         => 'cancelled',
			'succeeded', 'completed', 'success' => 'completed',
			'failed', 'error'   => 'failed',
			'stalled'           => 'stalled',
			'interrupted'       => 'interrupted',
			default             => 'running',
		};
	}
}

$homeboy_status = array(
	'ability'             => 'homeboy/agent-task-run-status/v1',
	'run'                 => array(
		'id'         => 'hb-run-1',
		'state'      => 'succeeded',
		'started_at' => '2026-06-25T12:00:00Z',
		'updated_at' => '2026-06-25T12:03:00Z',
	),
	'totals'              => array(
		'events'    => 2,
		'artifacts' => 2,
		'errors'    => 0,
	),
	'latest_event_cursor' => 'hb-cursor-2',
	'normalized_events'   => array(
		array(
			'id'         => 'hb-event-1',
			'cursor'     => 'hb-cursor-1',
			'type'       => 'log',
			'raw_type'   => 'stdout',
			'created_at' => '2026-06-25T12:01:00Z',
			'message'    => 'Started agent task.',
		),
		array(
			'id'         => 'hb-event-2',
			'cursor'     => 'hb-cursor-2',
			'type'       => 'artifact',
			'raw_type'   => 'artifact.recorded',
			'created_at' => '2026-06-25T12:03:00Z',
			'message'    => 'Recorded transcript artifact.',
		),
	),
	'artifact_refs'       => array(
		array(
			'id'    => 'artifact-transcript',
			'type'  => 'transcript',
			'label' => 'Transcript',
		),
		array(
			'id'    => 'artifact-bundle',
			'type'  => 'bundle',
			'label' => 'Run bundle',
		),
	),
);

$fixture = new Agents_API_Homeboy_Chat_Run_Bridge_Fixture( $homeboy_status );

add_filter(
	'wp_agent_chat_run_status_handler',
	static fn() => array( $fixture, 'get_run' ),
	10,
	2
);

add_filter(
	'wp_agent_chat_run_events_handler',
	static fn() => array( $fixture, 'list_events' ),
	10,
	2
);

$status = AgentsAPI\AI\Channels\agents_get_chat_run(
	array(
		'session_id' => 'session-homeboy-1',
		'run_id'     => 'run-chat-1',
	)
);

agents_api_smoke_assert_equals( 'run-chat-1', $status['run_id'] ?? null, 'Homeboy bridge preserves Agents API run id', $failures, $passes );
agents_api_smoke_assert_equals( 'session-homeboy-1', $status['session_id'] ?? null, 'Homeboy bridge preserves Agents API session id', $failures, $passes );
agents_api_smoke_assert_equals( 'completed', $status['status'] ?? null, 'Homeboy succeeded state maps to Agents API completed status', $failures, $passes );
agents_api_smoke_assert_equals( 'homeboy', $status['metadata']['orchestration']['provider'] ?? null, 'Homeboy bridge marks orchestration provider', $failures, $passes );
agents_api_smoke_assert_equals( 'homeboy/agent-task-run-status/v1', $status['metadata']['orchestration']['ability'] ?? null, 'Homeboy bridge records source ability', $failures, $passes );
agents_api_smoke_assert_equals( 'hb-run-1', $status['metadata']['orchestration']['run_id'] ?? null, 'Homeboy bridge maps provider run id', $failures, $passes );
agents_api_smoke_assert_equals( 'succeeded', $status['metadata']['orchestration']['state'] ?? null, 'Homeboy bridge preserves provider state in metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'hb-cursor-2', $status['metadata']['orchestration']['event_cursor'] ?? null, 'Homeboy bridge maps latest_event_cursor', $failures, $passes );
agents_api_smoke_assert_equals( 2, $status['metadata']['orchestration']['totals']['events'] ?? null, 'Homeboy bridge maps totals metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'artifact-bundle', $status['metadata']['orchestration']['artifact_refs'][1]['id'] ?? null, 'Homeboy bridge maps artifact refs metadata', $failures, $passes );

$events = AgentsAPI\AI\Channels\agents_list_chat_run_events(
	array(
		'session_id' => 'session-homeboy-1',
		'run_id'     => 'run-chat-1',
		'cursor'     => 'hb-cursor-0',
	)
);

agents_api_smoke_assert_equals( 'completed', $events['status'] ?? null, 'Homeboy event page maps run status', $failures, $passes );
agents_api_smoke_assert_equals( 'hb-cursor-2', $events['cursor'] ?? null, 'Homeboy event page maps latest cursor', $failures, $passes );
agents_api_smoke_assert_equals( false, $events['has_more'] ?? null, 'Homeboy event page maps has_more default', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $events['events'] ?? array() ), 'Homeboy event page maps normalized events', $failures, $passes );
agents_api_smoke_assert_equals( 'artifact', $events['events'][1]['type'] ?? null, 'Homeboy event page preserves normalized event type', $failures, $passes );
agents_api_smoke_assert_equals( 'artifact.recorded', $events['events'][1]['metadata']['raw_type'] ?? null, 'Homeboy event page keeps raw event type in metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'hb-cursor-2', $events['events'][1]['metadata']['orchestration']['event_cursor'] ?? null, 'Homeboy event metadata maps event cursor', $failures, $passes );
agents_api_smoke_assert_equals( 'artifact-transcript', $events['metadata']['orchestration']['artifact_refs'][0]['id'] ?? null, 'Homeboy event page maps artifact refs metadata', $failures, $passes );

$cancelled = AgentsAPI\AI\Channels\agents_cancel_chat_run(
	array(
		'session_id' => 'session-homeboy-1',
		'run_id'     => 'run-chat-1',
	)
);

agents_api_smoke_assert_equals( true, $cancelled instanceof WP_Error, 'Homeboy bridge fixture does not claim cancellation without a cancel handler', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_chat_run_not_found', $cancelled instanceof WP_Error ? $cancelled->get_error_code() : null, 'Homeboy cancellation remains handler-owned', $failures, $passes );

agents_api_smoke_finish( 'Homeboy chat run bridge fixture', $failures, $passes );

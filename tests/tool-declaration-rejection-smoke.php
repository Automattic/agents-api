<?php
/**
 * Pure-PHP smoke test for tool-declaration rejection diagnostics.
 *
 * Covers issue #292: when tool declarations fail validation they were dropped
 * silently, which could flip mediation off entirely with no indication. The
 * loop now emits `tool_declarations_rejected` (with reasons) whenever any are
 * dropped, plus `tool_mediation_disabled` when a tool executor was supplied but
 * every declaration was rejected.
 *
 * Run with: php tests/tool-declaration-rejection-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-tool-declaration-rejection-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

$executor = new class() implements AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		unset( $tool_definition, $context );
		return array(
			'success'   => true,
			'tool_name' => $tool_call['tool_name'] ?? '',
			'result'    => array( 'ok' => true ),
		);
	}
};

/**
 * Collect emitted loop events by name.
 *
 * @param array<int, array{event:string, payload:array<mixed>}> $log Event log.
 * @param string                                                $name Event name.
 * @return array<int, array<mixed>> Matching payloads.
 */
function rejection_smoke_events_named( array $log, string $name ): array {
	$matches = array();
	foreach ( $log as $entry ) {
		if ( $entry['event'] === $name ) {
			$matches[] = $entry['payload'];
		}
	}
	return $matches;
}

// --- [1] All declarations rejected: mediation flips off, both events fire ----
echo "\n[1] Every declaration rejected -> rejected + mediation_disabled events:\n";

$log_all = array();
$result  = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'go' ) ),
	static function ( array $messages, array $context ): array {
		unset( $context );
		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array( 'name' => 'foo__bar', 'parameters' => array() ),
			),
		);
	},
	array(
		'tool_executor'     => $executor,
		'max_turns'         => 2,
		'tool_declarations' => array(
			// No slash, and source isn't `client` -> rejected.
			'foo__bar' => array(
				'name'        => 'foo__bar',
				'source'      => 'mything',
				'description' => 'Invalid name format.',
				'parameters'  => array( 'type' => 'object' ),
				'executor'    => 'client',
				'scope'       => 'run',
			),
		),
		'on_event'          => static function ( string $event, array $payload ) use ( &$log_all ): void {
			$log_all[] = array(
				'event'   => $event,
				'payload' => $payload,
			);
		},
	)
);

$rejected_events = rejection_smoke_events_named( $log_all, 'tool_declarations_rejected' );
agents_api_smoke_assert_equals( 1, count( $rejected_events ), 'rejected event emitted once', $failures, $passes );
agents_api_smoke_assert_equals( 1, $rejected_events[0]['rejected_count'] ?? null, 'rejected_count is 1', $failures, $passes );
agents_api_smoke_assert_equals( 0, $rejected_events[0]['accepted_count'] ?? null, 'accepted_count is 0', $failures, $passes );
agents_api_smoke_assert_equals( 'foo__bar', $rejected_events[0]['rejected'][0]['name'] ?? null, 'rejected entry carries declared name', $failures, $passes );
$reason = $rejected_events[0]['rejected'][0]['reason'] ?? '';
agents_api_smoke_assert_equals( true, is_string( $reason ) && '' !== $reason, 'rejected entry carries a non-empty reason', $failures, $passes );

$disabled_events = rejection_smoke_events_named( $log_all, 'tool_mediation_disabled' );
agents_api_smoke_assert_equals( 1, count( $disabled_events ), 'mediation_disabled event emitted once', $failures, $passes );
agents_api_smoke_assert_equals( 'all_declarations_rejected', $disabled_events[0]['reason'] ?? null, 'mediation_disabled reason is all_declarations_rejected', $failures, $passes );

// Mediation was off, so the emitted tool call produced no results.
agents_api_smoke_assert_equals( 0, count( $result['tool_execution_results'] ?? array() ), 'no tools executed when all declarations rejected', $failures, $passes );

// --- [2] Mixed valid + invalid: only invalid rejected, mediation stays on ----
echo "\n[2] Mixed declarations -> only invalid rejected, mediation still runs:\n";

$log_mixed = array();
$result2   = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'go' ) ),
	static function ( array $messages, array $context ): array {
		if ( 1 === ( $context['turn'] ?? 0 ) ) {
			return array(
				'messages'   => $messages,
				'tool_calls' => array(
					array( 'name' => 'client/work', 'parameters' => array() ),
				),
			);
		}
		return array(
			'messages' => array_merge( $messages, array( array( 'role' => 'assistant', 'content' => 'done' ) ) ),
		);
	},
	array(
		'tool_executor'     => $executor,
		'max_turns'         => 3,
		'tool_declarations' => array(
			'client/work' => array(
				'name'        => 'client/work',
				'source'      => 'client',
				'description' => 'Valid client tool.',
				'parameters'  => array( 'type' => 'object' ),
				'executor'    => 'client',
				'scope'       => 'run',
			),
			'bad__name'   => array(
				'name'        => 'bad__name',
				'source'      => 'mything',
				'description' => 'Invalid.',
				'parameters'  => array( 'type' => 'object' ),
				'executor'    => 'client',
				'scope'       => 'run',
			),
		),
		'on_event'          => static function ( string $event, array $payload ) use ( &$log_mixed ): void {
			$log_mixed[] = array(
				'event'   => $event,
				'payload' => $payload,
			);
		},
	)
);

$rejected_mixed = rejection_smoke_events_named( $log_mixed, 'tool_declarations_rejected' );
agents_api_smoke_assert_equals( 1, count( $rejected_mixed ), 'mixed: rejected event emitted once', $failures, $passes );
agents_api_smoke_assert_equals( 1, $rejected_mixed[0]['rejected_count'] ?? null, 'mixed: one declaration rejected', $failures, $passes );
agents_api_smoke_assert_equals( 1, $rejected_mixed[0]['accepted_count'] ?? null, 'mixed: one declaration accepted', $failures, $passes );
agents_api_smoke_assert_equals( 0, count( rejection_smoke_events_named( $log_mixed, 'tool_mediation_disabled' ) ), 'mixed: no mediation_disabled event', $failures, $passes );
agents_api_smoke_assert_equals( true, count( $result2['tool_execution_results'] ?? array() ) >= 1, 'mixed: valid tool still executed', $failures, $passes );

// --- [3] All valid: no rejection events at all ------------------------------
echo "\n[3] All declarations valid -> no rejection events:\n";

$log_clean = array();
AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'go' ) ),
	static function ( array $messages, array $context ): array {
		unset( $context );
		return array( 'messages' => $messages );
	},
	array(
		'tool_executor'     => $executor,
		'max_turns'         => 1,
		'tool_declarations' => array(
			'client/work' => array(
				'name'        => 'client/work',
				'source'      => 'client',
				'description' => 'Valid client tool.',
				'parameters'  => array( 'type' => 'object' ),
				'executor'    => 'client',
				'scope'       => 'run',
			),
		),
		'on_event'          => static function ( string $event, array $payload ) use ( &$log_clean ): void {
			$log_clean[] = array(
				'event'   => $event,
				'payload' => $payload,
			);
		},
	)
);

agents_api_smoke_assert_equals( 0, count( rejection_smoke_events_named( $log_clean, 'tool_declarations_rejected' ) ), 'clean: no rejected event', $failures, $passes );
agents_api_smoke_assert_equals( 0, count( rejection_smoke_events_named( $log_clean, 'tool_mediation_disabled' ) ), 'clean: no mediation_disabled event', $failures, $passes );

agents_api_smoke_finish( 'tool declaration rejection diagnostics', $failures, $passes );

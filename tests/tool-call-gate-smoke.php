<?php
/**
 * Pure-PHP smoke test for the deterministic tool-call gate.
 *
 * Proves the gate primitive ({@see WP_Agent_Tool_Call_Gate}) and that the
 * conversation loop ENFORCES it from the runtime: a turn runner that deliberately
 * violates a rule (inspect past the budget, or try to finish without committing)
 * is blocked by the loop regardless of what the model "wants".
 *
 * Run with: php tests/tool-call-gate-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-tool-call-gate-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

use AgentsAPI\AI\WP_Agent_Conversation_Loop;
use AgentsAPI\AI\WP_Agent_Message;
use AgentsAPI\AI\WP_Agent_Tool_Call_Gate;

/**
 * Build a tool-call message for a hand-assembled transcript.
 *
 * @param string $tool_name Tool name.
 * @param int    $turn      Turn number.
 * @return array<string,mixed>
 */
function tool_call_gate_smoke_call( string $tool_name, int $turn = 1 ): array {
	static $sequence = 0;
	++$sequence;

	return WP_Agent_Message::toolCall( '', $tool_name, array(), $turn, array( 'tool_call_id' => $tool_name . '-' . $sequence ) );
}

// The faithful docs-agent bootstrap rule, restored as a native primitive:
// after `anchor`, at most 2 of the `read` discovery tools, then a `write`
// commit is required — both per-call and for completion.
$rules = array(
	array(
		'id'             => 'bounded-discovery',
		'after_tool'     => 'anchor',
		'limited_tools'  => array( 'read', 'grep' ),
		'max_calls'      => 2,
		'require_one_of' => array( 'write' ),
	),
);

echo "\n[1] Gate primitive decides per-call deterministically:\n";
$gate = WP_Agent_Tool_Call_Gate::from_config( $rules );
agents_api_smoke_assert_equals( true, $gate instanceof WP_Agent_Tool_Call_Gate, 'gate builds from declarative rules', $failures, $passes );

// Before the anchor is called, nothing is limited.
$pre_anchor = array( WP_Agent_Message::text( 'user', 'go' ) );
agents_api_smoke_assert_equals( true, $gate->evaluate_call( 'read', $pre_anchor )['allowed'], 'reads are unrestricted before the anchor fires', $failures, $passes );

// After the anchor + 2 reads, a 3rd read is blocked but a write is allowed.
$after_two_reads = array(
	WP_Agent_Message::text( 'user', 'go' ),
	tool_call_gate_smoke_call( 'anchor' ),
	tool_call_gate_smoke_call( 'read' ),
	tool_call_gate_smoke_call( 'read' ),
);
$third_read = $gate->evaluate_call( 'read', $after_two_reads );
agents_api_smoke_assert_equals( false, $third_read['allowed'], 'third discovery call past the budget is rejected', $failures, $passes );
agents_api_smoke_assert_equals( 'bounded-discovery', $third_read['context']['rule_id'] ?? '', 'rejection names the violated rule', $failures, $passes );
agents_api_smoke_assert_equals( 2, $third_read['context']['limited_count'] ?? 0, 'rejection reports the discovery count at the limit', $failures, $passes );
agents_api_smoke_assert_equals( true, $gate->evaluate_call( 'write', $after_two_reads )['allowed'], 'the required commit tool is always allowed', $failures, $passes );

// Once a write has happened after the anchor, the limit resets.
$after_write = array_merge( $after_two_reads, array( tool_call_gate_smoke_call( 'write' ) ) );
agents_api_smoke_assert_equals( true, $gate->evaluate_call( 'read', $after_write )['allowed'], 'discovery is permitted again after a commit', $failures, $passes );

echo "\n[2] Gate primitive blocks completion until a commit happens:\n";
agents_api_smoke_assert_equals( true, $gate->evaluate_completion( $pre_anchor )['allowed'], 'completion is allowed when discovery never began', $failures, $passes );
$completion_blocked = $gate->evaluate_completion( $after_two_reads );
agents_api_smoke_assert_equals( false, $completion_blocked['allowed'], 'completion is blocked after discovery with no commit', $failures, $passes );
agents_api_smoke_assert_equals( 'bounded-discovery', $completion_blocked['context']['rule_id'] ?? '', 'completion block names the violated rule', $failures, $passes );
agents_api_smoke_assert_equals( true, $gate->evaluate_completion( $after_write )['allowed'], 'completion is allowed once the commit tool ran', $failures, $passes );

// ---------------------------------------------------------------------------
// Loop enforcement: a tool executor that records every call it is asked to run.
// ---------------------------------------------------------------------------
$executor = new class() implements AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
	/** @var array<int,string> Names of tools the loop actually executed. */
	public array $executed = array();

	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		$this->executed[] = (string) ( $tool_call['tool_name'] ?? '' );

		return array(
			'success'   => true,
			'tool_name' => $tool_call['tool_name'],
			'result'    => array( 'ok' => true ),
		);
	}
};

$tools = array();
foreach ( array( 'anchor', 'read', 'grep', 'write' ) as $tool ) {
	$tools[ $tool ] = array(
		'name'        => $tool,
		'source'      => 'client',
		'description' => ucfirst( $tool ),
		'parameters'  => array( 'type' => 'object', 'properties' => array() ),
		'executor'    => 'client',
		'scope'       => 'run',
	);
}

echo "\n[3] Loop rejects an over-budget tool call the model insists on (within one turn):\n";
$executor->executed = array();
$over_budget_result = WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'bootstrap' ) ),
	static function ( array $messages ): array {
		// One turn that deliberately violates the rule: anchor, three reads
		// (the third is over the budget of two), then a commit. The runtime —
		// not the model — must refuse the third read.
		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array( 'id' => 'c-anchor', 'name' => 'anchor', 'parameters' => array() ),
				array( 'id' => 'c-read-1', 'name' => 'read', 'parameters' => array() ),
				array( 'id' => 'c-read-2', 'name' => 'read', 'parameters' => array() ),
				array( 'id' => 'c-read-3', 'name' => 'read', 'parameters' => array() ),
				array( 'id' => 'c-write-1', 'name' => 'write', 'parameters' => array() ),
			),
		);
	},
	array(
		'max_turns'         => 1,
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
		'tool_call_rules'   => $rules,
	)
);

agents_api_smoke_assert_equals( array( 'anchor', 'read', 'read', 'write' ), $executor->executed, 'runtime executed anchor + 2 reads + commit, but never the over-budget read', $failures, $passes );

// Locate the rejected read in the recorded results and prove the gate rejected it.
$rejected = array();
foreach ( $over_budget_result['tool_execution_results'] as $entry ) {
	if ( 'read' === ( $entry['tool_name'] ?? '' ) && false === ( $entry['result']['success'] ?? true ) ) {
		$rejected[] = $entry;
	}
}
agents_api_smoke_assert_equals( 1, count( $rejected ), 'exactly one read was rejected by the runtime', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Tool_Call_Gate::ERROR_TYPE_CALL_REJECTED, $rejected[0]['result']['metadata']['error_type'] ?? '', 'rejected read carries the gate error type', $failures, $passes );
$rejected_audit = array_values( array_filter(
	$over_budget_result['tool_audit_events'],
	static fn( array $event ): bool => 'read' === ( $event['tool_name'] ?? '' ) && false === ( $event['success'] ?? true )
) );
agents_api_smoke_assert_equals( WP_Agent_Tool_Call_Gate::ERROR_TYPE_CALL_REJECTED, $rejected_audit[0]['error_type'] ?? '', 'audit trail records the deterministic gate rejection', $failures, $passes );

echo "\n[4] Loop refuses to complete until the commit tool runs, no matter how often the model tries to finish:\n";
$executor->executed   = array();
$turns_seen           = array();
$gate_events          = array();
$completion_result = WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'bootstrap' ) ),
	static function ( array $messages, array $context ) use ( &$turns_seen ): array {
		$turn         = (int) ( $context['turn'] ?? 0 );
		$turns_seen[] = $turn;

		if ( 1 === $turn ) {
			// Begin discovery, no commit yet.
			return array(
				'messages'   => $messages,
				'tool_calls' => array(
					array( 'id' => 't1-anchor', 'name' => 'anchor', 'parameters' => array() ),
				),
			);
		}

		if ( 2 === $turn || 3 === $turn ) {
			// The model tries to finish (no tool calls) WITHOUT committing.
			// The runtime must block this on every attempt.
			return array(
				'messages'   => $messages,
				'content'    => 'All done.',
				'tool_calls' => array(),
			);
		}

		if ( 4 === $turn ) {
			// Finally commit.
			return array(
				'messages'   => $messages,
				'tool_calls' => array(
					array( 'id' => 't4-write', 'name' => 'write', 'parameters' => array() ),
				),
			);
		}

		// Turn 5: now finishing is allowed.
		return array(
			'messages'   => $messages,
			'content'    => 'Committed and done.',
			'tool_calls' => array(),
		);
	},
	array(
		'max_turns'         => 8,
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
		'tool_call_rules'   => $rules,
		'on_event'          => static function ( string $event, array $payload ) use ( &$gate_events ): void {
			if ( WP_Agent_Tool_Call_Gate::EVENT_COMPLETION_BLOCKED === $event ) {
				$gate_events[] = $payload;
			}
		},
	)
);

agents_api_smoke_assert_equals( array( 1, 2, 3, 4, 5 ), $turns_seen, 'loop kept re-prompting through the blocked finish attempts until the commit and one clean finish', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $gate_events ), 'completion was deterministically blocked on both premature finish attempts', $failures, $passes );
agents_api_smoke_assert_equals( true, in_array( 'write', $executor->executed, true ), 'the required commit tool ran before the loop was allowed to finish', $failures, $passes );
agents_api_smoke_assert_equals( true, (bool) ( $completion_result['completed'] ?? false ), 'loop completes once the commit requirement is satisfied', $failures, $passes );
agents_api_smoke_assert_equals( 'Committed and done.', $completion_result['final_content'] ?? '', 'final content is the post-commit answer, not the blocked one', $failures, $passes );

// A model-visible reason was injected so the agent can self-correct.
$injected = array_values( array_filter(
	$completion_result['messages'],
	static fn( array $m ): bool => WP_Agent_Tool_Call_Gate::EVENT_COMPLETION_BLOCKED === ( $m['metadata']['type'] ?? '' )
) );
agents_api_smoke_assert_equals( 2, count( $injected ), 'each block injects a model-visible correction message', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( (string) ( $injected[0]['content'] ?? '' ), 'COMPLETION BLOCKED' ), 'injected correction states the completion is blocked', $failures, $passes );

// ---------------------------------------------------------------------------
// Anchor-independent require_tool_use rule: do not finish without doing real
// work. Reproduces the native failure mode where a model narrates its intent
// ("List root.") as text on turn one instead of emitting a tool call, which the
// loop would otherwise treat as natural completion and stop with zero work.
// ---------------------------------------------------------------------------
$require_tool_use_rules = array(
	array(
		'id'               => 'require-tool-use',
		'require_tool_use' => true,
	),
);

echo "\n[5] require_tool_use rule blocks completion until at least one tool runs:\n";
$rtu_gate = WP_Agent_Tool_Call_Gate::from_config( $require_tool_use_rules );
agents_api_smoke_assert_equals( true, $rtu_gate instanceof WP_Agent_Tool_Call_Gate, 'gate builds from an anchor-less require_tool_use rule', $failures, $passes );

$no_tools_yet = array(
	WP_Agent_Message::text( 'user', 'List the workspace root then document it.' ),
	WP_Agent_Message::text( 'assistant', '_List root._' ),
);
$rtu_blocked = $rtu_gate->evaluate_completion( $no_tools_yet );
agents_api_smoke_assert_equals( false, $rtu_blocked['allowed'], 'completion is blocked when the transcript has zero tool calls', $failures, $passes );
agents_api_smoke_assert_equals( 'require-tool-use', $rtu_blocked['context']['rule_id'] ?? '', 'block names the require_tool_use rule', $failures, $passes );
agents_api_smoke_assert_equals( 0, $rtu_blocked['context']['tool_calls_seen'] ?? -1, 'block reports zero tool calls seen', $failures, $passes );

$one_tool = array_merge( $no_tools_yet, array( tool_call_gate_smoke_call( 'read' ) ) );
agents_api_smoke_assert_equals( true, $rtu_gate->evaluate_completion( $one_tool )['allowed'], 'completion is allowed once any tool call has run', $failures, $passes );

echo "\n[6] Loop does not prematurely complete a turn-one narration with zero tool calls:\n";
$executor->executed = array();
$rtu_turns_seen     = array();
$rtu_gate_events    = array();
$rtu_result = WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'List the workspace root then document it.' ) ),
	static function ( array $messages, array $context ) use ( &$rtu_turns_seen ): array {
		$turn               = (int) ( $context['turn'] ?? 0 );
		$rtu_turns_seen[]   = $turn;

		if ( 1 === $turn ) {
			// The exact native repro: the model narrates its intended action as
			// prose instead of emitting a tool call. The runtime must refuse to
			// treat this as a finished run.
			return array(
				'messages'   => $messages,
				'content'    => '_List root._',
				'tool_calls' => array(),
			);
		}

		if ( 2 === $turn ) {
			// After the nudge, the model finally acts.
			return array(
				'messages'   => $messages,
				'tool_calls' => array(
					array( 'id' => 't2-read', 'name' => 'read', 'parameters' => array() ),
				),
			);
		}

		// Turn 3: a tool has run, so finishing is now allowed.
		return array(
			'messages'   => $messages,
			'content'    => 'Documented the workspace root.',
			'tool_calls' => array(),
		);
	},
	array(
		'max_turns'         => 8,
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
		'tool_call_rules'   => $require_tool_use_rules,
		'on_event'          => static function ( string $event, array $payload ) use ( &$rtu_gate_events ): void {
			if ( WP_Agent_Tool_Call_Gate::EVENT_COMPLETION_BLOCKED === $event ) {
				$rtu_gate_events[] = $payload;
			}
		},
	)
);

agents_api_smoke_assert_equals( array( 1, 2, 3 ), $rtu_turns_seen, 'loop forced another turn instead of stopping after the turn-one narration', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $rtu_gate_events ), 'completion was blocked exactly once, on the zero-tool turn', $failures, $passes );
agents_api_smoke_assert_equals( true, in_array( 'read', $executor->executed, true ), 'the model executed a real tool after the nudge', $failures, $passes );
agents_api_smoke_assert_equals( true, (bool) ( $rtu_result['completed'] ?? false ), 'loop completes once a tool has actually run', $failures, $passes );
agents_api_smoke_assert_equals( 'Documented the workspace root.', $rtu_result['final_content'] ?? '', 'final content is the post-work answer, not the narration', $failures, $passes );

$rtu_injected = array_values( array_filter(
	$rtu_result['messages'],
	static fn( array $m ): bool => WP_Agent_Tool_Call_Gate::EVENT_COMPLETION_BLOCKED === ( $m['metadata']['type'] ?? '' )
) );
agents_api_smoke_assert_equals( 1, count( $rtu_injected ), 'a single model-visible nudge was injected', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( (string) ( $rtu_injected[0]['content'] ?? '' ), 'COMPLETION BLOCKED' ), 'the nudge tells the model its completion was blocked', $failures, $passes );

agents_api_smoke_finish( 'Agents API tool-call gate', $failures, $passes );

<?php
/**
 * Pure-PHP smoke test for the wp_pre_execute_ability approval slice.
 *
 * Run with: php tests/pre-execute-approval-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-pre-execute-approval-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

// Minimal stand-in for the WP 7.1 core sentinel class. The bridge uses
// `instanceof WP_Filter_Sentinel` to decide whether another consumer already
// short-circuited; an anonymous-instance check is enough for the test.
if ( ! class_exists( 'WP_Filter_Sentinel' ) ) {
	class WP_Filter_Sentinel {}
}

use AgentsAPI\AI\Abilities\WP_Agent_Ability_Lifecycle_Bridge;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Store;
use AgentsAPI\AI\Approvals\WP_Agent_Approval_Decision;
use AgentsAPI\AI\WP_Agent_Message;

WP_Agent_Ability_Lifecycle_Bridge::register();

// Build a pending-action shape every assertion below can reuse.
$pending_input = array(
	'action_id'   => 'pa-smoke-001',
	'kind'        => 'delete-post',
	'summary'     => 'Delete post #42.',
	'preview'     => array(
		'format' => 'text',
		'value'  => 'Post #42: "Hello world"',
	),
	'apply_input' => array(
		'post_id' => 42,
	),
	'created_at'  => '2026-05-28T00:00:00Z',
);

echo "\n[1] Sentinel passes through when no decision filter is hooked:\n";
$sentinel = new WP_Filter_Sentinel();
$result   = apply_filters( 'wp_pre_execute_ability', $sentinel, 'core/delete-post', array( 'post_id' => 42 ), null );
agents_api_smoke_assert_equals( true, $result === $sentinel, 'sentinel returned unchanged when nothing decides', $failures, $passes );

echo "\n[2] Decision filter returning null leaves the sentinel intact:\n";
add_filter(
	WP_Agent_Ability_Lifecycle_Bridge::FILTER_PRE_EXECUTE_DECISION,
	static function ( $decision ) {
		return $decision;
	}
);
$result = apply_filters( 'wp_pre_execute_ability', new WP_Filter_Sentinel(), 'core/delete-post', array( 'post_id' => 42 ), null );
agents_api_smoke_assert_equals( true, $result instanceof WP_Filter_Sentinel, 'null decision means pass through', $failures, $passes );

// Reset the action registry so each test stage runs against a clean filter slate.
$GLOBALS['__agents_api_smoke_actions'] = array();
WP_Agent_Ability_Lifecycle_Bridge::register();

echo "\n[3] Pending-action decision mints, stages, and returns an approval_required envelope:\n";
$stored = array();
add_filter(
	WP_Agent_Ability_Lifecycle_Bridge::FILTER_PENDING_ACTION_STORE,
	static function () use ( &$stored ) {
		return new class( $stored ) implements WP_Agent_Pending_Action_Store {
			/** @var array<int,WP_Agent_Pending_Action> Reference to outer log. */
			private array $log;

			public function __construct( array &$log ) {
				$this->log = &$log;
			}

			public function store( WP_Agent_Pending_Action $action ): bool {
				$this->log[] = $action;
				return true;
			}

			public function get( string $action_id, bool $include_resolved = false ): ?WP_Agent_Pending_Action {
				foreach ( $this->log as $action ) {
					if ( $action->to_array()['action_id'] === $action_id ) {
						return $action;
					}
				}
				return null;
			}

			public function list( array $filters = array() ): array {
				return $this->log;
			}

			public function summary( array $filters = array() ): array {
				return array( 'pending' => count( $this->log ) );
			}

			public function record_resolution( string $action_id, WP_Agent_Approval_Decision $decision, string $resolver, $result = null, ?string $error = null, array $metadata = array() ): bool {
				return true;
			}

			public function expire( ?string $before = null ): int {
				return 0;
			}

			public function delete( string $action_id ): bool {
				return false;
			}
		};
	}
);
add_filter(
	WP_Agent_Ability_Lifecycle_Bridge::FILTER_PRE_EXECUTE_DECISION,
	static function ( $decision, string $ability_name ) use ( $pending_input ) {
		if ( 'core/delete-post' !== $ability_name ) {
			return $decision;
		}
		return $pending_input;
	},
	10,
	2
);
$envelope = apply_filters( 'wp_pre_execute_ability', new WP_Filter_Sentinel(), 'core/delete-post', array( 'post_id' => 42 ), null );
agents_api_smoke_assert_equals( WP_Agent_Message::TYPE_APPROVAL_REQUIRED, $envelope['type'] ?? '', 'bridge returns approval_required envelope', $failures, $passes );
agents_api_smoke_assert_equals( 'pa-smoke-001', $envelope['payload']['action_id'] ?? '', 'envelope payload carries action_id', $failures, $passes );
agents_api_smoke_assert_equals( 'delete-post', $envelope['payload']['kind'] ?? '', 'envelope payload carries kind', $failures, $passes );
agents_api_smoke_assert_equals( 'Delete post #42.', $envelope['payload']['summary'] ?? '', 'envelope payload carries summary', $failures, $passes );
agents_api_smoke_assert_equals( 'core/delete-post', $envelope['metadata']['ability_name'] ?? '', 'envelope metadata carries ability_name', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $stored ), 'pending action staged via host store', $failures, $passes );
agents_api_smoke_assert_equals( 'pa-smoke-001', $stored[0]->to_array()['action_id'], 'staged pending action matches the minted shape', $failures, $passes );

// Reset for the coexistence case.
$GLOBALS['__agents_api_smoke_actions'] = array();
WP_Agent_Ability_Lifecycle_Bridge::register();

echo "\n[4] Coexistence: a higher-priority short-circuit is preserved when our bridge runs second:\n";
$other_decision = new \stdClass();
add_filter(
	'wp_pre_execute_ability',
	static function () use ( $other_decision ) {
		return $other_decision;
	},
	5,
	4
);
add_filter(
	WP_Agent_Ability_Lifecycle_Bridge::FILTER_PRE_EXECUTE_DECISION,
	static function () use ( $pending_input ) {
		return $pending_input;
	}
);
$result = apply_filters( 'wp_pre_execute_ability', new WP_Filter_Sentinel(), 'core/delete-post', array( 'post_id' => 42 ), null );
agents_api_smoke_assert_equals( true, $result === $other_decision, 'bridge bails when $pre is no longer a sentinel', $failures, $passes );

// Reset for the loop-level test.
$GLOBALS['__agents_api_smoke_actions'] = array();
WP_Agent_Ability_Lifecycle_Bridge::register();

echo "\n[5] Conversation loop surfaces status:approval_required when the executor returns the envelope:\n";

$approval_envelope = WP_Agent_Message::approvalRequired(
	'Delete post #42.',
	array(
		'action_id' => 'pa-smoke-001',
		'kind'      => 'delete-post',
		'summary'   => 'Delete post #42.',
		'preview'   => array(
			'format' => 'text',
			'value'  => 'Post #42: "Hello world"',
		),
	)
);

$loop_executor = new class( $approval_envelope ) implements AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
	/** @var array */
	private array $envelope;

	public function __construct( array $envelope ) {
		$this->envelope = $envelope;
	}

	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		return array(
			'success'   => true,
			'tool_name' => $tool_call['tool_name'],
			'result'    => $this->envelope,
		);
	}
};

$tools = array(
	'core/delete-post' => array(
		'name'        => 'core/delete-post',
		'source'      => 'core',
		'description' => 'Delete a post.',
		'parameters'  => array(
			'type'       => 'object',
			'required'   => array( 'post_id' ),
			'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
			),
		),
		'executor'    => 'core',
		'scope'       => 'run',
	),
);

$loop_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'delete post 42' ) ),
	static function ( array $messages ): array {
		return array(
			'messages'   => $messages,
			'content'    => 'I will delete post 42.',
			'tool_calls' => array(
				array(
					'id'         => 'call_42',
					'name'       => 'core/delete-post',
					'parameters' => array( 'post_id' => 42 ),
				),
			),
		);
	},
	array(
		'max_turns'         => 2,
		'tool_executor'     => $loop_executor,
		'tool_declarations' => $tools,
	)
);

agents_api_smoke_assert_equals( 'approval_required', $loop_result['status'] ?? '', 'loop result surfaces approval_required status', $failures, $passes );
agents_api_smoke_assert_equals( false, (bool) ( $loop_result['completed'] ?? true ), 'loop result is not marked completed', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Message::TYPE_APPROVAL_REQUIRED, $loop_result['approval_required']['type'] ?? '', 'loop carries the approval envelope through', $failures, $passes );
agents_api_smoke_assert_equals( 'pa-smoke-001', $loop_result['approval_required']['payload']['action_id'] ?? '', 'approval envelope action_id preserved', $failures, $passes );

agents_api_smoke_finish( 'pre-execute approval smoke', $failures, $passes );

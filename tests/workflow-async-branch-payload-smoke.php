<?php
/**
 * Regression smoke for the TWO real correctness bugs in the Action Scheduler
 * branch executor, found by a real 6-page Site Forge fanout:
 *
 *   BUG 1 (payload scaling). The branch descriptor — including the full shared
 *   `context` — rode INLINE in the AS action `args`. Action Scheduler enforces a
 *   hard 8,000-char limit on `args`; a realistic multi-KB context blew past it and
 *   every enqueue threw. This test uses a >8KB shared context and an AS shim that
 *   ENFORCES the 8,000-char args limit (throwing exactly as AS does). Before the
 *   fix, dispatch's inline payload exceeds the limit → the enqueue throws → FAIL.
 *   After the fix, the AS args are a small ref (well under 8,000 chars), the full
 *   descriptor is retrievable from the branch store, and the branch action
 *   rehydrates + runs it → PASS.
 *
 *   BUG 2 (silent failure). dispatch() enqueued with NO size guard and NO error
 *   handling. When the enqueue threw, AS's queue runner caught+logged+swallowed
 *   it, so dispatch() returned a phantom `ref => 0` handle for a branch that was
 *   never enqueued; the run then SUSPENDED against a branch that does not exist
 *   and hung. This test shims `as_enqueue_async_action` to fail (return 0), then
 *   asserts dispatch() surfaces a WP_Error and the run FAILS fast — never a
 *   phantom ref=0 handle, never a suspend. Before the fix: phantom handle + no
 *   error (silent). After: clean WP_Error / failed run (loud).
 *
 * Run with: php tests/workflow-async-branch-payload-smoke.php
 *
 * No WordPress required. Drives the REAL AS executor + REAL runner + REAL
 * reconcile/resume + REAL branch store — only Action Scheduler itself is shimmed.
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "workflow-async-branch-payload-smoke\n";

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}
if ( ! class_exists( 'WP_Ability' ) ) {
	class WP_Ability {
		public function __construct( private string $name, private array $args ) {}
		public function get_name(): string { return $this->name; }
		public function execute( $input = null ) {
			$callback = $this->args['execute_callback'] ?? null;
			return is_callable( $callback ) ? call_user_func( $callback, is_array( $input ) ? $input : array() ) : null;
		}
	}
}

$GLOBALS['__filters']   = array();
$GLOBALS['__abilities'] = array();
$GLOBALS['__options']   = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
		unset( $accepted_args );
		$GLOBALS['__filters'][ $hook ][ $priority ][] = $cb;
	}
}
if ( ! function_exists( 'remove_all_filters' ) ) {
	function remove_all_filters( string $hook ): void {
		unset( $GLOBALS['__filters'][ $hook ] );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		$cbs = $GLOBALS['__filters'][ $hook ] ?? array();
		ksort( $cbs );
		foreach ( $cbs as $bucket ) {
			foreach ( $bucket as $cb ) {
				$value = call_user_func_array( $cb, array_merge( array( $value ), $args ) );
			}
		}
		return $value;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
		add_filter( $hook, $cb, $priority, $accepted_args );
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		$cbs = $GLOBALS['__filters'][ $hook ] ?? array();
		ksort( $cbs );
		foreach ( $cbs as $bucket ) {
			foreach ( $bucket as $cb ) {
				call_user_func_array( $cb, $args );
			}
		}
	}
}
if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( string $name ) { return $GLOBALS['__abilities'][ $name ] ?? null; }
}
if ( ! function_exists( 'wp_has_ability' ) ) {
	function wp_has_ability( string $name ): bool { return isset( $GLOBALS['__abilities'][ $name ] ); }
}
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( string $name, array $args ) {
		$GLOBALS['__abilities'][ $name ] = new WP_Ability( $name, $args );
		return $GLOBALS['__abilities'][ $name ];
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ): bool { unset( $cap ); return true; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) {
		return array_key_exists( $option, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $option ] : $default;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $option, $value = '', $deprecated = '', $autoload = null ): bool {
		unset( $deprecated, $autoload );
		if ( array_key_exists( $option, $GLOBALS['__options'] ) ) {
			return false;
		}
		$GLOBALS['__options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		unset( $autoload );
		$GLOBALS['__options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		if ( array_key_exists( $option, $GLOBALS['__options'] ) ) {
			unset( $GLOBALS['__options'][ $option ] );
			return true;
		}
		return false;
	}
}

// ── The Action Scheduler function-shim, WITH the real 8,000-char args guard ──────
// AS enforces a hard 8,000-char limit on the JSON-encoded action args and THROWS
// when exceeded. This shim reproduces that exact behavior so the payload bug is
// caught for real: an inline branch descriptor (the pre-fix payload) trips it; a
// small store ref (the post-fix payload) does not. A "force fail" mode models an
// enqueue that fails for other reasons (AS down) for the fail-loud test.

final class AS_Limit_Shim {
	/** Action Scheduler's real hard limit on the encoded args column. */
	public const ARGS_LIMIT = 8000;

	/** @var array<int,array{id:int,hook:string,args:array<mixed>,group:string}> */
	public static array $queue = array();
	/** @var array<int,bool> */
	public static array $claimed = array();
	private static int $seq = 0;
	/** When true, every enqueue "fails" the way AS's runner swallows — throws. */
	public static bool $force_fail = false;

	public static function reset(): void {
		self::$queue      = array();
		self::$claimed    = array();
		self::$seq        = 0;
		self::$force_fail = false;
	}

	public static function enqueue( string $hook, array $args, string $group ): int {
		if ( self::$force_fail ) {
			throw new \RuntimeException( 'ActionScheduler queue is unavailable (simulated).' );
		}
		$encoded = wp_json_encode_shim( $args );
		if ( strlen( $encoded ) > self::ARGS_LIMIT ) {
			// This is the exact failure the real bug hit.
			throw new \InvalidArgumentException(
				sprintf(
					'ActionScheduler_Action::$args too long. It should not be more than %d characters when encoded as JSON (was %d).',
					self::ARGS_LIMIT,
					strlen( $encoded )
				)
			);
		}
		$id            = ++self::$seq;
		self::$queue[] = array(
			'id'    => $id,
			'hook'  => $hook,
			'args'  => $args,
			'group' => $group,
		);
		return $id;
	}

	public static function claim( int $id ): bool {
		if ( ! empty( self::$claimed[ $id ] ) ) {
			return false;
		}
		self::$claimed[ $id ] = true;
		return true;
	}

	/** @return array<int,array{id:int,hook:string,args:array<mixed>,group:string}> */
	public static function actions_for( string $hook ): array {
		return array_values(
			array_filter(
				self::$queue,
				static function ( array $action ) use ( $hook ): bool {
					return $action['hook'] === $hook;
				}
			)
		);
	}

	public static function fire( int $id ): bool {
		if ( ! self::claim( $id ) ) {
			return false;
		}
		foreach ( self::$queue as $action ) {
			if ( $action['id'] === $id ) {
				do_action( $action['hook'], ...$action['args'] );
				return true;
			}
		}
		return false;
	}
}

function wp_json_encode_shim( $value ): string {
	$encoded = json_encode( $value );
	return false === $encoded ? '' : $encoded;
}

if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	function as_enqueue_async_action( string $hook, array $args = array(), string $group = '' ) {
		return AS_Limit_Shim::enqueue( $hook, $args, $group );
	}
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

function smoke_assert_true( $actual, string $name, array &$failures, int &$passes ): void {
	smoke_assert( true, (bool) $actual, $name, $failures, $passes );
}

require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-bindings.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-spec-validator.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-spec.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-result.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-store.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-recorder.php';
require_once __DIR__ . '/../src/Abilities/class-wp-agent-ability-dispatcher.php';
require_once __DIR__ . '/../src/Runtime/interface-wp-agent-run-control-store.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-option-run-control-store.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-run-control.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-context.php';
require_once __DIR__ . '/../src/Workflows/interface-wp-agent-workflow-branch-executor.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-step-executor.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-runner.php';
require_once __DIR__ . '/../src/Workflows/register-agents-workflow-abilities.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-reconcile-lock.php';
require_once __DIR__ . '/../src/Workflows/register-reconcile-workflow-branch.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-action-scheduler-bridge.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-branch-store.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-action-scheduler-branch-executor.php';
require_once __DIR__ . '/../src/Workflows/register-workflow-branch-executor.php';

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Result;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Recorder;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Runner;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Action_Scheduler_Branch_Executor;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Branch_Store;

final class Payload_Smoke_Recorder implements WP_Agent_Workflow_Run_Recorder {
	/** @var array<string,array<string,mixed>> */
	public array $rows = array();

	public function start( WP_Agent_Workflow_Run_Result $result ) {
		$this->rows[ $result->get_run_id() ] = $result->to_array();
		return $result->get_run_id();
	}
	public function update( WP_Agent_Workflow_Run_Result $result ) {
		$this->rows[ $result->get_run_id() ] = $result->to_array();
		return true;
	}
	public function find( string $run_id ): ?WP_Agent_Workflow_Run_Result {
		return isset( $this->rows[ $run_id ] )
			? WP_Agent_Workflow_Run_Result::from_array( $this->rows[ $run_id ] )
			: null;
	}
	public function recent( array $args = array() ): array {
		unset( $args );
		return array_map(
			array( WP_Agent_Workflow_Run_Result::class, 'from_array' ),
			array_values( $this->rows )
		);
	}
}

function payload_register_ability( string $name, \Closure $handler ): void {
	$GLOBALS['__abilities'][ $name ] = new WP_Ability( $name, array( 'execute_callback' => $handler ) );
}

payload_register_ability(
	'demo/role-worker',
	static function ( array $input ): array {
		return array( 'fragment' => strtoupper( (string) ( $input['label'] ?? 'X' ) ) );
	}
);
payload_register_ability(
	'demo/aggregate',
	static function ( array $input ): array {
		return array( 'final_bundle' => 'FUSED[' . (string) ( $input['headline'] ?? '' ) . '|' . (string) ( $input['body'] ?? '' ) . ']' );
	}
);

/**
 * A realistic multi-page brief: a shared context well over the 8,000-char AS
 * args limit. This is the payload that broke the inline design.
 *
 * @return array<string,mixed>
 */
function payload_large_context(): array {
	$page = str_repeat( 'Design intent, spec, and brief prose for one page of the site forge output. ', 40 );
	$pages = array();
	for ( $i = 1; $i <= 6; $i++ ) {
		$pages[ 'page_' . $i ] = array(
			'title'   => 'Page ' . $i,
			'brief'   => $page,
			'spec'    => $page,
			'intent'  => $page,
		);
	}
	return array( 'brief' => $pages, 'marker' => 'M' );
}

function payload_roles_spec(): WP_Agent_Workflow_Spec {
	return WP_Agent_Workflow_Spec::from_array(
		array(
			'id'    => 'demo/payload-roles',
			'steps' => array(
				array(
					'id'       => 'scatter',
					'type'     => 'parallel',
					'context'  => payload_large_context(),
					'branches' => array(
						array(
							'role'          => 'headline',
							'required'      => true,
							'is_aggregator' => false,
							'steps'         => array(
								array( 'id' => 'h', 'type' => 'ability', 'ability' => 'demo/role-worker', 'args' => array( 'label' => 'head' ) ),
							),
						),
						array(
							'role'          => 'body',
							'required'      => true,
							'is_aggregator' => false,
							'steps'         => array(
								array( 'id' => 'b', 'type' => 'ability', 'ability' => 'demo/role-worker', 'args' => array( 'label' => 'body' ) ),
							),
						),
						array(
							'role'          => 'fuse',
							'required'      => true,
							'is_aggregator' => true,
							'steps'         => array(
								array(
									'id'      => 'agg',
									'type'    => 'ability',
									'ability' => 'demo/aggregate',
									'args'    => array(
										'headline' => '${vars.branch_outputs.headline.fragment}',
										'body'     => '${vars.branch_outputs.body.fragment}',
									),
								),
							),
						),
					),
				),
			),
		)
	);
}

// ═════════════════════════════════════════════════════════════════════════════
// BUG 1 — a >8KB shared context enqueues SMALL args and runs end-to-end.
// ═════════════════════════════════════════════════════════════════════════════
// Sanity: prove the context really is bigger than the AS args limit, so the
// pre-fix inline payload WOULD have thrown.

AS_Limit_Shim::reset();
$GLOBALS['__options'] = array();
$recorder = new Payload_Smoke_Recorder();
remove_all_filters( 'wp_agent_workflow_run_recorder' );
add_filter( 'wp_agent_workflow_run_recorder', static function () use ( $recorder ) { return $recorder; } );

$context_bytes = strlen( wp_json_encode_shim( payload_large_context() ) );
smoke_assert_true( $context_bytes > AS_Limit_Shim::ARGS_LIMIT, "bug1 setup: shared context ({$context_bytes} bytes) exceeds AS args limit (8000)", $failures, $passes );

// With the offload, dispatch enqueues without throwing even though the context is
// huge. (Pre-fix, run() would return a failed run whose parallel step errored with
// the "args too long" enqueue throw.)
$run = ( new WP_Agent_Workflow_Runner( $recorder ) )->run( payload_roles_spec(), array(), array( 'run_id' => 'pay-A' ) );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $run->get_status(), 'bug1: run SUSPENDED (dispatch did NOT throw on a >8KB context)', $failures, $passes );

$branch_actions = AS_Limit_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK );
smoke_assert( 2, count( $branch_actions ), 'bug1: one AS action enqueued per sibling branch', $failures, $passes );

// THE payload assertion: every enqueued AS args payload is SMALL — a reference,
// well under the 8,000-char limit — regardless of the multi-KB context.
$max_args = 0;
foreach ( $branch_actions as $action ) {
	$max_args = max( $max_args, strlen( wp_json_encode_shim( $action['args'] ) ) );
}
smoke_assert_true( $max_args < 1000, "bug1: AS args are small (max {$max_args} bytes, well under 8000)", $failures, $passes );

// The full descriptor is retrievable from the store and rehydrates the shared
// context (re-seated from the run-scoped row).
$first_payload = $branch_actions[0]['args'][0] ?? array();
$rehydrated    = WP_Agent_Workflow_Branch_Store::get_branch(
	(string) ( $first_payload['store_ref'] ?? '' ),
	(string) ( $first_payload['context_ref'] ?? '' )
);
smoke_assert_true( is_array( $rehydrated['branch_vars']['context']['brief'] ?? null ), 'bug1: store rehydrates the full >8KB shared context into the descriptor', $failures, $passes );

// End-to-end: fire branch actions + resume → run SUCCEEDED with the real
// aggregated output. The branch action rehydrated from the store and ran.
foreach ( $branch_actions as $action ) {
	AS_Limit_Shim::fire( $action['id'] );
}
foreach ( AS_Limit_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK ) as $action ) {
	AS_Limit_Shim::fire( $action['id'] );
}
$final = $recorder->find( 'pay-A' );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $final->get_status(), 'bug1: run SUCCEEDED end-to-end (branch action rehydrated from store and ran)', $failures, $passes );
smoke_assert( 'FUSED[HEAD|BODY]', $final->get_output()['steps']['scatter']['final']['final_bundle'] ?? '', 'bug1: aggregate fused the rehydrated branch outputs', $failures, $passes );

// Cleanup discipline: the run's stored branch payloads are released on resume.
$leftover = 0;
foreach ( array_keys( $GLOBALS['__options'] ) as $opt ) {
	if ( str_starts_with( (string) $opt, 'agents_wf_branch_' ) ) {
		++$leftover;
	}
}
smoke_assert( 0, $leftover, 'bug1: branch store rows cleaned up on resume (no orphans)', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// BUG 2 — an enqueue failure FAILS LOUD, never a phantom ref=0 suspend.
// ═════════════════════════════════════════════════════════════════════════════

AS_Limit_Shim::reset();
$GLOBALS['__options']  = array();
AS_Limit_Shim::$force_fail = true; // every enqueue throws (AS unavailable).
$recorder2 = new Payload_Smoke_Recorder();
remove_all_filters( 'wp_agent_workflow_run_recorder' );
add_filter( 'wp_agent_workflow_run_recorder', static function () use ( $recorder2 ) { return $recorder2; } );

// dispatch() directly: it must return a WP_Error, not a phantom ref=0 handle.
$executor  = new WP_Agent_Workflow_Action_Scheduler_Branch_Executor();
$dispatched = $executor->dispatch(
	array(
		array( 'key' => 'headline', 'run_id' => 'pay-fail', 'step_id' => 'scatter', 'required' => true, 'steps' => array( array( 'id' => 'h', 'type' => 'ability', 'ability' => 'demo/role-worker' ) ), 'branch_vars' => array( 'context' => array() ) ),
	),
	array( '_workflow_run_id' => 'pay-fail', '_workflow_step_id' => 'scatter', 'shared_context' => array() )
);
smoke_assert_true( is_wp_error( $dispatched ), 'bug2: dispatch() returns a WP_Error when the enqueue fails (no phantom ref=0 handle)', $failures, $passes );
smoke_assert( 'workflow_branch_dispatch_enqueue_failed', is_wp_error( $dispatched ) ? $dispatched->get_error_code() : '', 'bug2: WP_Error carries the descriptive dispatch-failure code', $failures, $passes );

// The full run path: a failed enqueue must FAIL the run fast — never SUSPENDED.
$run2 = ( new WP_Agent_Workflow_Runner( $recorder2 ) )->run( payload_roles_spec(), array(), array( 'run_id' => 'pay-fail-run' ) );
smoke_assert_true( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED !== $run2->get_status(), 'bug2: run did NOT suspend against un-enqueued branches (no silent stuck-suspend)', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $run2->get_status(), 'bug2: run FAILED fast when dispatch could not enqueue', $failures, $passes );

// No orphan store rows from the failed dispatch.
$leftover2 = 0;
foreach ( array_keys( $GLOBALS['__options'] ) as $opt ) {
	if ( str_starts_with( (string) $opt, 'agents_wf_branch_' ) ) {
		++$leftover2;
	}
}
smoke_assert( 0, $leftover2, 'bug2: failed dispatch cleaned up its stored rows (no orphans)', $failures, $passes );

echo "Passed: {$passes}, Failed: " . count( $failures ) . "\n";
exit( count( $failures ) > 0 ? 1 : 0 );

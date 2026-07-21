<?php
/**
 * Pure-PHP smoke test for async-runner dispatch result honesty.
 *
 * Run with: php tests/workflow-async-runner-dispatch-result-smoke.php
 *
 * @package AgentsAPI\Tests
 */

namespace WpOrg\Requests {
	class Response {
		public function __construct( public int $status_code ) {}
	}

	class Requests {
		/** @var array<int,mixed> */
		public static array $results = array();

		/**
		 * @param array<int,array<string,mixed>> $requests
		 * @param array<string,mixed>            $options
		 * @return array<int,mixed>
		 */
		public static function request_multiple( array $requests, array $options ): array {
			unset( $requests, $options );
			return self::$results;
		}
	}
}

namespace AgentsAPI\AI\Workflows {
	if ( ! class_exists( WP_Agent_Workflow_Action_Scheduler_Bridge::class ) ) {
		final class WP_Agent_Workflow_Action_Scheduler_Bridge {
			public const GROUP = 'agents-api';
		}
	}
}

namespace {
	defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

	$failures = array();
	$passes   = 0;

	echo "workflow-async-runner-dispatch-result-smoke\n";

	if ( ! class_exists( 'ActionScheduler' ) ) {
		class ActionScheduler {
			public static function is_initialized(): bool {
				return true;
			}
		}
	}

	if ( ! function_exists( 'admin_url' ) ) {
		function admin_url( string $path = '' ): string {
			return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
		}
	}

	if ( ! function_exists( 'wp_create_nonce' ) ) {
		function wp_create_nonce( string $action ): string {
			return 'nonce-' . $action;
		}
	}

	if ( ! function_exists( 'add_query_arg' ) ) {
		function add_query_arg( array $args, string $url ): string {
			$separator = false === strpos( $url, '?' ) ? '?' : '&';
			return $url . $separator . http_build_query( $args );
		}
	}

	if ( ! function_exists( 'esc_url_raw' ) ) {
		function esc_url_raw( string $url ): string {
			return $url;
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, $value ) {
			unset( $hook );
			return $value;
		}
	}

	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( string $url, int $component = -1 ) {
			return parse_url( $url, $component );
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

	require_once __DIR__ . '/../src/Workflows/interface-wp-agent-workflow-branch-executor.php';
	require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-action-scheduler-branch-executor.php';

	use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Action_Scheduler_Branch_Executor;
	use WpOrg\Requests\Requests;
	use WpOrg\Requests\Response;

	Requests::$results = array(
		new \RuntimeException( 'cURL error 7: Failed to connect to 127.0.0.1 port 443' ),
		new \RuntimeException( 'cURL error 7: Failed to connect to 127.0.0.1 port 443' ),
	);
	smoke_assert(
		0,
		WP_Agent_Workflow_Action_Scheduler_Branch_Executor::trigger_async_runner( 2 ),
		'all failed parallel loopbacks report 0 accepted dispatches',
		$failures,
		$passes
	);

	Requests::$results = array(
		new \RuntimeException( 'cURL error 7: Failed to connect to 127.0.0.1 port 443' ),
		new Response( 200 ),
		new Response( 204 ),
	);
	smoke_assert(
		2,
		WP_Agent_Workflow_Action_Scheduler_Branch_Executor::trigger_async_runner( 3 ),
		'mixed parallel loopbacks report only accepted dispatches',
		$failures,
		$passes
	);

	Requests::$results = array(
		new Response( 403 ),
		new Response( 503 ),
	);
	smoke_assert(
		0,
		WP_Agent_Workflow_Action_Scheduler_Branch_Executor::trigger_async_runner( 2 ),
		'rejected HTTP responses report 0 accepted dispatches',
		$failures,
		$passes
	);

	echo "\n{$passes} passing" . ( $failures ? ', ' . count( $failures ) . ' failing' : '' ) . "\n";
	if ( $failures ) {
		exit( 1 );
	}
}

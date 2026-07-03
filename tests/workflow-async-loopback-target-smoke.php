<?php
/**
 * Pure-PHP smoke test for the async-runner loopback dispatch TARGET rewrite —
 * {@see WP_Agent_Workflow_Action_Scheduler_Branch_Executor::loopback_dispatch_target()}.
 *
 * Run with: php tests/workflow-async-loopback-target-smoke.php
 *
 * The loopback burst that warms N async-runner workers must POST to where the
 * local server is ACTUALLY reachable on the loop. On a single-server site that is
 * the public scheme/port (rewritten to 127.0.0.1 to dodge mDNS). On a SPLIT
 * runtime — public HTTPS front door fronting an internal plain-HTTP worker pool
 * on a non-443 port — the public `https://…:443` points at a TLS listener that
 * does not exist on loopback, so every concurrent POST fails the TLS handshake
 * and the fan-out collapses to the serial WP-Cron drain.
 *
 * Covered:
 *   - DEFAULT (no filter): a public `https://<.local host>/…` rewrites to
 *     `https://127.0.0.1/…` with the canonical `Host: <.local host>` header —
 *     byte-for-byte the historical behavior (no regression for single-server
 *     sites).
 *   - OVERRIDE (filter set to `http://localhost:8882`): the target becomes
 *     `http://localhost:8882/…` carrying the AS nonce, with a canonical
 *     `Host: <public host>` header — NOT `https://127.0.0.1:443/…`. This is the
 *     plain-HTTP-non-443 split-runtime case.
 *   - the AS nonce query survives the rewrite in both cases.
 *
 * `loopback_dispatch_target()` is private; the test invokes it via a bound
 * closure and asserts on the ACTUAL constructed target — not a mock.
 *
 * No WordPress required.
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "workflow-async-loopback-target-smoke\n";

$GLOBALS['__filters'] = array();

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

// Minimal wp_parse_url shim (delegates to PHP's parse_url, WordPress's default).
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

function smoke_assert_true( $actual, string $name, array &$failures, int &$passes ): void {
	smoke_assert( true, (bool) $actual, $name, $failures, $passes );
}

require_once __DIR__ . '/../src/Workflows/interface-wp-agent-workflow-branch-executor.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-action-scheduler-branch-executor.php';

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Action_Scheduler_Branch_Executor;

/**
 * Invoke the private static loopback_dispatch_target() via a bound closure.
 *
 * @param string $url The admin-ajax dispatch URL.
 * @return array{url:string,headers:array<string,string>}
 */
function loopback_target( string $url ): array {
	$invoke = \Closure::bind(
		static function ( string $u ): array {
			return WP_Agent_Workflow_Action_Scheduler_Branch_Executor::loopback_dispatch_target( $u );
		},
		null,
		WP_Agent_Workflow_Action_Scheduler_Branch_Executor::class
	);
	return $invoke( $url );
}

// A realistic public admin-ajax dispatch URL: HTTPS front door on a Studio
// `.local` host, carrying the AS async-runner action + nonce.
$public_url = 'https://fisiostetic.local/wp-admin/admin-ajax.php?action=as_async_request_queue_runner&nonce=abc123';

// ═════════════════════════════════════════════════════════════════════════════
// 1. DEFAULT (no filter) — historical behavior preserved byte-for-byte.
// ═════════════════════════════════════════════════════════════════════════════

remove_all_filters( 'agents_workflow_async_runner_loopback_base' );

$default = loopback_target( $public_url );
smoke_assert(
	'https://127.0.0.1/wp-admin/admin-ajax.php?action=as_async_request_queue_runner&nonce=abc123',
	$default['url'],
	'default: public https/.local rewrites to https://127.0.0.1 (host dodge, scheme/port preserved)',
	$failures,
	$passes
);
smoke_assert(
	'fisiostetic.local',
	$default['headers']['Host'] ?? '',
	'default: canonical Host header carries the public host',
	$failures,
	$passes
);

// A loopback host in the public URL is passed through untouched (no override).
$default_loopback = loopback_target( 'http://localhost:8888/wp-admin/admin-ajax.php?action=as_async_request_queue_runner&nonce=z' );
smoke_assert(
	'http://localhost:8888/wp-admin/admin-ajax.php?action=as_async_request_queue_runner&nonce=z',
	$default_loopback['url'],
	'default: already-loopback URL passes through unchanged',
	$failures,
	$passes
);
smoke_assert_true(
	empty( $default_loopback['headers'] ),
	'default: already-loopback URL needs no Host header',
	$failures,
	$passes
);

// A public URL with an explicit non-default port keeps that port in URL + Host.
$default_ported = loopback_target( 'https://front.local:8443/wp-admin/admin-ajax.php?action=as_async_request_queue_runner&nonce=p' );
smoke_assert(
	'https://127.0.0.1:8443/wp-admin/admin-ajax.php?action=as_async_request_queue_runner&nonce=p',
	$default_ported['url'],
	'default: explicit public port is preserved in the 127.0.0.1 target',
	$failures,
	$passes
);
smoke_assert(
	'front.local:8443',
	$default_ported['headers']['Host'] ?? '',
	'default: explicit public port is carried in the Host authority',
	$failures,
	$passes
);

// ═════════════════════════════════════════════════════════════════════════════
// 2. OVERRIDE — the split-runtime plain-HTTP-non-443 case (the fix).
// ═════════════════════════════════════════════════════════════════════════════
// A runtime whose internal loopback is plain HTTP on localhost:8882 declares that
// base. The target must point there (correct scheme + host + port), NOT at the
// nonexistent https://127.0.0.1:443 TLS listener — while still carrying the
// canonical public Host header so the local server routes to the right site.

remove_all_filters( 'agents_workflow_async_runner_loopback_base' );
add_filter(
	'agents_workflow_async_runner_loopback_base',
	static function (): string {
		return 'http://localhost:8882';
	}
);

$override = loopback_target( $public_url );
smoke_assert(
	'http://localhost:8882/wp-admin/admin-ajax.php?action=as_async_request_queue_runner&nonce=abc123',
	$override['url'],
	'override: split runtime targets its internal http://localhost:8882 (correct scheme + port)',
	$failures,
	$passes
);
smoke_assert(
	'fisiostetic.local',
	$override['headers']['Host'] ?? '',
	'override: canonical public Host header preserved so the local server routes to the right site',
	$failures,
	$passes
);

// The regression this fixes: the target must NOT be the dead https/:443 form.
smoke_assert_true(
	'https://127.0.0.1/wp-admin/admin-ajax.php?action=as_async_request_queue_runner&nonce=abc123' !== $override['url']
		&& false === strpos( $override['url'], 'https://127.0.0.1' ),
	'override: target is NOT the dead https://127.0.0.1(:443) TLS form',
	$failures,
	$passes
);

// The AS nonce query survives the override rewrite.
smoke_assert_true(
	false !== strpos( $override['url'], 'action=as_async_request_queue_runner&nonce=abc123' ),
	'override: the AS async-runner action + nonce survive the rewrite',
	$failures,
	$passes
);

// Filter receives the source URL as its second arg (so a runtime can branch on it).
remove_all_filters( 'agents_workflow_async_runner_loopback_base' );
$seen_url = '';
add_filter(
	'agents_workflow_async_runner_loopback_base',
	static function ( string $base, string $url ) use ( &$seen_url ): string {
		$seen_url = $url;
		return 'http://localhost:8882';
	},
	10,
	2
);
loopback_target( $public_url );
smoke_assert(
	$public_url,
	$seen_url,
	'override: filter receives the source dispatch URL as its second argument',
	$failures,
	$passes
);

echo "Passed: {$passes}, Failed: " . count( $failures ) . "\n";
exit( count( $failures ) > 0 ? 1 : 0 );

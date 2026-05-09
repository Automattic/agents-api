<?php
/**
 * Pure-PHP smoke for the WP_Agent `subagents` field.
 *
 * Run with: php tests/subagents-smoke.php
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "subagents-smoke\n";

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
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $str ) { return (string) $str; }
}
if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( $func, $msg, $version ) { /* no-op for smoke */ }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return $s; }
}

require_once __DIR__ . '/../src/Registry/class-wp-agent.php';

$plain = new WP_Agent( 'commander', array( 'label' => 'Commander' ) );
smoke_assert( array(), $plain->get_subagents(), 'agent without subagents: empty array', $failures, $passes );
smoke_assert( false, $plain->is_coordinator(), 'agent without subagents: not coordinator', $failures, $passes );

$coord = new WP_Agent(
	'commander',
	array(
		'label'     => 'Commander',
		'subagents' => array( 'Detector', 'navigator', '' ),
	)
);
smoke_assert( array( 'detector', 'navigator' ), $coord->get_subagents(), 'subagents: sanitised + filtered', $failures, $passes );
smoke_assert( true, $coord->is_coordinator(), 'is_coordinator: true with subagents', $failures, $passes );

// to_array surfaces subagents
$arr = $coord->to_array();
smoke_assert( array( 'detector', 'navigator' ), $arr['subagents'] ?? null, 'to_array exposes subagents', $failures, $passes );

smoke_assert_throws(
	static fn() => new WP_Agent( 'c', array( 'subagents' => 'not-an-array' ) ),
	'must be an array',
	'rejects non-array subagents',
	$failures,
	$passes
);

if ( count( $failures ) > 0 ) {
	echo 'FAIL ' . count( $failures ) . " failures\n";
	exit( 1 );
}
echo "OK {$passes} passed\n";

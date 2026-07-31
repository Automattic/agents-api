<?php
/**
 * Test-only shim: neutralize the reconcile lock's per-attempt backoff so the
 * fail-closed acquisition path can be exercised without real wall-clock sleeps.
 *
 * The lock class calls an unqualified `usleep()` from inside the
 * `AgentsAPI\AI\Workflows` namespace. PHP resolves that to this namespaced
 * function (which takes precedence over the global builtin) when it is defined,
 * turning the bounded acquire loop into an instant spin for the test.
 *
 * @package AgentsAPI\Tests
 */

namespace AgentsAPI\AI\Workflows;

if ( ! function_exists( __NAMESPACE__ . '\\usleep' ) ) {
	/**
	 * No-op stand-in for the global usleep() during the fail-closed lock test.
	 *
	 * @param int $microseconds Ignored.
	 * @return void
	 */
	function usleep( int $microseconds ): void {
		unset( $microseconds );
	}
}

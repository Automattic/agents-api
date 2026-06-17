<?php
/**
 * Pure-PHP smoke test for generic input and SSE response primitives.
 *
 * Run with: php tests/response-and-input-primitives-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "response-and-input-primitives-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

agents_api_smoke_require_module();

use function AgentsAPI\AI\agents_api_emit_sse_json_frame;
use function AgentsAPI\AI\agents_api_numeric_to_int;
use function AgentsAPI\AI\agents_api_scalar_to_string;
use function AgentsAPI\AI\agents_api_string_keyed_array;

$stringable = new class() {
	public function __toString(): string {
		return 'stringable-value';
	}
};

agents_api_smoke_assert_equals( 'text', agents_api_scalar_to_string( 'text' ), 'scalar string passes through', $failures, $passes );
agents_api_smoke_assert_equals( '123', agents_api_scalar_to_string( 123 ), 'scalar int converts to string', $failures, $passes );
agents_api_smoke_assert_equals( 'stringable-value', agents_api_scalar_to_string( $stringable ), 'Stringable converts to string', $failures, $passes );
agents_api_smoke_assert_equals( '', agents_api_scalar_to_string( array( 'not text' ) ), 'array normalizes to empty string', $failures, $passes );
agents_api_smoke_assert_equals( '', agents_api_scalar_to_string( new stdClass() ), 'non-stringable object normalizes to empty string', $failures, $passes );

agents_api_smoke_assert_equals( 42, agents_api_numeric_to_int( '42' ), 'numeric string converts to int', $failures, $passes );
agents_api_smoke_assert_equals( 0, agents_api_numeric_to_int( 'abc' ), 'non-numeric value converts to zero', $failures, $passes );

$normalized = agents_api_string_keyed_array(
	array(
		'alpha' => 1,
		0       => 'drop',
		'beta'  => 2,
	)
);
agents_api_smoke_assert_equals( array( 'alpha' => 1, 'beta' => 2 ), $normalized, 'string-keyed array drops numeric keys', $failures, $passes );

ob_start();
agents_api_emit_sse_json_frame( array( 'ok' => true, 'message' => 'hello' ) );
$sse = ob_get_clean();

agents_api_smoke_assert_equals( "data: {\"ok\":true,\"message\":\"hello\"}\n\n", $sse, 'SSE emitter writes one JSON data frame', $failures, $passes );

agents_api_smoke_finish( 'generic response/input primitives', $failures, $passes );

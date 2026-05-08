<?php
/**
 * Pure-PHP smoke test for webhook verification and inbound idempotency helpers.
 *
 * Run with: php tests/webhook-safety-smoke.php
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "agents-api-webhook-safety-smoke\n";

$GLOBALS['__webhook_safety_transients'] = array();
$GLOBALS['__webhook_safety_now']        = 1000;

function get_transient( string $key ) {
	$entry = $GLOBALS['__webhook_safety_transients'][ $key ] ?? null;
	if ( null === $entry ) {
		return false;
	}

	if ( 0 !== $entry['expires'] && $entry['expires'] <= $GLOBALS['__webhook_safety_now'] ) {
		unset( $GLOBALS['__webhook_safety_transients'][ $key ] );
		return false;
	}

	return $entry['value'];
}

function set_transient( string $key, $value, int $expiration = 0 ): bool {
	$GLOBALS['__webhook_safety_transients'][ $key ] = array(
		'value'   => $value,
		'expires' => 0 < $expiration ? $GLOBALS['__webhook_safety_now'] + $expiration : 0,
	);
	return true;
}

function delete_transient( string $key ): bool {
	unset( $GLOBALS['__webhook_safety_transients'][ $key ] );
	return true;
}

function webhook_safety_assert( $expected, $actual, string $name, array &$failures, int &$passes ): void {
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

require_once __DIR__ . '/../src/Channels/class-wp-agent-webhook-signature.php';
require_once __DIR__ . '/../src/Channels/class-wp-agent-message-idempotency-store.php';
require_once __DIR__ . '/../src/Channels/class-wp-agent-transient-message-idempotency-store.php';
require_once __DIR__ . '/../src/Channels/class-wp-agent-message-idempotency.php';

use AgentsAPI\AI\Channels\WP_Agent_Message_Idempotency;
use AgentsAPI\AI\Channels\WP_Agent_Message_Idempotency_Store;
use AgentsAPI\AI\Channels\WP_Agent_Webhook_Signature;

$body      = '{"event":"message","text":"hello"}';
$secret    = 'super-secret';
$signature = hash_hmac( 'sha256', $body, $secret );

webhook_safety_assert( true, WP_Agent_Webhook_Signature::verify_hmac_sha256( $body, 'sha256=' . $signature, $secret ), 'hmac_prefixed_signature_valid', $failures, $passes );
webhook_safety_assert( true, WP_Agent_Webhook_Signature::verify_hmac_sha256( $body, 'SHA256=' . strtoupper( $signature ), $secret, array( 'expected_prefix' => 'SHA256=' ) ), 'hmac_uppercase_hex_valid', $failures, $passes );
webhook_safety_assert( false, WP_Agent_Webhook_Signature::verify_hmac_sha256( $body, $signature, $secret ), 'hmac_raw_hex_rejected_by_default', $failures, $passes );
webhook_safety_assert( true, WP_Agent_Webhook_Signature::verify_hmac_sha256( $body, $signature, $secret, array( 'allow_raw_hex' => true ) ), 'hmac_raw_hex_allowed_when_requested', $failures, $passes );
webhook_safety_assert( false, WP_Agent_Webhook_Signature::verify_hmac_sha256( $body, 'sha256=' . str_repeat( '0', 64 ), $secret ), 'hmac_wrong_signature_rejected', $failures, $passes );
webhook_safety_assert( false, WP_Agent_Webhook_Signature::verify_hmac_sha256( $body, 'sha1=' . $signature, $secret ), 'hmac_wrong_prefix_rejected', $failures, $passes );
webhook_safety_assert( false, WP_Agent_Webhook_Signature::verify_hmac_sha256( $body, 'sha256=' . $signature, '' ), 'hmac_empty_secret_rejected', $failures, $passes );
webhook_safety_assert( false, WP_Agent_Webhook_Signature::verify_hmac_sha256( $body, 'sha256=not-hex', $secret ), 'hmac_malformed_signature_rejected', $failures, $passes );

webhook_safety_assert( false, WP_Agent_Message_Idempotency::seen( 'whatsapp', 'wamid.1' ), 'idempotency_unseen_by_default', $failures, $passes );
WP_Agent_Message_Idempotency::mark_seen( 'whatsapp', 'wamid.1', 604800 );
webhook_safety_assert( true, WP_Agent_Message_Idempotency::seen( 'whatsapp', 'wamid.1' ), 'idempotency_seen_after_mark', $failures, $passes );
webhook_safety_assert( false, WP_Agent_Message_Idempotency::seen( 'slack', 'wamid.1' ), 'idempotency_scoped_by_provider', $failures, $passes );
WP_Agent_Message_Idempotency::forget( 'whatsapp', 'wamid.1' );
webhook_safety_assert( false, WP_Agent_Message_Idempotency::seen( 'whatsapp', 'wamid.1' ), 'idempotency_forget_removes_marker', $failures, $passes );

WP_Agent_Message_Idempotency::mark_seen( 'whatsapp', 'wamid.2', 10 );
$GLOBALS['__webhook_safety_now'] += 11;
webhook_safety_assert( false, WP_Agent_Message_Idempotency::seen( 'whatsapp', 'wamid.2' ), 'idempotency_marker_expires_after_ttl', $failures, $passes );

WP_Agent_Message_Idempotency::mark_seen( 'whatsapp', 'wamid.3', 0 );
webhook_safety_assert( false, WP_Agent_Message_Idempotency::seen( 'whatsapp', 'wamid.3' ), 'idempotency_zero_ttl_is_not_stored', $failures, $passes );

WP_Agent_Message_Idempotency::mark_seen( '', 'wamid.4', 60 );
webhook_safety_assert( false, WP_Agent_Message_Idempotency::seen( '', 'wamid.4' ), 'idempotency_empty_provider_is_ignored', $failures, $passes );

class Webhook_Safety_Fake_Store implements WP_Agent_Message_Idempotency_Store {
	public array $seen = array();

	public function seen( string $provider, string $message_id ): bool {
		return isset( $this->seen[ $provider . ':' . $message_id ] );
	}

	public function mark_seen( string $provider, string $message_id, int $ttl ): void {
		unset( $ttl );
		$this->seen[ $provider . ':' . $message_id ] = true;
	}

	public function forget( string $provider, string $message_id ): void {
		unset( $this->seen[ $provider . ':' . $message_id ] );
	}
}

$fake_store = new Webhook_Safety_Fake_Store();
WP_Agent_Message_Idempotency::set_store( $fake_store );
WP_Agent_Message_Idempotency::mark_seen( 'bridge', 'queue-1', 30 );
webhook_safety_assert( true, WP_Agent_Message_Idempotency::seen( 'bridge', 'queue-1' ), 'idempotency_store_can_be_replaced', $failures, $passes );
WP_Agent_Message_Idempotency::set_store( null );
webhook_safety_assert( false, WP_Agent_Message_Idempotency::seen( 'bridge', 'queue-1' ), 'idempotency_store_can_be_reset', $failures, $passes );

if ( ! empty( $failures ) ) {
	echo "\nFailures: " . implode( ', ', $failures ) . "\n";
	exit( 1 );
}

echo "\nAll {$passes} webhook safety assertions passed.\n";

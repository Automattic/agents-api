<?php
/**
 * Pure-PHP smoke test for remote bridge primitives.
 *
 * Run with: php tests/remote-bridge-smoke.php
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "agents-api-remote-bridge-smoke\n";

$GLOBALS['__remote_bridge_options'] = array();
$GLOBALS['__remote_bridge_connectors'] = array(
	'matrix-bridge' => array(
		'name'           => 'Matrix Bridge',
		'description'    => 'Generic Matrix bridge connector metadata.',
		'type'           => 'agent_bridge',
		'authentication' => array( 'method' => 'none' ),
	),
);

function get_option( string $key, $default = '' ) {
	return $GLOBALS['__remote_bridge_options'][ $key ] ?? $default;
}

function update_option( string $key, $value, $autoload = null ): bool {
	unset( $autoload );
	$GLOBALS['__remote_bridge_options'][ $key ] = $value;
	return true;
}

function wp_get_connector( string $id ): ?array {
	return $GLOBALS['__remote_bridge_connectors'][ $id ] ?? null;
}

function remote_bridge_assert( $expected, $actual, string $name, array &$failures, int &$passes ): void {
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

require_once __DIR__ . '/../src/Channels/class-wp-agent-bridge-client.php';
require_once __DIR__ . '/../src/Channels/class-wp-agent-bridge-queue-item.php';
require_once __DIR__ . '/../src/Channels/class-wp-agent-bridge-store.php';
require_once __DIR__ . '/../src/Channels/class-wp-agent-option-bridge-store.php';
require_once __DIR__ . '/../src/Channels/class-wp-agent-bridge.php';

use AgentsAPI\AI\Channels\WP_Agent_Bridge;

$client = WP_Agent_Bridge::register_client(
	'Message_Relay',
	'https://bridge.example.test/callback',
	array( 'label' => 'Message relay' ),
	'matrix_bridge'
);

remote_bridge_assert( 'message-relay', $client->client_id, 'client_id_is_normalized', $failures, $passes );
remote_bridge_assert( 'matrix-bridge', $client->connector_id, 'connector_id_is_normalized', $failures, $passes );
remote_bridge_assert( 'Matrix Bridge', $client->connector()['name'] ?? null, 'client_resolves_core_connector_metadata', $failures, $passes );

$stored_client = WP_Agent_Bridge::get_client( 'message-relay' );
remote_bridge_assert( true, null !== $stored_client, 'registered_client_is_stored', $failures, $passes );
remote_bridge_assert( 'https://bridge.example.test/callback', $stored_client?->callback_url, 'registered_client_preserves_callback_url', $failures, $passes );

$item_one = WP_Agent_Bridge::enqueue(
	array(
		'client_id'    => 'message-relay',
		'connector_id' => 'matrix-bridge',
		'agent'        => 'support-agent',
		'session_id'   => 'sess-1',
		'role'         => 'assistant',
		'content'      => 'First reply',
		'completed'    => true,
		'metadata'     => array( 'model' => 'test' ),
	)
);
$item_two = WP_Agent_Bridge::enqueue(
	array(
		'client_id'  => 'message-relay',
		'agent'      => 'support-agent',
		'session_id' => 'sess-2',
		'content'    => 'Second reply',
	)
);
WP_Agent_Bridge::enqueue(
	array(
		'client_id' => 'other-relay',
		'agent'     => 'support-agent',
		'content'   => 'Other relay reply',
	)
);

$pending = WP_Agent_Bridge::pending( 'message-relay' );
remote_bridge_assert( 2, count( $pending ), 'pending_returns_client_items_only', $failures, $passes );
remote_bridge_assert( $item_one->queue_id, $pending[0]->queue_id, 'pending_preserves_queue_order', $failures, $passes );
remote_bridge_assert( 'First reply', $pending[0]->content, 'pending_preserves_content', $failures, $passes );
remote_bridge_assert( array( 'model' => 'test' ), $pending[0]->metadata, 'pending_preserves_metadata', $failures, $passes );

$filtered = WP_Agent_Bridge::pending( 'message-relay', 25, array( 'sess-2' ) );
remote_bridge_assert( 1, count( $filtered ), 'pending_can_filter_by_session', $failures, $passes );
remote_bridge_assert( $item_two->queue_id, $filtered[0]->queue_id, 'pending_session_filter_returns_matching_item', $failures, $passes );

remote_bridge_assert( 1, WP_Agent_Bridge::ack( 'message-relay', array( $item_one->queue_id ) ), 'ack_removes_matching_item', $failures, $passes );
remote_bridge_assert( 1, count( WP_Agent_Bridge::pending( 'message-relay' ) ), 'acked_item_is_no_longer_pending', $failures, $passes );
remote_bridge_assert( 0, WP_Agent_Bridge::ack( 'message-relay', array( 'missing-id' ) ), 'ack_ignores_missing_items', $failures, $passes );
remote_bridge_assert( 0, WP_Agent_Bridge::ack( 'wrong-client', array( $item_two->queue_id ) ), 'ack_is_scoped_by_client', $failures, $passes );
remote_bridge_assert( 1, count( WP_Agent_Bridge::pending( 'message-relay' ) ), 'wrong_client_ack_does_not_remove_item', $failures, $passes );

if ( ! empty( $failures ) ) {
	echo "\nFailures: " . implode( ', ', $failures ) . "\n";
	exit( 1 );
}

echo "\nAll {$passes} remote bridge assertions passed.\n";

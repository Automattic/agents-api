<?php
/**
 * Tests for WP_Agent_Message.
 *
 * @package AgentsAPI\Tests
 */

namespace AgentsAPI\Tests\Unit;

use AgentsAPI\AI\WP_Agent_Message;
use PHPUnit\Framework\TestCase;

/**
 * Covers pure message-envelope normalization and coalescing contracts.
 */
final class WPAgentMessageTest extends TestCase {

	public function test_coalesce_empty_input_round_trips(): void {
		$this->assertSame( array(), WP_Agent_Message::coalesce_consecutive_same_role( array() ) );
	}

	public function test_coalesce_preserves_alternating_roles(): void {
		$messages = array(
			WP_Agent_Message::text( 'user', 'hello' ),
			WP_Agent_Message::text( 'assistant', 'hi' ),
			WP_Agent_Message::text( 'user', 'how are you' ),
		);

		$result = WP_Agent_Message::coalesce_consecutive_same_role( $messages );

		$this->assertCount( 3, $result );
		$this->assertSame( 'user', $result[0]['role'] );
		$this->assertSame( 'assistant', $result[1]['role'] );
		$this->assertSame( 'user', $result[2]['role'] );
	}

	public function test_coalesce_merges_assistant_text_and_tool_call_into_multimodal_envelope(): void {
		$preamble  = WP_Agent_Message::text( 'assistant', 'Let me search the archive first.' );
		$tool_call = WP_Agent_Message::toolCall( 'Calling search', 'archive/search', array( 'q' => 'hello' ), 1 );

		$result = WP_Agent_Message::coalesce_consecutive_same_role(
			array(
				WP_Agent_Message::text( 'user', 'find that note' ),
				$preamble,
				$tool_call,
			)
		);

		$this->assertCount( 2, $result );
		$this->assertSame( 'user', $result[0]['role'] );
		$this->assertSame( 'assistant', $result[1]['role'] );
		$this->assertSame( WP_Agent_Message::TYPE_MULTIMODAL_PART, $result[1]['type'] );
		$this->assertSame( 'Let me search the archive first.', $result[1]['content'] );
		$this->assertSame(
			array( WP_Agent_Message::TYPE_TEXT, WP_Agent_Message::TYPE_TOOL_CALL ),
			array_column( $result[1]['payload']['parts'], 'type' )
		);
	}

	public function test_coalesce_merges_multiple_tool_calls_without_losing_parts(): void {
		$result = WP_Agent_Message::coalesce_consecutive_same_role(
			array(
				WP_Agent_Message::toolCall( 'a', 'tool/a', array(), 1 ),
				WP_Agent_Message::toolCall( 'b', 'tool/b', array(), 1 ),
				WP_Agent_Message::toolCall( 'c', 'tool/c', array(), 1 ),
			)
		);

		$this->assertCount( 1, $result );
		$this->assertCount( 3, $result[0]['payload']['parts'] );
		$this->assertSame(
			array( 'tool/a', 'tool/b', 'tool/c' ),
			array_map(
				static function ( array $part ): string {
					return $part['payload']['tool_name'];
				},
				$result[0]['payload']['parts']
			)
		);
	}

	public function test_coalesce_flattens_already_multimodal_envelopes(): void {
		$call_a = WP_Agent_Message::toolCall( 'a', 'tool/a', array(), 1 );
		$call_b = WP_Agent_Message::toolCall( 'b', 'tool/b', array(), 1 );
		$call_c = WP_Agent_Message::toolCall( 'c', 'tool/c', array(), 1 );

		$already_merged = WP_Agent_Message::coalesce_consecutive_same_role( array( $call_a, $call_b ) );
		$result         = WP_Agent_Message::coalesce_consecutive_same_role( array( $already_merged[0], $call_c ) );

		$this->assertCount( 1, $result );
		$this->assertCount( 3, $result[0]['payload']['parts'] );
		$this->assertNotContains( WP_Agent_Message::TYPE_MULTIMODAL_PART, array_column( $result[0]['payload']['parts'], 'type' ) );
	}

	public function test_coalesce_preserves_role_boundaries_between_tool_calls_and_results(): void {
		$result = WP_Agent_Message::coalesce_consecutive_same_role(
			array(
				WP_Agent_Message::toolCall( 'a', 'tool/a', array(), 1 ),
				WP_Agent_Message::toolResult( 'ok', 'tool/a', array( 'success' => true ) ),
				WP_Agent_Message::toolCall( 'b', 'tool/b', array(), 1 ),
			)
		);

		$this->assertCount( 3, $result );
		$this->assertSame( array( 'assistant', 'user', 'assistant' ), array_column( $result, 'role' ) );
	}

	public function test_coalesce_is_idempotent(): void {
		$once = WP_Agent_Message::coalesce_consecutive_same_role(
			array(
				WP_Agent_Message::text( 'assistant', 'Let me search the archive first.' ),
				WP_Agent_Message::toolCall( 'Calling search', 'archive/search', array( 'q' => 'hello' ), 1 ),
			)
		);

		$this->assertSame( $once, WP_Agent_Message::coalesce_consecutive_same_role( $once ) );
	}
}

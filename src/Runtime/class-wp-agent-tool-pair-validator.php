<?php
/**
 * Tool-call / tool-result pair validator.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects and removes orphan tool_call / tool_result messages in a transcript.
 *
 * Provider request shapes (Anthropic-style tool_use/tool_result blocks, OpenAI-style
 * tool_calls/tool messages) require every tool call to be paired with a result. A
 * transcript with an orphan tool_call or tool_result is a provider 400 waiting to
 * happen. This validator gives consumers a substrate-level helper to detect or
 * scrub such transcripts before dispatch.
 *
 * Pairing rule: a tool_call envelope at index `i` matches the next tool_result
 * envelope at index `j > i` with the same `payload.tool_name`. Matching is FIFO
 * per tool name — the oldest unmatched tool_call wins, mirroring how providers
 * resolve tool-use IDs positionally when multiple calls share a name.
 */
class WP_Agent_Tool_Pair_Validator {

	public const KIND_ORPHAN_TOOL_CALL   = 'orphan_tool_call';
	public const KIND_ORPHAN_TOOL_RESULT = 'orphan_tool_result';

	public const EVENT_VALIDATED = 'tool_pair_validated';
	public const EVENT_PRUNED    = 'tool_pair_pruned';

	/**
	 * Inspect a message list and return any orphan tool_call / tool_result envelopes.
	 *
	 * Returned reports are sorted by ascending message index. Each entry has the
	 * shape `{ index, kind, type, tool_name }`.
	 *
	 * @param array<int, array<string, mixed>> $messages Raw or normalized messages.
	 * @return array<int, array<string, mixed>> Orphan reports.
	 */
	public static function validate( array $messages ): array {
		$orphans = array();
		$pending = array();

		foreach ( array_values( $messages ) as $index => $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$envelope = WP_Agent_Message::normalize( $message );
			$type     = $envelope['type'];

			if ( WP_Agent_Message::TYPE_TOOL_CALL === $type ) {
				$pending[] = array(
					'index'     => $index,
					'tool_name' => self::tool_name( $envelope ),
				);
				continue;
			}

			if ( WP_Agent_Message::TYPE_TOOL_RESULT !== $type ) {
				continue;
			}

			$tool_name   = self::tool_name( $envelope );
			$matched_pos = self::match_pending( $pending, $tool_name );

			if ( null === $matched_pos ) {
				$orphans[] = array(
					'index'     => $index,
					'kind'      => self::KIND_ORPHAN_TOOL_RESULT,
					'type'      => WP_Agent_Message::TYPE_TOOL_RESULT,
					'tool_name' => $tool_name,
				);
				continue;
			}

			array_splice( $pending, $matched_pos, 1 );
		}

		foreach ( $pending as $pending_call ) {
			$orphans[] = array(
				'index'     => $pending_call['index'],
				'kind'      => self::KIND_ORPHAN_TOOL_CALL,
				'type'      => WP_Agent_Message::TYPE_TOOL_CALL,
				'tool_name' => $pending_call['tool_name'],
			);
		}

		usort(
			$orphans,
			static function ( array $a, array $b ): int {
				return $a['index'] <=> $b['index'];
			}
		);

		return $orphans;
	}

	/**
	 * Convenience predicate: does the transcript have zero orphans?
	 *
	 * @param array<int, array<string, mixed>> $messages Messages.
	 * @return bool
	 */
	public static function is_paired( array $messages ): bool {
		return array() === self::validate( $messages );
	}

	/**
	 * Drop orphan tool_call / tool_result envelopes from the message list.
	 *
	 * Non-tool messages and properly paired tool messages are preserved. The
	 * returned events array follows the same `{type, metadata}` shape used by
	 * the compaction lifecycle so consumers can forward both through a single
	 * event sink.
	 *
	 * @param array<int, array<string, mixed>> $messages Messages.
	 * @return array{messages: array<int, array<string, mixed>>, removed: array<int, array<string, mixed>>, events: array<int, array<string, mixed>>}
	 */
	public static function prune( array $messages ): array {
		$source  = array_values( $messages );
		$orphans = self::validate( $source );

		if ( array() === $orphans ) {
			return array(
				'messages' => $source,
				'removed'  => array(),
				'events'   => array(
					self::event(
						self::EVENT_VALIDATED,
						array(
							'total_messages' => count( $source ),
							'orphan_count'   => 0,
						)
					),
				),
			);
		}

		$drop_indices = array();
		foreach ( $orphans as $orphan ) {
			$drop_indices[ (int) $orphan['index'] ] = true;
		}

		$retained = array();
		foreach ( $source as $index => $message ) {
			if ( isset( $drop_indices[ $index ] ) ) {
				continue;
			}
			$retained[] = $message;
		}

		$event = self::event(
			self::EVENT_PRUNED,
			array(
				'total_messages'   => count( $source ),
				'orphan_count'     => count( $orphans ),
				'retained_count'   => count( $retained ),
				'removed_indices'  => array_map( 'intval', array_keys( $drop_indices ) ),
				'orphans'          => $orphans,
			)
		);

		return array(
			'messages' => $retained,
			'removed'  => $orphans,
			'events'   => array( $event ),
		);
	}

	/**
	 * Find the FIFO-oldest pending tool_call with the given name.
	 *
	 * @param array<int, array<string, mixed>> $pending   Pending list.
	 * @param string                           $tool_name Tool name to match.
	 * @return int|null Index in $pending or null when no match.
	 */
	private static function match_pending( array $pending, string $tool_name ): ?int {
		foreach ( $pending as $position => $candidate ) {
			if ( ( $candidate['tool_name'] ?? '' ) === $tool_name ) {
				return $position;
			}
		}

		return null;
	}

	/**
	 * Read a normalized envelope's payload tool name.
	 *
	 * @param array<string, mixed> $envelope Normalized envelope.
	 * @return string
	 */
	private static function tool_name( array $envelope ): string {
		$name = $envelope['payload']['tool_name'] ?? '';
		return is_string( $name ) ? $name : '';
	}

	/**
	 * Build a lifecycle event payload.
	 *
	 * @param string               $type Event type.
	 * @param array<string, mixed> $data Event data.
	 * @return array<string, mixed>
	 */
	private static function event( string $type, array $data ): array {
		return array(
			'type'     => $type,
			'metadata' => $data,
		);
	}
}

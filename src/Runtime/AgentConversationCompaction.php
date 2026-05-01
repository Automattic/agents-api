<?php
/**
 * Agent conversation compaction contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes compaction policy and applies transcript compaction safely.
 *
 * This class defines the runtime contract only. Callers provide the summarizer
 * callable, so provider/model execution stays outside Agents API.
 */
class AgentConversationCompaction {

	public const EVENT_STARTED   = 'compaction_started';
	public const EVENT_COMPLETED = 'compaction_completed';
	public const EVENT_FAILED    = 'compaction_failed';

	public const STATUS_SKIPPED   = 'skipped';
	public const STATUS_COMPACTED = 'compacted';
	public const STATUS_FAILED    = 'failed';

	/**
	 * Default declarative compaction policy.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_policy(): array {
		return array(
			'enabled'                       => false,
			'max_messages'                  => 40,
			'recent_messages'               => 12,
			'summary_role'                  => 'system',
			'summary_prefix'                => 'Earlier conversation summary:',
			'summary_model'                 => '',
			'summary_provider'              => '',
			'preserve_tool_boundaries'      => true,
			'conservation_enabled'          => false,
			'minimum_conserved_byte_ratio'  => 1.0,
			'fail_on_conservation_failure' => true,
		);
	}

	/**
	 * Normalize caller-provided compaction policy.
	 *
	 * @param array<string, mixed> $policy Raw policy.
	 * @return array<string, mixed>
	 */
	public static function normalize_policy( array $policy ): array {
		$normalized = array_merge( self::default_policy(), $policy );

		$normalized['enabled']                       = (bool) $normalized['enabled'];
		$normalized['max_messages']                  = max( 1, (int) $normalized['max_messages'] );
		$normalized['recent_messages']               = max( 1, (int) $normalized['recent_messages'] );
		$normalized['summary_role']                  = self::normalize_string( $normalized['summary_role'], 'system' );
		$normalized['summary_prefix']                = self::normalize_string( $normalized['summary_prefix'], 'Earlier conversation summary:' );
		$normalized['summary_model']                 = self::normalize_string( $normalized['summary_model'], '' );
		$normalized['summary_provider']              = self::normalize_string( $normalized['summary_provider'], '' );
		$normalized['preserve_tool_boundaries']      = (bool) $normalized['preserve_tool_boundaries'];
		$normalized['conservation_enabled']          = (bool) $normalized['conservation_enabled'];
		$normalized['minimum_conserved_byte_ratio']  = max( 0.0, (float) $normalized['minimum_conserved_byte_ratio'] );
		$normalized['fail_on_conservation_failure'] = (bool) $normalized['fail_on_conservation_failure'];

		if ( $normalized['recent_messages'] >= $normalized['max_messages'] ) {
			$normalized['recent_messages'] = max( 1, $normalized['max_messages'] - 1 );
		}

		return $normalized;
	}

	/**
	 * Compact a transcript before model dispatch.
	 *
	 * The summarizer receives `(array $messages_to_summarize, array $context)` and
	 * must return a summary string or an array with `summary` and optional
	 * `archived_items`. On failure the original transcript is returned unchanged
	 * with a `compaction_failed` lifecycle event.
	 *
	 * @param array    $messages   Complete transcript messages.
	 * @param array    $policy     Compaction policy.
	 * @param callable $summarizer Summary producer supplied by the runtime.
	 * @return array{messages: array<int, array<string, mixed>>, metadata: array<string, mixed>, events: array<int, array<string, mixed>>}
	 */
	public static function compact( array $messages, array $policy, callable $summarizer ): array {
		$policy              = self::normalize_policy( $policy );
		$normalized_messages = AgentMessageEnvelope::normalize_many( $messages );
		$total_messages      = count( $normalized_messages );

		$original_stats = self::item_stats( $normalized_messages );

		if ( ! $policy['enabled'] || $total_messages <= $policy['max_messages'] ) {
			return self::result( $normalized_messages, self::STATUS_SKIPPED, self::metadata( $policy, $original_stats, array(), $original_stats ), array() );
		}

		$cutoff = self::select_boundary( $normalized_messages, $policy );
		if ( $cutoff <= 0 ) {
			return self::result( $normalized_messages, self::STATUS_SKIPPED, self::metadata( $policy, $original_stats, array(), $original_stats ), array() );
		}

		$messages_to_summarize = array_slice( $normalized_messages, 0, $cutoff );
		$retained_messages     = array_slice( $normalized_messages, $cutoff );
		$retained_stats        = self::item_stats( $retained_messages );

		$summary_context = array(
			'policy'          => $policy,
			'total_messages'  => $total_messages,
			'compact_count'   => $cutoff,
			'retained_count'  => count( $retained_messages ),
			'boundary'        => array(
				'compact_until' => $cutoff - 1,
				'retain_from'   => $cutoff,
			),
		);

		$started_event = self::event( self::EVENT_STARTED, $summary_context );

		try {
			$summary_result = call_user_func( $summarizer, $messages_to_summarize, $summary_context );
			$summary        = self::summary_text( $summary_result );
			$archived_items = self::archived_items( $summary_result );

			if ( ! is_string( $summary ) || '' === trim( $summary ) ) {
				throw new \RuntimeException( 'Summary must be a non-empty string.' );
			}
		} catch ( \Throwable $error ) {
			$failure_context          = self::metadata( $policy, $original_stats, array(), $retained_stats, array(), $summary_context );
			$failure_context['error'] = $error->getMessage();

			return self::result(
				$normalized_messages,
				self::STATUS_FAILED,
				$failure_context,
				array( $started_event, self::event( self::EVENT_FAILED, $failure_context ) )
			);
		}

		$summary_message = AgentMessageEnvelope::text(
			$policy['summary_role'],
			$policy['summary_prefix'] . "\n\n" . trim( $summary ),
			array(
				'agents_api_compaction' => array(
					'compacted_message_count' => $cutoff,
					'retained_message_count'  => $total_messages - $cutoff,
					'summary_provider'        => $policy['summary_provider'],
					'summary_model'           => $policy['summary_model'],
				),
			)
		);

		$compacted_messages = array_merge( array( $summary_message ), $retained_messages );
		$compacted_stats    = self::item_stats( array( $summary_message ) );
		$archived_stats     = self::item_stats( $archived_items );
		$complete_context   = self::metadata( $policy, $original_stats, $compacted_stats, $retained_stats, $archived_stats, $summary_context );
		$complete_context['summary_message'] = $summary_message;

		if ( self::conservation_failed( $complete_context ) ) {
			$complete_context['error'] = 'Compaction conservation check failed.';

			return self::result(
				$normalized_messages,
				self::STATUS_FAILED,
				$complete_context,
				array( $started_event, self::event( self::EVENT_FAILED, $complete_context ) )
			);
		}

		return self::result(
			$compacted_messages,
			self::STATUS_COMPACTED,
			$complete_context,
			array( $started_event, self::event( self::EVENT_COMPLETED, $complete_context ) )
		);
	}

	/**
	 * Select the first retained message index without cutting tool boundaries.
	 *
	 * @param array<int, array<string, mixed>> $messages Normalized messages.
	 * @param array<string, mixed>             $policy   Normalized policy.
	 * @return int Boundary index.
	 */
	public static function select_boundary( array $messages, array $policy ): int {
		$policy = self::normalize_policy( $policy );
		$cutoff = max( 0, count( $messages ) - $policy['recent_messages'] );

		if ( ! $policy['preserve_tool_boundaries'] ) {
			return $cutoff;
		}

		while ( $cutoff > 0 && isset( $messages[ $cutoff ] ) && AgentMessageEnvelope::TYPE_TOOL_RESULT === AgentMessageEnvelope::type( $messages[ $cutoff ] ) ) {
			--$cutoff;
		}

		while ( $cutoff > 0 && isset( $messages[ $cutoff - 1 ] ) && AgentMessageEnvelope::TYPE_TOOL_CALL === AgentMessageEnvelope::type( $messages[ $cutoff - 1 ] ) ) {
			--$cutoff;
		}

		return $cutoff;
	}

	/**
	 * Build a normalized result array.
	 *
	 * @param array<int, array<string, mixed>> $messages Messages.
	 * @param string                           $status   Compaction status.
	 * @param array<string, mixed>             $metadata Compaction metadata.
	 * @param array<int, array<string, mixed>> $events   Lifecycle events.
	 * @return array<string, mixed>
	 */
	private static function result( array $messages, string $status, array $metadata, array $events ): array {
		$metadata['status'] = $status;

		return array(
			'messages' => $messages,
			'metadata' => array( 'compaction' => $metadata ),
			'events'   => $events,
		);
	}

	/**
	 * Build generic provenance and conservation metadata.
	 *
	 * @param array<string, mixed> $policy          Normalized policy.
	 * @param array<string, int>   $original_stats  Original item stats.
	 * @param array<string, int>   $compacted_stats Compacted item stats.
	 * @param array<string, int>   $retained_stats  Retained item stats.
	 * @param array<string, int>   $archived_stats  Archived item stats.
	 * @param array<string, mixed> $extra           Extra metadata.
	 * @return array<string, mixed>
	 */
	private static function metadata( array $policy, array $original_stats, array $compacted_stats = array(), array $retained_stats = array(), array $archived_stats = array(), array $extra = array() ): array {
		$compacted_stats = array_merge( array( 'item_count' => 0, 'byte_count' => 0 ), $compacted_stats );
		$retained_stats  = array_merge( array( 'item_count' => 0, 'byte_count' => 0 ), $retained_stats );
		$archived_stats  = array_merge( array( 'item_count' => 0, 'byte_count' => 0 ), $archived_stats );

		$conserved_bytes = $compacted_stats['byte_count'] + $retained_stats['byte_count'] + $archived_stats['byte_count'];
		$required_bytes  = (int) ceil( $original_stats['byte_count'] * $policy['minimum_conserved_byte_ratio'] );
		$passed          = ! $policy['conservation_enabled'] || $conserved_bytes >= $required_bytes;

		$metadata = array_merge(
			$extra,
			array(
				'policy'       => $policy,
				'provenance'   => array(
					'original'  => $original_stats,
					'compacted' => $compacted_stats,
					'retained'  => $retained_stats,
					'archived'  => $archived_stats,
				),
				'summarizer'   => array(
					'provider' => $policy['summary_provider'],
					'model'    => $policy['summary_model'],
				),
				'conservation' => array(
					'enabled'                      => $policy['conservation_enabled'],
					'minimum_conserved_byte_ratio' => $policy['minimum_conserved_byte_ratio'],
					'required_byte_count'          => $required_bytes,
					'conserved_byte_count'         => $conserved_bytes,
					'conserved_byte_ratio'         => 0 === $original_stats['byte_count'] ? 1.0 : $conserved_bytes / $original_stats['byte_count'],
					'passed'                       => $passed,
					'failed_closed'                => $policy['conservation_enabled'] && $policy['fail_on_conservation_failure'] && ! $passed,
				),
			)
		);

		return $metadata;
	}

	/**
	 * Determine whether compaction should fail because conservation did not pass.
	 *
	 * @param array<string, mixed> $metadata Compaction metadata.
	 * @return bool
	 */
	private static function conservation_failed( array $metadata ): bool {
		$conservation = $metadata['conservation'] ?? array();
		return true === ( $conservation['failed_closed'] ?? false );
	}

	/**
	 * Extract summary text from the summarizer result.
	 *
	 * @param mixed $summary_result Summarizer result.
	 * @return mixed
	 */
	private static function summary_text( $summary_result ) {
		if ( is_array( $summary_result ) && array_key_exists( 'summary', $summary_result ) ) {
			return $summary_result['summary'];
		}

		return $summary_result;
	}

	/**
	 * Extract optional archived items from the summarizer result.
	 *
	 * @param mixed $summary_result Summarizer result.
	 * @return array<int, mixed>
	 */
	private static function archived_items( $summary_result ): array {
		if ( ! is_array( $summary_result ) || ! is_array( $summary_result['archived_items'] ?? null ) ) {
			return array();
		}

		return array_values( $summary_result['archived_items'] );
	}

	/**
	 * Count items and content bytes for generic provenance metadata.
	 *
	 * @param array<int, mixed> $items Items.
	 * @return array{item_count: int, byte_count: int}
	 */
	private static function item_stats( array $items ): array {
		$bytes = 0;

		foreach ( $items as $item ) {
			$bytes += self::item_bytes( $item );
		}

		return array(
			'item_count' => count( $items ),
			'byte_count' => $bytes,
		);
	}

	/**
	 * Count bytes for an item's durable content.
	 *
	 * @param mixed $item Item.
	 * @return int
	 */
	private static function item_bytes( $item ): int {
		$content = is_array( $item ) && array_key_exists( 'content', $item ) ? $item['content'] : $item;

		if ( is_string( $content ) ) {
			return strlen( $content );
		}

		$encoded = self::json_encode( $content );
		return false === $encoded ? 0 : strlen( $encoded );
	}

	/**
	 * Encode data with a pure-PHP fallback for smoke tests.
	 *
	 * @param mixed $data Data.
	 * @return string|false
	 */
	private static function json_encode( $data ) {
		if ( function_exists( 'wp_json_encode' ) ) {
			return wp_json_encode( $data );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure-PHP smoke tests run without WordPress loaded.
		return json_encode( $data );
	}

	/**
	 * Build a lifecycle event payload for streaming clients.
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

	/**
	 * Normalize string policy fields.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Fallback value.
	 * @return string
	 */
	private static function normalize_string( $value, string $fallback ): string {
		if ( ! is_string( $value ) ) {
			return $fallback;
		}

		$value = trim( $value );
		return '' === $value ? $fallback : $value;
	}
}

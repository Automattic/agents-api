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
			'enabled'                  => false,
			'max_messages'             => 40,
			'recent_messages'          => 12,
			'summary_role'             => 'system',
			'summary_prefix'           => 'Earlier conversation summary:',
			'summary_model'            => '',
			'summary_provider'         => '',
			'preserve_tool_boundaries' => true,
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

		$normalized['enabled']                  = (bool) $normalized['enabled'];
		$normalized['max_messages']             = max( 1, (int) $normalized['max_messages'] );
		$normalized['recent_messages']          = max( 1, (int) $normalized['recent_messages'] );
		$normalized['summary_role']             = self::normalize_string( $normalized['summary_role'], 'system' );
		$normalized['summary_prefix']           = self::normalize_string( $normalized['summary_prefix'], 'Earlier conversation summary:' );
		$normalized['summary_model']            = self::normalize_string( $normalized['summary_model'], '' );
		$normalized['summary_provider']         = self::normalize_string( $normalized['summary_provider'], '' );
		$normalized['preserve_tool_boundaries'] = (bool) $normalized['preserve_tool_boundaries'];

		if ( $normalized['recent_messages'] >= $normalized['max_messages'] ) {
			$normalized['recent_messages'] = max( 1, $normalized['max_messages'] - 1 );
		}

		return $normalized;
	}

	/**
	 * Compact a transcript before model dispatch.
	 *
	 * The summarizer receives `(array $messages_to_summarize, array $context)` and
	 * must return a summary string. On failure the original transcript is returned
	 * unchanged with a `compaction_failed` lifecycle event.
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

		if ( ! $policy['enabled'] || $total_messages <= $policy['max_messages'] ) {
			return self::result( $normalized_messages, self::STATUS_SKIPPED, array(), array() );
		}

		$cutoff = self::select_boundary( $normalized_messages, $policy );
		if ( $cutoff <= 0 ) {
			return self::result( $normalized_messages, self::STATUS_SKIPPED, array(), array() );
		}

		$summary_context = array(
			'policy'         => $policy,
			'total_messages' => $total_messages,
			'compact_count'  => $cutoff,
			'retained_count' => $total_messages - $cutoff,
			'boundary'       => array(
				'compact_until' => $cutoff - 1,
				'retain_from'   => $cutoff,
			),
		);

		$started_event = self::event( self::EVENT_STARTED, $summary_context );

		try {
			$summary = call_user_func( $summarizer, array_slice( $normalized_messages, 0, $cutoff ), $summary_context );
			if ( ! is_string( $summary ) || '' === trim( $summary ) ) {
				throw new \RuntimeException( 'Summary must be a non-empty string.' );
			}
		} catch ( \Throwable $error ) {
			$failure_context          = $summary_context;
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

		$compacted_messages                  = array_merge( array( $summary_message ), array_slice( $normalized_messages, $cutoff ) );
		$complete_context                    = $summary_context;
		$complete_context['summary_message'] = $summary_message;

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
		$policy          = self::normalize_policy( $policy );
		$recent_messages = (int) $policy['recent_messages'];
		$cutoff          = max( 0, count( $messages ) - $recent_messages );

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
	 * @return array{messages: array<int, array<string, mixed>>, metadata: array<string, mixed>, events: array<int, array<string, mixed>>}
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

<?php
/**
 * Generic chat run-control primitives.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers for canonical chat run-control contracts.
 */
class WP_Agent_Chat_Run_Control {

	public const STATUS_QUEUED     = 'queued';
	public const STATUS_RUNNING    = 'running';
	public const STATUS_CANCELLING = 'cancelling';
	public const STATUS_CANCELLED  = 'cancelled';
	public const STATUS_COMPLETED  = 'completed';
	public const STATUS_FAILED     = 'failed';

	/** @return string[] */
	public static function statuses(): array {
		return array(
			self::STATUS_QUEUED,
			self::STATUS_RUNNING,
			self::STATUS_CANCELLING,
			self::STATUS_CANCELLED,
			self::STATUS_COMPLETED,
			self::STATUS_FAILED,
		);
	}

	/**
	 * Generate an opaque client-addressable run ID.
	 */
	public static function generate_run_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return 'run_' . str_replace( '-', '', wp_generate_uuid4() );
		}

		try {
			return 'run_' . bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			unset( $error );
			return 'run_' . str_replace( '.', '', uniqid( '', true ) );
		}
	}

	/**
	 * Normalize a run status payload returned by a runtime.
	 *
	 * @param array<string,mixed> $run Raw run status.
	 * @return array<string,mixed>
	 */
	public static function normalize_run( array $run ): array {
		$run_id     = trim( (string) ( $run['run_id'] ?? '' ) );
		$session_id = trim( (string) ( $run['session_id'] ?? '' ) );
		$status     = self::normalize_status( $run['status'] ?? self::STATUS_RUNNING );

		if ( '' === $run_id ) {
			throw new \InvalidArgumentException( 'run_id must be a non-empty string' );
		}

		if ( '' === $session_id ) {
			throw new \InvalidArgumentException( 'session_id must be a non-empty string' );
		}

		$normalized = array(
			'run_id'     => $run_id,
			'session_id' => $session_id,
			'status'     => $status,
			'started_at' => isset( $run['started_at'] ) ? (string) $run['started_at'] : '',
			'updated_at' => isset( $run['updated_at'] ) ? (string) $run['updated_at'] : '',
			'metadata'   => isset( $run['metadata'] ) && is_array( $run['metadata'] ) ? $run['metadata'] : array(),
		);

		if ( isset( $run['queued_message_id'] ) ) {
			$normalized['queued_message_id'] = (string) $run['queued_message_id'];
		}

		if ( isset( $run['position'] ) ) {
			$normalized['position'] = max( 0, (int) $run['position'] );
		}

		if ( isset( $run['cancelled'] ) ) {
			$normalized['cancelled'] = (bool) $run['cancelled'];
		}

		return $normalized;
	}

	/**
	 * Normalize status values while keeping the public vocabulary bounded.
	 */
	public static function normalize_status( $status ): string {
		$status = is_string( $status ) ? strtolower( trim( $status ) ) : '';
		return in_array( $status, self::statuses(), true ) ? $status : self::STATUS_RUNNING;
	}

	/**
	 * Build the interrupt message shape consumed by WP_Agent_Conversation_Loop.
	 *
	 * Runtimes that cannot abort an in-flight provider request immediately can
	 * persist this message for their loop-level `interrupt_source` to return.
	 *
	 * @param string              $run_id     Run to cancel.
	 * @param string              $session_id Session containing the run.
	 * @param array<string,mixed> $metadata   Additional runtime metadata.
	 * @return array<string,mixed>
	 */
	public static function cancellation_interrupt_message(
		string $run_id,
		string $session_id = '',
		array $metadata = array()
	): array {
		return WP_Agent_Message::text(
			'user',
			'Cancel this run.',
			array_merge(
				$metadata,
				array(
					'type'             => 'chat_run_interrupt',
					'interrupt_action' => 'cancel',
					'run_id'           => $run_id,
					'session_id'       => $session_id,
				)
			)
		);
	}
}

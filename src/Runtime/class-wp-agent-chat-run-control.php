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
	private const OPTION_KEY       = 'agents_api_chat_run_control';

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
	 * Start or update an addressable chat run in the default store.
	 *
	 * @param string              $run_id     Run ID.
	 * @param string              $session_id Session ID.
	 * @param array<string,mixed> $metadata   Run metadata.
	 * @return array<string,mixed> Normalized run.
	 */
	public static function start_run( string $run_id, string $session_id, array $metadata = array() ): array {
		$now = self::now();
		$run = array(
			'run_id'     => $run_id,
			'session_id' => $session_id,
			'status'     => self::STATUS_RUNNING,
			'started_at' => $metadata['started_at'] ?? $now,
			'updated_at' => $now,
			'metadata'   => $metadata,
		);

		$state                    = self::state();
		$state['runs'][ $run_id ] = $run;
		self::save_state( $state );

		return self::normalize_run( $run );
	}

	/**
	 * Complete a stored chat run.
	 *
	 * @param string $run_id Run ID.
	 * @param string $status Terminal status.
	 * @return array<string,mixed>|null Normalized run, or null when absent.
	 */
	public static function finish_run( string $run_id, string $status = self::STATUS_COMPLETED ): ?array {
		$state = self::state();
		if ( ! isset( $state['runs'][ $run_id ] ) ) {
			return null;
		}

		$run               = $state['runs'][ $run_id ];
		$run['status']     = self::normalize_status( $status );
		$run['updated_at'] = self::now();
		if ( self::STATUS_CANCELLED === $run['status'] ) {
			$run['cancelled'] = true;
		}

		$state['runs'][ $run_id ] = $run;
		self::save_state( $state );

		return self::normalize_run( $run );
	}

	/**
	 * Read a stored chat run.
	 *
	 * @param string $run_id Run ID.
	 * @return array<string,mixed>|null Normalized run, or null when absent.
	 */
	public static function get_run( string $run_id ): ?array {
		$state = self::state();
		$run   = $state['runs'][ $run_id ] ?? null;
		return is_array( $run ) ? self::normalize_run( $run ) : null;
	}

	/**
	 * Request cancellation of a stored chat run.
	 *
	 * @param string $run_id Run ID.
	 * @return array<string,mixed>|null Normalized run, or null when absent.
	 */
	public static function request_cancel( string $run_id ): ?array {
		$state = self::state();
		if ( ! isset( $state['runs'][ $run_id ] ) ) {
			return null;
		}

		$run               = $state['runs'][ $run_id ];
		$terminal          = in_array( self::normalize_status( $run['status'] ?? '' ), array( self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED ), true );
		$run['status']     = $terminal ? self::normalize_status( $run['status'] ?? '' ) : self::STATUS_CANCELLING;
		$run['cancelled']  = ! $terminal;
		$run['updated_at'] = self::now();

		$state['runs'][ $run_id ] = $run;
		self::save_state( $state );

		return self::normalize_run( $run );
	}

	/**
	 * Return a cancellation interrupt when one was requested for the run.
	 *
	 * @param string $run_id     Run ID.
	 * @param string $session_id Session ID.
	 * @return array<string,mixed>|null Interrupt message or null.
	 */
	public static function cancellation_interrupt_for_run( string $run_id, string $session_id = '' ): ?array {
		$run = self::get_run( $run_id );
		if ( null === $run || self::STATUS_CANCELLING !== $run['status'] ) {
			return null;
		}

		$resolved_session_id = '' !== $session_id ? $session_id : (string) $run['session_id'];

		return self::cancellation_interrupt_message( $run_id, $resolved_session_id );
	}

	/**
	 * Queue a follow-up message for a chat session.
	 *
	 * @param array<string,mixed> $input Canonical queue input.
	 * @return array<string,mixed> Queue result.
	 */
	public static function queue_message( array $input ): array {
		$session_id = trim( (string) ( $input['session_id'] ?? '' ) );
		$run_id     = trim( (string) ( $input['run_id'] ?? '' ) );
		if ( '' === $session_id ) {
			throw new \InvalidArgumentException( 'session_id must be a non-empty string' );
		}

		$queued_id = 'queued_' . str_replace( 'run_', '', self::generate_run_id() );
		$item      = array(
			'queued_message_id' => $queued_id,
			'session_id'        => $session_id,
			'run_id'            => $run_id,
			'agent'             => sanitize_title( (string) ( $input['agent'] ?? '' ) ),
			'message'           => (string) ( $input['message'] ?? '' ),
			'attachments'       => is_array( $input['attachments'] ?? null ) ? $input['attachments'] : array(),
			'client_context'    => is_array( $input['client_context'] ?? null ) ? $input['client_context'] : array(),
			'created_at'        => self::now(),
		);

		$state                            = self::state();
		$state['queues'][ $session_id ]   = array_values( $state['queues'][ $session_id ] ?? array() );
		$state['queues'][ $session_id ][] = $item;
		$position                         = count( $state['queues'][ $session_id ] );
		self::save_state( $state );

		return self::normalize_run( array(
			'run_id'            => '' !== $run_id ? $run_id : self::generate_run_id(),
			'session_id'        => $session_id,
			'status'            => self::STATUS_QUEUED,
			'updated_at'        => self::now(),
			'queued_message_id' => $queued_id,
			'position'          => $position,
		) );
	}

	/**
	 * Claim queued messages for a session.
	 *
	 * @param string $session_id Session ID.
	 * @return array<int,array<string,mixed>> Queued items.
	 */
	public static function claim_queued_messages( string $session_id ): array {
		$state = self::state();
		$items = array_values( $state['queues'][ $session_id ] ?? array() );
		unset( $state['queues'][ $session_id ] );
		self::save_state( $state );
		return array_filter( $items, 'is_array' );
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

	/** @return array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>} */
	private static function state(): array {
		$state = function_exists( 'get_option' ) ? get_option( self::OPTION_KEY, array() ) : array();
		return array(
			'runs'   => is_array( $state['runs'] ?? null ) ? $state['runs'] : array(),
			'queues' => is_array( $state['queues'] ?? null ) ? $state['queues'] : array(),
		);
	}

	/** @param array<string,mixed> $state State to persist. */
	private static function save_state( array $state ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_KEY, $state, false );
		}
	}

	private static function now(): string {
		return gmdate( 'c' );
	}
}

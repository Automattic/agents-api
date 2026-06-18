<?php
/**
 * Generic addressable run-control primitive.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Storage-neutral helpers for run status and cancellation state.
 */
class WP_Agent_Run_Control {

	public const STATUS_QUEUED               = 'queued';
	public const STATUS_RUNNING              = 'running';
	public const STATUS_CANCELLING           = 'cancelling';
	public const STATUS_CANCELLED            = 'cancelled';
	public const STATUS_COMPLETED            = 'completed';
	public const STATUS_SUCCEEDED            = 'succeeded';
	public const STATUS_FAILED               = 'failed';
	public const STATUS_RUNTIME_TOOL_PENDING = 'runtime_tool_pending';
	public const STATUS_APPROVAL_REQUIRED    = 'approval_required';
	public const STATUS_BUDGET_EXCEEDED      = 'budget_exceeded';
	public const STATUS_STALLED              = 'stalled';
	public const STATUS_INTERRUPTED          = 'interrupted';

	/** @return string[] */
	public static function statuses(): array {
		return array(
			self::STATUS_QUEUED,
			self::STATUS_RUNNING,
			self::STATUS_CANCELLING,
			self::STATUS_CANCELLED,
			self::STATUS_COMPLETED,
			self::STATUS_SUCCEEDED,
			self::STATUS_FAILED,
			self::STATUS_RUNTIME_TOOL_PENDING,
			self::STATUS_APPROVAL_REQUIRED,
			self::STATUS_BUDGET_EXCEEDED,
			self::STATUS_STALLED,
			self::STATUS_INTERRUPTED,
		);
	}

	/**
	 * Generate an opaque client-addressable run ID.
	 */
	public static function generate_run_id( string $prefix = 'run_' ): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return $prefix . str_replace( '-', '', wp_generate_uuid4() );
		}

		try {
			return $prefix . bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			unset( $error );
			return $prefix . str_replace( '.', '', uniqid( '', true ) );
		}
	}

	/**
	 * Normalize status values while keeping the public vocabulary bounded.
	 */
	public static function normalize_status( mixed $status ): string {
		$status = is_string( $status ) ? strtolower( trim( $status ) ) : '';
		return in_array( $status, self::statuses(), true ) ? $status : self::STATUS_RUNNING;
	}

	/**
	 * Normalize a generic run payload.
	 *
	 * @param array<string,mixed> $run Raw run status.
	 * @return array<string,mixed>
	 */
	public static function normalize_run( array $run ): array {
		$run_id = trim( self::string_value( $run['run_id'] ?? null ) );
		$status = self::normalize_status( $run['status'] ?? self::STATUS_RUNNING );

		if ( '' === $run_id ) {
			throw new \InvalidArgumentException( 'run_id must be a non-empty string' );
		}

		$normalized = array(
			'run_id'     => $run_id,
			'status'     => $status,
			'started_at' => self::string_value( $run['started_at'] ?? null ),
			'updated_at' => self::string_value( $run['updated_at'] ?? null ),
			'metadata'   => isset( $run['metadata'] ) && is_array( $run['metadata'] ) ? $run['metadata'] : array(),
		);

		foreach ( array( 'session_id', 'workflow_id', 'executor_id', 'queued_message_id' ) as $field ) {
			if ( isset( $run[ $field ] ) ) {
				$normalized[ $field ] = self::string_value( $run[ $field ] );
			}
		}

		if ( isset( $run['position'] ) ) {
			$normalized['position'] = max( 0, self::int_value( $run['position'] ) );
		}

		if ( isset( $run['cancelled'] ) ) {
			$normalized['cancelled'] = (bool) $run['cancelled'];
		}

		return $normalized;
	}

	/**
	 * Start or update an addressable run in the selected store.
	 *
	 * @param string              $store_key Option key used by the backing store.
	 * @param string              $run_id    Run ID.
	 * @param array<string,mixed> $run       Run fields.
	 * @return array<string,mixed>
	 */
	public static function start_run( string $store_key, string $run_id, array $run = array() ): array {
		$now = self::now();
		$run = array_merge(
			$run,
			array(
				'run_id'     => $run_id,
				'status'     => self::STATUS_RUNNING,
				'started_at' => $run['started_at'] ?? $now,
				'updated_at' => $now,
				'metadata'   => isset( $run['metadata'] ) && is_array( $run['metadata'] ) ? $run['metadata'] : array(),
			)
		);

		$state                    = self::state( $store_key );
		$state['runs'][ $run_id ] = $run;
		self::save_state( $store_key, $state );

		return self::normalize_run( $run );
	}

	/**
	 * Store a normalized run result.
	 *
	 * @param string              $store_key Option key used by the backing store.
	 * @param array<string,mixed> $run       Run payload.
	 * @return array<string,mixed>
	 */
	public static function save_run( string $store_key, array $run ): array {
		$normalized               = self::normalize_run( $run );
		$normalized['updated_at'] = '' !== $normalized['updated_at'] ? $normalized['updated_at'] : self::now();
		$run_id                   = self::string_value( $normalized['run_id'] );

		$state                    = self::state( $store_key );
		$state['runs'][ $run_id ] = $normalized;
		self::save_state( $store_key, $state );

		return $normalized;
	}

	/**
	 * Finish a stored run.
	 *
	 * @param string $store_key Option key used by the backing store.
	 * @param string $run_id    Run ID.
	 * @param string $status    Terminal status.
	 * @return array<string,mixed>|null
	 */
	public static function finish_run( string $store_key, string $run_id, string $status = self::STATUS_COMPLETED ): ?array {
		$state = self::state( $store_key );
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
		self::save_state( $store_key, $state );

		return self::normalize_run( $run );
	}

	/**
	 * Read a stored run.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_run( string $store_key, string $run_id ): ?array {
		$state = self::state( $store_key );
		$run   = $state['runs'][ $run_id ] ?? null;
		return is_array( $run ) ? self::normalize_run( $run ) : null;
	}

	/**
	 * Request cancellation of a stored run.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function request_cancel( string $store_key, string $run_id ): ?array {
		$state = self::state( $store_key );
		if ( ! isset( $state['runs'][ $run_id ] ) ) {
			return null;
		}

		$run               = $state['runs'][ $run_id ];
		$terminal          = in_array( self::normalize_status( $run['status'] ?? '' ), array( self::STATUS_COMPLETED, self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_BUDGET_EXCEEDED, self::STATUS_STALLED, self::STATUS_INTERRUPTED ), true );
		$run['status']     = $terminal ? self::normalize_status( $run['status'] ?? '' ) : self::STATUS_CANCELLING;
		$run['cancelled']  = ! $terminal;
		$run['updated_at'] = self::now();

		$state['runs'][ $run_id ] = $run;
		self::save_state( $store_key, $state );

		return self::normalize_run( $run );
	}

	public static function cancel_requested( string $store_key, string $run_id ): bool {
		$run = self::get_run( $store_key, $run_id );
		return null !== $run && self::STATUS_CANCELLING === ( $run['status'] ?? '' );
	}

	/** @return array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>} */
	public static function state( string $store_key ): array {
		$state = function_exists( 'get_option' ) ? get_option( $store_key, array() ) : array();
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return array(
			'runs'   => self::stored_runs( $state['runs'] ?? array() ),
			'queues' => self::stored_queues( $state['queues'] ?? array() ),
		);
	}

	/**
	 * @param array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>} $state
	 */
	public static function save_state( string $store_key, array $state ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( $store_key, $state, false );
		}
	}

	public static function now(): string {
		return gmdate( 'c' );
	}

	private static function string_value( mixed $value ): string {
		return is_int( $value ) || is_float( $value ) || is_string( $value ) || is_bool( $value ) ? (string) $value : '';
	}

	private static function int_value( mixed $value ): int {
		return is_int( $value ) || is_float( $value ) || is_string( $value ) || is_bool( $value ) ? (int) $value : 0;
	}

	/**
	 * @param mixed $runs Raw stored runs.
	 * @return array<string,array<string,mixed>>
	 */
	private static function stored_runs( mixed $runs ): array {
		if ( ! is_array( $runs ) ) {
			return array();
		}

		$stored = array();
		foreach ( $runs as $run_id => $run ) {
			if ( is_string( $run_id ) && is_array( $run ) ) {
				$stored[ $run_id ] = self::assoc_array( $run );
			}
		}

		return $stored;
	}

	/**
	 * @param mixed $queues Raw stored queues.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private static function stored_queues( mixed $queues ): array {
		if ( ! is_array( $queues ) ) {
			return array();
		}

		$stored = array();
		foreach ( $queues as $scope => $items ) {
			if ( ! is_string( $scope ) || ! is_array( $items ) ) {
				continue;
			}

			$stored[ $scope ] = array();
			foreach ( $items as $item ) {
				if ( is_array( $item ) ) {
					$stored[ $scope ][] = self::assoc_array( $item );
				}
			}
		}

		return $stored;
	}

	/**
	 * @param array<mixed> $value
	 * @return array<string,mixed>
	 */
	private static function assoc_array( array $value ): array {
		$result = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $item;
			}
		}
		return $result;
	}
}

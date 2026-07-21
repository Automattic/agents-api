<?php
/**
 * Generic chat run-control primitives.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers for canonical chat run-control contracts.
 */
class WP_Agent_Chat_Run_Control {

	public const STATUS_QUEUED               = 'queued';
	public const STATUS_RUNNING              = 'running';
	public const STATUS_CANCELLING           = 'cancelling';
	public const STATUS_CANCELLED            = 'cancelled';
	public const STATUS_COMPLETED            = 'completed';
	public const STATUS_FAILED               = 'failed';
	public const STATUS_RUNTIME_TOOL_PENDING = 'runtime_tool_pending';
	public const STATUS_APPROVAL_REQUIRED    = 'approval_required';
	public const STATUS_BUDGET_EXCEEDED      = 'budget_exceeded';
	public const STATUS_STALLED              = 'stalled';
	public const STATUS_INTERRUPTED          = 'interrupted';
	private const OPTION_KEY                 = 'agents_api_chat_run_control';
	private const SESSION_OWNER_OPTION_KEY   = 'agents_api_chat_session_owners';

	/** @return string[] */
	public static function statuses(): array {
		return array(
			self::STATUS_QUEUED,
			self::STATUS_RUNNING,
			self::STATUS_CANCELLING,
			self::STATUS_CANCELLED,
			self::STATUS_COMPLETED,
			self::STATUS_FAILED,
			self::STATUS_RUNTIME_TOOL_PENDING,
			self::STATUS_APPROVAL_REQUIRED,
			self::STATUS_BUDGET_EXCEEDED,
			self::STATUS_STALLED,
			self::STATUS_INTERRUPTED,
		);
	}

	/**
	 * Resolve and bind canonical workspace and principal ownership.
	 *
	 * @param array<mixed> $input Ability input.
	 * @return array{workspace:?WP_Agent_Workspace_Scope,owner:?array{type:string,key:string}}|\WP_Error
	 */
	public static function context_from_input( array $input ) {
		$workspace = null;
		if ( array_key_exists( 'workspace', $input ) ) {
			if ( ! is_array( $input['workspace'] ) ) {
				return new \WP_Error( 'agents_chat_run_invalid_workspace', 'workspace must be a canonical workspace object.' );
			}
			try {
				$workspace = WP_Agent_Workspace_Scope::from_array( WP_Agent_Run_Control::string_keyed_array( $input['workspace'] ) );
			} catch ( \InvalidArgumentException $error ) {
				return new \WP_Error( 'agents_chat_run_invalid_workspace', $error->getMessage() );
			}
		}

		$principal = null;
		try {
			if ( ( $input['principal'] ?? null ) instanceof WP_Agent_Execution_Principal ) {
				$principal = $input['principal'];
			} elseif ( is_array( $input['principal'] ?? null ) ) {
				$principal = WP_Agent_Execution_Principal::from_array( WP_Agent_Run_Control::string_keyed_array( $input['principal'] ) );
			} else {
				$request_context                    = WP_Agent_Run_Control::string_keyed_array( $input );
				$request_context['request_context'] = WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST;
				$principal                         = WP_Agent_Execution_Principal::resolve( $request_context );
			}
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'agents_chat_run_invalid_principal', $error->getMessage() );
		}

		if ( ! $principal instanceof WP_Agent_Execution_Principal ) {
			$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
			if ( $user_id > 0 ) {
				$agent     = self::string_value( $input['agent'] ?? '__wordpress_user__' );
				$principal = WP_Agent_Execution_Principal::user_session( $user_id, '' !== $agent ? $agent : '__wordpress_user__' );
			}
		}

		$owner         = $principal instanceof WP_Agent_Execution_Principal ? $principal->conversation_owner() : null;
		$claimed_owner = is_array( $input['session_owner'] ?? null ) ? WP_Agent_Run_Control::string_keyed_array( $input['session_owner'] ) : null;
		if ( is_array( $claimed_owner ) ) {
			$claimed_owner = array(
				'type' => sanitize_key( self::string_value( $claimed_owner['type'] ?? '' ) ),
				'key'  => trim( self::string_value( $claimed_owner['key'] ?? '' ) ),
			);
			if ( '' === $claimed_owner['type'] || '' === $claimed_owner['key'] ) {
				return new \WP_Error( 'agents_chat_run_invalid_owner', 'session_owner type and key are required.' );
			}
			if ( is_array( $owner ) && $claimed_owner !== $owner ) {
				return new \WP_Error( 'agents_chat_run_owner_forbidden', 'session_owner must match the authenticated conversation principal.' );
			}
			$owner = $owner ?? $claimed_owner;
		}

		if ( $workspace instanceof WP_Agent_Workspace_Scope && ! is_array( $owner ) ) {
			return new \WP_Error( 'agents_chat_run_owner_required', 'Explicit workspace run control requires an authenticated conversation owner.' );
		}
		if ( $workspace instanceof WP_Agent_Workspace_Scope && ! WP_Agent_Run_Control::supports_workspace_state() ) {
			return new \WP_Error( 'agents_chat_run_workspace_unsupported', 'The registered run-control store does not support explicit workspaces.' );
		}

		return array(
			'workspace' => $workspace,
			'owner'     => $owner,
		);
	}

	/**
	 * Generate an opaque client-addressable run ID.
	 */
	public static function generate_run_id(): string {
		return WP_Agent_Run_Control::generate_run_id();
	}

	/**
	 * Normalize a run status payload returned by a runtime.
	 *
	 * @param array<string,mixed> $run Raw run status.
	 * @return array<string,mixed>
	 */
	public static function normalize_run( array $run ): array {
		$run_id     = trim( self::string_value( $run['run_id'] ?? null ) );
		$session_id = trim( self::string_value( $run['session_id'] ?? null ) );
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
			'started_at' => self::string_value( $run['started_at'] ?? null ),
			'updated_at' => self::string_value( $run['updated_at'] ?? null ),
			'metadata'   => isset( $run['metadata'] ) && is_array( $run['metadata'] ) ? $run['metadata'] : array(),
		);

		if ( isset( $run['queued_message_id'] ) ) {
			$normalized['queued_message_id'] = self::string_value( $run['queued_message_id'] );
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
	 * Normalize status values while keeping the public vocabulary bounded.
	 */
	public static function normalize_status( mixed $status ): string {
		$status = WP_Agent_Run_Control::normalize_status( $status );
		return in_array( $status, self::statuses(), true ) ? $status : self::STATUS_RUNNING;
	}

	/**
	 * Start or update an addressable chat run in the default store.
	 *
	 * @param string              $run_id     Run ID.
	 * @param string              $session_id Session ID.
	 * @param array<string,mixed> $metadata   Run metadata.
	 * @param array<string,mixed>|null $owner Canonical conversation owner.
	 * @return array<string,mixed>|\WP_Error Normalized run.
	 */
	public static function start_run( string $run_id, string $session_id, array $metadata = array(), ?WP_Agent_Workspace_Scope $workspace = null, ?array $owner = null ) {
		$canonical_owner = self::session_owner_fingerprint( $session_id, $workspace, $owner, true );
		if ( $canonical_owner instanceof \WP_Error ) {
			return $canonical_owner;
		}

		$state    = self::state( $workspace );
		$existing = $state['runs'][ $run_id ] ?? null;
		if ( is_array( $existing ) && ( $session_id !== self::string_value( $existing['session_id'] ?? '' ) || ! self::fingerprint_matches( $existing['_owner'] ?? null, $canonical_owner ) ) ) {
			return new \WP_Error( 'agents_chat_run_owner_forbidden', 'The run_id is already bound to another session or owner.' );
		}

		return self::normalize_run( WP_Agent_Run_Control::start_run(
			self::OPTION_KEY,
			$run_id,
			array(
				'session_id' => $session_id,
				'metadata'   => $metadata,
				'_owner'     => $canonical_owner,
			),
			$workspace
		) );
	}

	/**
	 * Complete a stored chat run.
	 *
	 * @param string $run_id Run ID.
	 * @param string $status Terminal status.
	 * @return array<string,mixed>|null Normalized run, or null when absent.
	 */
	public static function finish_run( string $run_id, string $status = self::STATUS_COMPLETED, ?WP_Agent_Workspace_Scope $workspace = null ): ?array {
		$run = WP_Agent_Run_Control::finish_run( self::OPTION_KEY, $run_id, $status, $workspace );
		return null === $run ? null : self::normalize_run( $run );
	}

	/**
	 * Read a stored chat run.
	 *
	 * @param string $run_id Run ID.
	 * @param array<string,mixed>|null $owner Canonical conversation owner.
	 * @return array<string,mixed>|null Normalized run, or null when absent.
	 */
	public static function get_run( string $run_id, ?WP_Agent_Workspace_Scope $workspace = null, ?array $owner = null ): ?array {
		if ( ! self::can_access_run( $run_id, $workspace, $owner ) ) {
			return null;
		}

		$run = WP_Agent_Run_Control::get_run( self::OPTION_KEY, $run_id, $workspace );
		return null === $run ? null : self::normalize_run( $run );
	}

	/**
	 * Request cancellation of a stored chat run.
	 *
	 * @param string $run_id Run ID.
	 * @param array<string,mixed>|null $owner Canonical conversation owner.
	 * @return array<string,mixed>|null Normalized run, or null when absent.
	 */
	public static function request_cancel( string $run_id, ?WP_Agent_Workspace_Scope $workspace = null, ?array $owner = null ): ?array {
		if ( ! self::can_access_run( $run_id, $workspace, $owner ) ) {
			return null;
		}

		$run = WP_Agent_Run_Control::request_cancel( self::OPTION_KEY, $run_id, $workspace );
		return null === $run ? null : self::normalize_run( $run );
	}

	/**
	 * Return a cancellation interrupt when one was requested for the run.
	 *
	 * @param string $run_id     Run ID.
	 * @param string $session_id Session ID.
	 * @param array<string,mixed>|null $owner Canonical conversation owner.
	 * @return array<string,mixed>|null Interrupt message or null.
	 */
	public static function cancellation_interrupt_for_run( string $run_id, string $session_id = '', ?WP_Agent_Workspace_Scope $workspace = null, ?array $owner = null ): ?array {
		$run = self::get_run( $run_id, $workspace, $owner );
		if ( null === $run || self::STATUS_CANCELLING !== $run['status'] ) {
			return null;
		}

		$resolved_session_id = '' !== $session_id ? $session_id : self::string_value( $run['session_id'] ?? null );

		return self::cancellation_interrupt_message( $run_id, $resolved_session_id );
	}

	/**
	 * Queue a follow-up message for a chat session.
	 *
	 * @param array<string,mixed> $input Canonical queue input.
	 * @param array<string,mixed>|null $owner Canonical conversation owner.
	 * @return array<string,mixed>|\WP_Error Queue result.
	 */
	public static function queue_message( array $input, ?WP_Agent_Workspace_Scope $workspace = null, ?array $owner = null ) {
		$session_id = trim( self::string_value( $input['session_id'] ?? null ) );
		if ( '' === $session_id ) {
			throw new \InvalidArgumentException( 'session_id must be a non-empty string' );
		}

		$target = self::queue_target( $input, $workspace, $owner );
		if ( $target instanceof \WP_Error ) {
			return $target;
		}
		$run_id = $target['run_id'];

		$queued_id = 'queued_' . str_replace( 'run_', '', self::generate_run_id() );
		$item      = array(
			'queued_message_id' => $queued_id,
			'session_id'        => $session_id,
			'run_id'            => $run_id,
			'agent'             => sanitize_title( self::string_value( $input['agent'] ?? null ) ),
			'message'           => self::string_value( $input['message'] ?? null ),
			'attachments'       => is_array( $input['attachments'] ?? null ) ? $input['attachments'] : array(),
			'client_context'    => is_array( $input['client_context'] ?? null ) ? $input['client_context'] : array(),
			'created_at'        => self::now(),
			'_owner'            => $target['owner_fingerprint'],
		);

		$state                            = self::state( $workspace );
		$state['queues'][ $session_id ]   = array_values( $state['queues'][ $session_id ] ?? array() );
		$state['queues'][ $session_id ][] = $item;
		$position                         = count( $state['queues'][ $session_id ] );
		self::save_state( $state, $workspace );

		return self::normalize_run( array(
			'run_id'            => $run_id,
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
	 * @param array<string,mixed>|null $owner Canonical conversation owner.
	 * @return array<int,array<string,mixed>> Queued items.
	 */
	public static function claim_queued_messages( string $session_id, ?WP_Agent_Workspace_Scope $workspace = null, ?array $owner = null ): array {
		$canonical_owner = self::session_owner_fingerprint( $session_id, $workspace, $owner, false );
		if ( $canonical_owner instanceof \WP_Error ) {
			return array();
		}

		$state = self::state( $workspace );
		$items = array_values( array_filter(
			$state['queues'][ $session_id ] ?? array(),
			static fn( array $item ): bool => self::fingerprint_matches( $item['_owner'] ?? null, $canonical_owner )
		) );
		unset( $state['queues'][ $session_id ] );
		self::save_state( $state, $workspace );
		return array_map( array( self::class, 'public_queue_item' ), $items );
	}

	/**
	 * Check whether queue input resolves to a session owned by the principal.
	 *
	 * @param array<string,mixed>      $input Canonical queue input.
	 * @param array<string,mixed>|null $owner Canonical conversation owner.
	 */
	public static function can_queue_message( array $input, ?WP_Agent_Workspace_Scope $workspace = null, ?array $owner = null ): bool {
		return ! ( self::queue_target( $input, $workspace, $owner ) instanceof \WP_Error );
	}

	/**
	 * List lifecycle events for a principal-owned run.
	 *
	 * @param array<string,mixed>|null $owner Canonical conversation owner.
	 * @return array<string,mixed>|null
	 */
	public static function list_events( string $run_id, string $cursor = '', int $limit = 100, ?WP_Agent_Workspace_Scope $workspace = null, ?array $owner = null ): ?array {
		if ( ! self::can_access_run( $run_id, $workspace, $owner ) ) {
			return null;
		}

		return WP_Agent_Run_Control::list_events( self::OPTION_KEY, $run_id, $cursor, $limit, $workspace );
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

	/** @return array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} */
	private static function state( ?WP_Agent_Workspace_Scope $workspace = null ): array {
		return WP_Agent_Run_Control::state( self::OPTION_KEY, $workspace );
	}

	private static function string_value( mixed $value ): string {
		return is_int( $value ) || is_float( $value ) || is_string( $value ) || is_bool( $value ) ? (string) $value : '';
	}

	private static function int_value( mixed $value ): int {
		return is_int( $value ) || is_float( $value ) || is_string( $value ) || is_bool( $value ) ? (int) $value : 0;
	}

	/** @param array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} $state State to persist. */
	private static function save_state( array $state, ?WP_Agent_Workspace_Scope $workspace = null ): void {
		WP_Agent_Run_Control::save_state( self::OPTION_KEY, $state, $workspace );
	}

	/** @param array<string,mixed>|null $owner */
	private static function owner_fingerprint( ?array $owner ): string {
		if ( ! is_string( $owner['type'] ?? null ) || ! is_string( $owner['key'] ?? null ) ) {
			return '';
		}

		return hash( 'sha256', $owner['type'] . ':' . $owner['key'] );
	}

	/** @param array<string,mixed>|null $owner */
	private static function owner_matches( mixed $stored, ?array $owner ): bool {
		$stored = is_string( $stored ) ? $stored : '';
		return '' === $stored || ( '' !== self::owner_fingerprint( $owner ) && hash_equals( $stored, self::owner_fingerprint( $owner ) ) );
	}

	private static function fingerprint_matches( mixed $stored, string $expected ): bool {
		return is_string( $stored ) && hash_equals( $expected, $stored );
	}

	/**
	 * Resolve a queue target from stored run state, which is authoritative for
	 * both the session identity and its canonical owner.
	 *
	 * @param array<string,mixed>      $input Queue input.
	 * @param array<string,mixed>|null $owner Resolved principal owner.
	 * @return array{run_id:string,owner_fingerprint:string}|\WP_Error
	 */
	private static function queue_target( array $input, ?WP_Agent_Workspace_Scope $workspace, ?array $owner ) {
		$session_id      = trim( self::string_value( $input['session_id'] ?? null ) );
		$run_id          = trim( self::string_value( $input['run_id'] ?? null ) );
		$state           = self::state( $workspace );
		$canonical_owner = self::session_owner_fingerprint( $session_id, $workspace, $owner, false );
		if ( $canonical_owner instanceof \WP_Error ) {
			return $canonical_owner;
		}

		if ( '' !== $run_id ) {
			$candidate = $state['runs'][ $run_id ] ?? null;
			if ( ! is_array( $candidate ) || $session_id !== trim( self::string_value( $candidate['session_id'] ?? null ) ) || ! self::fingerprint_matches( $candidate['_owner'] ?? null, $canonical_owner ) ) {
				return new \WP_Error( 'agents_chat_run_not_found', 'No chat run was found for the requested session owner.' );
			}
		} else {
			$latest_key = '';
			foreach ( $state['runs'] as $candidate_run_id => $candidate ) {
				if ( $session_id === trim( self::string_value( $candidate['session_id'] ?? null ) ) && self::fingerprint_matches( $candidate['_owner'] ?? null, $canonical_owner ) ) {
					$candidate_key = self::string_value( $candidate['updated_at'] ?? '' ) . ':' . self::string_value( $candidate['started_at'] ?? '' ) . ':' . $candidate_run_id;
					if ( '' !== $run_id && $candidate_key <= $latest_key ) {
						continue;
					}
					$latest_key = $candidate_key;
					$run_id = $candidate_run_id;
				}
			}
		}

		if ( '' === $run_id ) {
			return new \WP_Error( 'agents_chat_run_not_found', 'No chat run was found for the requested session.' );
		}

		return array(
			'run_id'            => $run_id,
			'owner_fingerprint' => $canonical_owner,
		);
	}

	/**
	 * Resolve or create the immutable owner binding for a session.
	 *
	 * Existing runs are consulted only to migrate pre-binding state. Conflicting
	 * historical owners fail closed without changing the binding or queue.
	 *
	 * @param array<string,mixed>|null $owner Resolved principal owner.
	 * @return string|\WP_Error Canonical owner fingerprint.
	 */
	private static function session_owner_fingerprint( string $session_id, ?WP_Agent_Workspace_Scope $workspace, ?array $owner, bool $create ) {
		$session_id  = trim( $session_id );
		$fingerprint = self::owner_fingerprint( $owner );
		if ( '' === $session_id ) {
			return new \WP_Error( 'agents_chat_run_not_found', 'No chat session was requested.' );
		}
		if ( $workspace instanceof WP_Agent_Workspace_Scope && '' === $fingerprint ) {
			return new \WP_Error( 'agents_chat_run_owner_required', 'Explicit workspace run control requires an authenticated conversation owner.' );
		}

		$binding_key   = hash( 'sha256', $session_id );
		$binding_state = WP_Agent_Run_Control::state( self::SESSION_OWNER_OPTION_KEY, $workspace );
		$binding       = $binding_state['runs'][ $binding_key ] ?? null;
		if ( is_array( $binding ) ) {
			$stored = is_string( $binding['_owner'] ?? null ) ? $binding['_owner'] : '';
			if ( $session_id !== self::string_value( $binding['session_id'] ?? '' ) || ! self::fingerprint_matches( $stored, $fingerprint ) ) {
				return new \WP_Error( 'agents_chat_run_owner_forbidden', 'The session is owned by another conversation principal.' );
			}
			return $stored;
		}

		$historical_owners = array();
		foreach ( self::state( $workspace )['runs'] as $run ) {
			if ( $session_id !== self::string_value( $run['session_id'] ?? '' ) ) {
				continue;
			}
			$stored = is_string( $run['_owner'] ?? null ) ? $run['_owner'] : '';
			$historical_owners[ $stored ] = true;
		}

		if ( count( $historical_owners ) > 1 ) {
			return new \WP_Error( 'agents_chat_run_owner_forbidden', 'The session has conflicting historical owner state.' );
		}
		if ( 1 === count( $historical_owners ) ) {
			$stored = (string) array_key_first( $historical_owners );
			if ( ! self::fingerprint_matches( $stored, $fingerprint ) ) {
				return new \WP_Error( 'agents_chat_run_owner_forbidden', 'The session is owned by another conversation principal.' );
			}
			$fingerprint = $stored;
		} elseif ( ! $create ) {
			return new \WP_Error( 'agents_chat_run_not_found', 'No chat run was found for the requested session.' );
		}

		$binding_state['runs'][ $binding_key ] = array(
			'session_id' => $session_id,
			'_owner'     => $fingerprint,
		);
		WP_Agent_Run_Control::save_state( self::SESSION_OWNER_OPTION_KEY, $binding_state, $workspace );

		return $fingerprint;
	}

	/** @param array<string,mixed>|null $owner */
	private static function can_access_run( string $run_id, ?WP_Agent_Workspace_Scope $workspace, ?array $owner ): bool {
		$state = self::state( $workspace );
		$run   = $state['runs'][ $run_id ] ?? null;
		return is_array( $run ) && self::owner_matches( $run['_owner'] ?? '', $owner );
	}

	/**
	 * @param array<string,mixed> $item Stored queue item.
	 * @return array<string,mixed>
	 */
	private static function public_queue_item( array $item ): array {
		unset( $item['_owner'] );
		return $item;
	}

	private static function now(): string {
		return gmdate( 'c' );
	}
}

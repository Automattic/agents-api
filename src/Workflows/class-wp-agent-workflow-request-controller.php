<?php
/**
 * Request-triggered, reconnectable controller for a single workflow operation.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Workflows;

use AgentsAPI\AI\WP_Agent_Run_Control;

defined( 'ABSPATH' ) || exit;

/**
 * Owns durable request idempotency and one foreground advance lane per operation.
 *
 * The controller deliberately knows nothing about the consumer's job, user, or
 * delivery channel. Consumers supply an opaque operation id and optional terminal
 * callbacks; workflow execution remains the runner/awaiter responsibility.
 */
final class WP_Agent_Workflow_Request_Controller {

	public const SCHEMA = 'agents-api/workflow-request-controller/v1';

	/** @var callable|null */
	private $terminal_action;
	/** @var callable|null */
	private $terminal_cleanup;
	/** @var callable */
	private $clock;

	public function __construct(
		private WP_Agent_Workflow_Runner $runner,
		private WP_Agent_Workflow_Run_Recorder $recorder,
		private WP_Agent_Workflow_Run_Awaiter $awaiter,
		private string $store_key = 'agents_workflow_request_controller',
		?callable $terminal_action = null,
		?callable $terminal_cleanup = null,
		?callable $clock = null
	) {
		$this->terminal_action  = $terminal_action;
		$this->terminal_cleanup = $terminal_cleanup;
		$this->clock            = $clock ?? static fn(): int => time();
	}

	/**
	 * Reserve an operation and make one bounded foreground advance attempt.
	 * Repeated starts for the same operation never create a second workflow run.
	 *
	 * @param array<string,mixed> $inputs Workflow inputs.
	 * @param array<string,mixed> $options Runner options plus `await` and
	 *                                     `lease_seconds` controller options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function start( string $operation_id, WP_Agent_Workflow_Spec $spec, array $inputs = array(), array $options = array() ): array|\WP_Error {
		$operation_id = trim( $operation_id );
		if ( '' === $operation_id ) {
			return new \WP_Error( 'agents_workflow_operation_required', 'operation_id must be a non-empty string.' );
		}

		$this->reserve( $operation_id, $spec, $inputs, $options );
		return $this->advance( $operation_id, $options );
	}

	/**
	 * Reconnect to an existing operation. No workflow spec or consumer state is
	 * required because the durable reservation contains the replayable start data.
	 *
	 * @param array<string,mixed> $options Await and lease options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function reconnect( string $operation_id, array $options = array() ): array|\WP_Error {
		return $this->advance( trim( $operation_id ), $options );
	}

	/** @return array<string,mixed>|null */
	public function get( string $operation_id ): ?array {
		$state = WP_Agent_Run_Control::state( $this->store_key );
		$entry = $state['runs'][ trim( $operation_id ) ] ?? null;
		return is_array( $entry ) ? $entry : null;
	}

	/**
	 * @param array<string,mixed> $inputs
	 * @param array<string,mixed> $options
	 */
	private function reserve( string $operation_id, WP_Agent_Workflow_Spec $spec, array $inputs, array $options ): void {
		WP_Agent_Run_Control::mutate_state(
			$this->store_key,
			function ( array $state ) use ( $operation_id, $spec, $inputs, $options ): array {
				if ( isset( $state['runs'][ $operation_id ] ) ) {
					return array( 'state' => $state, 'result' => null );
				}
				$state['runs'][ $operation_id ] = array(
					'run_id'      => 'workflow_request_' . substr( hash( 'sha256', $this->store_key . "\0" . $operation_id ), 0, 32 ),
					'spec'        => $spec->to_array(),
					'inputs'      => $inputs,
					'options'     => $this->runner_options( $options ),
					'terminal'    => false,
					'disposition' => 'pending',
					'lease'       => array(),
				);
				return array( 'state' => $state, 'result' => null );
			}
		);
	}

	/**
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>|\WP_Error
	 */
	private function advance( string $operation_id, array $options ): array|\WP_Error {
		if ( '' === $operation_id ) {
			return new \WP_Error( 'agents_workflow_operation_required', 'operation_id must be a non-empty string.' );
		}
		$lease = $this->claim_lease( $operation_id, max( 1, $this->int_value( $options['lease_seconds'] ?? 30 ) ) );
		if ( null === $lease ) {
			$entry = $this->get( $operation_id );
			return null === $entry ? new \WP_Error( 'agents_workflow_operation_not_found', 'No workflow operation was found for the requested operation_id.' ) : $this->response( $operation_id, $entry, true );
		}

		try {
			$entry = $this->get( $operation_id );
			if ( null === $entry ) {
				return new \WP_Error( 'agents_workflow_operation_not_found', 'No workflow operation was found for the requested operation_id.' );
			}
			$result = $this->recorder->find( $this->string_value( $entry['run_id'] ?? '' ) );
			if ( null === $result ) {
				$spec = WP_Agent_Workflow_Spec::from_array( is_array( $entry['spec'] ?? null ) ? $entry['spec'] : array() );
				if ( is_wp_error( $spec ) ) {
					return $spec;
				}
				$run_options           = is_array( $entry['options'] ?? null ) ? $entry['options'] : array();
				$run_options['run_id'] = $this->string_value( $entry['run_id'] ?? '' );
				$result                = $this->runner->run( $spec, is_array( $entry['inputs'] ?? null ) ? $entry['inputs'] : array(), $run_options );
			}

			if ( $result->is_suspended() ) {
				$awaited = $this->awaiter->await( $result->get_run_id(), $this->recorder, $this->array_value( $options['await'] ?? array() ) );
				if ( is_wp_error( $awaited ) ) {
					return $awaited;
				}
				$result = $this->recorder->find( $result->get_run_id() ) ?? $result;
			}

			$entry = $this->record_terminal( $operation_id, $result );
			if ( ! empty( $entry['terminal'] ) ) {
				$this->deliver_terminal_once( $operation_id, $entry, $result );
				$entry = $this->get( $operation_id ) ?? $entry;
			}
			return $this->response( $operation_id, $entry, false );
		} finally {
			$this->release_lease( $operation_id, $lease );
		}
	}

	/** @return string|null */
	private function claim_lease( string $operation_id, int $seconds ): ?string {
		$result = WP_Agent_Run_Control::mutate_state( $this->store_key, function ( array $state ) use ( $operation_id, $seconds ) {
			$entry = $state['runs'][ $operation_id ] ?? null;
			if ( ! is_array( $entry ) || ! empty( $entry['terminal'] ) ) {
				return array( 'state' => $state, 'result' => null );
			}
			$now = $this->int_value( ( $this->clock )() );
			$lease = $this->array_value( $entry['lease'] ?? array() );
			if ( $this->int_value( $lease['expires_at'] ?? 0 ) > $now ) {
				return array( 'state' => $state, 'result' => null );
			}
			$token          = bin2hex( random_bytes( 12 ) );
			$entry['lease'] = array( 'token' => $token, 'expires_at' => $now + $seconds );
			$state['runs'][ $operation_id ] = $entry;
			return array( 'state' => $state, 'result' => $token );
		} );
		return is_string( $result ) ? $result : null;
	}

	/** @return array<string,mixed> */
	private function record_terminal( string $operation_id, WP_Agent_Workflow_Run_Result $result ): array {
		$stored = WP_Agent_Run_Control::mutate_state( $this->store_key, function ( array $state ) use ( $operation_id, $result ) {
			$entry = $this->array_value( $state['runs'][ $operation_id ] ?? array() );
			if ( $this->is_terminal( $result ) && empty( $entry['terminal'] ) ) {
				$entry['terminal']        = true;
				$entry['terminal_status'] = $result->get_status();
				$entry['result']          = $result->to_run_result_envelope()->to_array();
				$entry['lease']           = array();
			}
			$state['runs'][ $operation_id ] = $entry;
			return array( 'state' => $state, 'result' => $entry );
		} );
		return $this->array_value( $stored );
	}

	/** @param array<string,mixed> $entry */
	private function deliver_terminal_once( string $operation_id, array $entry, WP_Agent_Workflow_Run_Result $result ): void {
		$claimed = WP_Agent_Run_Control::mutate_state( $this->store_key, function ( array $state ) use ( $operation_id ) {
			$stored = $this->array_value( $state['runs'][ $operation_id ] ?? array() );
			if ( 'pending' !== ( $stored['disposition'] ?? '' ) ) {
				return array( 'state' => $state, 'result' => false );
			}
			$stored['disposition']       = 'delivered';
			$stored['terminal_cleanup']  = true;
			$stored['lease']             = array();
			$state['runs'][ $operation_id ] = $stored;
			return array( 'state' => $state, 'result' => true );
		} );
		if ( true !== $claimed ) {
			return;
		}
		try {
			if ( null !== $this->terminal_action ) {
				call_user_func( $this->terminal_action, $operation_id, $result, $entry );
			}
		} finally {
			// A failed consumer action must not retain this operation's terminal resources.
			if ( null !== $this->terminal_cleanup ) {
				call_user_func( $this->terminal_cleanup, $operation_id, $result, $entry );
			}
		}
	}

	private function release_lease( string $operation_id, string $token ): void {
		WP_Agent_Run_Control::mutate_state( $this->store_key, function ( array $state ) use ( $operation_id, $token ) {
			$entry = $state['runs'][ $operation_id ] ?? null;
			if ( is_array( $entry ) && $token === $this->string_value( $this->array_value( $entry['lease'] ?? array() )['token'] ?? '' ) ) {
				$entry['lease'] = array();
				$state['runs'][ $operation_id ] = $entry;
			}
			return array( 'state' => $state, 'result' => null );
		} );
	}

	/**
	 * @param array<string,mixed> $entry
	 * @return array<string,mixed>
	 */
	private function response( string $operation_id, array $entry, bool $busy ): array {
		$terminal = ! empty( $entry['terminal'] );
		return array(
			'schema'        => self::SCHEMA,
			'operation_id'  => $operation_id,
			'run_id'        => $this->string_value( $entry['run_id'] ?? '' ),
			'terminal'      => $terminal,
			'reconnectable' => ! $terminal,
			'busy'          => $busy,
			'status'        => $terminal ? $this->string_value( $entry['terminal_status'] ?? '' ) : 'running',
			'result'        => $terminal ? ( $entry['result'] ?? null ) : null,
		);
	}

	/**
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	private function runner_options( array $options ): array {
		unset( $options['await'], $options['lease_seconds'] );
		return $options;
	}

	private function is_terminal( WP_Agent_Workflow_Run_Result $result ): bool {
		return in_array( $result->get_status(), array( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, WP_Agent_Workflow_Run_Result::STATUS_FAILED, WP_Agent_Workflow_Run_Result::STATUS_SKIPPED, WP_Agent_Workflow_Run_Result::STATUS_CANCELLED ), true );
	}

	/** @return array<string,mixed> */
	private function array_value( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return WP_Agent_Run_Control::string_keyed_array( $value );
	}

	private function string_value( mixed $value ): string {
		return is_scalar( $value ) || $value instanceof \Stringable ? (string) $value : '';
	}

	private function int_value( mixed $value ): int {
		return is_int( $value ) || is_float( $value ) || is_string( $value ) || is_bool( $value ) ? (int) $value : 0;
	}
}

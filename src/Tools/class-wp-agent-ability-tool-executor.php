<?php
/**
 * Ability-backed tool executor.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Tools;

use AgentsAPI\AI\Abilities\WP_Agent_Ability_Dispatcher;
use AgentsAPI\AI\WP_Agent_Execution_Principal;
use WP_Agent_Authorization_Policy;
use WP_Agent_WordPress_Authorization_Policy;

defined( 'ABSPATH' ) || exit;

/**
 * Executes host-owned tool calls through the WordPress Abilities API.
 */
class WP_Agent_Ability_Tool_Executor implements WP_Agent_Tool_Executor {

	/**
	 * Execute a prepared tool call by invoking its mapped ability.
	 *
	 * Tool declarations may specify `ability` or `ability_name` when the model-facing
	 * tool name differs from the registered ability name. Otherwise the tool name is
	 * used directly as the ability name.
	 *
	 * When a declaration names a `required_capability`, the existing authorization
	 * policy is consulted before dispatch: the ability only runs when the execution
	 * principal's capability ceiling (intersected with the acting WordPress user)
	 * permits the capability. A denial returns an auditable tool-error result so the
	 * model learns the tool was not permitted rather than receiving its output.
	 *
	 * @param array<mixed> $tool_call       Normalized prepared tool call.
	 * @param array<mixed> $tool_definition Tool declaration selected for the call.
	 * @param array<mixed> $context         Host runtime context for this invocation.
	 * @return array<mixed> Normalized tool execution result.
	 */
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		$tool_call    = WP_Agent_Tool_Call::normalize( $tool_call );
		$tool_name    = is_string( $tool_call['tool_name'] ?? null ) ? $tool_call['tool_name'] : '';
		$parameters   = isset( $tool_call['parameters'] ) && is_array( $tool_call['parameters'] ) ? $tool_call['parameters'] : array();
		$ability_name = $this->ability_name( $tool_call, $tool_definition );

		if ( '' === $ability_name ) {
			return WP_Agent_Tool_Result::error(
				$tool_name,
				'Tool declaration does not identify an ability.',
				array( 'error_type' => 'ability_name_missing' )
			);
		}

		$required_capability = WP_Agent_Tool_Declaration::requiredCapability( $tool_definition );
		if ( '' !== $required_capability ) {
			$denial = $this->authorize_required_capability(
				$tool_name,
				$ability_name,
				$required_capability,
				$parameters,
				$context
			);

			if ( null !== $denial ) {
				return $denial;
			}
		}

		$result = WP_Agent_Ability_Dispatcher::dispatch( $ability_name, $parameters );
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
			return WP_Agent_Tool_Result::error(
				$tool_name,
				$this->wp_error_message( $result ),
				array(
					'ability_name'        => $ability_name,
					'error_code'          => $result->get_error_code(),
					'error_type'          => $this->error_type( $result ),
					'parameters'          => WP_Agent_Ability_Dispatcher::redacted_parameters( $ability_name, $parameters ),
					'parameters_redacted' => true,
				)
			);
		}

		return WP_Agent_Tool_Result::success(
			$tool_name,
			$result,
			array(
				'ability_name'        => $ability_name,
				'parameters'          => WP_Agent_Ability_Dispatcher::redacted_parameters( $ability_name, $parameters ),
				'parameters_redacted' => true,
			)
		);
	}

	/**
	 * Enforce a tool's required capability against the execution principal.
	 *
	 * Resolves the principal and authorization policy from the host runtime
	 * context, then asks the existing {@see WP_Agent_Authorization_Policy::can()}
	 * whether the principal's capability ceiling permits the required capability.
	 * The ceiling is the intersection of the acting WordPress user's capabilities
	 * and any token/client restrictions; this method depends only on the ceiling
	 * being present on the principal, not on how it was derived.
	 *
	 * @param string       $tool_name           Model-facing tool name.
	 * @param string       $ability_name        Resolved ability name.
	 * @param string       $required_capability Required WordPress capability.
	 * @param array<mixed> $parameters          Prepared tool parameters (redacted on denial).
	 * @param array<mixed> $context             Host runtime context.
	 * @return array<string,mixed>|null Denial result when not permitted, or null to proceed.
	 */
	private function authorize_required_capability( string $tool_name, string $ability_name, string $required_capability, array $parameters, array $context ): ?array {
		$principal = $context['principal'] ?? null;
		$policy    = $context['authorization_policy'] ?? null;

		if ( $policy instanceof WP_Agent_Authorization_Policy ) {
			$authorization_policy = $policy;
		} else {
			$authorization_policy = new WP_Agent_WordPress_Authorization_Policy();
		}

		if ( $principal instanceof WP_Agent_Execution_Principal ) {
			$allowed = $authorization_policy->can( $principal, $required_capability );
			$reason  = 'capability_not_permitted';
			$safe_metadata = $principal->to_safe_metadata();
		} else {
			$allowed       = false;
			$reason        = 'principal_unavailable';
			$safe_metadata = null;
		}

		if ( $allowed ) {
			return null;
		}

		return WP_Agent_Tool_Result::error(
			$tool_name,
			sprintf( 'Tool "%1$s" requires the "%2$s" capability, which is not permitted for this execution.', $tool_name, $required_capability ),
			array(
				'error_type'          => 'capability_denied',
				'required_capability' => $required_capability,
				'ability_name'        => $ability_name,
				'parameters'          => WP_Agent_Ability_Dispatcher::redacted_parameters( $ability_name, $parameters ),
				'parameters_redacted' => true,
				'denial'              => array(
					'allowed'   => false,
					'operation' => 'tool_execution',
					'reason'    => $reason,
					'principal' => $safe_metadata,
				),
			)
		);
	}

	/**
	 * Resolve the registered ability name for a tool call.
	 *
	 * @param array<mixed> $tool_call       Normalized prepared tool call.
	 * @param array<mixed> $tool_definition Tool declaration selected for the call.
	 * @return string Ability name.
	 */
	private function ability_name( array $tool_call, array $tool_definition ): string {
		$metadata = isset( $tool_call['metadata'] ) && is_array( $tool_call['metadata'] ) ? $tool_call['metadata'] : array();

		$candidates = array(
			$tool_definition['ability'] ?? null,
			$tool_definition['ability_name'] ?? null,
			$metadata['ability_name'] ?? null,
			$tool_call['tool_name'] ?? null,
		);

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
				return trim( $candidate );
			}
		}

		return '';
	}

	/**
	 * Return a human-readable WP_Error message without requiring full WP stubs.
	 *
	 * @param mixed $error WP_Error-like value.
	 * @return string Error message.
	 */
	private function wp_error_message( $error ): string {
		if ( is_object( $error ) && method_exists( $error, 'get_error_message' ) ) {
			$message = $error->get_error_message();
			if ( is_string( $message ) && '' !== $message ) {
				return $message;
			}
		}

		return 'Ability execution failed.';
	}

	/**
	 * Normalize dispatcher/core WP_Error codes to executor error types.
	 *
	 * @param mixed $error WP_Error-like value.
	 * @return string Error type.
	 */
	private function error_type( $error ): string {
		$code = is_object( $error ) && method_exists( $error, 'get_error_code' ) ? $error->get_error_code() : '';

		return match ( $code ) {
			'abilities_api_missing' => 'abilities_api_unavailable',
			'ability_not_found'     => 'ability_not_found',
			'ability_name_missing'  => 'ability_name_missing',
			default                 => 'ability_error',
		};
	}
}

<?php
/**
 * Generic tool execution core.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Prepares and executes product-neutral tool declarations.
 */
class ToolExecutionCore {

	public const EXECUTOR_CALLABLE = 'callable';
	public const EXECUTOR_ABILITY  = 'ability';
	public const EXECUTOR_CLIENT   = RuntimeToolDeclaration::EXECUTOR_CLIENT;

	/**
	 * Prepare a tool invocation for execution.
	 *
	 * @param string $tool_name       Tool identifier.
	 * @param array  $tool_parameters Runtime tool-call parameters.
	 * @param array  $available_tools Tool declarations keyed by name.
	 * @param array  $context         Host runtime context for this invocation.
	 * @return array<string, mixed> Prepared invocation or normalized error result.
	 */
	public function prepareToolCall( string $tool_name, array $tool_parameters, array $available_tools, array $context = array() ): array {
		$tool_definition = $available_tools[ $tool_name ] ?? null;
		if ( ! is_array( $tool_definition ) ) {
			return array_merge(
				array( 'ready' => false ),
				ToolExecutionResult::error( $tool_name, "Tool '{$tool_name}' not found" )
			);
		}

		$validation = ToolParameters::validateRequiredParameters( $tool_parameters, $tool_definition );
		if ( ! $validation['valid'] ) {
			return array_merge(
				array( 'ready' => false ),
				ToolExecutionResult::error(
					$tool_name,
					sprintf( 'Tool "%s" requires the following parameters: %s.', $tool_name, implode( ', ', $validation['missing'] ) ),
					array( 'missing_parameters' => $validation['missing'] )
				)
			);
		}

		return array(
			'ready'      => true,
			'tool_name'  => $tool_name,
			'tool_def'   => $tool_definition,
			'parameters' => ToolParameters::buildParameters( $tool_parameters, $context, $tool_definition ),
		);
	}

	/**
	 * Execute a prepared tool definition directly.
	 *
	 * @param string $tool_name       Tool identifier.
	 * @param array  $parameters      Complete parameters.
	 * @param array  $tool_definition Normalized tool declaration.
	 * @return array<string, mixed> Normalized execution result.
	 */
	public function executePreparedTool( string $tool_name, array $parameters, array $tool_definition ): array {
		$executor = $tool_definition['executor'] ?? self::EXECUTOR_CALLABLE;

		if ( self::EXECUTOR_CLIENT === $executor ) {
			return ToolExecutionResult::error( $tool_name, 'Client-executed tools cannot be executed by the server runtime.' );
		}

		if ( self::EXECUTOR_ABILITY === $executor || ! empty( $tool_definition['ability'] ) ) {
			return $this->executeAbilityTool( $tool_name, $parameters, $tool_definition );
		}

		$callback = $tool_definition['callback'] ?? null;
		if ( ! is_callable( $callback ) ) {
			return ToolExecutionResult::error( $tool_name, 'Tool definition is missing a callable executor.' );
		}

		try {
			$result = call_user_func( $callback, $parameters, $tool_definition );
		} catch ( \Throwable $throwable ) {
			return ToolExecutionResult::error( $tool_name, $throwable->getMessage() );
		}

		if ( is_array( $result ) && array_key_exists( 'success', $result ) ) {
			$result['tool_name'] = is_string( $result['tool_name'] ?? null ) ? $result['tool_name'] : $tool_name;
			return ToolExecutionResult::normalize( $result );
		}

		return ToolExecutionResult::success( $tool_name, $result );
	}

	/**
	 * Execute a complete tool invocation.
	 *
	 * @param string $tool_name       Tool identifier.
	 * @param array  $tool_parameters Runtime tool-call parameters.
	 * @param array  $available_tools Tool declarations keyed by name.
	 * @param array  $context         Host runtime context for this invocation.
	 * @return array<string, mixed> Normalized execution result.
	 */
	public function executeTool( string $tool_name, array $tool_parameters, array $available_tools, array $context = array() ): array {
		$prepared = $this->prepareToolCall( $tool_name, $tool_parameters, $available_tools, $context );
		if ( empty( $prepared['ready'] ) ) {
			unset( $prepared['ready'] );
			return $prepared;
		}

		return $this->executePreparedTool( $tool_name, $prepared['parameters'], $prepared['tool_def'] );
	}

	/**
	 * Execute a declaration linked to a WordPress Ability when available.
	 *
	 * @param string $tool_name       Tool identifier.
	 * @param array  $parameters      Complete parameters.
	 * @param array  $tool_definition Normalized tool declaration.
	 * @return array<string, mixed> Normalized execution result.
	 */
	private function executeAbilityTool( string $tool_name, array $parameters, array $tool_definition ): array {
		$ability_slug = $tool_definition['ability'] ?? '';
		if ( ! is_string( $ability_slug ) || '' === $ability_slug ) {
			return ToolExecutionResult::error( $tool_name, 'Ability-backed tool is missing an ability slug.' );
		}

		if ( ! class_exists( '\WP_Abilities_Registry' ) ) {
			return ToolExecutionResult::error( $tool_name, sprintf( "Tool '%s' references ability '%s', but the WordPress Abilities API is not available.", $tool_name, $ability_slug ) );
		}

		$registry = \WP_Abilities_Registry::get_instance();
		$ability  = $registry->get_registered( $ability_slug );
		if ( ! $ability ) {
			return ToolExecutionResult::error( $tool_name, sprintf( "Tool '%s' references missing ability '%s'.", $tool_name, $ability_slug ) );
		}

		$permission = $ability->check_permissions( $parameters );
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $permission ) ) {
			return ToolExecutionResult::error( $tool_name, $permission->get_error_message() );
		}

		if ( true !== $permission ) {
			return ToolExecutionResult::error( $tool_name, sprintf( "Tool '%s' is not permitted by ability '%s'.", $tool_name, $ability_slug ) );
		}

		$result = $ability->execute( $parameters );
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
			return ToolExecutionResult::error( $tool_name, $result->get_error_message() );
		}

		return ToolExecutionResult::success( $tool_name, $result, array( 'ability' => $ability_slug ) );
	}
}

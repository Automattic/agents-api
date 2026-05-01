<?php
/**
 * Tool source registry.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Composes tool declarations from named sources before runtime policy filtering.
 */
class ToolSourceRegistry {

	/**
	 * @var array<string, callable>
	 */
	private array $sources = array();

	/**
	 * Register a source callback.
	 *
	 * Source callbacks receive `(array $context, ToolSourceRegistry $registry)` and
	 * return tool declarations keyed by tool name.
	 *
	 * @param string   $source_slug Source slug.
	 * @param callable $source      Source callback.
	 * @return void
	 */
	public function registerSource( string $source_slug, callable $source ): void {
		if ( '' === $source_slug ) {
			throw new \InvalidArgumentException( 'invalid_tool_source: source_slug must be a non-empty string' );
		}

		$this->sources[ $source_slug ] = $source;
	}

	/**
	 * Remove a source callback.
	 *
	 * @param string $source_slug Source slug.
	 * @return void
	 */
	public function unregisterSource( string $source_slug ): void {
		unset( $this->sources[ $source_slug ] );
	}

	/**
	 * Return registered source callbacks.
	 *
	 * @param array $context Runtime context.
	 * @return array<string, callable>
	 */
	public function getSources( array $context = array() ): array {
		$sources = $this->sources;
		if ( function_exists( 'apply_filters' ) ) {
			$sources = apply_filters( 'agents_api_tool_sources', $sources, $context, $this );
		}

		return is_array( $sources ) ? array_filter( $sources, 'is_callable' ) : array();
	}

	/**
	 * Gather tools from registered sources in source order.
	 *
	 * Earlier sources win when two sources return the same tool name.
	 *
	 * @param array $context Runtime context.
	 * @return array<string, array> Tool declarations keyed by tool name.
	 */
	public function gather( array $context = array() ): array {
		$tools   = array();
		$sources = $this->getSources( $context );
		$order   = array_keys( $sources );

		if ( function_exists( 'apply_filters' ) ) {
			$order = apply_filters( 'agents_api_tool_source_order', $order, $context, $this );
		}

		if ( ! is_array( $order ) ) {
			return array();
		}

		foreach ( $order as $source_slug ) {
			if ( ! is_string( $source_slug ) || ! isset( $sources[ $source_slug ] ) ) {
				continue;
			}

			$source_tools = call_user_func( $sources[ $source_slug ], $context, $this );
			if ( ! is_array( $source_tools ) ) {
				continue;
			}

			foreach ( $source_tools as $tool_name => $tool_definition ) {
				if ( ! is_string( $tool_name ) || isset( $tools[ $tool_name ] ) || ! is_array( $tool_definition ) ) {
					continue;
				}

				$tools[ $tool_name ] = $this->normalizeGatheredTool( $tool_name, $source_slug, $tool_definition );
			}
		}

		return $tools;
	}

	/**
	 * Normalize source metadata on a gathered declaration.
	 *
	 * @param string $tool_name       Tool identifier.
	 * @param string $source_slug     Source slug.
	 * @param array  $tool_definition Raw tool declaration.
	 * @return array<string, mixed>
	 */
	private function normalizeGatheredTool( string $tool_name, string $source_slug, array $tool_definition ): array {
		$tool_definition['name']   = is_string( $tool_definition['name'] ?? null ) && '' !== $tool_definition['name'] ? $tool_definition['name'] : $tool_name;
		$tool_definition['source'] = is_string( $tool_definition['source'] ?? null ) && '' !== $tool_definition['source'] ? $tool_definition['source'] : $source_slug;

		if ( ! isset( $tool_definition['parameters'] ) || ! is_array( $tool_definition['parameters'] ) ) {
			$tool_definition['parameters'] = array();
		}

		return $tool_definition;
	}
}

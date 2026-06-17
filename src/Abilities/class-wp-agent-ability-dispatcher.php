<?php
/**
 * Shared agent-side ability dispatcher.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves Abilities API abilities and invokes core WP_Ability::execute().
 */
class WP_Agent_Ability_Dispatcher {

	/**
	 * Redacted placeholder used for sensitive parameter values.
	 */
	public const REDACTED_VALUE = '[redacted]';

	/**
	 * Dispatch an ability through WordPress core's WP_Ability::execute().
	 *
	 * @param string       $ability_name Registered ability name.
	 * @param array<mixed> $parameters   Ability parameters.
	 * @return mixed|\WP_Error Ability result, or a WP_Error before dispatch.
	 */
	public static function dispatch( string $ability_name, array $parameters = array() ) {
		$ability_name = trim( $ability_name );
		if ( '' === $ability_name ) {
			return new \WP_Error( 'ability_name_missing', 'Ability name is required.' );
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new \WP_Error( 'abilities_api_missing', 'Abilities API is not loaded; cannot dispatch ability.' );
		}

		$ability = wp_get_ability( $ability_name );
		if ( ! $ability instanceof \WP_Ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability is not registered.', array( 'ability_name' => $ability_name ) );
		}

		return $ability->execute( $parameters );
	}

	/**
	 * Return ability parameters with sensitive values redacted.
	 *
	 * Sensitivity is detected from explicit ability metadata and JSON-schema
	 * annotations, then backed by a conservative key-name fallback.
	 *
	 * @param string       $ability_name Registered ability name.
	 * @param array<mixed> $parameters   Raw ability parameters.
	 * @return array<string,mixed> Redacted parameters.
	 */
	public static function redacted_parameters( string $ability_name, array $parameters ): array {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( trim( $ability_name ) ) : null;
		$paths   = $ability instanceof \WP_Ability ? self::sensitive_parameter_paths( $ability ) : array();

		$redacted = self::redact_value( $parameters, '', $paths );
		return is_array( $redacted ) ? self::string_keyed_array( $redacted ) : array();
	}

	/**
	 * Resolve sensitive parameter paths declared by an ability.
	 *
	 * @param \WP_Ability $ability Ability object.
	 * @return array<string,bool> Dot paths keyed to true.
	 */
	public static function sensitive_parameter_paths( \WP_Ability $ability ): array {
		$paths = array();

		if ( method_exists( $ability, 'get_meta_item' ) ) {
			self::add_meta_paths( $paths, $ability->get_meta_item( 'sensitive_parameters', array() ) );
			self::add_meta_paths( $paths, $ability->get_meta_item( 'parameter_sensitivity', array() ) );
		}

		self::collect_schema_paths( $ability->get_input_schema(), '', $paths );

		return $paths;
	}

	/**
	 * Add explicit meta-defined paths.
	 *
	 * @param array<string,bool> $paths Existing path map.
	 * @param mixed              $value Meta value.
	 */
	private static function add_meta_paths( array &$paths, $value ): void {
		if ( ! is_array( $value ) ) {
			return;
		}

		foreach ( $value as $key => $item ) {
			if ( is_string( $item ) && '' !== trim( $item ) ) {
				$paths[ trim( $item ) ] = true;
				continue;
			}

			if ( is_string( $key ) && true === $item && '' !== trim( $key ) ) {
				$paths[ trim( $key ) ] = true;
			}
		}
	}

	/**
	 * Collect sensitive paths from JSON schema property annotations.
	 *
	 * @param array<mixed>        $schema JSON-schema fragment.
	 * @param string              $path   Current dot path.
	 * @param array<string,bool>  $paths  Sensitive path map.
	 */
	private static function collect_schema_paths( array $schema, string $path, array &$paths ): void {
		if ( self::schema_marks_sensitive( $schema ) && '' !== $path ) {
			$paths[ $path ] = true;
		}

		$properties = $schema['properties'] ?? array();
		if ( is_array( $properties ) ) {
			foreach ( $properties as $name => $property_schema ) {
				if ( ! is_string( $name ) || ! is_array( $property_schema ) ) {
					continue;
				}

				self::collect_schema_paths( $property_schema, '' === $path ? $name : $path . '.' . $name, $paths );
			}
		}

		$items = $schema['items'] ?? null;
		if ( is_array( $items ) ) {
			self::collect_schema_paths( $items, '' === $path ? '*' : $path . '.*', $paths );
		}
	}

	/**
	 * Determine whether a schema node marks its value as sensitive.
	 *
	 * @param array<mixed> $schema JSON-schema fragment.
	 * @return bool
	 */
	private static function schema_marks_sensitive( array $schema ): bool {
		foreach ( array( 'sensitive', 'x-sensitive', 'secret', 'writeOnly' ) as $key ) {
			if ( true === ( $schema[ $key ] ?? false ) ) {
				return true;
			}
		}

		return isset( $schema['format'] ) && 'password' === $schema['format'];
	}

	/**
	 * Redact sensitive values in nested data.
	 *
	 * @param mixed              $value Value to redact.
	 * @param string             $path  Current dot path.
	 * @param array<string,bool> $paths Sensitive path map.
	 * @return mixed Redacted value.
	 */
	private static function redact_value( $value, string $path, array $paths ) {
		$key = self::last_path_segment( $path );
		if ( '' !== $path && ( isset( $paths[ $path ] ) || self::sensitive_key( $key ) ) ) {
			return self::REDACTED_VALUE;
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		$redacted = array();
		foreach ( $value as $item_key => $item_value ) {
			$segment   = is_string( $item_key ) ? $item_key : '*';
			$next_path = '' === $path ? $segment : $path . '.' . $segment;
			if ( ! isset( $paths[ $next_path ] ) && '*' !== $segment ) {
				$wildcard_path = '' === $path ? '*' : $path . '.*';
				if ( isset( $paths[ $wildcard_path ] ) ) {
					$next_path = $wildcard_path;
				}
			}

			$redacted[ $item_key ] = self::redact_value( $item_value, $next_path, $paths );
		}

		return $redacted;
	}

	/**
	 * Check a parameter key against conservative sensitive-name patterns.
	 *
	 * @param string $key Parameter key.
	 * @return bool
	 */
	private static function sensitive_key( string $key ): bool {
		return '' !== $key && 1 === preg_match( '/(api[_-]?key|authorization|cookie|credential|nonce|password|secret|token)/i', $key );
	}

	/**
	 * Return the final segment from a dot path.
	 *
	 * @param string $path Dot path.
	 * @return string Segment.
	 */
	private static function last_path_segment( string $path ): string {
		$parts = explode( '.', $path );
		return (string) end( $parts );
	}

	/**
	 * Keep only string-keyed top-level parameters.
	 *
	 * @param array<array-key,mixed> $value Raw array.
	 * @return array<string,mixed>
	 */
	private static function string_keyed_array( array $value ): array {
		$result = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $item;
			}
		}

		return $result;
	}
}

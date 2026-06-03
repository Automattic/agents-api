<?php
/**
 * Runtime tool declaration validator.
 *
 * Runtime tools are declared by a client or transport for one agent run and
 * are executed by the client. This class only validates the declaration
 * shape; it intentionally does not register, expose, or execute those tools.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Validates scoped runtime tool declarations before policy integration.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exceptions are not rendered output.
class WP_Agent_Tool_Declaration {

	public const SOURCE_CLIENT   = 'client';
	public const EXECUTOR_CLIENT = 'client';
	public const EXECUTOR_HOST   = 'host';
	public const SCOPE_RUN       = 'run';

	/**
	 * Generic runtime metadata key for duplicate-call behavior.
	 */
	public const RUNTIME_DUPLICATE_POLICY = 'duplicate_policy';

	/**
	 * Generic runtime metadata key for progress/completion signaling.
	 */
	public const RUNTIME_COMPLETION_SIGNAL = 'completion_signal';

	/**
	 * Generic runtime metadata key for where a tool may be exposed.
	 */
	public const RUNTIME_CAPABILITY_SCOPE = 'capability_scope';

	/**
	 * Tool may be exposed inside a disposable runtime sandbox.
	 */
	public const CAPABILITY_SCOPE_SANDBOX_SAFE = 'sandbox_safe';

	/**
	 * Tool belongs to the parent/control-plane runtime and should stay out of sandboxes.
	 */
	public const CAPABILITY_SCOPE_PARENT_ONLY = 'parent_only';

	/**
	 * Generic runtime metadata key for the target execution environment.
	 */
	public const RUNTIME_ENVIRONMENT = 'environment';

	/**
	 * Tool declaration targets a disposable sandbox runtime.
	 */
	public const ENVIRONMENT_DISPOSABLE_SANDBOX = 'disposable_sandbox';

	/**
	 * Tool declaration targets a parent/control-plane runtime.
	 */
	public const ENVIRONMENT_PARENT_CONTROL = 'parent_control';

	/**
	 * Normalize a runtime tool declaration or throw a field-scoped error.
	 *
	 * @param array<mixed> $declaration Raw runtime tool declaration.
	 * @return array<mixed> Normalized declaration.
	 */
	public static function normalize( array $declaration ): array {
		$errors = self::validate( $declaration );
		if ( ! empty( $errors ) ) {
			$message = sprintf(
				'invalid_runtime_tool_declaration: %s',
				implode( ', ', self::sanitizeErrorKeys( $errors ) )
			);

			throw new \InvalidArgumentException(
				$message
			);
		}

		$name   = is_string( $declaration['name'] ?? null ) ? $declaration['name'] : '';
		$source = self::sourceFromName( $name );
		$description = $declaration['description'] ?? '';

		$normalized = array(
			'name'        => $name,
			'source'      => $source,
			'description' => is_string( $description ) ? trim( $description ) : '',
			'parameters'  => $declaration['parameters'] ?? array(),
			'executor'    => self::EXECUTOR_CLIENT,
			'scope'       => self::SCOPE_RUN,
		);

		$runtime = self::normalizeRuntimeMetadata( $declaration['runtime'] ?? array() );
		if ( ! empty( $runtime ) ) {
			$normalized['runtime'] = $runtime;
		}

		return $normalized;
	}

	/**
	 * Normalize a conversation-request tool declaration.
	 *
	 * Client runtime tools keep the strict `normalize()` contract. Host-owned
	 * declarations are request/replay catalog entries for tools executed by the
	 * host runtime rather than the client transport.
	 *
	 * @param array<mixed> $declaration Raw request tool declaration.
	 * @return array<mixed> Normalized declaration.
	 */
	public static function normalizeForConversationRequest( array $declaration ): array {
		$name     = is_string( $declaration['name'] ?? null ) ? $declaration['name'] : '';
		$executor = $declaration['executor'] ?? null;

		if ( self::EXECUTOR_CLIENT === $executor || self::SOURCE_CLIENT === self::sourceFromName( $name ) ) {
			return self::normalize( $declaration );
		}

		$errors = self::validateHostDeclaration( $declaration );
		if ( ! empty( $errors ) ) {
			$message = sprintf(
				'invalid_conversation_tool_declaration: %s',
				implode( ', ', self::sanitizeErrorKeys( $errors ) )
			);

			throw new \InvalidArgumentException(
				$message
			);
		}

		$source      = self::sourceFromName( $name );
		$description = $declaration['description'] ?? '';

		$normalized = array(
			'name'        => $name,
			'source'      => $source,
			'description' => is_string( $description ) ? trim( $description ) : '',
			'parameters'  => $declaration['parameters'] ?? array(),
			'executor'    => self::EXECUTOR_HOST,
			'scope'       => self::SCOPE_RUN,
		);

		$runtime = self::normalizeRuntimeMetadata( $declaration['runtime'] ?? array() );
		if ( ! empty( $runtime ) ) {
			$normalized['runtime'] = $runtime;
		}

		return $normalized;
	}

	/**
	 * Validate a runtime tool declaration without throwing.
	 *
	 * @param array<mixed> $declaration Raw runtime tool declaration.
	 * @return string[] Machine-readable invalid field names.
	 */
	public static function validate( array $declaration ): array {
		$errors = array();

		$name = $declaration['name'] ?? null;
		if (
			! is_string( $name )
			|| '' === $name
			|| ! preg_match( '/^[a-z][a-z0-9_-]*\/[a-z][a-z0-9_-]*$/', $name )
		) {
			$errors[] = 'name';
		}

		$source = is_string( $name ) ? self::sourceFromName( $name ) : '';
		if ( self::SOURCE_CLIENT !== $source ) {
			$errors[] = 'source';
		}

		if (
			isset( $declaration['source'] )
			&& $declaration['source'] !== $source
		) {
			$errors[] = 'source';
		}

		$description = $declaration['description'] ?? null;
		if ( ! is_string( $description ) || '' === trim( $description ) ) {
			$errors[] = 'description';
		}

		if (
			isset( $declaration['parameters'] )
			&& ! is_array( $declaration['parameters'] )
		) {
			$errors[] = 'parameters';
		}

		if ( ( $declaration['executor'] ?? null ) !== self::EXECUTOR_CLIENT ) {
			$errors[] = 'executor';
		}

		if ( ( $declaration['scope'] ?? null ) !== self::SCOPE_RUN ) {
			$errors[] = 'scope';
		}

		if ( isset( $declaration['runtime'] ) && ! is_array( $declaration['runtime'] ) ) {
			$errors[] = 'runtime';
		}

		return array_values( array_unique( $errors ) );
	}

	/**
	 * Validate a host-owned request/replay tool declaration without throwing.
	 *
	 * @param array<mixed> $declaration Raw host declaration.
	 * @return string[] Machine-readable invalid field names.
	 */
	private static function validateHostDeclaration( array $declaration ): array {
		$errors = array();

		$name = $declaration['name'] ?? null;
		if (
			! is_string( $name )
			|| '' === $name
			|| ! preg_match( '/^[a-z][a-z0-9_-]*\/[a-z][a-z0-9_-]*$/', $name )
		) {
			$errors[] = 'name';
		}

		$source = is_string( $name ) ? self::sourceFromName( $name ) : '';
		if ( '' === $source || self::SOURCE_CLIENT === $source ) {
			$errors[] = 'source';
		}

		if (
			isset( $declaration['source'] )
			&& $declaration['source'] !== $source
		) {
			$errors[] = 'source';
		}

		$description = $declaration['description'] ?? null;
		if ( ! is_string( $description ) || '' === trim( $description ) ) {
			$errors[] = 'description';
		}

		if (
			isset( $declaration['parameters'] )
			&& ! is_array( $declaration['parameters'] )
		) {
			$errors[] = 'parameters';
		}

		if ( ( $declaration['executor'] ?? null ) !== self::EXECUTOR_HOST ) {
			$errors[] = 'executor';
		}

		if ( ( $declaration['scope'] ?? null ) !== self::SCOPE_RUN ) {
			$errors[] = 'scope';
		}

		if ( isset( $declaration['runtime'] ) && ! is_array( $declaration['runtime'] ) ) {
			$errors[] = 'runtime';
		}

		return array_values( array_unique( $errors ) );
	}

	/**
	 * Normalize optional product-neutral runtime metadata.
	 *
	 * Runtime metadata is a JSON-friendly object used by agent loops and hosts to
	 * make generic execution decisions without hardcoding product tool names. The
	 * canonical keys are `duplicate_policy` and `completion_signal`, but callers
	 * may include additional product-neutral scalar/list values for future policy.
	 *
	 * @param mixed $runtime Raw runtime metadata.
	 * @return array<string, mixed> Normalized runtime metadata.
	 */
	public static function normalizeRuntimeMetadata( $runtime ): array {
		if ( ! is_array( $runtime ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $runtime as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}

			$normalized_value = self::normalizeRuntimeMetadataValue( $value );
			if ( null === $normalized_value ) {
				continue;
			}

			$normalized[ $key ] = $normalized_value;
		}

		return $normalized;
	}

	/**
	 * Normalize one JSON-friendly runtime metadata value.
	 *
	 * @param mixed $value Raw metadata value.
	 * @return mixed|null Normalized value, or null when unsupported.
	 */
	private static function normalizeRuntimeMetadataValue( $value ) {
		if ( is_string( $value ) || is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
			return $value;
		}

		if ( ! is_array( $value ) ) {
			return null;
		}

		$normalized = array();
		foreach ( $value as $key => $item ) {
			$normalized_item = self::normalizeRuntimeMetadataValue( $item );
			if ( null === $normalized_item ) {
				continue;
			}

			if ( is_string( $key ) ) {
				$normalized[ $key ] = $normalized_item;
			} else {
				$normalized[] = $normalized_item;
			}
		}

		return $normalized;
	}

	/**
	 * Build a namespaced runtime tool name.
	 *
	 * @param string $source Runtime tool source slug.
	 * @param string $tool_slug Tool slug local to the source.
	 * @return string Namespaced tool name.
	 */
	public static function namespacedName( string $source, string $tool_slug ): string {
		return $source . '/' . $tool_slug;
	}

	/**
	 * Extract the source prefix from a namespaced runtime tool name.
	 *
	 * @param string $name Runtime tool name.
	 * @return string Source prefix, or empty string when unnamespaced.
	 */
	public static function sourceFromName( string $name ): string {
		$parts = explode( '/', $name, 2 );
		return count( $parts ) === 2 ? $parts[0] : '';
	}

	/**
	 * Sanitize validator field names without requiring WordPress functions.
	 *
	 * @param string[] $errors Raw validator field names.
	 * @return string[] Sanitized field names.
	 */
	private static function sanitizeErrorKeys( array $errors ): array {
		return array_map(
			static function ( string $error ): string {
				$sanitized = preg_replace( '/[^a-z0-9_-]/', '', strtolower( $error ) );
				return is_string( $sanitized ) ? $sanitized : '';
			},
			$errors
		);
	}
}

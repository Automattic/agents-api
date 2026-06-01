<?php
/**
 * PHPStan class stubs for the WordPress 7.1 Abilities API.
 *
 * These symbols are not yet present in php-stubs/wordpress-stubs (which tracks
 * stable WordPress). They are provided here so static analysis can resolve the
 * Abilities API surface the substrate consumes. Signatures are intentionally
 * minimal — enough for type resolution, not a behavioral spec. Function stubs
 * live in abilities-api-functions.php (phpcs requires one file to contain either
 * OO structures or functions, not both).
 *
 * @see https://github.com/WordPress/abilities-api
 */

/**
 * A registered ability.
 */
class WP_Ability {

	/**
	 * @param string               $name Ability name.
	 * @param array<string, mixed> $args Ability arguments.
	 */
	public function __construct( string $name, array $args = array() ) {}

	public function get_name(): string {}

	public function get_label(): string {}

	public function get_description(): string {}

	public function get_category(): string {}

	/**
	 * @return array<string, mixed>
	 */
	public function get_input_schema(): array {}

	/**
	 * @return array<string, mixed>
	 */
	public function get_output_schema(): array {}

	/**
	 * @param mixed $input Ability input.
	 * @return mixed|\WP_Error
	 */
	public function execute( $input = null ) {}
}

/**
 * Registry of abilities.
 */
class WP_Abilities_Registry {

	public static function get_instance(): self {}

	/**
	 * @param string               $name Ability name.
	 * @param array<string, mixed> $args Ability arguments.
	 */
	public function register( string $name, array $args = array() ): ?WP_Ability {}

	public function unregister( string $name ): bool {}

	public function get_registered( string $name ): ?WP_Ability {}

	/**
	 * @return array<string, WP_Ability>
	 */
	public function get_all_registered(): array {}
}

/**
 * Per-invocation sentinel used by `wp_pre_execute_ability` to detect a
 * short-circuit return distinct from any real ability result.
 */
class WP_Filter_Sentinel {}

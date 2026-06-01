<?php
/**
 * PHPStan stubs for the WordPress 7.1 Abilities API.
 *
 * These symbols are not yet present in php-stubs/wordpress-stubs (which tracks
 * stable WordPress). They are provided here so static analysis can resolve the
 * Abilities API surface the substrate consumes. Signatures are intentionally
 * minimal — enough for type resolution, not a behavioral spec.
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

/**
 * @param string               $name Ability name.
 * @param array<string, mixed> $args Ability arguments.
 */
function wp_register_ability( string $name, array $args = array() ): ?WP_Ability {}

function wp_get_ability( string $name ): ?WP_Ability {}

function wp_has_ability( string $name ): bool {}

function wp_unregister_ability( string $name ): bool {}

/**
 * @return array<string, WP_Ability>
 */
function wp_get_abilities(): array {}

/**
 * @param string               $slug Category slug.
 * @param array<string, mixed> $args Category arguments.
 */
function wp_register_ability_category( string $slug, array $args = array() ): bool {}

function wp_has_ability_category( string $slug ): bool {}

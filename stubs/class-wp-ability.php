<?php
/**
 * PHPStan stub for the WordPress 7.1 Abilities API `WP_Ability` class.
 *
 * Not yet present in php-stubs/wordpress-stubs (which tracks stable WordPress).
 * Minimal signatures for type resolution only. See abilities-api-functions.php
 * for the function stubs.
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

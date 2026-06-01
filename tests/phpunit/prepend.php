<?php
/**
 * Prepend file for PHPUnit's Composer autoload boundary.
 *
 * PHPUnit loads Composer's autoloader before its configured bootstrap, and this
 * package autoloads the plugin entrypoint. Define the pure-PHP smoke stubs first
 * so the plugin can load outside WordPress during unit tests.
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__ ) . '/agents-api-smoke-helpers.php';

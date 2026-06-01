<?php
/**
 * PHPUnit bootstrap for pure-PHP Agents API unit tests.
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__ ) . '/agents-api-smoke-helpers.php';

$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
} else {
	agents_api_smoke_require_module();
}

<?php
/**
 * CLI regression for Composer's files autoloader outside a WordPress request.
 *
 * Run with: php tests/composer-autoload-smoke.php
 *
 * @package AgentsAPI\Tests
 */

echo "agents-api-composer-autoload-smoke\n";

require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( defined( 'AGENTS_API_LOADED' ) ) {
	fwrite( STDERR, "FAIL: Composer autoload initialized Agents API outside WordPress.\n" );
	exit( 1 );
}

echo "PASS: Composer autoload returns without WordPress.\n";

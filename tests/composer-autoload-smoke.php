<?php
/**
 * CLI regression for Composer's files autoloader outside a WordPress request.
 *
 * Run with: php tests/composer-autoload-smoke.php
 *
 * @package AgentsAPI\Tests
 */

echo "agents-api-composer-autoload-smoke\n";

$loader = require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( defined( 'AGENTS_API_LOADED' ) ) {
	fwrite( STDERR, "FAIL: Composer autoload initialized Agents API outside WordPress.\n" );
	exit( 1 );
}

$package_file = $loader->findFile( 'WP_Agent_Package' );
if ( ! is_string( $package_file ) || ! str_ends_with( str_replace( '\\', '/', $package_file ), '/src/Packages/class-wp-agent-package.php' ) ) {
	fwrite( STDERR, "FAIL: Composer classmap does not expose WP_Agent_Package.\n" );
	exit( 1 );
}

$outcome_file = $loader->findFile( 'AgentsAPI\\AI\\WP_Agent_Run_Outcome' );
if ( ! is_string( $outcome_file ) || ! str_ends_with( str_replace( '\\', '/', $outcome_file ), '/src/Runtime/class-wp-agent-run-outcome.php' ) ) {
	fwrite( STDERR, "FAIL: Composer classmap does not expose WP_Agent_Run_Outcome.\n" );
	exit( 1 );
}

if ( class_exists( 'WP_Agent_Package', false ) ) {
	fwrite( STDERR, "FAIL: Composer autoload eagerly loaded WP_Agent_Package outside WordPress.\n" );
	exit( 1 );
}

if ( class_exists( 'AgentsAPI\\AI\\WP_Agent_Run_Outcome', false ) ) {
	fwrite( STDERR, "FAIL: Composer autoload eagerly loaded WP_Agent_Run_Outcome outside WordPress.\n" );
	exit( 1 );
}

echo "PASS: Composer autoload returns without WordPress and exposes public classmaps lazily.\n";

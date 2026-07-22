<?php
/**
 * Regression for a newer Agents API copy following an older loaded copy.
 *
 * Run with: php tests/bootstrap-version-skew-smoke.php
 *
 * @package AgentsAPI\Tests
 */

require_once __DIR__ . '/agents-api-smoke-helpers.php';

$root     = dirname( __DIR__ );
$old_copy = sys_get_temp_dir() . '/agents-api-old-' . bin2hex( random_bytes( 8 ) );

/** @param string $source Source directory. @param string $destination Destination directory. */
function agents_api_copy_directory( string $source, string $destination ): void {
	mkdir( $destination, 0777, true );
	foreach ( new DirectoryIterator( $source ) as $entry ) {
		if ( $entry->isDot() ) {
			continue;
		}

		$target = $destination . '/' . $entry->getFilename();
		if ( $entry->isDir() ) {
			agents_api_copy_directory( $entry->getPathname(), $target );
			continue;
		}

		copy( $entry->getPathname(), $target );
	}
}

agents_api_copy_directory( $root . '/src', $old_copy . '/src' );
$old_bootstrap = (string) file_get_contents( $root . '/agents-api.php' );
if ( str_contains( $old_bootstrap, 'GLOB_BRACE' ) ) {
	fwrite( STDERR, "FAIL: bootstrap discovery depends on the optional GLOB_BRACE flag.\n" );
	exit( 1 );
}

$old_bootstrap = str_replace(
	"require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-channel.php';\n",
	'',
	$old_bootstrap
);
file_put_contents( $old_copy . '/agents-api.php', $old_bootstrap );

require $old_copy . '/agents-api.php';

if ( class_exists( 'AgentsAPI\\AI\\Channels\\WP_Agent_Channel', false ) ) {
	fwrite( STDERR, "FAIL: skew fixture unexpectedly loaded the newer class.\n" );
	exit( 1 );
}

$hook_count = count( $GLOBALS['__agents_api_smoke_actions'] );
require $root . '/agents-api.php';

if ( ! class_exists( 'AgentsAPI\\AI\\Channels\\WP_Agent_Channel', false ) ) {
	fwrite( STDERR, "FAIL: newer copy did not load its missing class.\n" );
	exit( 1 );
}

if ( $hook_count !== count( $GLOBALS['__agents_api_smoke_actions'] ) ) {
	fwrite( STDERR, "FAIL: newer copy repeated bootstrap side effects.\n" );
	exit( 1 );
}

echo "PASS: portable discovery tops up missing classes without repeating hooks.\n";

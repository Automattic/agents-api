<?php
/**
 * Pure-PHP smoke test for the optional safe execution workspace primitive.
 *
 * Run with: php tests/safe-execution-workspace-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "safe-execution-workspace-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

$GLOBALS['__agents_api_smoke_abilities']  = array();
$GLOBALS['__agents_api_smoke_categories'] = array();

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): array { return $this->data; }
}

function current_user_can( string $capability ): bool {
	unset( $capability );
	return true;
}

function wp_has_ability_category( string $category ): bool {
	return isset( $GLOBALS['__agents_api_smoke_categories'][ $category ] );
}

function wp_register_ability_category( string $category, array $args ): void {
	$GLOBALS['__agents_api_smoke_categories'][ $category ] = $args;
}

function wp_has_ability( string $ability ): bool {
	return isset( $GLOBALS['__agents_api_smoke_abilities'][ $ability ] );
}

function wp_register_ability( string $ability, array $args ): void {
	$GLOBALS['__agents_api_smoke_abilities'][ $ability ] = $args;
}

function agents_api_workspace_smoke_rm( string $path ): void {
	if ( ! is_dir( $path ) ) {
		return;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $entry ) {
		$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	}
	rmdir( $path );
}

agents_api_smoke_require_module();

$disabled_targets = AgentsAPI\AI\Tasks\agents_execution_targets();
agents_api_smoke_assert_equals( array(), $disabled_targets, 'workspace target is disabled by default', $failures, $passes );
agents_api_smoke_assert_equals( false, isset( $GLOBALS['__agents_api_smoke_abilities']['agents/workspace-prepare'] ), 'workspace abilities are not registered before opt-in', $failures, $passes );

$root = sys_get_temp_dir() . '/agents-api-safe-workspace-' . bin2hex( random_bytes( 4 ) );
mkdir( $root, 0755, true );

add_filter( 'agents_api_enable_blessed_workspace', static fn(): bool => true );
add_filter( 'agents_api_blessed_workspace_root', static fn(): string => $root );

do_action( 'wp_abilities_api_categories_init' );
do_action( 'wp_abilities_api_init' );

$targets = AgentsAPI\AI\Tasks\agents_execution_targets();
agents_api_smoke_assert_equals( 'agents-api/safe-execution-workspace', $targets[0]['id'] ?? '', 'workspace target registers when opted in', $failures, $passes );
agents_api_smoke_assert_equals( true, in_array( 'code.execution.safe-root', $targets[0]['capabilities'] ?? array(), true ), 'workspace target declares safe-root capability', $failures, $passes );
agents_api_smoke_assert_equals( true, $targets[0]['metadata']['isolated_from_site'] ?? false, 'workspace target declares site isolation', $failures, $passes );
agents_api_smoke_assert_equals( true, isset( $GLOBALS['__agents_api_smoke_abilities']['agents/workspace-prepare'] ), 'workspace prepare ability registers when opted in', $failures, $passes );
agents_api_smoke_assert_equals( true, isset( $GLOBALS['__agents_api_smoke_abilities']['agents/workspace-write-file'] ), 'workspace write ability registers when opted in', $failures, $passes );

$prepare = $GLOBALS['__agents_api_smoke_abilities']['agents/workspace-prepare']['execute_callback'];
$write   = $GLOBALS['__agents_api_smoke_abilities']['agents/workspace-write-file']['execute_callback'];
$read    = $GLOBALS['__agents_api_smoke_abilities']['agents/workspace-read-file']['execute_callback'];
$list    = $GLOBALS['__agents_api_smoke_abilities']['agents/workspace-list']['execute_callback'];

$prepared = call_user_func( $prepare, array( 'handle' => 'site-generation' ) );
agents_api_smoke_assert_equals( true, $prepared['success'] ?? false, 'prepare creates named workspace', $failures, $passes );
agents_api_smoke_assert_equals( true, is_dir( $root . '/site-generation' ), 'workspace directory exists under configured root', $failures, $passes );

$written = call_user_func(
	$write,
	array(
		'handle'  => 'site-generation',
		'path'    => 'artifacts/index.html',
		'content' => '<main>Hello</main>',
	)
);
agents_api_smoke_assert_equals( true, $written['success'] ?? false, 'write stores file inside workspace', $failures, $passes );

$read_result = call_user_func(
	$read,
	array(
		'handle' => 'site-generation',
		'path'   => 'artifacts/index.html',
	)
);
agents_api_smoke_assert_equals( '<main>Hello</main>', $read_result['content'] ?? '', 'read returns written workspace file', $failures, $passes );

$listed = call_user_func( $list, array() );
agents_api_smoke_assert_equals( 'site-generation', $listed['workspaces'][0]['handle'] ?? '', 'list returns prepared workspace', $failures, $passes );

$traversal = call_user_func(
	$write,
	array(
		'handle'  => 'site-generation',
		'path'    => '../escape.txt',
		'content' => 'nope',
	)
);
agents_api_smoke_assert_equals( true, $traversal instanceof WP_Error, 'write rejects parent traversal', $failures, $passes );
agents_api_smoke_assert_equals( false, file_exists( $root . '/escape.txt' ), 'traversal does not write outside workspace', $failures, $passes );

add_filter( 'agents_api_blessed_workspace_root', static fn(): string => dirname( __DIR__ ), 20 );

$blocked_targets = AgentsAPI\AI\Tasks\agents_execution_targets();
agents_api_smoke_assert_equals( array(), $blocked_targets, 'workspace target rejects site-containing root', $failures, $passes );

$blocked_prepare = call_user_func( $prepare, array( 'handle' => 'site-generation' ) );
agents_api_smoke_assert_equals( true, $blocked_prepare instanceof WP_Error, 'prepare rejects site-containing root', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_workspace_root_not_isolated', $blocked_prepare->get_error_code(), 'prepare returns isolated root error code', $failures, $passes );

agents_api_workspace_smoke_rm( $root );

agents_api_smoke_finish( 'safe execution workspace', $failures, $passes );

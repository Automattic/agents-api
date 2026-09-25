<?php
/**
 * Smoke test: expired built-in branch rows are swept, live ones are kept.
 *
 * Run: php tests/workflow-branch-store-sweep-smoke.php
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['options'] = array();
function get_option( string $option, $default = false ) {
	return array_key_exists( $option, $GLOBALS['options'] ) ? $GLOBALS['options'][ $option ] : $default;
}
function update_option( string $option, $value, $autoload = null ): bool {
	$GLOBALS['options'][ $option ] = $value;
	return true;
}
function add_option( string $option, $value = '', $deprecated = '', $autoload = null ): bool {
	if ( array_key_exists( $option, $GLOBALS['options'] ) ) {
		return false;
	}
	$GLOBALS['options'][ $option ] = $value;
	return true;
}
function delete_option( string $option ): bool {
	unset( $GLOBALS['options'][ $option ] );
	return true;
}

// Minimal wpdb: answers the sweeper's prefix scan from the option array.
$GLOBALS['wpdb'] = new class() {
	public string $options = 'wp_options';
	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}
	public function prepare( string $query, ...$args ): array {
		return $args;
	}
	public function get_col( array $prepared ): array {
		$prefix = stripslashes( rtrim( (string) $prepared[0], '%' ) );
		$names  = array_values( array_filter( array_keys( $GLOBALS['options'] ), static fn( $name ): bool => str_starts_with( $name, $prefix ) ) );
		return array_slice( $names, 0, (int) $prepared[1] );
	}
};

require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-branch-store.php';

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Branch_Store;

$passed = 0;
$failed = 0;
$check  = static function ( bool $ok, string $label ) use ( &$passed, &$failed ): void {
	echo ( $ok ? '  PASS ' : '  FAIL ' ) . $label . "\n";
	$ok ? ++$passed : ++$failed;
};

$past   = time() - 10;
$future = time() + 3600;
$GLOBALS['options'] = array(
	'agents_wf_branch_expired'          => array( 'expires' => $past ),
	'agents_wf_branch_live'             => array( 'expires' => $future ),
	'agents_wf_branch_ctx_expired'      => array( 'expires' => $past ),
	'agents_wf_branch_admission_old'    => array( 'status' => 'pending', 'expires' => $past ),
	'agents_wf_branch_index_orphan'     => array( 'agents_wf_branch_gone' ),
	'agents_wf_branch_index_live'       => array( 'agents_wf_branch_live' ),
	'agents_wf_branch_no_expiry'        => array( 'payload' => 1 ),
	'unrelated_option'                  => array( 'expires' => $past ),
);

$deleted = WP_Agent_Workflow_Branch_Store::sweep_expired();
$left    = array_keys( $GLOBALS['options'] );
sort( $left );

$check( 4 === $deleted, "deletes expired rows and the orphaned index ({$deleted})" );
$check(
	array( 'agents_wf_branch_index_live', 'agents_wf_branch_live', 'agents_wf_branch_no_expiry', 'unrelated_option' ) === $left,
	'keeps live rows, indexes with live refs, rows without expiry, and other options'
);

$GLOBALS['options'] = array( 'agents_wf_branch_expired' => array( 'expires' => $past ) );
WP_Agent_Workflow_Branch_Store::begin_admission( 'run-1' );
$check( ! isset( $GLOBALS['options']['agents_wf_branch_expired'] ), 'admission triggers a sweep' );

$GLOBALS['options']['agents_wf_branch_expired_again'] = array( 'expires' => $past );
WP_Agent_Workflow_Branch_Store::begin_admission( 'run-2' );
$check( isset( $GLOBALS['options']['agents_wf_branch_expired_again'] ), 'a second admission inside the window does not sweep again' );

echo "Passed: {$passed}, Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );

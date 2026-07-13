<?php
/**
 * Validate the Docs Agent consumer against its native producer chain.
 *
 * Run with DOCS_AGENT_DIR and WP_CODEBOX_DIR pointing at fresh producer checkouts.
 *
 * @package AgentsAPI\Tests
 */

declare( strict_types=1 );

$root           = dirname( __DIR__ );
$docs_agent_dir = getenv( 'DOCS_AGENT_DIR' );
$wp_codebox_dir = getenv( 'WP_CODEBOX_DIR' );
$failures       = array();

function agents_api_docs_agent_contract_assert( bool $condition, string $message, array &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

function agents_api_docs_agent_contract_read( $directory, string $path, array &$failures ): string {
	if ( ! is_string( $directory ) || '' === $directory || ! is_file( rtrim( $directory, '/' ) . '/' . $path ) ) {
		$failures[] = "Missing producer file: {$path}.";
		return '';
	}

	return (string) file_get_contents( rtrim( $directory, '/' ) . '/' . $path );
}

$consumer_workflow = (string) file_get_contents( $root . '/.github/workflows/docs-agent.yml' );

agents_api_docs_agent_contract_assert(
	( is_string( $docs_agent_dir ) && '' !== $docs_agent_dir ) === ( is_string( $wp_codebox_dir ) && '' !== $wp_codebox_dir ),
	'DOCS_AGENT_DIR and WP_CODEBOX_DIR must be configured together.',
	$failures
);

if ( is_string( $docs_agent_dir ) && '' !== $docs_agent_dir && is_string( $wp_codebox_dir ) && '' !== $wp_codebox_dir ) {
	$docs_workflow     = agents_api_docs_agent_contract_read( $docs_agent_dir, '.github/workflows/maintain-docs.yml', $failures );
	$wp_codebox_schema = agents_api_docs_agent_contract_read( $wp_codebox_dir, 'contracts/run-agent-task-reusable-workflow-interface.v1.json', $failures );
	$wp_codebox_schema = json_decode( $wp_codebox_schema, true );

	agents_api_docs_agent_contract_assert( is_array( $wp_codebox_schema ), 'WP Codebox producer schema must be valid JSON.', $failures );
	agents_api_docs_agent_contract_assert( 'wp-codebox/reusable-workflow-interface/v1' === ( $wp_codebox_schema['schema'] ?? null ), 'WP Codebox producer schema version must match.', $failures );
	agents_api_docs_agent_contract_assert( isset( $wp_codebox_schema['inputs']['external_package_source'] ), 'WP Codebox must require an external native package source.', $failures );
	agents_api_docs_agent_contract_assert( isset( $wp_codebox_schema['secrets']['EXTERNAL_PACKAGE_SOURCE_POLICY'] ), 'WP Codebox must require the external package source policy.', $failures );
	agents_api_docs_agent_contract_assert( str_contains( $docs_workflow, 'technical-docs-maintenance-agent.agent.json' ), 'Docs Agent must map technical maintenance to its native package.', $failures );
	agents_api_docs_agent_contract_assert( str_contains( $docs_workflow, 'external_package_source:' ), 'Docs Agent must pass a native external package source to its runner.', $failures );
	agents_api_docs_agent_contract_assert( str_contains( $docs_workflow, 'EXTERNAL_PACKAGE_SOURCE_POLICY: ${{ secrets.EXTERNAL_PACKAGE_SOURCE_POLICY }}' ), 'Docs Agent must forward the external package source policy.', $failures );
	agents_api_docs_agent_contract_assert( str_contains( $docs_workflow, 'ACCESS_TOKEN: ${{ secrets.ACCESS_TOKEN }}' ), 'Docs Agent must forward the publication token.', $failures );
}

foreach (
	array(
		'uses: Automattic/docs-agent/.github/workflows/maintain-docs.yml@main',
		'audience: technical',
		'run_kind: maintenance',
		'base_ref: main',
		'docs_branch: docs-agent/agents-api-docs-upkeep',
		'writable_paths: README.md,docs/**',
		'"composer test"',
		'"php tests/no-product-imports-smoke.php"',
		'"git diff --check"',
		'ACCESS_TOKEN: ${{ secrets.ACCESS_TOKEN }}',
		'EXTERNAL_PACKAGE_SOURCE_POLICY: ${{ secrets.EXTERNAL_PACKAGE_SOURCE_POLICY }}',
	) as $required_fragment ) {
	agents_api_docs_agent_contract_assert( str_contains( $consumer_workflow, $required_fragment ), "Docs Agent consumer must include {$required_fragment}.", $failures );
}

foreach ( array( 'openai_model:', 'docs_agent_ref:', 'run_agent:', 'agent_bundle:', 'validation_dependencies:', 'context_repositories:', 'bootstrap_contract:' ) as $obsolete_fragment ) {
	agents_api_docs_agent_contract_assert( ! str_contains( $consumer_workflow, $obsolete_fragment ), "Docs Agent consumer must not retain obsolete input {$obsolete_fragment}.", $failures );
}

if ( $failures ) {
	fwrite( STDERR, "Docs Agent native workflow contract failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

fwrite( STDOUT, "Docs Agent native workflow contract passed.\n" );

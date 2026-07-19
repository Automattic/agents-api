<?php
/**
 * Validate the Docs Agent consumer against its pinned native producer chain.
 *
 * Run with DOCS_AGENT_DIR at Docs Agent #165's merge commit and WP_CODEBOX_DIR
 * at WP Codebox v0.12.29. This test intentionally fails without
 * both checkouts because it verifies the producer interfaces, not just copies
 * of their expected values in this repository.
 *
 * @package AgentsAPI\Tests
 */

declare( strict_types=1 );

const AGENTS_API_DOCS_AGENT_REVISION = '06a7e92e0f4d265d09bbdb6dae1ec78fd8e7c825';
const AGENTS_API_WP_CODEBOX_REF      = 'v0.12.29';
const AGENTS_API_WP_CODEBOX_REVISION = 'bc982947ec33c78160125026e16d357b7ece3ea1';

$root     = dirname( __DIR__ );
$failures = array();

function agents_api_docs_agent_contract_assert( bool $condition, string $message, array &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

function agents_api_docs_agent_contract_directory( string $name, array &$failures ): string {
	$directory = getenv( $name );
	if ( ! is_string( $directory ) || '' === $directory || ! is_dir( $directory ) ) {
		$failures[] = sprintf(
			'%1$s must point to the pinned producer checkout. Run DOCS_AGENT_DIR=/path/to/docs-agent WP_CODEBOX_DIR=/path/to/wp-codebox php tests/docs-agent-native-workflow-contract.php.',
			$name
		);
		return '';
	}

	return rtrim( $directory, '/' );
}

function agents_api_docs_agent_contract_read( string $directory, string $path, array &$failures ): string {
	$file = $directory . '/' . $path;
	if ( ! is_file( $file ) ) {
		$failures[] = "Missing producer file: {$path}.";
		return '';
	}

	return (string) file_get_contents( $file );
}

function agents_api_docs_agent_contract_revision( string $directory, string $expected, string $name, array &$failures ): void {
	$revision = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $directory ) . ' rev-parse HEAD 2>/dev/null' ) );
	agents_api_docs_agent_contract_assert( $expected === $revision, "{$name} must be checked out at {$expected}; found {$revision}.", $failures );
}

function agents_api_docs_agent_contract_match( string $content, string $pattern, string $message, array &$failures ): void {
	agents_api_docs_agent_contract_assert( 1 === preg_match( $pattern, $content ), $message, $failures );
}

$docs_agent_dir = agents_api_docs_agent_contract_directory( 'DOCS_AGENT_DIR', $failures );
$wp_codebox_dir = agents_api_docs_agent_contract_directory( 'WP_CODEBOX_DIR', $failures );

if ( $failures ) {
	fwrite( STDERR, "Docs Agent native workflow contract failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

agents_api_docs_agent_contract_revision( $docs_agent_dir, AGENTS_API_DOCS_AGENT_REVISION, 'Docs Agent', $failures );
agents_api_docs_agent_contract_revision( $wp_codebox_dir, AGENTS_API_WP_CODEBOX_REVISION, 'WP Codebox', $failures );

$consumer_workflow = (string) file_get_contents( $root . '/.github/workflows/docs-agent.yml' );
$docs_workflow     = agents_api_docs_agent_contract_read( $docs_agent_dir, '.github/workflows/maintain-docs.yml', $failures );
$schema_json       = agents_api_docs_agent_contract_read( $wp_codebox_dir, 'contracts/run-agent-task-reusable-workflow-interface.v1.json', $failures );
$schema            = json_decode( $schema_json, true );

agents_api_docs_agent_contract_assert( is_array( $schema ), 'WP Codebox reusable-workflow schema must be valid JSON.', $failures );
if ( is_array( $schema ) ) {
	agents_api_docs_agent_contract_assert( 'wp-codebox/reusable-workflow-interface/v1' === ( $schema['schema'] ?? null ), 'WP Codebox reusable-workflow schema version must match.', $failures );

	foreach (
		array(
			'wp_codebox_release_ref'  => array( true, 'string' ),
			'external_package_source' => array( true, 'string' ),
			'runtime_sources'         => array( false, 'string' ),
			'target_repo'             => array( true, 'string' ),
			'prompt'                  => array( true, 'string' ),
			'writable_paths'          => array( false, 'string' ),
			'verification_commands'   => array( false, 'string' ),
			'drift_checks'            => array( false, 'string' ),
			'success_requires_pr'     => array( false, 'boolean' ),
			'access_token_repos'      => array( false, 'string' ),
			'allowed_repos'           => array( false, 'string' ),
			'run_agent'               => array( false, 'boolean' ),
			'dry_run'                 => array( false, 'boolean' ),
		) as $input => $contract
	) {
		agents_api_docs_agent_contract_assert( $contract[0] === ( $schema['inputs'][ $input ]['required'] ?? null ), "WP Codebox {$input} required contract must match.", $failures );
		agents_api_docs_agent_contract_assert( $contract[1] === ( $schema['inputs'][ $input ]['type'] ?? null ), "WP Codebox {$input} type contract must match.", $failures );
	}

	foreach ( array( 'OPENAI_API_KEY' => false, 'ACCESS_TOKEN' => false, 'EXTERNAL_PACKAGE_SOURCE_POLICY' => true ) as $secret => $required ) {
		agents_api_docs_agent_contract_assert( $required === ( $schema['secrets'][ $secret ]['required'] ?? null ), "WP Codebox {$secret} secret contract must match.", $failures );
	}
}

agents_api_docs_agent_contract_match( $consumer_workflow, '~^\s*uses:\s*Automattic/docs-agent/\.github/workflows/maintain-docs\.yml@' . AGENTS_API_DOCS_AGENT_REVISION . '\s*$~m', 'Consumer must pin Docs Agent #165.', $failures );
agents_api_docs_agent_contract_match( $consumer_workflow, '~^\s*audience:\s*technical\s*$~m', 'Consumer audience must be technical.', $failures );
agents_api_docs_agent_contract_match( $consumer_workflow, '~^\s*run_kind:\s*maintenance\s*$~m', 'Consumer run kind must be maintenance.', $failures );
agents_api_docs_agent_contract_match( $consumer_workflow, '~^\s*base_ref:\s*main\s*$~m', 'Consumer target base must be main.', $failures );
agents_api_docs_agent_contract_match( $consumer_workflow, '~^\s*docs_branch:\s*docs-agent/agents-api-docs-upkeep\s*$~m', 'Consumer publication branch must be stable and exact.', $failures );
agents_api_docs_agent_contract_match( $consumer_workflow, '~^\s*writable_paths:\s*README\.md,docs/\*\*\s*$~m', 'Consumer writable paths must be README.md,docs/**.', $failures );
agents_api_docs_agent_contract_match( $consumer_workflow, '~"composer install --no-interaction --prefer-dist --no-progress"\s*,\s*"composer test"\s*,\s*"php tests/no-product-imports-smoke\.php"~s', 'Consumer verification commands must hydrate dependencies before the test chain.', $failures );
agents_api_docs_agent_contract_match( $consumer_workflow, '~"git diff --check"~', 'Consumer drift check must run git diff --check.', $failures );
agents_api_docs_agent_contract_match( $consumer_workflow, '~^\s*OPENAI_API_KEY:\s*\$\{\{ secrets\.OPENAI_API_KEY \}\}\s*$~m', 'Consumer must forward OPENAI_API_KEY.', $failures );
agents_api_docs_agent_contract_match( $consumer_workflow, '~^\s*EXTERNAL_PACKAGE_SOURCE_POLICY:\s*\$\{\{ secrets\.EXTERNAL_PACKAGE_SOURCE_POLICY \}\}\s*$~m', 'Consumer must forward EXTERNAL_PACKAGE_SOURCE_POLICY.', $failures );
agents_api_docs_agent_contract_assert( 0 === preg_match( '~secrets\.ACCESS_TOKEN|^\s*ACCESS_TOKEN:\s*~m', $consumer_workflow ), 'Consumer must not require or forward ACCESS_TOKEN.', $failures );

foreach ( array( 'contents: write', 'pull-requests: write', 'issues: write' ) as $permission ) {
	agents_api_docs_agent_contract_match( $consumer_workflow, '~^\s*' . preg_quote( $permission, '~' ) . '\s*$~m', "Consumer must grant {$permission} for built-in-token publication.", $failures );
}

agents_api_docs_agent_contract_match( $docs_workflow, '~technical:maintenance\)\s+package_path="bundles/technical-docs-agent/native/technical-docs-maintenance-agent\.agent\.json"\s+agent_slug="technical-docs-maintenance-agent"\s+package_digest="sha256-bytes-v1:78fef9f8d787866c7b48b8f044769d38c0528778c8e2a82af816f9f8ea65014f"\s+lane_requires_pr=false~s', 'Docs Agent #165 technical maintenance lane must map to its exact native package.', $failures );
agents_api_docs_agent_contract_match( $docs_workflow, '~uses:\s*Automattic/wp-codebox/\.github/workflows/run-agent-task\.yml@' . preg_quote( AGENTS_API_WP_CODEBOX_REF, '~' ) . '~', 'Docs Agent must pin WP Codebox v0.12.29.', $failures );
agents_api_docs_agent_contract_match( $docs_workflow, '~wp_codebox_release_ref:\s*' . preg_quote( AGENTS_API_WP_CODEBOX_REF, '~' ) . '.*?external_package_source:\s*\$\{\{ needs\.prepare\.outputs\.external_package_source \}\}.*?runtime_sources:\s*\$\{\{ needs\.prepare\.outputs\.runtime_sources \}\}.*?target_repo:\s*\$\{\{ github\.repository \}\}.*?writable_paths:\s*\$\{\{ inputs\.writable_paths \}\}.*?verification_commands:\s*\$\{\{ needs\.prepare\.outputs\.verification_commands \}\}.*?drift_checks:\s*\$\{\{ needs\.prepare\.outputs\.drift_checks \}\}.*?success_requires_pr:\s*\$\{\{ needs\.prepare\.outputs\.success_requires_pr == \'true\' \}\}.*?access_token_repos:\s*\$\{\{ github\.repository \}\}.*?allowed_repos:\s*\'\["\$\{\{ github\.repository \}\}"\]\'~s', 'Docs Agent must preserve the WP Codebox release, external-package, runtime-source, target, writable, verification, publication, and access chain.', $failures );
agents_api_docs_agent_contract_match( $docs_workflow, '~OPENAI_API_KEY:\s*\$\{\{ secrets\.OPENAI_API_KEY \}\}\s+ACCESS_TOKEN:\s*\$\{\{ github\.token \}\}\s+EXTERNAL_PACKAGE_SOURCE_POLICY:\s*\$\{\{ secrets\.EXTERNAL_PACKAGE_SOURCE_POLICY \}\}~s', 'Docs Agent must forward caller credentials and its built-in publication token.', $failures );

if ( $failures ) {
	fwrite( STDERR, "Docs Agent native workflow contract failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

fwrite( STDOUT, "Docs Agent native workflow contract passed.\n" );

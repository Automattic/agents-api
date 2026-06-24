<?php
/**
 * Static smoke test proving Agents API stays product-free, UI-free, and table-free.
 *
 * Run with: php tests/no-product-imports-smoke.php
 *
 * @package AgentsAPI\Tests
 */

$failures = array();
$passes   = 0;

echo "agents-api-no-product-imports-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

$agents_api_dir = realpath( __DIR__ . '/..' );
agents_api_smoke_assert_equals( true, is_string( $agents_api_dir ), 'agents-api directory exists', $failures, $passes );

$production_paths = array(
	'agents-api.php' => $agents_api_dir . '/agents-api.php',
	'src'            => $agents_api_dir . '/src',
);

$package_example_paths = array(
	'README.md'                      => $agents_api_dir . '/README.md',
	'docs/registry-and-packages.md'  => $agents_api_dir . '/docs/registry-and-packages.md',
);

$forbidden_namespaces = array(
	'ExampleProduct\\Core\\Steps',
	'ExampleProduct\\Core\\Database\\Jobs',
	'ExampleProduct\\Core\\Admin',
	'ExampleProduct\\Core\\Assets',
	'ExampleProduct\\Engine\\Handlers',
	'ExampleProduct\\Core\\ActionScheduler',
	'ExampleProduct\\Engine\\AI\\System\\Tasks\\Retention',
	'ExampleProduct\\Engine\\Pipelines',
	'ExampleProduct\\Engine\\Flows',
	'ExampleProduct\\Engine\\Queue',
	'ExampleProduct\\Core\\Content',
);

$forbidden_product_vocabulary = array(
	'Data Machine',
	'DataMachine',
	'data machine',
	'datamachine',
	'data-machine',
	'data_machine',
	'Homeboy',
	'homeboy',
	'Acme Runner',
	'Acme_Runner',
	'acme-runner',
	'acme_runner',
	'wp-site-generator',
	'wp_site_generator',
	'wp site generator',
	'wp-site generator',
	'WPSG',
	'wpsg',
);

$forbidden_admin_apis = array(
	'add_menu_page',
	'add_submenu_page',
	'add_options_page',
	'add_management_page',
	'add_dashboard_page',
	'add_theme_page',
	'add_plugins_page',
	'register_setting',
);

$forbidden_admin_hooks = array(
	'admin_menu',
	'network_admin_menu',
	'user_admin_menu',
	'admin_init',
	'admin_enqueue_scripts',
	'admin_post_',
);

$forbidden_table_ownership_patterns = array(
	'/\bdbDelta\s*\(/i'                       => 'runs dbDelta',
	'/\bCREATE\s+(?:TEMPORARY\s+)?TABLE\b/i' => 'creates database tables',
	'/\bregister_activation_hook\s*\(/i'       => 'registers activation hook',
);

$matches = array();
$files   = array();
foreach ( $production_paths as $path ) {
	if ( is_file( $path ) ) {
		$files[] = new SplFileInfo( $path );
		continue;
	}

	if ( ! is_dir( $path ) ) {
		continue;
	}

	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'php' === $file->getExtension() ) {
			$files[] = $file;
		}
	}
}

foreach ( $files as $file ) {
	$relative_path = str_replace( (string) $agents_api_dir . '/', '', $file->getPathname() );
	$source        = (string) file_get_contents( $file->getPathname() );

	foreach ( $forbidden_namespaces as $namespace ) {
		$quoted = preg_quote( $namespace, '/' );
		if ( preg_match( '/(?:use\s+|new\s+|extends\s+|implements\s+|instanceof\s+|\\\\)' . $quoted . '(?:\\\\|;|\s|\(|::)/', $source ) ) {
			$matches[] = $relative_path . ' imports ' . $namespace;
		}
	}

	if ( preg_match( '/(?:use\s+|new\s+|extends\s+|implements\s+|instanceof\s+)\\?ExampleProduct\\\\/', $source ) ) {
		$matches[] = $relative_path . ' imports an ExampleProduct namespace';
	}

	foreach ( $forbidden_admin_apis as $function_name ) {
		if ( preg_match( '/\\b' . preg_quote( $function_name, '/' ) . '\\s*\(/', $source ) ) {
			$matches[] = $relative_path . ' registers admin UI via ' . $function_name;
		}
	}

	foreach ( $forbidden_admin_hooks as $hook_name ) {
		if ( preg_match( '/add_action\s*\(\s*[\'\"]' . preg_quote( $hook_name, '/' ) . '/', $source ) ) {
			$matches[] = $relative_path . ' registers admin hook ' . $hook_name;
		}
	}

	foreach ( $forbidden_product_vocabulary as $term ) {
		if ( preg_match( '/(?<![A-Za-z0-9_])' . preg_quote( $term, '/' ) . '(?![A-Za-z0-9_])/i', $source ) ) {
			$matches[] = $relative_path . ' contains downstream product vocabulary: ' . $term;
		}
	}

	foreach ( $forbidden_table_ownership_patterns as $pattern => $reason ) {
		if ( preg_match( $pattern, $source ) ) {
			$matches[] = $relative_path . ' ' . $reason;
		}
	}
}

agents_api_smoke_assert_equals( array(), $matches, 'agents-api production source has no product imports, product vocabulary, admin UI registrations, or table ownership', $failures, $passes );

$package_example_matches = array();
foreach ( $package_example_paths as $relative_path => $path ) {
	if ( ! is_file( $path ) ) {
		continue;
	}

	$source = (string) file_get_contents( $path );
	foreach ( $forbidden_product_vocabulary as $term ) {
		if ( preg_match( '/(?<![A-Za-z0-9_])' . preg_quote( $term, '/' ) . '(?![A-Za-z0-9_])/i', $source ) ) {
			$package_example_matches[] = $relative_path . ' package examples contain downstream product vocabulary: ' . $term;
		}
	}
}

agents_api_smoke_assert_equals( array(), $package_example_matches, 'package documentation examples use neutral artifact and package vocabulary', $failures, $passes );
agents_api_smoke_assert_equals( $forbidden_namespaces, array_values( array_unique( $forbidden_namespaces ) ), 'forbidden namespace list has no duplicates', $failures, $passes );
agents_api_smoke_assert_equals( $forbidden_product_vocabulary, array_values( array_unique( $forbidden_product_vocabulary ) ), 'forbidden product vocabulary list has no duplicates', $failures, $passes );
agents_api_smoke_assert_equals( $forbidden_admin_apis, array_values( array_unique( $forbidden_admin_apis ) ), 'forbidden admin API list has no duplicates', $failures, $passes );
agents_api_smoke_assert_equals( $forbidden_admin_hooks, array_values( array_unique( $forbidden_admin_hooks ) ), 'forbidden admin hook list has no duplicates', $failures, $passes );

agents_api_smoke_finish( 'Agents API no-product-imports', $failures, $passes );

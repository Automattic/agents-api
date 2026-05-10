<?php
/**
 * Validate relative Markdown links for a generated docs branch.
 *
 * Usage: php tests/playground-ci/scripts/validate-docs-links.php <repo-path> <git-ref>
 */

declare( strict_types=1 );

$repo = $argv[1] ?? '';
$ref  = $argv[2] ?? '';

if ( '' === $repo || '' === $ref ) {
	fwrite( STDERR, "Usage: php validate-docs-links.php <repo-path> <git-ref>\n" );
	exit( 2 );
}

$run_git = static function ( array $args ) use ( $repo ): string {
	$command = 'git -C ' . escapeshellarg( $repo );
	foreach ( $args as $arg ) {
		$command .= ' ' . escapeshellarg( (string) $arg );
	}

	$output = array();
	$status = 0;
	exec( $command, $output, $status );
	if ( 0 !== $status ) {
		throw new RuntimeException( "Git command failed: {$command}" );
	}

	return implode( "\n", $output );
};

$normalize_path = static function ( string $path ): string {
	$parts = array();
	foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $part ) {
		if ( '' === $part || '.' === $part ) {
			continue;
		}
		if ( '..' === $part ) {
			array_pop( $parts );
			continue;
		}
		$parts[] = $part;
	}
	return implode( '/', $parts );
};

$tree_output = $run_git( array( 'ls-tree', '-r', '--name-only', $ref ) );
$all_files   = array_filter( explode( "\n", $tree_output ) );
$file_set    = array_fill_keys( $all_files, true );
$markdown_files = array_values(
	array_filter(
		$all_files,
		static fn( string $file ): bool => ( 'README.md' === $file || str_starts_with( $file, 'docs/' ) ) && str_ends_with( strtolower( $file ), '.md' )
	)
);

$missing = array();
foreach ( $markdown_files as $file ) {
	$content = $run_git( array( 'show', $ref . ':' . $file ) );
	if ( ! preg_match_all( '/(?<!!)\[[^\]]*\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', $content, $matches ) ) {
		continue;
	}

	$base_dir = dirname( $file );
	$base_dir = '.' === $base_dir ? '' : $base_dir;
	foreach ( $matches[1] as $target ) {
		$target = trim( html_entity_decode( $target, ENT_QUOTES | ENT_HTML5 ) );
		if ( '' === $target || str_starts_with( $target, '#' ) || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $target ) || str_starts_with( $target, '/' ) ) {
			continue;
		}

		$target = preg_replace( '/[?#].*$/', '', $target );
		$target = rawurldecode( $target );
		$resolved = $normalize_path( ( '' === $base_dir ? '' : $base_dir . '/' ) . $target );
		$candidates = array( $resolved );
		if ( ! str_ends_with( strtolower( $resolved ), '.md' ) ) {
			$candidates[] = $resolved . '.md';
			$candidates[] = rtrim( $resolved, '/' ) . '/README.md';
		}

		$exists = false;
		foreach ( $candidates as $candidate ) {
			if ( isset( $file_set[ $candidate ] ) ) {
				$exists = true;
				break;
			}
		}

		if ( ! $exists ) {
			$missing[] = "{$file} -> {$target}";
		}
	}
}

if ( ! empty( $missing ) ) {
	fwrite( STDERR, "Broken relative Markdown links found:\n" );
	foreach ( $missing as $link ) {
		fwrite( STDERR, "- {$link}\n" );
	}
	exit( 1 );
}

fwrite( STDOUT, "Docs Markdown link validation passed.\n" );

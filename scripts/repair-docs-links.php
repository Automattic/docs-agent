<?php
/**
 * Repair generated documentation links in a target repository branch.
 *
 * Usage: php scripts/repair-docs-links.php <repo-path> <branch> [--commit]
 */

declare( strict_types=1 );

$repo   = $argv[1] ?? '';
$branch = $argv[2] ?? '';
$commit = in_array( '--commit', $argv, true );

if ( '' === $repo || '' === $branch ) {
	fwrite( STDERR, "Usage: php repair-docs-links.php <repo-path> <branch> [--commit]\n" );
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

$is_relative_doc_link = static function ( string $target ): bool {
	$target = trim( html_entity_decode( $target, ENT_QUOTES | ENT_HTML5 ) );
	return '' !== $target && ! str_starts_with( $target, '#' ) && ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $target ) && ! str_starts_with( $target, '/' );
};

$run_git( array( 'fetch', 'origin', $branch ) );
$run_git( array( 'checkout', '-B', $branch, 'origin/' . $branch ) );

$tracked_output = $run_git( array( 'ls-files' ) );
$all_files      = array_values( array_filter( explode( "\n", $tracked_output ) ) );
$file_set       = array_fill_keys( $all_files, true );
$markdown_files = array_values(
	array_filter(
		$all_files,
		static fn( string $file ): bool => ( 'README.md' === $file || str_starts_with( $file, 'docs/' ) ) && str_ends_with( strtolower( $file ), '.md' )
	)
);

$link_exists = static function ( string $file, string $target ) use ( $file_set, $normalize_path ): bool {
	$target = preg_replace( '/[?#].*$/', '', $target );
	$target = rawurldecode( (string) $target );
	$base_dir = dirname( $file );
	$base_dir = '.' === $base_dir ? '' : $base_dir;
	$resolved = $normalize_path( ( '' === $base_dir ? '' : $base_dir . '/' ) . $target );

	$candidates = array( $resolved );
	if ( ! str_ends_with( strtolower( $resolved ), '.md' ) ) {
		$candidates[] = $resolved . '.md';
		$candidates[] = rtrim( $resolved, '/' ) . '/README.md';
	}

	foreach ( $candidates as $candidate ) {
		if ( isset( $file_set[ $candidate ] ) ) {
			return true;
		}
	}

	return false;
};

$repaired = array();
foreach ( $markdown_files as $file ) {
	$path = rtrim( $repo, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $file );
	$content = (string) file_get_contents( $path );
	$next = preg_replace_callback(
		'/(?<!!)(\[[^\]]+\])\(([^)\s]+)(?:\s+"[^"]*")?\)/',
		static function ( array $matches ) use ( $file, $is_relative_doc_link, $link_exists, &$repaired ): string {
			$label = $matches[1];
			$target = trim( html_entity_decode( $matches[2], ENT_QUOTES | ENT_HTML5 ) );
			if ( ! $is_relative_doc_link( $target ) || $link_exists( $file, $target ) ) {
				return $matches[0];
			}

			$repaired[] = "{$file} -> {$target}";
			return substr( $label, 1, -1 );
		},
		$content
	);

	if ( is_string( $next ) && $next !== $content ) {
		file_put_contents( $path, $next );
	}
}

if ( empty( $repaired ) ) {
	fwrite( STDOUT, "Docs Markdown link repair found no broken relative links.\n" );
	exit( 0 );
}

fwrite( STDOUT, "Repaired broken relative Markdown links:\n" );
foreach ( $repaired as $link ) {
	fwrite( STDOUT, "- {$link}\n" );
}

if ( $commit ) {
	$run_git( array( 'add', 'README.md', 'docs' ) );
	$status = trim( $run_git( array( 'status', '--short' ) ) );
	if ( '' !== $status ) {
		$run_git( array( 'config', 'user.name', 'github-actions[bot]' ) );
		$run_git( array( 'config', 'user.email', '41898282+github-actions[bot]@users.noreply.github.com' ) );
		$run_git( array( 'commit', '-m', 'Repair generated docs links' ) );
		$run_git( array( 'push', 'origin', 'HEAD:' . $branch ) );
	}
}

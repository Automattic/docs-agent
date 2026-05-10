<?php
/**
 * Smoke-test generated docs link repair.
 *
 * Run: php tests/repair-docs-links-smoke.php
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );
$tmp  = sys_get_temp_dir() . '/docs-agent-link-repair-' . bin2hex( random_bytes( 4 ) );
$repo = $tmp . '/repo';
$bare = $tmp . '/remote.git';

$run = static function ( string $command ): void {
	exec( $command . ' 2>&1', $output, $status );
	if ( 0 !== $status ) {
		throw new RuntimeException( "Command failed: {$command}\n" . implode( "\n", $output ) );
	}
};

$run( 'mkdir -p ' . escapeshellarg( $repo . '/docs/technical' ) );
$run( 'git init --bare ' . escapeshellarg( $bare ) );
$run( 'git init ' . escapeshellarg( $repo ) );
$run( 'git -C ' . escapeshellarg( $repo ) . ' config user.name ' . escapeshellarg( 'Docs Agent Test' ) );
$run( 'git -C ' . escapeshellarg( $repo ) . ' config user.email ' . escapeshellarg( 'docs-agent-test@example.com' ) );
$run( 'git -C ' . escapeshellarg( $repo ) . ' remote add origin ' . escapeshellarg( $bare ) );

file_put_contents( $repo . '/README.md', "# Test\n" );
file_put_contents( $repo . '/docs/technical/index.md', "# Index\n\nSee [Architecture](architecture.md) and [Future](future.md).\n" );
file_put_contents( $repo . '/docs/technical/architecture.md', "# Architecture\n" );

$run( 'git -C ' . escapeshellarg( $repo ) . ' add README.md docs' );
$run( 'git -C ' . escapeshellarg( $repo ) . ' commit -m ' . escapeshellarg( 'Seed docs' ) );
$run( 'git -C ' . escapeshellarg( $repo ) . ' branch -M docs-agent/technical-bootstrap-docs' );
$run( 'git -C ' . escapeshellarg( $repo ) . ' push -u origin docs-agent/technical-bootstrap-docs' );

$run( 'php ' . escapeshellarg( $root . '/scripts/repair-docs-links.php' ) . ' ' . escapeshellarg( $repo ) . ' ' . escapeshellarg( 'docs-agent/technical-bootstrap-docs' ) );

$content = (string) file_get_contents( $repo . '/docs/technical/index.md' );
if ( ! str_contains( $content, '[Architecture](architecture.md)' ) ) {
	throw new RuntimeException( 'Existing relative doc link should remain linked.' );
}
if ( str_contains( $content, '[Future](future.md)' ) ) {
	throw new RuntimeException( 'Missing relative doc link should be converted to text.' );
}
if ( ! str_contains( $content, 'Future' ) ) {
	throw new RuntimeException( 'Missing relative doc link label should remain readable.' );
}

$run( 'rm -rf ' . escapeshellarg( $tmp ) );

fwrite( STDOUT, "Docs link repair smoke test passed.\n" );

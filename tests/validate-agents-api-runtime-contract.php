<?php
/**
 * Validate the immutable Agents API capability baseline for native packages.
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$fixture_path = $root . '/tests/agents-api-runtime-capability.fixture.json';
$fixture      = json_decode( (string) file_get_contents( $fixture_path ), true );
$assert( is_array( $fixture ), 'Agents API runtime capability fixture must be valid JSON.' );
$assert( 'docs-agent/agents-api-runtime-capability/v1' === ( $fixture['schema'] ?? null ), 'Agents API runtime capability fixture schema mismatch.' );
$revision = $fixture['revision'] ?? null;
$assert( is_string( $revision ) && preg_match( '/^[0-9a-f]{40}$/', $revision ) === 1, 'Agents API runtime capability fixture must declare an immutable revision.' );
$capability_commit = $fixture['capability_commit'] ?? null;
$assert( is_string( $capability_commit ) && preg_match( '/^[0-9a-f]{40}$/', $capability_commit ) === 1, 'Agents API runtime capability fixture must retain the immutable capability introduction commit.' );
$assert( is_array( $fixture['capabilities'] ?? null ) && 3 === count( $fixture['capabilities'] ), 'Agents API runtime capability fixture must identify every required native tool capability.' );

$runtime_source = array(
	'version'    => 1,
	'role'       => 'component',
	'repository' => $fixture['repository'],
	'revision'   => $revision,
	'path'       => '.',
	'metadata'   => array( 'slug' => 'agents-api', 'loadAs' => 'mu-plugin', 'pluginFile' => 'agents-api.php' ),
);

$workflow = (string) file_get_contents( $root . '/.github/workflows/maintain-docs.yml' );
preg_match( "/runtime_sources='(?<json>[^']+)'/", $workflow, $runtime_sources_match );
$runtime_sources = json_decode( $runtime_sources_match['json'] ?? '', true );
$assert( $runtime_source === ( $runtime_sources[0] ?? null ), 'Hosted runtime sources must pin the Agents API native tool capability revision.' );

$tests_workflow = (string) file_get_contents( $root . '/.github/workflows/tests.yml' );
$assert( preg_match( '/repository: Automattic\/agents-api\s+ref: ' . preg_quote( $revision, '/' ) . '/s', $tests_workflow ) === 1, 'Native importer CI must test the hosted Agents API capability revision.' );

$agents_api_dir = getenv( 'AGENTS_API_DIR' );
if ( is_string( $agents_api_dir ) && is_dir( $agents_api_dir ) ) {
	$checked_out_revision = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $agents_api_dir ) . ' rev-parse HEAD' ) );
	$assert( $revision === $checked_out_revision, 'The checked-out Agents API runtime must match the immutable capability revision.' );
	exec( 'git -C ' . escapeshellarg( $agents_api_dir ) . ' merge-base --is-ancestor ' . escapeshellarg( $capability_commit ) . ' HEAD', $output, $status );
	$assert( 0 === $status, 'The checked-out Agents API runtime must retain the original native tool capabilities.' );
}

fwrite( STDOUT, "Agents API runtime capability contract passed.\n" );

<?php
/**
 * Keep the reusable workflow's native package descriptors tied to real bytes.
 *
 * Run: php tests/validate-external-native-package-sources.php
 */

declare( strict_types=1 );

$root     = dirname( __DIR__ );
$workflow = (string) file_get_contents( $root . '/.github/workflows/maintain-docs.yml' );

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$read_revision_package = static function ( string $revision, string $path ) use ( $root ): string {
	$command = sprintf(
		'git -C %s show %s',
		escapeshellarg( $root ),
		escapeshellarg( $revision . ':' . $path )
	);
	$contents = shell_exec( $command );
	if ( ! is_string( $contents ) || '' === $contents ) {
		throw new RuntimeException( "Unable to read {$path} from immutable revision {$revision}." );
	}

	return $contents;
};

$package_revision = '7b2df969c34de112ec7ad13189ba94226a7f76f3';
$packages = array(
	'technical:bootstrap' => array( 'path' => 'bundles/technical-docs-agent/native/technical-docs-bootstrap-agent.agent.json', 'slug' => 'technical-docs-bootstrap-agent', 'digest' => '9050c9b4364a5a91b057fd51ff5f667efc320f9d6776160ab3e7cf4fd5e4f0e6' ),
	'technical:maintenance' => array( 'path' => 'bundles/technical-docs-agent/native/technical-docs-maintenance-agent.agent.json', 'slug' => 'technical-docs-maintenance-agent', 'digest' => '6057aad4eb7c5f0320ccfbce9da93a5fa1d3fc521478b5571ed81c28129325aa' ),
	'user:bootstrap'      => array( 'path' => 'bundles/user-docs-agent/native/user-docs-bootstrap-agent.agent.json', 'slug' => 'user-docs-bootstrap-agent', 'digest' => 'ee558a73f2a393c1912d62f7f40dbdd4bc31ac0168c3c248316b3430258c55cd' ),
	'user:maintenance'    => array( 'path' => 'bundles/user-docs-agent/native/user-docs-maintenance-agent.agent.json', 'slug' => 'user-docs-maintenance-agent', 'digest' => 'e2248f014c1409a8d4c5cb303ea37c0b24ae05babd02d40c6032160daf60d4c4' ),
	'skills:maintenance'  => array( 'path' => 'bundles/skills-agent/native/skills-maintenance-agent.agent.json', 'slug' => 'skills-maintenance-agent', 'digest' => 'c643f8bd31864cdd607f9025a000694a65a3fdf91532e4311fbac541e708a2b2' ),
);

foreach ( $packages as $lane => $package ) {
	$contents = $read_revision_package( $package_revision, $package['path'] );
	$document = json_decode( $contents, true );
	$assert( is_array( $document ), "{$lane} package is invalid JSON." );
	$assert( $package['slug'] === ( $document['agent']['agent_slug'] ?? null ), "{$lane} descriptor must use the package's canonical agent slug." );
	$assert( $package['digest'] === hash( 'sha256', $contents ), "{$lane} descriptor digest does not match immutable package bytes." );

	$case = preg_quote( $lane . ')', '/' );
	$pattern = '/case "\$AUDIENCE:\$RUN_KIND" in.*?' . $case . '(?<body>.*?);;(?=\n            )/s';
	$assert( 1 === preg_match( $pattern, $workflow, $match ), "{$lane} must have a deterministic workflow lane mapping." );
	$body = $match['body'];
	$assert( str_contains( $body, 'package_path="' . $package['path'] . '"' ), "{$lane} must map to its standalone package path." );
	$assert( str_contains( $body, 'agent_slug="' . $package['slug'] . '"' ), "{$lane} must map to its canonical agent slug." );
	$assert( str_contains( $body, 'package_digest="sha256-bytes-v1:' . $package['digest'] . '"' ), "{$lane} must map to its byte digest." );
}

$fixture = json_decode( (string) file_get_contents( $root . '/tests/reusable-workflow-empty-called-workflow-context.fixture.json' ), true );
$assert( is_array( $fixture ), 'The empty called-workflow context fixture must be valid JSON.' );
$assert( array( 29280583288, 29280583450, 29280583504, 29280583286 ) === ( $fixture['failed_runs'] ?? null ), 'The regression fixture must retain all failed consumer runs.' );
$assert( '' === ( $fixture['called_workflow_context']['job_workflow_sha'] ?? null ), 'The regression fixture must model the empty called-workflow SHA.' );
$assert( $package_revision === ( $fixture['expected_package_revision'] ?? null ), 'An empty called-workflow context must prepare the fixed package revision.' );

$assert( str_contains( $workflow, 'DOCS_AGENT_PACKAGE_REVISION: ' . $package_revision ), 'The workflow must define the immutable package revision once.' );
$assert( str_contains( $workflow, '--arg revision "$DOCS_AGENT_PACKAGE_REVISION"' ), 'The recipe must use the immutable package revision.' );
$assert( str_contains( $workflow, '--arg revision "$DOCS_AGENT_PACKAGE_REVISION" --arg path "$package_path"' ), 'The runner descriptor must use the immutable package revision.' );
$assert( ! str_contains( $workflow, 'github.job_workflow_sha' ), 'Recipe preparation must not depend on the called-workflow SHA.' );
$assert( ! str_contains( $workflow, 'github.workflow_sha' ), 'The external source revision must not use the consumer workflow SHA.' );
$assert( ! str_contains( $workflow, 'DOCS_AGENT_REVISION' ), 'The workflow must use the package-source revision name consistently.' );
$assert( str_contains( $workflow, "grep -Eq '^[0-9a-fA-F]{40}\$'" ), 'The workflow must fail closed when the source revision is not a full SHA.' );
$assert( str_contains( $workflow, 'external_package_source: ${{ needs.prepare.outputs.external_package_source }}' ), 'The native runner must receive the descriptor output.' );
$assert( ! str_contains( $workflow, 'agent_bundle:' ), 'The active workflow must not pass a legacy agent bundle.' );
$assert( ! str_contains( $workflow, 'pipeline_slug' ) && ! str_contains( $workflow, 'flow_slug' ) && ! str_contains( $workflow, 'bundle_path' ), 'The active workflow must not pass legacy bundle envelopes.' );
$assert( str_contains( $workflow, 'EXTERNAL_PACKAGE_SOURCE_POLICY:' ) && str_contains( $workflow, 'EXTERNAL_PACKAGE_SOURCE_POLICY: ${{ secrets.EXTERNAL_PACKAGE_SOURCE_POLICY }}' ), 'The policy secret must be required and forwarded without serialization.' );
$assert( str_contains( $workflow, 'OPENAI_API_KEY: ${{ secrets.OPENAI_API_KEY }}' ), 'OPENAI_API_KEY must be forwarded without serialization.' );
$assert( str_contains( $workflow, 'ACCESS_TOKEN: ${{ github.token }}' ), 'Same-repository publication must use the caller-scoped GitHub token.' );
$assert( ! str_contains( $workflow, 'secrets.ACCESS_TOKEN' ), 'Docs Agent must not require a consumer ACCESS_TOKEN override.' );

fwrite( STDOUT, "Docs Agent external native package source validation passed.\n" );

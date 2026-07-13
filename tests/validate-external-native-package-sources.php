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

$packages = array(
	'technical:bootstrap' => array( 'path' => 'bundles/technical-docs-agent/native/technical-docs-bootstrap-agent.agent.json', 'slug' => 'technical-docs-bootstrap-agent', 'digest' => '9050c9b4364a5a91b057fd51ff5f667efc320f9d6776160ab3e7cf4fd5e4f0e6' ),
	'technical:maintenance' => array( 'path' => 'bundles/technical-docs-agent/native/technical-docs-maintenance-agent.agent.json', 'slug' => 'technical-docs-maintenance-agent', 'digest' => '6057aad4eb7c5f0320ccfbce9da93a5fa1d3fc521478b5571ed81c28129325aa' ),
	'user:bootstrap'      => array( 'path' => 'bundles/user-docs-agent/native/user-docs-bootstrap-agent.agent.json', 'slug' => 'user-docs-bootstrap-agent', 'digest' => 'ee558a73f2a393c1912d62f7f40dbdd4bc31ac0168c3c248316b3430258c55cd' ),
	'user:maintenance'    => array( 'path' => 'bundles/user-docs-agent/native/user-docs-maintenance-agent.agent.json', 'slug' => 'user-docs-maintenance-agent', 'digest' => 'e2248f014c1409a8d4c5cb303ea37c0b24ae05babd02d40c6032160daf60d4c4' ),
	'skills:maintenance'  => array( 'path' => 'bundles/skills-agent/native/skills-maintenance-agent.agent.json', 'slug' => 'skills-maintenance-agent', 'digest' => 'c643f8bd31864cdd607f9025a000694a65a3fdf91532e4311fbac541e708a2b2' ),
);

foreach ( $packages as $lane => $package ) {
	$path = $root . '/' . $package['path'];
	$assert( is_file( $path ), "{$lane} package is missing." );
	$document = json_decode( (string) file_get_contents( $path ), true );
	$assert( is_array( $document ), "{$lane} package is invalid JSON." );
	$assert( $package['slug'] === ( $document['agent']['agent_slug'] ?? null ), "{$lane} descriptor must use the package's canonical agent slug." );
	$assert( $package['digest'] === hash_file( 'sha256', $path ), "{$lane} descriptor digest does not match package bytes." );

	$case = preg_quote( $lane . ')', '/' );
	$pattern = '/case "\$AUDIENCE:\$RUN_KIND" in.*?' . $case . '(?<body>.*?);;(?=\n            )/s';
	$assert( 1 === preg_match( $pattern, $workflow, $match ), "{$lane} must have a deterministic workflow lane mapping." );
	$body = $match['body'];
	$assert( str_contains( $body, 'package_path="' . $package['path'] . '"' ), "{$lane} must map to its standalone package path." );
	$assert( str_contains( $body, 'agent_slug="' . $package['slug'] . '"' ), "{$lane} must map to its canonical agent slug." );
	$assert( str_contains( $body, 'package_digest="sha256-bytes-v1:' . $package['digest'] . '"' ), "{$lane} must map to its byte digest." );
}

$assert( str_contains( $workflow, 'DOCS_AGENT_REVISION: ${{ github.job_workflow_sha }}' ), 'The external source revision must come from github.job_workflow_sha.' );
$assert( ! str_contains( $workflow, 'github.workflow_sha' ), 'The external source revision must not use the consumer workflow SHA.' );
$assert( str_contains( $workflow, "grep -Eq '^[0-9a-fA-F]{40}\$'" ), 'The workflow must fail closed when the source revision is not a full SHA.' );
$assert( str_contains( $workflow, 'external_package_source: ${{ needs.prepare.outputs.external_package_source }}' ), 'The native runner must receive the descriptor output.' );
$assert( ! str_contains( $workflow, 'agent_bundle:' ), 'The active workflow must not pass a legacy agent bundle.' );
$assert( ! str_contains( $workflow, 'pipeline_slug' ) && ! str_contains( $workflow, 'flow_slug' ) && ! str_contains( $workflow, 'bundle_path' ), 'The active workflow must not pass legacy bundle envelopes.' );
$assert( str_contains( $workflow, 'EXTERNAL_PACKAGE_SOURCE_POLICY:' ) && str_contains( $workflow, 'EXTERNAL_PACKAGE_SOURCE_POLICY: ${{ secrets.EXTERNAL_PACKAGE_SOURCE_POLICY }}' ), 'The policy secret must be required and forwarded without serialization.' );
$assert( str_contains( $workflow, 'ACCESS_TOKEN: ${{ secrets.ACCESS_TOKEN }}' ), 'ACCESS_TOKEN must remain a separate publication credential.' );

fwrite( STDOUT, "Docs Agent external native package source validation passed.\n" );

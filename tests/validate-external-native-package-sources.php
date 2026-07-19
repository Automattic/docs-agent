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

$package_revision = '85f0d162a7d499fdc1286891371342727d084c88';
$packages = array(
	'technical:bootstrap' => array( 'path' => 'bundles/technical-docs-agent/native/technical-docs-bootstrap-agent.agent.json', 'slug' => 'technical-docs-bootstrap-agent', 'digest' => '0230e0e3fd8a4f045a545407d3d01c22df537b05f031260e29d6b531285b7839' ),
	'technical:maintenance' => array( 'path' => 'bundles/technical-docs-agent/native/technical-docs-maintenance-agent.agent.json', 'slug' => 'technical-docs-maintenance-agent', 'digest' => '975c7b0a0a7aff52897c52be5ac903a7fb110ea3c33e16227f8694c74c932519' ),
	'user:bootstrap'      => array( 'path' => 'bundles/user-docs-agent/native/user-docs-bootstrap-agent.agent.json', 'slug' => 'user-docs-bootstrap-agent', 'digest' => 'a3ada797a125f638364caac43004bbb47fcc8c7bdc89021f25fcfcdc4c2d502f' ),
	'user:maintenance'    => array( 'path' => 'bundles/user-docs-agent/native/user-docs-maintenance-agent.agent.json', 'slug' => 'user-docs-maintenance-agent', 'digest' => '747de10fdb52d7c1543b404030e0e0a2604b6496e95d50143149f72c975a98fb' ),
	'skills:maintenance'  => array( 'path' => 'bundles/skills-agent/native/skills-maintenance-agent.agent.json', 'slug' => 'skills-maintenance-agent', 'digest' => '0f8d35fe0026aa62b9ddf2e86c4f9432b4d8aae90757dfbaf334d4a0671fb8a3' ),
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

$readme = (string) file_get_contents( $root . '/README.md' );
$policy_json = '{"version":1,"repositories":{"automattic/docs-agent":["bundles/technical-docs-agent/native/technical-docs-bootstrap-agent.agent.json","bundles/technical-docs-agent/native/technical-docs-maintenance-agent.agent.json","bundles/user-docs-agent/native/user-docs-bootstrap-agent.agent.json","bundles/user-docs-agent/native/user-docs-maintenance-agent.agent.json","bundles/skills-agent/native/skills-maintenance-agent.agent.json"]},"runtime_sources":{"automattic/agents-api":["."],"wordpress/php-ai-client":["."]},"runtime_artifacts":[{"url":"https://downloads.wordpress.org/plugin/ai-provider-for-openai.1.0.3.zip","sha256":"48f3c0c714b3164cda79d320829830d5a0ea1116e0b19653da8af898a22d3bb6"}]}';
$assert( str_contains( $readme, $policy_json ), 'README must provide the exact copyable v1 external package source policy.' );
$policy = json_decode( $policy_json, true );
$assert( is_array( $policy ), 'The documented external package source policy must be valid JSON.' );
$assert( 5 === count( $policy['repositories']['automattic/docs-agent'] ?? array() ), 'The policy must authorize all five Docs Agent package paths.' );
$assert( array( '.' ) === ( $policy['runtime_sources']['automattic/agents-api'] ?? null ), 'The policy must authorize the Agents API root only.' );
$assert( array( '.' ) === ( $policy['runtime_sources']['wordpress/php-ai-client'] ?? null ), 'The policy must authorize the PHP AI Client root only.' );
$assert( array( array( 'url' => 'https://downloads.wordpress.org/plugin/ai-provider-for-openai.1.0.3.zip', 'sha256' => '48f3c0c714b3164cda79d320829830d5a0ea1116e0b19653da8af898a22d3bb6' ) ) === ( $policy['runtime_artifacts'] ?? null ), 'The policy must authorize only the checksum-pinned OpenAI provider artifact.' );

fwrite( STDOUT, "Docs Agent external native package source validation passed.\n" );

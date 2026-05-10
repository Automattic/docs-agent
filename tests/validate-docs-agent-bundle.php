<?php
/**
 * Validate the reusable Docs Agent bundle shape.
 *
 * Run: php tests/validate-docs-agent-bundle.php
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );

$read_json = static function ( string $path ): array {
    if ( ! is_file( $path ) ) {
        throw new RuntimeException( "Missing JSON file: {$path}" );
    }

    $data = json_decode( (string) file_get_contents( $path ), true );
    if ( ! is_array( $data ) ) {
        throw new RuntimeException( "Invalid JSON file: {$path}" );
    }

    return $data;
};

$assert = static function ( bool $condition, string $message ): void {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
};

$bundle_dir = $root . '/bundles/docs-agent';
$manifest   = $read_json( $bundle_dir . '/manifest.json' );
$example    = $read_json( $root . '/examples/homeboy-runner-config.example.json' );

$assert( 'docs-agent' === ( $manifest['bundle_slug'] ?? '' ), 'Manifest bundle_slug must be docs-agent.' );
$assert( 'docs-agent' === ( $manifest['agent']['slug'] ?? '' ), 'Manifest agent slug must be docs-agent.' );
$expected_pipelines = array( 'technical-docs-pipeline', 'user-docs-pipeline' );
$expected_flows     = array( 'technical-docs-flow', 'user-docs-flow' );

foreach ( $expected_pipelines as $pipeline_slug ) {
    $assert( in_array( $pipeline_slug, $manifest['included']['pipelines'] ?? array(), true ), "Manifest must include {$pipeline_slug}." );
}

foreach ( $expected_flows as $flow_slug ) {
    $assert( in_array( $flow_slug, $manifest['included']['flows'] ?? array(), true ), "Manifest must include {$flow_slug}." );
}

foreach ( array( 'MEMORY.md', 'SOUL.md' ) as $memory_file ) {
    $assert( is_file( $bundle_dir . '/memory/agent/' . $memory_file ), "Missing memory file: {$memory_file}" );
}

$workflow_pairs = array(
    'technical' => array( 'pipeline' => 'technical-docs-pipeline', 'flow' => 'technical-docs-flow' ),
    'user'      => array( 'pipeline' => 'user-docs-pipeline', 'flow' => 'user-docs-flow' ),
);

foreach ( $workflow_pairs as $workflow => $pair ) {
    $pipeline = $read_json( $bundle_dir . '/pipelines/' . $pair['pipeline'] . '.json' );
    $flow     = $read_json( $bundle_dir . '/flows/' . $pair['flow'] . '.json' );

    $assert( $pair['pipeline'] === ( $pipeline['slug'] ?? '' ), "{$workflow} pipeline slug mismatch." );
    $assert( $pair['flow'] === ( $flow['slug'] ?? '' ), "{$workflow} flow slug mismatch." );
    $assert( $pair['pipeline'] === ( $flow['pipeline_slug'] ?? '' ), "{$workflow} flow must point at {$pair['pipeline']}." );
    $assert( str_contains( (string) ( $pipeline['steps'][0]['step_config']['system_prompt'] ?? '' ), 'source code' ), "{$workflow} pipeline must generate docs from source code." );

    $tools = $flow['steps'][0]['enabled_tools'] ?? array();
    foreach ( array( 'get_github_file', 'create_or_update_github_file', 'create_github_pull_request' ) as $required_tool ) {
        $assert( in_array( $required_tool, $tools, true ), "{$workflow} flow missing required tool: {$required_tool}" );
    }

    $assert( empty( $flow['steps'][0]['completion_assertions'] ?? array() ), "{$workflow} flow must allow no-op success without required PR tools." );
}

$assert( false === ( $example['success_requires_pr'] ?? true ), 'Example runner config must allow no_changes success.' );
$assert( 'technical-docs-pipeline' === ( $example['pipeline_slug'] ?? '' ), 'Example config must select the technical pipeline.' );
$assert( 'technical-docs-flow' === ( $example['flow_slug'] ?? '' ), 'Example config must select the technical flow.' );

$recorders = $example['tool_recorders'] ?? array();
$file_recorder = null;
foreach ( $recorders as $recorder ) {
    if ( is_array( $recorder ) && 'create_or_update_github_file' === ( $recorder['tool'] ?? '' ) ) {
        $file_recorder = $recorder;
        break;
    }
}

$assert( is_array( $file_recorder ), 'Example config must record create_or_update_github_file.' );
$assert( array( 'README.md', 'docs/**' ) === ( $file_recorder['forced_parameters']['allowed_file_paths'] ?? null ), 'Example config must force writable docs paths.' );

fwrite( STDOUT, "Docs Agent bundle validation passed.\n" );

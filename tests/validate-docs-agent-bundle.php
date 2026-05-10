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
$pipeline   = $read_json( $bundle_dir . '/pipelines/docs-agent-pipeline.json' );
$flow       = $read_json( $bundle_dir . '/flows/docs-maintenance-flow.json' );
$example    = $read_json( $root . '/examples/homeboy-runner-config.example.json' );

$assert( 'docs-agent' === ( $manifest['bundle_slug'] ?? '' ), 'Manifest bundle_slug must be docs-agent.' );
$assert( 'docs-agent' === ( $manifest['agent']['slug'] ?? '' ), 'Manifest agent slug must be docs-agent.' );
$assert( in_array( 'docs-agent-pipeline', $manifest['included']['pipelines'] ?? array(), true ), 'Manifest must include docs-agent-pipeline.' );
$assert( in_array( 'docs-maintenance-flow', $manifest['included']['flows'] ?? array(), true ), 'Manifest must include docs-maintenance-flow.' );

foreach ( array( 'MEMORY.md', 'SOUL.md' ) as $memory_file ) {
    $assert( is_file( $bundle_dir . '/memory/agent/' . $memory_file ), "Missing memory file: {$memory_file}" );
}

$assert( 'docs-agent-pipeline' === ( $pipeline['slug'] ?? '' ), 'Pipeline slug mismatch.' );
$assert( 'docs-maintenance-flow' === ( $flow['slug'] ?? '' ), 'Flow slug mismatch.' );
$assert( 'docs-agent-pipeline' === ( $flow['pipeline_slug'] ?? '' ), 'Flow must point at docs-agent-pipeline.' );

$tools = $flow['steps'][0]['enabled_tools'] ?? array();
foreach ( array( 'get_github_file', 'create_or_update_github_file', 'create_github_pull_request' ) as $required_tool ) {
    $assert( in_array( $required_tool, $tools, true ), "Flow missing required tool: {$required_tool}" );
}

$assert( empty( $flow['steps'][0]['completion_assertions'] ?? array() ), 'Flow must allow no-op success without required PR tools.' );
$assert( false === ( $example['success_requires_pr'] ?? true ), 'Example runner config must allow no_changes success.' );

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

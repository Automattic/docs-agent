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
		throw new RuntimeException( "Invalid JSON file: {$path}: " . json_last_error_msg() );
	}

	return $data;
};

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$spec       = $read_json( $root . '/tests/docs-agent.validate-bundle-spec.json' );
$bundle_dir = realpath( $root . '/tests/' . ( $spec['bundle_dir'] ?? '' ) );
$assert( is_string( $bundle_dir ) && is_dir( $bundle_dir ), 'Spec bundle_dir must point to an existing directory.' );

$manifest = $read_json( $bundle_dir . '/manifest.json' );
$assert( ( $spec['bundle_slug'] ?? '' ) === ( $manifest['bundle_slug'] ?? '' ), 'Manifest bundle_slug mismatch.' );
$assert( ( $spec['agent_slug'] ?? '' ) === ( $manifest['agent']['slug'] ?? '' ), 'Manifest agent slug mismatch.' );

foreach ( $spec['memory_files'] ?? array() as $memory_file ) {
	$assert( is_file( $bundle_dir . '/memory/agent/' . $memory_file ), "Missing memory file: {$memory_file}" );
}

foreach ( $spec['expected_pipelines'] ?? array() as $pipeline_slug ) {
	$assert( in_array( $pipeline_slug, $manifest['included']['pipelines'] ?? array(), true ), "Manifest must include pipeline {$pipeline_slug}." );
	$pipeline      = $read_json( $bundle_dir . '/pipelines/' . $pipeline_slug . '.json' );
	$pipeline_spec = $spec['pipeline_assertions'][ $pipeline_slug ] ?? array();
	$assert( $pipeline_slug === ( $pipeline['slug'] ?? '' ), "Pipeline slug mismatch for {$pipeline_slug}." );

	$system_prompt = (string) ( $pipeline['steps'][0]['step_config']['system_prompt'] ?? '' );
	if ( isset( $pipeline_spec['system_prompt_must_contain'] ) ) {
		$assert( str_contains( $system_prompt, (string) $pipeline_spec['system_prompt_must_contain'] ), "Pipeline {$pipeline_slug} system prompt missing required text." );
	}
	$assert( str_contains( strtolower( $system_prompt ), 'living documentation' ), "Pipeline {$pipeline_slug} must describe living documentation." );
}

foreach ( $spec['expected_flows'] ?? array() as $flow_slug ) {
	$assert( in_array( $flow_slug, $manifest['included']['flows'] ?? array(), true ), "Manifest must include flow {$flow_slug}." );
	$flow      = $read_json( $bundle_dir . '/flows/' . $flow_slug . '.json' );
	$flow_spec = $spec['flow_assertions'][ $flow_slug ] ?? array();
	$assert( $flow_slug === ( $flow['slug'] ?? '' ), "Flow slug mismatch for {$flow_slug}." );
	$assert( ( $flow_spec['pipeline_slug'] ?? '' ) === ( $flow['pipeline_slug'] ?? '' ), "Flow {$flow_slug} pipeline slug mismatch." );

	$step  = $flow['steps'][0] ?? array();
	$tools = $step['enabled_tools'] ?? array();
	foreach ( $flow_spec['ai_step_required_tools'] ?? array() as $required_tool ) {
		$assert( in_array( $required_tool, $tools, true ), "Flow {$flow_slug} missing required tool: {$required_tool}" );
	}

	if ( ! empty( $flow_spec['completion_assertions_empty'] ) ) {
		$assert( empty( $step['completion_assertions'] ?? array() ), "Flow {$flow_slug} must allow no-op success without required PR tools." );
	}

	$flow_prompt = strtolower( (string) ( $step['prompt_queue'][0]['prompt'] ?? '' ) );
	if ( str_contains( $flow_slug, 'bootstrap' ) ) {
		foreach ( array( 'coverage map', 'not complete until', 'out of scope' ) as $phrase ) {
			$assert( str_contains( $flow_prompt, $phrase ), "Bootstrap flow {$flow_slug} missing phrase: {$phrase}" );
		}
	}
	if ( str_contains( $flow_slug, 'maintenance' ) || in_array( $flow_slug, array( 'technical-docs-flow', 'user-docs-flow' ), true ) ) {
		foreach ( array( 'maintenance pass', 'focused updates', 'no_changes' ) as $phrase ) {
			$assert( str_contains( $flow_prompt, $phrase ), "Maintenance flow {$flow_slug} missing phrase: {$phrase}" );
		}
	}
}

$example_path = $root . '/tests/' . ( $spec['example_runner_config'] ?? '' );
$example      = $read_json( $example_path );
foreach ( $spec['example_assertions'] ?? array() as $key => $expected ) {
	$assert( $expected === ( $example[ $key ] ?? null ), "Example config {$key} mismatch." );
}

$recorders     = $example['tool_recorders'] ?? array();
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

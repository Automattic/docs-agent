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

$spec = $read_json( $root . '/tests/docs-agent.validate-bundle-spec.json' );

foreach ( $spec['bundles'] ?? array() as $bundle_name => $bundle_spec ) {
	$bundle_dir = realpath( $root . '/tests/' . ( $bundle_spec['bundle_dir'] ?? '' ) );
	$assert( is_string( $bundle_dir ) && is_dir( $bundle_dir ), "Spec bundle_dir must point to an existing directory for {$bundle_name}." );

	$manifest = $read_json( $bundle_dir . '/manifest.json' );
	$assert( ( $bundle_spec['bundle_slug'] ?? '' ) === ( $manifest['bundle_slug'] ?? '' ), "Manifest bundle_slug mismatch for {$bundle_name}." );
	$assert( ( $bundle_spec['agent_slug'] ?? '' ) === ( $manifest['agent']['slug'] ?? '' ), "Manifest agent slug mismatch for {$bundle_name}." );
	$assert( preg_match( '/^Automattic\/docs-agent@v\d+\.\d+\.\d+$/', (string) ( $manifest['source_ref'] ?? '' ) ) === 1, "Manifest source_ref must point at a Docs Agent release tag for {$bundle_name}." );
	$assert( preg_match( '/^[0-9a-f]{40}$/', (string) ( $manifest['source_revision'] ?? '' ) ) === 1, "Manifest source_revision must be a full commit SHA for {$bundle_name}." );
	$assert( ! str_contains( strtolower( (string) ( $manifest['source_revision'] ?? '' ) ), 'initial-' ), "Manifest source_revision must not use placeholder provenance for {$bundle_name}." );

	foreach ( $bundle_spec['memory_files'] ?? array() as $memory_file ) {
		$assert( is_file( $bundle_dir . '/memory/agent/' . $memory_file ), "Missing {$bundle_name} memory file: {$memory_file}" );
	}

	foreach ( $bundle_spec['expected_pipelines'] ?? array() as $pipeline_slug ) {
		$assert( in_array( $pipeline_slug, $manifest['included']['pipelines'] ?? array(), true ), "Manifest {$bundle_name} must include pipeline {$pipeline_slug}." );
		$pipeline      = $read_json( $bundle_dir . '/pipelines/' . $pipeline_slug . '.json' );
		$pipeline_spec = $spec['pipeline_assertions'][ $pipeline_slug ] ?? array();
		$assert( $pipeline_slug === ( $pipeline['slug'] ?? '' ), "Pipeline slug mismatch for {$pipeline_slug}." );

		$system_prompt = (string) ( $pipeline['steps'][0]['step_config']['system_prompt'] ?? '' );
		if ( isset( $pipeline_spec['system_prompt_must_contain'] ) ) {
			$assert( str_contains( $system_prompt, (string) $pipeline_spec['system_prompt_must_contain'] ), "Pipeline {$pipeline_slug} system prompt missing required text." );
		}
		foreach ( $pipeline_spec['system_prompt_must_contain_all'] ?? array() as $required ) {
			$assert( str_contains( $system_prompt, (string) $required ), "Pipeline {$pipeline_slug} system prompt missing required text: {$required}" );
		}
		foreach ( $pipeline_spec['system_prompt_forbidden'] ?? array() as $forbidden ) {
			$assert( ! str_contains( $system_prompt, (string) $forbidden ), "Pipeline {$pipeline_slug} system prompt must not contain: {$forbidden}" );
		}
		$assert( str_contains( strtolower( $system_prompt ), 'living documentation' ), "Pipeline {$pipeline_slug} must describe living documentation." );
	}

	foreach ( $bundle_spec['expected_flows'] ?? array() as $flow_slug ) {
		$assert( in_array( $flow_slug, $manifest['included']['flows'] ?? array(), true ), "Manifest {$bundle_name} must include flow {$flow_slug}." );
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
		foreach ( $flow_spec['prompt_forbidden'] ?? array() as $forbidden ) {
			$assert( ! str_contains( $flow_prompt, strtolower( (string) $forbidden ) ), "Flow {$flow_slug} prompt must not contain: {$forbidden}" );
		}
		foreach ( $flow_spec['prompt_must_contain'] ?? array() as $required ) {
			$assert( str_contains( $flow_prompt, strtolower( (string) $required ) ), "Flow {$flow_slug} prompt missing required text: {$required}" );
		}
		if ( str_contains( $flow_slug, 'bootstrap' ) ) {
			$completion_assertions = $step['completion_assertions'] ?? array();
			$assert( array( 'create_github_pull_request' ) === ( $completion_assertions['required_tool_names'] ?? null ), "Bootstrap flow {$flow_slug} must require pull request creation." );
			$assert( 6 === ( $completion_assertions['minimum_successful_tool_counts']['workspace_write'] ?? null ), "Bootstrap flow {$flow_slug} must require at least six workspace file writes." );
			$assert( str_contains( $flow_prompt, 'runner-provided workspace handle' ), "Bootstrap flow {$flow_slug} must direct agents to use the runner workspace." );
			$assert( str_contains( $flow_prompt, 'workspace_git_push' ), "Bootstrap flow {$flow_slug} must direct agents through the workspace git workflow." );
			$assert( ! str_contains( $flow_prompt, 'create_or_update_github_file' ), "Bootstrap flow {$flow_slug} must not reference direct GitHub file writes." );

			$required_phrases = array( 'clean documentation surface', 'documentation information architecture', 'committed scope that fits the run budget', 'future coverage section as plain text', 'separate', 'digestible', 'hierarchy mirrors', 'parent/child relationships', 'cross-links', 'committed documentation scope', 'write topic pages first', 'index last', 'write every linked documentation page', 'future coverage with the reason' );
			$required_phrases = array_merge( $required_phrases, str_starts_with( $flow_slug, 'technical-' ) ? array( 'source inventory', 'preserve or improve', 'reference-level details', 'representative payloads or examples', 'reconcile the written docs against the source inventory' ) : array( 'private inventory', 'frontend users', 'user docs index', 'docs/user/', 'practical product details', 'reconcile the written docs against the private product inventory' ) );
			foreach ( $required_phrases as $phrase ) {
				$assert( str_contains( $flow_prompt, strtolower( $phrase ) ), "Bootstrap flow {$flow_slug} missing phrase: {$phrase}" );
			}
		}
		if ( str_contains( $flow_slug, 'maintenance' ) || in_array( $flow_slug, array( 'technical-docs-flow', 'user-docs-flow' ), true ) ) {
			foreach ( array( 'maintenance pass', 'focused updates', 'no_changes' ) as $phrase ) {
				$assert( str_contains( $flow_prompt, $phrase ), "Maintenance flow {$flow_slug} missing phrase: {$phrase}" );
			}
		}
	}
}

$example_path = $root . '/tests/' . ( $spec['example_runner_config'] ?? '' );
$example      = $read_json( $example_path );
foreach ( $spec['example_assertions'] ?? array() as $key => $expected ) {
	$assert( $expected === ( $example[ $key ] ?? null ), "Example config {$key} mismatch." );
}

$runner_workspace = $example['runner_workspace'] ?? array();
$assert( ! empty( $runner_workspace['enabled'] ), 'Example config must enable runner-owned workspace provisioning.' );
$assert( 'docs/agent-run' === ( $runner_workspace['branch_prefix'] ?? null ), 'Example config must declare the docs branch prefix.' );
$assert( is_file( $root . '/scripts/repair-docs-links.php' ), 'Docs link repair script must be available to consumer workflows.' );

fwrite( STDOUT, "Docs Agent bundle validation passed.\n" );

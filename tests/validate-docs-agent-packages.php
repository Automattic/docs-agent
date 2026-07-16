<?php
/**
 * Validate the reusable Docs Agent native package shape.
 *
 * Run: php tests/validate-docs-agent-packages.php
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

$expected_artifact_schemas = array(
	'docs_agent_transcript'             => 'docs-agent/transcript/v1',
	'docs_agent_change_summary'        => 'docs-agent/change-summary/v1',
	'docs_agent_verification_report'   => 'docs-agent/verification-report/v1',
	'docs_agent_drift_report'          => 'docs-agent/drift-report/v1',
	'docs_agent_workspace_publication' => 'docs-agent/workspace-publication/v1',
);

$example_path = $root . '/examples/runner-recipe.example.json';
$example      = $read_json( $example_path );
$recipe       = $read_json( $root . '/ci/docs-agent-runner-recipe.json' );
$assert( 'docs-agent/runner-recipe/v1' === ( $example['schema'] ?? null ), 'Example config must use the portable Docs Agent runner recipe schema.' );
$assert( 'OWNER/REPO' === ( $example['targetRepository'] ?? null ), 'Example config must use the portable target repository placeholder.' );
$assert( 'docs-agent/runner-recipe/v1' === ( $recipe['schema'] ?? null ), 'Runner recipe must use the portable Docs Agent runner recipe schema.' );
$expected_package_source = array(
	'repository' => 'Automattic/docs-agent',
	'revision'   => '7b2df969c34de112ec7ad13189ba94226a7f76f3',
	'path'       => 'bundles/technical-docs-agent/native/technical-docs-maintenance-agent.agent.json',
	'digest'     => 'sha256-bytes-v1:6057aad4eb7c5f0320ccfbce9da93a5fa1d3fc521478b5571ed81c28129325aa',
);
foreach ( array( $recipe, $example ) as $runner_recipe ) {
	$assert( $expected_package_source === ( $runner_recipe['docsAgent']['externalPackageSource'] ?? null ), 'Runner recipes must use the immutable Docs Agent package source.' );
	$assert( 'technical-docs-maintenance-agent' === ( $runner_recipe['docsAgent']['agentSlug'] ?? null ), 'Runner recipes must use the package agent slug.' );
}

$runner_workspace = $example['runner']['workspace'] ?? array();
$assert( ! empty( $runner_workspace['enabled'] ), 'Example config must enable runner-owned workspace provisioning.' );
$assert( 'docs/agent-run' === ( $runner_workspace['branch_prefix'] ?? null ), 'Example config must declare the docs branch prefix.' );
$assert( is_file( $root . '/scripts/repair-docs-links.php' ), 'Docs link repair script must be available to consumer workflows.' );

$maintain_docs_workflow = (string) file_get_contents( $root . '/.github/workflows/maintain-docs.yml' );
foreach ( array( 'verification_commands:', 'drift_checks:', 'schema:"docs-agent/runner-recipe/v1"' ) as $required_workflow_text ) {
	$assert( str_contains( $maintain_docs_workflow, $required_workflow_text ), "maintain-docs.yml missing required text: {$required_workflow_text}" );
}
$assert( str_contains( $maintain_docs_workflow, 'recipe_json:' ), 'maintain-docs.yml must expose a portable recipe output.' );
$assert( str_contains( $maintain_docs_workflow, 'validation_dependencies:' ), 'maintain-docs.yml must expose caller-owned validation dependencies.' );
$assert( str_contains( $maintain_docs_workflow, 'validationDependencies:$validationDependencies' ), 'maintain-docs.yml must retain validation dependencies in the portable recipe.' );
$assert( str_contains( $maintain_docs_workflow, 'schema:"docs-agent/runner-recipe/v1"' ), 'maintain-docs.yml must build a portable Docs Agent recipe.' );
$assert( str_contains( $maintain_docs_workflow, 'declared_artifacts_json:' ), 'maintain-docs.yml must expose typed artifact declarations as a reusable workflow output.' );
$assert( str_contains( $maintain_docs_workflow, 'artifact_declarations<<EOF' ), 'maintain-docs.yml must prepare typed artifact declarations without caller-specific projections.' );
$assert( str_contains( $maintain_docs_workflow, 'artifact_declarations<<EOF' ), 'maintain-docs.yml must expose artifact declarations through workflow outputs.' );

$generic_codebox_agent_task_workflow = 'uses: Automattic/wp-codebox/.github/workflows/run-agent-task.yml@v0.12.23';
$assert( str_contains( $maintain_docs_workflow, $generic_codebox_agent_task_workflow ), 'maintain-docs.yml must call the generic Codebox agent-task workflow.' );
$assert( str_contains( $maintain_docs_workflow, 'wp_codebox_release_ref: v0.12.23' ), 'maintain-docs.yml must pass the matching WP Codebox release tag.' );

$assert( str_contains( $maintain_docs_workflow, '--arg writablePaths "$INPUT_WRITABLE_PATHS"' ), 'maintain-docs.yml must include writable paths in the portable recipe.' );
$assert( str_contains( $maintain_docs_workflow, 'output_projections:' ), 'maintain-docs.yml must project the bounded runner publication result.' );
$assert( str_contains( $maintain_docs_workflow, 'OPENAI_API_KEY: ${{ secrets.OPENAI_API_KEY }}' ), 'maintain-docs.yml must explicitly forward OPENAI_API_KEY to the native runner.' );
$assert( str_contains( $maintain_docs_workflow, 'ACCESS_TOKEN: ${{ github.token }}' ), 'maintain-docs.yml must forward the caller-scoped GitHub token to the native runner.' );
$assert( str_contains( $maintain_docs_workflow, 'EXTERNAL_PACKAGE_SOURCE_POLICY: ${{ secrets.EXTERNAL_PACKAGE_SOURCE_POLICY }}' ), 'maintain-docs.yml must explicitly forward the external package source policy.' );
$assert( str_contains( $maintain_docs_workflow, 'runtime_sources: ${{ needs.prepare.outputs.runtime_sources }}' ), 'maintain-docs.yml must pass its complete native runtime closure to WP Codebox.' );

$workflow_readme = (string) file_get_contents( $root . '/.github/workflows/README.md' );
foreach ( array( 'Docs Agent Runner Recipe', 'portable recipe', 'Docs Agent owns the native package' ) as $migration_note_text ) {
	$assert( str_contains( $workflow_readme, $migration_note_text ), "Workflow README missing agent runtime note: {$migration_note_text}" );
}
$assert( str_contains( $workflow_readme, 'v0.12.23' ), 'Workflow README must record the WP Codebox release tag.' );
$assert( str_contains( $workflow_readme, 'Diagnostic messages that name runtime classes remain reviewable' ), 'Workflow README must document diagnostic-versus-source detection.' );
$assert( str_contains( $workflow_readme, 'allowlisted review artifacts' ), 'Workflow README must document the WP Codebox upload allowlist.' );
$assert( str_contains( $workflow_readme, 'normalized failed result' ), 'Workflow README must document normalized WP Codebox failures.' );
$assert( str_contains( $workflow_readme, 'sanitizes private runtime paths' ), 'Workflow README must document private runtime-path sanitization.' );
$assert( str_contains( $workflow_readme, 'https://github.com/Automattic/wp-codebox/issues/1767' ), 'Workflow README must link the WP Codebox runtime closure issue.' );
$assert( str_contains( $workflow_readme, 'Docs Agent declares the runtime sources' ), 'Workflow README must document Docs Agent ownership of the runtime closure.' );
$assert( str_contains( $workflow_readme, 'WP Codebox materializes and lowers them under its generic runtime contract' ), 'Workflow README must document the generic producer lowering boundary.' );
$assert( str_contains( $workflow_readme, 'compact reviewer transcript' ), 'Workflow README must document the compact reviewer transcript upload.' );
$assert( str_contains( $workflow_readme, 'private paths' ), 'Workflow README must document the reviewer transcript privacy boundary.' );
$assert( str_contains( $workflow_readme, 'pre-sanitization reviewer-evidence descriptor' ), 'Workflow README must document the pre-sanitization reviewer-evidence descriptor.' );
$assert( str_contains( $workflow_readme, 'reviewer-safe workflow-result projection' ), 'Workflow README must document the reviewer-safe workflow-result projection.' );

$declared_artifact_names = array_keys( $expected_artifact_schemas );
foreach ( $declared_artifact_names as $artifact_name ) {
	$assert( str_contains( $maintain_docs_workflow, $artifact_name ), "maintain-docs.yml missing typed artifact declaration {$artifact_name}." );
}

$example_text = (string) file_get_contents( $example_path );
foreach ( array( '/path/to', '/Users/', 'localhost', '127.0.0.1' ) as $local_path_fragment ) {
	$assert( ! str_contains( $example_text, $local_path_fragment ), "Example runner config must not contain local-only path or host fragment: {$local_path_fragment}" );
}
$example_artifacts = $example['artifacts']['expected'] ?? null;
$assert( is_array( $example_artifacts ), 'Example runner config must include expected artifact names.' );
$example_declarations = $example['artifacts']['declarations'] ?? null;
$assert( is_array( $example_declarations ), 'Example runner config must include typed artifact declarations.' );
$example_artifacts_by_name = array();
foreach ( $example_declarations as $artifact ) {
	$assert( is_array( $artifact ), 'Example runner config typed artifact entries must be objects.' );
	$example_artifacts_by_name[ (string) ( $artifact['name'] ?? '' ) ] = $artifact;
}
foreach ( $expected_artifact_schemas as $name => $schema ) {
	$assert( in_array( $name, $example_artifacts, true ), "Example runner config expected_artifacts missing {$name}." );
	$assert( isset( $example_artifacts_by_name[ $name ] ), "Example runner config missing typed artifact {$name}." );
	$assert( 'docs-agent/artifact-declaration/v1' === ( $example_artifacts_by_name[ $name ]['schema'] ?? '' ), "Example runner config typed artifact {$name} declaration schema mismatch." );
	$assert( $schema === ( $example_artifacts_by_name[ $name ]['artifact_schema'] ?? '' ), "Example runner config typed artifact {$name} schema mismatch." );
}

/*
 * Native Agents API package validation.
 *
 * The native packages are flat agent specs that import through the Agents API
 * runtime bundle importer (`wp_agent_import_runtime_bundles`), which requires
 * `agent.{agent_slug, agent_name, agent_config, meta}`. Each package is the sole
 * instruction authority for its lane and preserves a workspace-only editing
 * boundary with no agent-owned git/PR publication.
 */
$native_spec = $read_json( $root . '/tests/native-agent.validate-package-spec.json' );
$native_dirs = array();
foreach ( $native_spec['native_package_dirs'] ?? array() as $package_name => $native_package_dir ) {
	$native_dir = realpath( $root . '/tests/' . $native_package_dir );
	$assert( is_string( $native_dir ) && is_dir( $native_dir ), "Native package dir must point to an existing directory for {$package_name}." );
	$native_dirs[ $package_name ] = $native_dir;
}

$expected_native_paths = array();
foreach ( $native_spec['agents'] ?? array() as $native_file => $native_assertions ) {
	$expected_native_paths[] = $native_dirs[ $native_assertions['package'] ] . '/' . $native_file;
}
sort( $expected_native_paths );
$actual_native_paths = glob( $root . '/bundles/*/native/*.agent.json' ) ?: array();
sort( $actual_native_paths );
$assert( $expected_native_paths === $actual_native_paths, 'Docs Agent must expose exactly the five declared native packages.' );

foreach ( $native_spec['agents'] ?? array() as $native_file => $native_assertions ) {
	$native_package_name = $native_assertions['package'] ?? '';
	$native_dir          = $native_dirs[ $native_package_name ] ?? null;
	$assert( is_string( $native_dir ), "Native package {$native_file} must name an existing native package directory." );
	$native_path = $native_dir . '/' . $native_file;
	$assert( is_file( $native_path ), "Missing native agent package file: {$native_file}" );

	$native_package = $read_json( $native_path );

	$agent = $native_package['agent'] ?? array();
	$assert( is_array( $agent ), "Native package {$native_file} must declare an agent object." );
	$source_revision = (string) ( $native_assertions['source_revision'] ?? '' );
	$assert( '' !== $source_revision, "Native package {$native_file} must declare its immutable source revision in the test spec." );
	$assert( "Automattic/docs-agent@{$source_revision}" === ( $native_package['source_ref'] ?? '' ), "Native package {$native_file} source_ref must resolve to the commit that contains this package." );
	$assert( $source_revision === ( $native_package['source_revision'] ?? '' ), "Native package {$native_file} source_revision must identify the commit that contains this package." );
	$assert( $source_revision === ( $agent['meta']['source_version'] ?? '' ), "Native package {$native_file} imported source_version must identify the package commit." );

	// agents-api importer required shape.
	$assert( ( $native_assertions['agent_slug'] ?? '' ) === ( $agent['agent_slug'] ?? '' ), "Native package {$native_file} agent_slug mismatch." );
	$assert( is_string( $agent['agent_name'] ?? null ) && '' !== trim( (string) $agent['agent_name'] ), "Native package {$native_file} must declare a non-empty agent_name." );
	$assert( is_array( $agent['agent_config'] ?? null ), "Native package {$native_file} must declare an agent_config object." );
	$assert( is_array( $agent['meta'] ?? null ), "Native package {$native_file} must declare a meta object." );

	$config = $agent['agent_config'];
	$assert( is_string( $config['instructions'] ?? null ) && '' !== trim( (string) $config['instructions'] ), "Native package {$native_file} agent_config.instructions must be a non-empty string." );
	$tools = $config['enabled_tools'] ?? array();
	$assert( is_array( $tools ) && array() !== $tools, "Native package {$native_file} agent_config.enabled_tools must be a non-empty list." );

	foreach ( $native_assertions['required_tools'] ?? array() as $required_tool ) {
		$assert( in_array( $required_tool, $tools, true ), "Native package {$native_file} missing required tool: {$required_tool}" );
	}

	$workspace_tools = array_values(
		array_filter(
			$tools,
			static fn( $tool ): bool => is_string( $tool ) && str_starts_with( $tool, 'workspace_' )
		)
	);
	$assert( ( $native_assertions['permitted_workspace_tools'] ?? array() ) === $workspace_tools, "Native package {$native_file} workspace tools do not match its permitted workspace tool policy." );

	$write_gate_id = $native_assertions['required_write_gate'] ?? '';
	$write_gates   = array_filter(
		$config['tool_call_rules'] ?? array(),
		static fn( $rule ): bool => is_array( $rule ) && $write_gate_id === ( $rule['id'] ?? '' )
	);
	$write_gate = reset( $write_gates );
	$assert( is_array( $write_gate ), "Native package {$native_file} must declare its required write gate." );
	$assert( true === ( $write_gate['require_tool_use'] ?? false ), "Native package {$native_file} write gate must require tool use." );
	$assert( 1 === ( $write_gate['min_tool_calls'] ?? 0 ), "Native package {$native_file} write gate must require at least one write." );
	$assert( array( 'workspace_write', 'workspace_edit', 'workspace_apply_patch' ) === ( $write_gate['require_one_of'] ?? array() ), "Native package {$native_file} write gate must require an allowed workspace write tool." );

	// Runner-neutral boundary: no agent-owned publication tools.
	foreach ( $native_spec['forbidden_publication_tools'] ?? array() as $forbidden_tool ) {
		$assert( ! in_array( $forbidden_tool, $tools, true ), "Native package {$native_file} must not enable publication tool: {$forbidden_tool}" );
	}

	$instructions = strtolower( (string) $config['instructions'] );
	foreach ( $native_assertions['instructions_must_contain'] ?? array() as $required_phrase ) {
		$assert( str_contains( $instructions, strtolower( (string) $required_phrase ) ), "Native package {$native_file} instructions missing required text: {$required_phrase}" );
	}
}

fwrite( STDOUT, "Docs Agent native package validation passed.\n" );

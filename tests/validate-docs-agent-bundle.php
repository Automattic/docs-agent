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

$expected_artifact_schemas = array(
	'docs_agent_transcript'             => 'docs-agent/transcript/v1',
	'docs_agent_change_summary'        => 'docs-agent/change-summary/v1',
	'docs_agent_verification_report'   => 'docs-agent/verification-report/v1',
	'docs_agent_drift_report'          => 'docs-agent/drift-report/v1',
	'docs_agent_workspace_publication' => 'docs-agent/workspace-publication/v1',
);

$spec = $read_json( $root . '/tests/docs-agent.validate-bundle-spec.json' );

foreach ( $spec['bundles'] ?? array() as $bundle_name => $bundle_spec ) {
	$bundle_dir = realpath( $root . '/tests/' . ( $bundle_spec['bundle_dir'] ?? '' ) );
	$assert( is_string( $bundle_dir ) && is_dir( $bundle_dir ), "Spec bundle_dir must point to an existing directory for {$bundle_name}." );

	$manifest = $read_json( $bundle_dir . '/manifest.json' );
	$assert( ( $bundle_spec['bundle_slug'] ?? '' ) === ( $manifest['bundle_slug'] ?? '' ), "Manifest bundle_slug mismatch for {$bundle_name}." );
	$assert( ( $bundle_spec['agent_slug'] ?? '' ) === ( $manifest['agent']['slug'] ?? '' ), "Manifest agent slug mismatch for {$bundle_name}." );
	$assert( preg_match( '/^Automattic\/docs-agent@v\d+\.\d+\.\d+$/', (string) ( $manifest['source_ref'] ?? '' ) ) === 1, "Manifest source_ref must point at a Docs Agent release tag for {$bundle_name}." );
	$assert( preg_match( '/^[0-9a-f]{40}$/', (string) ( $manifest['source_revision'] ?? '' ) ) === 1, "Manifest source_revision must be a 40-character commit SHA for {$bundle_name}." );
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
		if ( str_contains( $pipeline_slug, 'docs-' ) || str_contains( $pipeline_slug, 'skills-' ) ) {
			foreach ( array( 'runner', 'workspace_git_add', 'workspace_git_commit', 'workspace_git_push', 'create_github_pull_request', 'commit, push', 'open one reviewable pull request' ) as $forbidden_prompt_text ) {
				$assert( ! str_contains( $system_prompt, $forbidden_prompt_text ), "Pipeline {$pipeline_slug} system prompt must not reference agent-owned publication: {$forbidden_prompt_text}" );
			}
			$assert( str_contains( $system_prompt, 'provided workspace' ) || str_contains( $system_prompt, 'selected flow' ), "Pipeline {$pipeline_slug} must keep agent instructions scoped to workspace editing." );
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
			$assert( empty( $completion_assertions['required_tool_names'] ?? array() ), "Bootstrap flow {$flow_slug} must not require publication tools." );
			$assert( str_contains( $flow_prompt, 'provided workspace' ), "Bootstrap flow {$flow_slug} must direct agents to use the provided workspace." );
			foreach ( array( 'workspace_git_add', 'workspace_git_commit', 'workspace_git_push', 'create_github_pull_request', 'comment_github_pull_request' ) as $forbidden_tool ) {
				$assert( ! in_array( $forbidden_tool, $tools, true ), "Bootstrap flow {$flow_slug} must not enable {$forbidden_tool}." );
			}
			foreach ( array( 'workspace_git_add', 'workspace_git_commit', 'workspace_git_push', 'create_github_pull_request' ) as $forbidden_prompt_text ) {
				$assert( ! str_contains( $flow_prompt, $forbidden_prompt_text ), "Bootstrap flow {$flow_slug} prompt must not reference {$forbidden_prompt_text}." );
			}
			$assert( ! str_contains( $flow_prompt, 'create_or_update_github_file' ), "Bootstrap flow {$flow_slug} must not reference direct GitHub file writes." );
			foreach ( array( 'future coverage', 'deferred', 'saved for later', 'backlog' ) as $backlog_phrase ) {
				$assert( ! str_contains( $flow_prompt, $backlog_phrase ), "Bootstrap flow {$flow_slug} must avoid backlog language: {$backlog_phrase}" );
			}

			$required_phrases = array( 'complete initial documentation system', 'documentation information architecture', 'separate', 'digestible', 'hierarchy mirrors', 'parent/child relationships', 'cross-links', 'completed written documentation system', 'write topic pages first', 'index last', 'link to pages that exist in the repository', 'bootstrap contract', 'required paths', 'required glob counts', 'entry point links', 'positive completion criteria' );
			$required_phrases = array_merge( $required_phrases, str_starts_with( $flow_slug, 'technical-' ) ? array( 'source inventory', 'preserve or improve', 'reference-level details', 'representative payloads or examples', 'reconcile the written docs against the source inventory' ) : array( 'private inventory', 'frontend users', 'user docs index', 'docs/user/', 'practical product details', 'reconcile the written docs against the private product inventory' ) );
			foreach ( $required_phrases as $phrase ) {
				$assert( str_contains( $flow_prompt, strtolower( $phrase ) ), "Bootstrap flow {$flow_slug} missing phrase: {$phrase}" );
			}
		}
		if ( str_contains( $flow_slug, 'maintenance' ) ) {
			foreach ( array( 'maintenance pass', 'focused updates', 'no_changes' ) as $phrase ) {
				$assert( str_contains( $flow_prompt, $phrase ), "Maintenance flow {$flow_slug} missing phrase: {$phrase}" );
			}
		}
		if ( str_contains( $flow_slug, 'docs-' ) || str_contains( $flow_slug, 'skills-' ) ) {
			foreach ( array( 'workspace_git_add', 'workspace_git_commit', 'workspace_git_push', 'create_github_pull_request', 'comment_github_pull_request' ) as $forbidden_tool ) {
				$assert( ! in_array( $forbidden_tool, $tools, true ), "Workspace-editing flow {$flow_slug} must not enable {$forbidden_tool}." );
			}
			foreach ( array( 'runner', 'workspace_git_add', 'workspace_git_commit', 'workspace_git_push', 'create_github_pull_request', 'commit, push', 'publish the pull request', 'open one' ) as $forbidden_prompt_text ) {
				$assert( ! str_contains( $flow_prompt, $forbidden_prompt_text ), "Workspace-editing flow {$flow_slug} prompt must not reference {$forbidden_prompt_text}." );
			}
			$assert( str_contains( $flow_prompt, 'workspace_git_status' ) && str_contains( $flow_prompt, 'workspace_git_diff' ), "Workspace-editing flow {$flow_slug} must end by inspecting status and diff." );
		}
	}
}

$example_path = $root . '/tests/' . ( $spec['example_runner_config'] ?? '' );
$example      = $read_json( $example_path );
$recipe       = $read_json( $root . '/ci/docs-agent-runner-recipe.json' );
foreach ( $spec['example_assertions'] ?? array() as $key => $expected ) {
	$assert( $expected === ( $example[ $key ] ?? null ), "Example config {$key} mismatch." );
}

$runner_recipe = $example['runner_recipe'] ?? array();
$assert( 'docs-agent/datamachine-agent-ci' === ( $runner_recipe['id'] ?? null ), 'Example config must prefer the Docs Agent runner recipe id.' );
$assert( '.ci/docs-agent/ci/docs-agent-runner-recipe.json' === ( $runner_recipe['source'] ?? null ), 'Example config must point at the reusable Docs Agent runner recipe.' );
$assert( '.ci/docs-agent/ci/resolve-docs-agent-runner-recipe.php' === ( $runner_recipe['resolved_by'] ?? null ), 'Example config must point at the recipe resolver.' );

$assert( 'docs-agent/datamachine-agent-ci' === ( $recipe['id'] ?? null ), 'Runner recipe must declare the Docs Agent recipe id.' );
$assert( 'wp-codebox' === ( $recipe['runtime_provider'] ?? null ), 'Runner recipe must declare the current runtime provider compatibility value.' );
$assert( 'datamachine-agent-ci' === ( $recipe['runtime_profile'] ?? null ), 'Runner recipe must declare the Data Machine agent runtime profile.' );
$assert( isset( $recipe['workspace_policy']['mount']['target'] ) && '/wordpress/wp-content/mu-plugins/docs-agent-workspace-policy.php' === $recipe['workspace_policy']['mount']['target'], 'Runner recipe must centralize the workspace policy mu-plugin mount.' );
$assert( in_array( 'datamachine/run-flow', $recipe['required_abilities'] ?? array(), true ), 'Runner recipe must centralize required Docs Agent runtime abilities.' );

$runner_workspace = $example['runner_workspace'] ?? array();
$assert( ! empty( $runner_workspace['enabled'] ), 'Example config must enable runner-owned workspace provisioning.' );
$assert( 'docs/agent-run' === ( $runner_workspace['branch_prefix'] ?? null ), 'Example config must declare the docs branch prefix.' );
$assert( 'bundle' === ( $example['runtime_execution']['kind'] ?? null ), 'Example config must declare a bundle runtime_execution.' );
$assert( '.ci/docs-agent/bundles/technical-docs-agent' === ( $example['runtime_execution']['source'] ?? null ), 'Example config must point runtime_execution at the Docs Agent bundle checkout.' );
$assert( in_array( 'Automattic/docs-agent@v0.1.0', $example['validation_dependencies'] ?? array(), true ), 'Example config must keep Docs Agent as a validation dependency.' );
$assert( is_file( $root . '/scripts/repair-docs-links.php' ), 'Docs link repair script must be available to consumer workflows.' );

$maintain_docs_workflow = (string) file_get_contents( $root . '/.github/workflows/maintain-docs.yml' );
foreach ( array( 'context_repositories:', 'verification_commands:', 'drift_checks:', 'bootstrap_contract:', 'workspace_contract_checks:', 'Bootstrap contract success criteria:', 'paths_exist:', 'glob_min_count:', 'required_paths', 'required_globs' ) as $required_workflow_text ) {
	$assert( str_contains( $maintain_docs_workflow, $required_workflow_text ), "maintain-docs.yml missing required text: {$required_workflow_text}" );
}
$assert( str_contains( $maintain_docs_workflow, 'WORKFLOW_REF: ${{ github.workflow_ref }}' ), 'maintain-docs.yml must expose the reusable workflow ref to provenance resolution.' );
$assert( str_contains( $maintain_docs_workflow, 'docs_agent_ref="${workflow_ref##*@}"' ), 'maintain-docs.yml must default docs_agent_ref from the pinned reusable workflow ref.' );
$assert( str_contains( $maintain_docs_workflow, 'context_repositories: ${{ needs.prepare.outputs.context_repositories }}' ), 'maintain-docs.yml must pass context_repositories through to the canonical runner.' );
$assert( str_contains( $maintain_docs_workflow, 'verification_commands: ${{ needs.prepare.outputs.verification_commands }}' ), 'maintain-docs.yml must pass verification_commands through to the canonical runner.' );
$assert( str_contains( $maintain_docs_workflow, 'drift_checks: ${{ needs.prepare.outputs.drift_checks }}' ), 'maintain-docs.yml must pass drift_checks through to the canonical runner.' );
$assert( str_contains( $maintain_docs_workflow, 'workspace_contract_checks: ${{ needs.prepare.outputs.workspace_contract_checks }}' ), 'maintain-docs.yml must pass workspace_contract_checks through to the canonical runner.' );
$assert( str_contains( $maintain_docs_workflow, 'max_turns: ${{ inputs.max_turns }}' ), 'maintain-docs.yml must pass max_turns through to the canonical runner.' );
$assert( str_contains( $maintain_docs_workflow, 'step_budget: ${{ inputs.step_budget }}' ), 'maintain-docs.yml must pass step_budget through to the canonical runner.' );
$assert( str_contains( $maintain_docs_workflow, 'time_budget_ms: ${{ inputs.time_budget_ms }}' ), 'maintain-docs.yml must pass time_budget_ms through to the canonical runner.' );

$assert( preg_match( '/max_turns:\s*\n\s+description:[^\n]*\n\s+type:\s*number\n\s+default:\s*(\d+)/', $maintain_docs_workflow, $max_turns_match ) === 1, 'maintain-docs.yml must declare a numeric max_turns default.' );
$assert( (int) $max_turns_match[1] >= 48, 'maintain-docs.yml max_turns default must be at least 48 for multi-file bootstrap contracts.' );
$assert( preg_match( '/step_budget:\s*\n\s+description:[^\n]*\n\s+type:\s*number\n\s+default:\s*(\d+)/', $maintain_docs_workflow, $step_budget_match ) === 1, 'maintain-docs.yml must declare a numeric step_budget default.' );
$assert( (int) $step_budget_match[1] >= 64, 'maintain-docs.yml step_budget default must be at least 64 for multi-file bootstrap contracts.' );
$assert( preg_match( '/time_budget_ms:\s*\n\s+description:[^\n]*\n\s+type:\s*number\n\s+default:\s*(\d+)/', $maintain_docs_workflow, $time_budget_match ) === 1, 'maintain-docs.yml must declare a numeric time_budget_ms default.' );
$assert( (int) $time_budget_match[1] >= 1200000, 'maintain-docs.yml time_budget_ms default must be at least 1200000 for multi-file bootstrap contracts.' );
$assert( str_contains( $maintain_docs_workflow, 'allowed_repos: \'["${{ github.repository }}"]\'' ), 'maintain-docs.yml must keep the target repository as the only Docs Agent writable repository boundary.' );
$assert( ! str_contains( $maintain_docs_workflow, 'Automattic/studio' ), 'maintain-docs.yml must not hardcode downstream Studio context.' );
$assert( ! str_contains( $maintain_docs_workflow, 'WordPress/agent-skills' ), 'maintain-docs.yml must not hardcode downstream skills context.' );
$assert( str_contains( $maintain_docs_workflow, 'declared_artifacts_json:' ), 'maintain-docs.yml must expose typed artifact declarations as a reusable workflow output.' );
$assert( str_contains( $maintain_docs_workflow, 'expected_artifacts<<EOF' ), 'maintain-docs.yml must prepare typed artifact declarations without caller-specific projections.' );
$assert( str_contains( $maintain_docs_workflow, 'artifact_declarations<<EOF' ), 'maintain-docs.yml must prepare typed artifact declarations without caller-specific projections.' );
$assert( str_contains( $maintain_docs_workflow, 'uses: Extra-Chill/homeboy-extensions/.github/workflows/runtime-agent-full-run.yml@main' ), 'maintain-docs.yml must use the generic runtime agent full-run workflow.' );
$assert( str_contains( $maintain_docs_workflow, 'homeboy_extensions_ref: main' ), 'maintain-docs.yml must run matching Homeboy Extensions scripts for agent runtime inputs.' );
$assert( str_contains( $maintain_docs_workflow, 'Checkout docs-agent recipe' ), 'maintain-docs.yml must check out Docs Agent before resolving the runner recipe.' );
$assert( str_contains( $maintain_docs_workflow, 'Resolve runner recipe' ), 'maintain-docs.yml must resolve the centralized runner recipe.' );
$assert( str_contains( $maintain_docs_workflow, 'expected_artifacts: ${{ needs.prepare.outputs.expected_artifacts }}' ), 'maintain-docs.yml must pass expected_artifacts through to the canonical runner.' );
$assert( str_contains( $maintain_docs_workflow, 'artifact_declarations: ${{ needs.prepare.outputs.artifact_declarations }}' ), 'maintain-docs.yml must pass artifact_declarations through to the canonical runner.' );

foreach ( array( 'runtime_provider: ${{ needs.prepare.outputs.runtime_provider }}', 'runtime_ref: ${{ needs.prepare.outputs.runtime_ref }}', 'runtime_profile: ${{ needs.prepare.outputs.runtime_profile }}', 'runtime_profiles: ${{ needs.prepare.outputs.runtime_profiles }}', 'runtime_execution:', 'runtime_dependencies: ${{ needs.prepare.outputs.runtime_dependencies }}', 'runtime_components: ${{ needs.prepare.outputs.runtime_components }}', 'runtime_mounts: ${{ needs.prepare.outputs.runtime_mounts }}', 'required_abilities: ${{ needs.prepare.outputs.required_abilities }}', 'validation_dependencies:' ) as $runtime_input ) {
	$assert( str_contains( $maintain_docs_workflow, $runtime_input ), "maintain-docs.yml must use {$runtime_input}." );
}
$assert( str_contains( $maintain_docs_workflow, 'replay_bundle_artifact_name:' ), 'maintain-docs.yml must upload replay bundle artifacts.' );
$assert( str_contains( $maintain_docs_workflow, 'runtime_config:' ), 'maintain-docs.yml must preserve Data Machine runtime config explicitly.' );
$assert( ! str_contains( $maintain_docs_workflow, 'datamachine-agent-ci.yml' ), 'maintain-docs.yml must not use the legacy Data Machine adapter workflow.' );
$assert( ! str_contains( $maintain_docs_workflow, 'bundle_path: ' ), 'maintain-docs.yml must use runtime_execution instead of bundle_path.' );
$assert( ! str_contains( $maintain_docs_workflow, 'bundle_repo:' ), 'maintain-docs.yml must use validation_dependencies instead of bundle_repo.' );
$assert( ! str_contains( $maintain_docs_workflow, 'bundle_ref:' ), 'maintain-docs.yml must use validation_dependencies instead of bundle_ref.' );
$assert( ! str_contains( $maintain_docs_workflow, 'bundle_path_in_repo:' ), 'maintain-docs.yml must use runtime_execution instead of bundle_path_in_repo.' );
$assert( ! str_contains( $maintain_docs_workflow, 'agent_runtime:' ), 'maintain-docs.yml must use runtime_provider instead of agent_runtime.' );
$assert( ! str_contains( $maintain_docs_workflow, 'agent_runtime_ref:' ), 'maintain-docs.yml must use runtime_ref instead of agent_runtime_ref.' );
$assert( ! str_contains( $maintain_docs_workflow, 'extra_required_abilities:' ), 'maintain-docs.yml must use required_abilities instead of extra_required_abilities.' );
$assert( ! str_contains( $maintain_docs_workflow, 'wp_codebox_ref:' ), 'maintain-docs.yml must not use wp_codebox_ref.' );
$assert( ! str_contains( $maintain_docs_workflow, 'extra_wp_codebox_mounts:' ), 'maintain-docs.yml must not use extra_wp_codebox_mounts.' );
$assert( ! str_contains( $maintain_docs_workflow, 'agents_api_ref:' ), 'maintain-docs.yml must use runtime_dependencies instead of agents_api_ref.' );
$assert( ! str_contains( $maintain_docs_workflow, 'data_machine_ref:' ), 'maintain-docs.yml must use runtime_dependencies instead of data_machine_ref.' );
$assert( ! str_contains( $maintain_docs_workflow, 'data_machine_code_ref:' ), 'maintain-docs.yml must use runtime_dependencies instead of data_machine_code_ref.' );
$assert( ! str_contains( $maintain_docs_workflow, 'engine_data_outputs:' ), 'maintain-docs.yml must use runtime_output_projections instead of engine_data_outputs.' );

$workflow_readme = (string) file_get_contents( $root . '/.github/workflows/README.md' );
foreach ( array( 'Docs Agent Runner Recipe', 'Extra-Chill/homeboy-extensions@main', 'Automattic/docs-agent#100', 'runtime-agent-full-run.yml', 'docs-agent/datamachine-agent-ci', 'resolve-docs-agent-runner-recipe.php', 'runtime_dependencies', 'runtime_components', 'runtime_mounts' ) as $migration_note_text ) {
	$assert( str_contains( $workflow_readme, $migration_note_text ), "Workflow README missing agent runtime note: {$migration_note_text}" );
}

$docs_agent_workflow = (string) file_get_contents( $root . '/.github/workflows/docs-agent.yml' );
foreach ( array( 'runtime_output_projections:', 'transcript_artifact_name:', 'expected_artifacts:', 'artifact_declarations:', 'homeboy_extensions_ref: main' ) as $required_central_workflow_text ) {
	$assert( str_contains( $docs_agent_workflow, $required_central_workflow_text ), "docs-agent.yml missing existing compatibility output: {$required_central_workflow_text}" );
}
foreach ( array( 'uses: Extra-Chill/homeboy-extensions/.github/workflows/runtime-agent-full-run.yml@main', 'Resolve runner recipe', 'runtime_provider: ${{ needs.prepare.outputs.runtime_provider }}', 'runtime_ref: ${{ needs.prepare.outputs.runtime_ref }}', 'runtime_profile: ${{ needs.prepare.outputs.runtime_profile }}', 'runtime_profiles: ${{ needs.prepare.outputs.runtime_profiles }}', 'runtime_execution:', 'runtime_dependencies: ${{ needs.prepare.outputs.runtime_dependencies }}', 'runtime_components: ${{ needs.prepare.outputs.runtime_components }}', 'runtime_mounts: ${{ needs.prepare.outputs.runtime_mounts }}', 'required_abilities: ${{ needs.prepare.outputs.required_abilities }}' ) as $central_runtime_input ) {
	$assert( str_contains( $docs_agent_workflow, $central_runtime_input ), "docs-agent.yml must use {$central_runtime_input}." );
}
$assert( str_contains( $docs_agent_workflow, 'replay_bundle_artifact_name:' ), 'docs-agent.yml must upload replay bundle artifacts.' );
$assert( ! str_contains( $docs_agent_workflow, 'datamachine-agent-ci.yml' ), 'docs-agent.yml must not use the legacy Data Machine adapter workflow.' );
$assert( ! str_contains( $docs_agent_workflow, 'bundle_path: ' ), 'docs-agent.yml must use runtime_execution instead of bundle_path.' );
$assert( ! str_contains( $docs_agent_workflow, 'extra_required_abilities:' ), 'docs-agent.yml must use required_abilities instead of extra_required_abilities.' );
$assert( ! str_contains( $docs_agent_workflow, 'agent_runtime:' ), 'docs-agent.yml must use runtime_provider instead of agent_runtime.' );
$assert( ! str_contains( $docs_agent_workflow, 'agent_runtime_ref:' ), 'docs-agent.yml must use runtime_ref instead of agent_runtime_ref.' );
$assert( ! str_contains( $docs_agent_workflow, 'wp_codebox_ref:' ), 'docs-agent.yml must not use wp_codebox_ref.' );
$assert( ! str_contains( $docs_agent_workflow, 'extra_wp_codebox_mounts:' ), 'docs-agent.yml must not use extra_wp_codebox_mounts.' );
$assert( ! str_contains( $docs_agent_workflow, 'validation_dependencies: Automattic/agents-api@' ), 'docs-agent.yml must let Homeboy runtime inputs supply runtime validation dependencies.' );
$assert( ! str_contains( $docs_agent_workflow, 'agents_api_ref:' ), 'docs-agent.yml must use runtime_dependencies instead of agents_api_ref.' );
$assert( ! str_contains( $docs_agent_workflow, 'data_machine_ref:' ), 'docs-agent.yml must use runtime_dependencies instead of data_machine_ref.' );
$assert( ! str_contains( $docs_agent_workflow, 'data_machine_code_ref:' ), 'docs-agent.yml must use runtime_dependencies instead of data_machine_code_ref.' );
$assert( ! str_contains( $docs_agent_workflow, 'engine_data_outputs:' ), 'docs-agent.yml must use runtime_output_projections instead of engine_data_outputs.' );

$declared_artifact_names = array_keys( $expected_artifact_schemas );
foreach ( $declared_artifact_names as $artifact_name ) {
	$assert( str_contains( $maintain_docs_workflow, $artifact_name ), "maintain-docs.yml missing typed artifact declaration {$artifact_name}." );
}

$skills_example = (string) file_get_contents( $root . '/examples/build-with-wordpress-skills-workflow.yml' );
foreach ( array( 'audience: skills', 'base_ref: trunk', 'docs_branch: docs-agent/build-with-wordpress-skills', 'context_repositories:', 'Automattic/studio', 'WordPress/agent-skills', 'verification_commands:', 'drift_checks:' ) as $required_example_text ) {
	$assert( str_contains( $skills_example, $required_example_text ), "build-with-wordpress skills example missing required text: {$required_example_text}" );
}
$assert( ! str_contains( $skills_example, 'prompt:' ), 'build-with-wordpress skills example must use canonical runner inputs instead of prompt boilerplate.' );

$example_text = (string) file_get_contents( $example_path );
foreach ( array( '/path/to', '/Users/', 'localhost', '127.0.0.1' ) as $local_path_fragment ) {
	$assert( ! str_contains( $example_text, $local_path_fragment ), "Example runner config must not contain local-only path or host fragment: {$local_path_fragment}" );
}

$example_artifacts = $example['expected_artifacts'] ?? null;
$assert( is_array( $example_artifacts ), 'Example runner config must include expected artifact names.' );
$example_declarations = $example['artifact_declarations'] ?? null;
$assert( is_array( $example_declarations ), 'Example runner config must include typed artifact declarations.' );
$example_artifacts_by_name = array();
foreach ( $example_declarations as $artifact ) {
	$assert( is_array( $artifact ), 'Example runner config typed artifact entries must be objects.' );
	$example_artifacts_by_name[ (string) ( $artifact['name'] ?? '' ) ] = $artifact;
}
foreach ( $expected_artifact_schemas as $name => $schema ) {
	$assert( in_array( $name, $example_artifacts, true ), "Example runner config expected_artifacts missing {$name}." );
	$assert( isset( $example_artifacts_by_name[ $name ] ), "Example runner config missing typed artifact {$name}." );
	$assert( 'wp-codebox/artifact-declaration/v1' === ( $example_artifacts_by_name[ $name ]['schema'] ?? '' ), "Example runner config typed artifact {$name} declaration schema mismatch." );
	$assert( $schema === ( $example_artifacts_by_name[ $name ]['artifact_schema'] ?? '' ), "Example runner config typed artifact {$name} schema mismatch." );
}

fwrite( STDOUT, "Docs Agent bundle validation passed.\n" );

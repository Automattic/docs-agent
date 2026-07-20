<?php
/**
 * Validate Docs Agent's reusable workflow call against WP Codebox's published
 * interface fixture. Run with WP_CODEBOX_DIR pointing at the producer checkout.
 */

declare( strict_types=1 );

$root           = dirname( __DIR__ );
$wp_codebox_dir = getenv( 'WP_CODEBOX_DIR' );

if ( ! is_string( $wp_codebox_dir ) || '' === $wp_codebox_dir ) {
	throw new RuntimeException( 'WP_CODEBOX_DIR must point at a WP Codebox checkout.' );
}

$read_json = static function ( string $path ): array {
	if ( ! is_file( $path ) ) {
		throw new RuntimeException( "Missing producer contract: {$path}" );
	}

	$data = json_decode( (string) file_get_contents( $path ), true );
	if ( ! is_array( $data ) ) {
		throw new RuntimeException( "Invalid producer contract: {$path}: " . json_last_error_msg() );
	}

	return $data;
};

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$result_value = static function ( array $value, string $path ) {
	$current = $value;
	foreach ( explode( '.', $path ) as $segment ) {
		if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
			return null;
		}
		$current = $current[ $segment ];
	}

	return $current;
};

$contract = $read_json( rtrim( $wp_codebox_dir, '/' ) . '/contracts/run-agent-task-reusable-workflow-interface.v1.json' );
$assert( 'wp-codebox/reusable-workflow-interface/v1' === ( $contract['schema'] ?? null ), 'WP Codebox producer schema version mismatch.' );
$assert( '.github/workflows/run-agent-task.yml' === ( $contract['workflow'] ?? null ), 'WP Codebox producer contract targets an unexpected workflow.' );
$release = $read_json( $root . '/tests/wp-codebox-release.fixture.json' );
$release_tag = $release['tag'] ?? null;
$assert( is_string( $release_tag ) && preg_match( '/^v\d+\.\d+\.\d+$/', $release_tag ) === 1, 'WP Codebox release fixture must declare an exact release tag.' );
$release_revision = $release['revision'] ?? null;
$assert( is_string( $release_revision ) && preg_match( '/^[0-9a-f]{40}$/', $release_revision ) === 1, 'WP Codebox release fixture must declare the immutable tag commit.' );
$producer_candidate_revision = $release['producer_revision'] ?? null;
$assert( is_string( $producer_candidate_revision ) && preg_match( '/^[0-9a-f]{40}$/', $producer_candidate_revision ) === 1, 'WP Codebox release fixture must declare the immutable reusable-workflow producer candidate.' );
$assert( substr( $release_tag, 1 ) === ( $release['package_version'] ?? null ), 'WP Codebox release fixture package version must match its tag.' );
$published_assets = $release['published_assets'] ?? null;
$assert( is_array( $published_assets ), 'WP Codebox release fixture must record published release assets.' );
$assert( array(
	'01-wp-codebox.zip' => 'sha256:a239c72ae79c5b24fe7bed9ccfd375c0b70892ca66406d98140a51d219cfaaee',
	'02-wp-codebox-workspace-0.12.29.tgz' => 'sha256:d332924b3b91ef8e47b5ebca7063b2964734d3ac081dd62fa18ec9417fefdcd5',
) === $published_assets, 'WP Codebox release fixture must retain the published v0.12.29 asset digests.' );
$run = $release['run'] ?? null;
$assert( is_string( $run ) && preg_match( '/^\d+$/', $run ) === 1, 'WP Codebox release fixture must retain the regression run reference.' );
$diagnostic_regression_run = $release['diagnostic_regression_run'] ?? null;
$assert( is_string( $diagnostic_regression_run ) && preg_match( '/^\d+$/', $diagnostic_regression_run ) === 1, 'WP Codebox release fixture must retain the diagnostic regression run reference.' );
$upload_regression_run = $release['upload_regression_run'] ?? null;
$assert( is_string( $upload_regression_run ) && preg_match( '/^\d+$/', $upload_regression_run ) === 1, 'WP Codebox release fixture must retain the upload-layout regression run reference.' );
$assert( true === ( $release['successful_noop_optional_artifacts'] ?? null ), 'WP Codebox release fixture must cover successful no-op optional artifacts.' );
$assert( true === ( $release['optional_output_projections'] ?? null ), 'WP Codebox release fixture must cover optional output projections.' );
$assert( true === ( $release['validation_dependencies_caller_owned'] ?? null ), 'WP Codebox release fixture must cover caller-owned validation dependencies.' );
$assert( true === ( $release['private_runtime_sources_absent'] ?? null ), 'WP Codebox release fixture must cover private runtime-source isolation.' );
$assert( true === ( $release['private_runtime_path_sanitization'] ?? null ), 'WP Codebox release fixture must require private runtime-path sanitization.' );
$assert( true === ( $release['allowlisted_uploads'] ?? null ), 'WP Codebox release fixture must require allowlisted uploads.' );
$assert( true === ( $release['normalized_failures'] ?? null ), 'WP Codebox release fixture must require normalized failures.' );
$assert( true === ( $release['diagnostic_class_names_allowed'] ?? null ), 'WP Codebox release fixture must permit diagnostic class names.' );
$assert( true === ( $release['php_shaped_source_blocked'] ?? null ), 'WP Codebox release fixture must block PHP-shaped runtime source.' );
$assert( true === ( $release['imported_package_identity_execution'] ?? null ), 'WP Codebox release fixture must execute the imported package identity.' );
$assert( true === ( $release['sandbox_local_copy_on_write_workspace_tools'] ?? null ), 'WP Codebox release fixture must cover sandbox-local copy-on-write workspace tools.' );
$assert( true === ( $release['host_patch_apply_verification_publication'] ?? null ), 'WP Codebox release fixture must cover host patch apply, verification, and publication.' );
$assert( true === ( $release['provenance_only_uploads'] ?? null ), 'WP Codebox release fixture must cover provenance-only uploads.' );
$assert( true === ( $release['canonical_task_input_workspace_handoff'] ?? null ), 'WP Codebox release fixture must cover canonical task_input workspace handoff.' );
$assert( true === ( $release['external_secret_filtered_seed_snapshot'] ?? null ), 'WP Codebox release fixture must cover external secret-filtered workspace seed snapshots.' );
$assert( true === ( $release['actionable_patch_normalization'] ?? null ), 'WP Codebox release fixture must cover actionable patch normalization.' );
$assert( true === ( $release['failure_evidence_preserved'] ?? null ), 'WP Codebox release fixture must preserve normalized runtime evidence on failures.' );
$assert( true === ( $release['canonical_compact_reviewer_transcript_upload'] ?? null ), 'WP Codebox release fixture must upload the canonical compact reviewer transcript.' );
$assert( true === ( $release['canonical_transcript_privacy_boundary'] ?? null ), 'WP Codebox release fixture must preserve the canonical transcript privacy boundary.' );
$assert( true === ( $release['pre_sanitization_canonical_reviewer_evidence_descriptor'] ?? null ), 'WP Codebox release fixture must preserve the pre-sanitization canonical reviewer-evidence descriptor.' );
$assert( true === ( $release['reviewer_safe_workflow_result_projection'] ?? null ), 'WP Codebox release fixture must require the reviewer-safe workflow-result projection.' );
$assert( true === ( $release['runner_workspace_seed_host_identity_validation'] ?? null ), 'WP Codebox release fixture must validate seed and host identity before apply-back.' );
$assert( true === ( $release['rejected_apply_evidence_retained'] ?? null ), 'WP Codebox release fixture must retain rejected apply evidence.' );
$assert( true === ( $release['pre_redaction_trusted_apply_input'] ?? null ), 'WP Codebox release fixture must preserve the pre-redaction trusted apply input.' );
$assert( true === ( $release['git_ignored_workspace_artifacts_excluded'] ?? null ), 'WP Codebox release fixture must exclude Git-ignored verification artifacts from publication integrity snapshots.' );
$assert( true === ( $release['runtime_control_files_excluded'] ?? null ), 'WP Codebox release fixture must exclude mutable .codebox runtime control files from publication integrity snapshots.' );
$assert( true === ( $release['workspace_integrity_change_evidence'] ?? null ), 'WP Codebox release fixture must preserve bounded workspace integrity change evidence.' );
$assert( true === ( $release['non_git_workspace_snapshot_fallback'] ?? null ), 'WP Codebox release fixture must retain bounded snapshots for non-Git workspaces.' );
$assert( true === ( $release['canonical_repository_casing'] ?? null ), 'WP Codebox release fixture must retain canonical repository casing coverage.' );
$native_result_path = $release['native_result_path'] ?? null;
$workflow_result_path = $release['workflow_result_path'] ?? null;
$assert( '.codebox/native-agent-task-result.json' === $native_result_path, 'WP Codebox release fixture must declare the controlled native result path.' );
$assert( '.codebox/agent-task-workflow-result.json' === $workflow_result_path, 'WP Codebox release fixture must declare the workflow result path.' );
$producer_package = $read_json( rtrim( $wp_codebox_dir, '/' ) . '/package.json' );
$assert( ( $release['package_version'] ?? null ) === ( $producer_package['version'] ?? null ), 'Checked-out WP Codebox package version must match the release fixture.' );
$producer_revision = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $wp_codebox_dir ) . ' rev-parse HEAD' ) );
$assert( $producer_candidate_revision === $producer_revision, 'Checked-out WP Codebox producer must match the immutable reusable-workflow candidate commit.' );

$workflow = (string) file_get_contents( $root . '/.github/workflows/maintain-docs.yml' );
$assert( preg_match( '/^\s*uses: Automattic\/wp-codebox\/\.github\/workflows\/run-agent-task\.yml@' . preg_quote( $producer_candidate_revision, '/' ) . '$/m', $workflow ) === 1, 'Docs Agent must call the immutable WP Codebox producer candidate.' );
$validation_workflow = (string) file_get_contents( $root . '/.github/workflows/validate.yml' );
$assert( str_contains( $validation_workflow, 'repository: Automattic/wp-codebox' ) && str_contains( $validation_workflow, 'ref: ' . $producer_candidate_revision ), 'CI must validate against the same immutable WP Codebox producer candidate.' );
$workflow_readme = (string) file_get_contents( $root . '/.github/workflows/README.md' );
$assert( str_contains( $workflow_readme, 'run `' . $run . '`' ), 'Workflow documentation must retain the regression run reference.' );

$producer_workflow = (string) file_get_contents( rtrim( $wp_codebox_dir, '/' ) . '/.github/workflows/run-agent-task.yml' );
$producer_request_builder = (string) file_get_contents( rtrim( $wp_codebox_dir, '/' ) . '/.github/scripts/run-agent-task/build-codebox-task-request.mjs' );
$producer_execute = (string) file_get_contents( rtrim( $wp_codebox_dir, '/' ) . '/.github/scripts/run-agent-task/execute-native-agent-task.mjs' );
$producer_runtime_sources = (string) file_get_contents( rtrim( $wp_codebox_dir, '/' ) . '/.github/scripts/run-agent-task/materialize-external-native-package.mjs' );
$producer_upload = (string) file_get_contents( rtrim( $wp_codebox_dir, '/' ) . '/.github/scripts/run-agent-task/prepare-agent-task-upload.mjs' );
$producer_upload_policy = (string) file_get_contents( rtrim( $wp_codebox_dir, '/' ) . '/.github/scripts/run-agent-task/artifact-upload-policy.mjs' );
$producer_sanitizer = (string) file_get_contents( rtrim( $wp_codebox_dir, '/' ) . '/.github/scripts/run-agent-task/runtime-source-sanitizer.mjs' );
$producer_result = $read_json( rtrim( $wp_codebox_dir, '/' ) . '/contracts/agent-task-workflow-result.fixture.json' );
$producer_diagnostic_regression = $read_json( rtrim( $wp_codebox_dir, '/' ) . '/fixtures/agent-task-upload-run-' . $diagnostic_regression_run . '.json' );
$producer_upload_regression = $read_json( rtrim( $wp_codebox_dir, '/' ) . '/fixtures/agent-task-upload-run-' . $upload_regression_run . '.json' );
$assert( str_contains( $producer_workflow, 'workspace/.codebox/agent-task-upload' ), 'WP Codebox producer workflow must upload the controlled task bundle.' );
$assert( str_contains( $producer_execute, '"--result-file", nativeResultPath' ), 'WP Codebox producer must pass the native result-file argument.' );
$assert( str_contains( $producer_execute, 'const nativeResultPath = join(controlledCodeboxPath, "native-agent-task-result.json")' ), 'WP Codebox producer must constrain the native result path to .codebox.' );
$assert( str_contains( $producer_execute, 'const resultPath = join(workspace, ".codebox", "agent-task-workflow-result.json")' ), 'WP Codebox producer must write the workflow result at the declared path.' );
$assert( str_contains( $producer_execute, 'async function writeNormalizedFailure(error, request = {})' ), 'WP Codebox producer must normalize lifecycle failures into workflow results.' );
$assert( str_contains( $producer_execute, 'status: "failed"' ) && str_contains( $producer_execute, 'success: false' ), 'WP Codebox normalized failures must report failed status.' );
$assert( str_contains( $producer_upload, 'const declaredPaths = new Set(declaredArtifactPaths(result, declarations(request)))' ), 'WP Codebox uploads must derive artifacts from the declared allowlist.' );
$assert( str_contains( $producer_upload, 'for (const path of declaredPaths)' ), 'WP Codebox uploads must stage only declared reviewer artifacts.' );
$assert( str_contains( $producer_upload, 'agent-task-artifacts", "exclusions.json' ), 'WP Codebox uploads must report excluded source and undeclared artifacts.' );
$assert( str_contains( $producer_upload_policy, 'function containsRuntimeSourceContent(text)' ), 'WP Codebox must distinguish source-shaped content from diagnostics.' );
$assert( str_contains( $producer_upload_policy, 'const PHP_OPENING_TAG' ) && str_contains( $producer_upload_policy, 'const PHP_DECLARATION' ) && str_contains( $producer_upload_policy, 'const WORDPRESS_PLUGIN_HEADER' ), 'WP Codebox source detection must require PHP-shaped source evidence.' );
$assert( str_contains( $producer_workflow, 'workspace/.codebox/agent-task-upload' ) && str_contains( $producer_workflow, 'include-hidden-files: true' ), 'WP Codebox must upload the complete controlled hidden-file bundle.' );
$assert( str_contains( $producer_execute, 'sandbox_tool_policy: {' ), 'WP Codebox producer must include an explicit sandbox tool-policy in the native task request.' );
$assert( str_contains( $producer_execute, 'schema: "wp-codebox/sandbox-tool-policy/v1"' ), 'WP Codebox producer sandbox tool-policy must use the published schema.' );
$assert( str_contains( $producer_execute, 'version: 1' ), 'WP Codebox producer sandbox tool-policy must declare its contract version.' );
$assert( str_contains( $producer_execute, 'tools: runnerWorkspaceTools.map((id) => ({ id, runtime_tool_id: id, execution_location: "sandbox", transport_visibility: "sandbox", allowed: true' ), 'WP Codebox producer sandbox tool-policy must preserve the allowlisted sandbox-local tool mapping.' );
$assert( 'wp-codebox/agent-task-workflow-result/v1' === ( $producer_result['schema'] ?? null ), 'WP Codebox workflow-result fixture schema mismatch.' );
$assert( 'skipped' === ( $producer_result['status'] ?? null ), 'WP Codebox workflow-result fixture must model the skipped contract.' );
$assert( '.codebox/agent-task-request.json' === ( $producer_result['request_path'] ?? null ), 'WP Codebox workflow-result fixture request path mismatch.' );

preg_match( '/uses: Automattic\/wp-codebox\/\.github\/workflows\/run-agent-task\.yml@[^\n]+\n    with:\n(?<inputs>.*?)\n    secrets:\n(?<secrets>(?:      [^\n]*\n?)*)/s', $workflow, $caller );
$assert( isset( $caller['inputs'], $caller['secrets'] ), 'Docs Agent must declare producer inputs and secrets.' );

$caller_inputs = array();
preg_match_all( '/^      (?<name>[a-z_]+): (?<value>[^\n]+)$/m', $caller['inputs'], $matches, PREG_SET_ORDER );
foreach ( $matches as $match ) {
	$caller_inputs[ $match['name'] ] = trim( $match['value'] );
}

foreach ( $caller_inputs as $name => $value ) {
	$input = $contract['inputs'][ $name ] ?? null;
	$assert( is_array( $input ), "Docs Agent passes producer input {$name}, which is absent from the producer schema." );
	$type = $input['type'] ?? null;
	$actual_type = preg_match( '/^(true|false)$/', $value ) === 1 || in_array( $name, array( 'success_requires_pr', 'run_agent', 'dry_run' ), true ) ? 'boolean' : ( is_numeric( $value ) ? 'number' : 'string' );
	$assert( $type === $actual_type, "Docs Agent input {$name} has {$actual_type} shape; producer requires {$type}." );
}

foreach ( $contract['inputs'] ?? array() as $name => $input ) {
	if ( ! empty( $input['required'] ) ) {
		$assert( array_key_exists( $name, $caller_inputs ), "Docs Agent does not satisfy required producer input {$name}." );
	}
}

$assert( $release_tag === ( $caller_inputs['wp_codebox_release_ref'] ?? null ), 'Docs Agent must pass the WP Codebox release tag required by the producer contract.' );
$assert( $producer_candidate_revision === ( $caller_inputs['wp_codebox_workflow_ref'] ?? null ), 'Docs Agent must execute helpers from the accepted WP Codebox producer revision.' );
$assert( '${{ needs.prepare.outputs.runtime_sources }}' === ( $caller_inputs['runtime_sources'] ?? null ), 'Docs Agent must pass its prepared runtime closure to WP Codebox.' );
$assert( '${{ needs.prepare.outputs.validation_dependencies }}' === ( $caller_inputs['validation_dependencies'] ?? null ), 'Docs Agent must pass caller-owned validation dependencies to WP Codebox.' );
$assert( ! isset( $caller_inputs['provider'], $caller_inputs['model'] ), 'Docs Agent must leave provider/model selection to the published WP Codebox contract.' );
$assert( 'string' === ( $contract['inputs']['provider']['type'] ?? null ) && 'openai' === ( $contract['inputs']['provider']['default'] ?? null ), 'WP Codebox must publish the OpenAI provider input.' );
$assert( 'string' === ( $contract['inputs']['model']['type'] ?? null ) && 'gpt-5.5' === ( $contract['inputs']['model']['default'] ?? null ), 'WP Codebox must publish the default model input.' );
$is_coherent_producer_pair = static function ( string $consumer_workflow, string $producer_revision, string $runtime_release_tag ): bool {
	preg_match( '/uses: Automattic\/wp-codebox\/\.github\/workflows\/run-agent-task\.yml@(?<workflow_revision>[^\s]+)/', $consumer_workflow, $workflow_match );
	preg_match( '/^\s+wp_codebox_release_ref: (?<release_tag>[^\s]+)$/m', $consumer_workflow, $release_match );
	preg_match( '/^\s+wp_codebox_workflow_ref: (?<helper_revision>[^\s]+)$/m', $consumer_workflow, $helper_match );

	return isset( $workflow_match['workflow_revision'], $release_match['release_tag'], $helper_match['helper_revision'] )
		&& $producer_revision === $workflow_match['workflow_revision']
		&& $producer_revision === $helper_match['helper_revision']
		&& $runtime_release_tag === $release_match['release_tag'];
};
$assert( $is_coherent_producer_pair( $workflow, $producer_candidate_revision, $release_tag ), 'Docs Agent must pair the immutable producer candidate with the exact packaged runtime release tag.' );
$assert( ! $is_coherent_producer_pair( str_replace( 'wp_codebox_release_ref: ' . $release_tag, 'wp_codebox_release_ref: v0.12.3', $workflow ), $producer_candidate_revision, $release_tag ), 'A mismatched WP Codebox packaged runtime release tag must fail.' );
$assert( ! $is_coherent_producer_pair( str_replace( $producer_candidate_revision, $release_revision, $workflow ), $producer_candidate_revision, $release_tag ), 'A mismatched WP Codebox reusable-workflow producer revision must fail.' );

$caller_secrets = array();
preg_match_all( '/^      (?<name>[A-Z0-9_]+): (?<value>[^\n]+)$/m', $caller['secrets'], $matches, PREG_SET_ORDER );
foreach ( $matches as $match ) {
	$caller_secrets[ $match['name'] ] = trim( $match['value'] );
	$assert( isset( $contract['secrets'][ $match['name'] ] ), "Docs Agent forwards secret {$match['name']}, which is absent from the producer schema." );
}

foreach ( $contract['secrets'] ?? array() as $name => $secret ) {
	if ( ! empty( $secret['required'] ) ) {
		$assert( array_key_exists( $name, $caller_secrets ), "Docs Agent does not satisfy required producer secret {$name}." );
	}
}

$assert( '${{ github.token }}' === ( $caller_secrets['ACCESS_TOKEN'] ?? null ), 'Docs Agent must explicitly forward the caller-scoped GitHub token as ACCESS_TOKEN.' );
$assert( '${{ secrets.OPENAI_API_KEY }}' === ( $caller_secrets['OPENAI_API_KEY'] ?? null ), 'Docs Agent must explicitly forward OPENAI_API_KEY.' );
$assert( '${{ secrets.EXTERNAL_PACKAGE_SOURCE_POLICY }}' === ( $caller_secrets['EXTERNAL_PACKAGE_SOURCE_POLICY'] ?? null ), 'Docs Agent must explicitly forward the external package policy secret.' );
$assert( preg_match( '/secrets:\n      OPENAI_API_KEY:\n(?:        .*\n)*        required: false/m', $workflow ) === 1, 'Docs Agent must expose OPENAI_API_KEY as an optional reusable-workflow secret.' );
$assert( preg_match( '/secrets:\n      ACCESS_TOKEN:/m', $workflow ) !== 1, 'Docs Agent must not require an ACCESS_TOKEN override from reusable-workflow callers.' );
$assert( preg_match( '/secrets:\n(?:      [A-Z_]+:\n(?:        .*\n)*        required: (?:true|false)\n)*      EXTERNAL_PACKAGE_SOURCE_POLICY:\n(?:        .*\n)*        required: true/m', $workflow ) === 1, 'Docs Agent must require EXTERNAL_PACKAGE_SOURCE_POLICY from reusable-workflow callers.' );
$assert( '${{ inputs.run_agent }}' === ( $caller_inputs['run_agent'] ?? null ), 'Docs Agent must delegate run_agent so WP Codebox reports deterministic skipped status.' );
$assert( '${{ inputs.dry_run }}' === ( $caller_inputs['dry_run'] ?? null ), 'Docs Agent must delegate dry_run so WP Codebox validates without starting a live run.' );
$assert( preg_match( '/validation_dependencies:\n        description: Optional caller-owned command that installs dependencies needed before validation\.\n        type: string\n        default: ""/', $workflow ) === 1, 'Docs Agent must expose validation_dependencies as an optional empty-string reusable-workflow input.' );
$assert( preg_match( '/require_pr:\n        description: Require a published target-repository pull request for successful maintenance\.\n        type: boolean\n        default: false/', $workflow ) === 1, 'Docs Agent must expose require_pr as an optional boolean reusable-workflow input.' );
$assert( preg_match( '/\n    if: inputs\.run_agent\n/', $workflow ) !== 1, 'Docs Agent must not skip the producer job outside the producer contract.' );
$assert( preg_match( '/permissions:\n      contents: write\n      pull-requests: write\n      issues: write/', $workflow ) === 1, 'Docs Agent must declare caller-token publication permissions.' );
$assert( str_contains( $workflow, 'if [ "$RUN_AGENT" = true ] && [ "$DRY_RUN" != true ] && [ -z "$OPENAI_API_KEY" ]; then' ), 'Docs Agent must fail closed for a live OpenAI run without OPENAI_API_KEY.' );
$assert( str_contains( $workflow, 'Set run_agent=false or dry_run=true for an explicit non-live path.' ), 'Docs Agent preflight must document skipped and dry-run paths.' );

$producer_outputs = array();
foreach ( $contract['outputs'] ?? array() as $name => $output ) {
	$producer_outputs[ $name ] = $output['value'] ?? null;
	$assert( is_string( $producer_outputs[ $name ] ) && preg_match( '/^\$\{\{ jobs\.run-agent-task\.outputs\.' . preg_quote( $name, '/' ) . ' \}\}$/', $producer_outputs[ $name ] ) === 1, "WP Codebox producer output {$name} must map to its run-agent-task job output." );
}

$caller_outputs = array();
preg_match_all( '/^      (?<name>[a-z_]+):\n        description: [^\n]+\n        value: \$\{\{ jobs\.(?<job>[a-z-]+)\.outputs\.(?<producer>[a-z_]+) \}\}$/m', $workflow, $matches, PREG_SET_ORDER );
foreach ( $matches as $match ) {
	$caller_outputs[ $match['name'] ] = array( 'job' => $match['job'], 'output' => $match['producer'] );
}

foreach ( array( 'job_status', 'transcript_summary', 'projected_outputs_json', 'credential_mode', 'declared_artifacts_json' ) as $name ) {
	$assert( isset( $producer_outputs[ $name ] ), "WP Codebox producer contract does not define {$name}." );
	$assert( array( 'job' => 'run-agent', 'output' => $name ) === ( $caller_outputs[ $name ] ?? null ), "Docs Agent {$name} must forward the matching WP Codebox producer output." );
}

$assert( isset( $caller_inputs['external_package_source'] ), 'Docs Agent must provide the required external package descriptor.' );
$assert( ! isset( $caller_inputs['agent_bundle'] ), 'Docs Agent must not pass the removed agent_bundle input.' );
$assert( str_contains( $workflow, 'DOCS_AGENT_PACKAGE_REVISION: a51e79ac698610177852170332a1135a9c315951' ), 'Docs Agent must use the fixed native package source revision.' );
$assert( ! str_contains( $workflow, 'github.job_workflow_sha' ), 'Docs Agent must not depend on unavailable called-workflow provenance.' );

preg_match( "/runtime_sources='(?<json>[^']+)'/", $workflow, $runtime_sources_match );
$assert( isset( $runtime_sources_match['json'] ), 'Docs Agent must prepare native runtime sources.' );
$runtime_sources = json_decode( $runtime_sources_match['json'], true );
$assert( is_array( $runtime_sources ) && 3 === count( $runtime_sources ), 'Docs Agent must declare the complete three-source native runtime closure.' );
$assert( array(
	'version' => 1,
	'role' => 'component',
	'repository' => 'Automattic/agents-api',
	'revision' => '78e2dd409010f98fa4d26cdd72572117384ab18d',
	'path' => '.',
	'metadata' => array( 'slug' => 'agents-api', 'loadAs' => 'mu-plugin', 'pluginFile' => 'agents-api.php' ),
) === ( $runtime_sources[0] ?? null ), 'Docs Agent must declare the pinned Agents API MU-plugin component.' );
$assert( array(
	'version' => 1,
	'role' => 'provider_plugin',
	'source' => array( 'type' => 'https_zip', 'url' => 'https://downloads.wordpress.org/plugin/ai-provider-for-openai.1.0.3.zip', 'sha256' => '48f3c0c714b3164cda79d320829830d5a0ea1116e0b19653da8af898a22d3bb6', 'archive_root' => 'ai-provider-for-openai' ),
	'metadata' => array( 'slug' => 'ai-provider-for-openai', 'pluginFile' => 'plugin.php', 'activate' => true, 'providers' => array( 'openai' ) ),
) === ( $runtime_sources[1] ?? null ), 'Docs Agent must declare the checksum-pinned activated OpenAI provider.' );
$assert( array(
	'version' => 1,
	'role' => 'bundled_library',
	'repository' => 'WordPress/php-ai-client',
	'revision' => '631704201d15ffeff7091ad3bc7156db74054956',
	'path' => '.',
	'metadata' => array( 'library' => 'php-ai-client', 'strategy' => 'wordpress-scoped-bundle' ),
) === ( $runtime_sources[2] ?? null ), 'Docs Agent must declare the pinned WordPress-scoped PHP AI Client overlay.' );
$assert( str_contains( $producer_runtime_sources, 'return { component_contracts:' ), 'WP Codebox must lower component runtime sources through component contracts.' );
$assert( str_contains( $producer_runtime_sources, 'provider_plugin_paths:' ) && str_contains( $producer_runtime_sources, 'provider_plugins:' ), 'WP Codebox must lower provider runtime sources through provider plugin inputs.' );
$assert( str_contains( $producer_runtime_sources, 'runtime_overlays:' ) && str_contains( $producer_runtime_sources, 'kind: "bundled-library"' ), 'WP Codebox must lower bundled libraries through runtime overlays.' );
$assert( str_contains( $producer_runtime_sources, 'metadata.providers must be a non-empty canonical list of provider ids.' ), 'WP Codebox must require provider-plugin metadata to declare canonical provider ids.' );
$assert( str_contains( $producer_runtime_sources, 'Requested provider ${provider} is not declared by an authorized runtime provider plugin.' ), 'WP Codebox must authorize the selected provider against runtime metadata before execution.' );
$assert( str_contains( $producer_sanitizer, 'function runtimeSourceProvenance(source)' ), 'WP Codebox artifacts must derive runtime-source provenance separately from materialized runtime paths.' );
$assert( str_contains( $producer_sanitizer, 'if (key === "runtime_sources" && Array.isArray(entry)) return [[key, entry.map(runtimeSourceProvenance).map((source) => sanitizeRuntimeSourceValue(source, root))]]' ), 'WP Codebox artifacts must emit sanitized provenance-only runtime sources.' );
$assert( str_contains( $producer_sanitizer, 'if (descriptor.role === "provider_plugin" && Array.isArray(descriptor.metadata?.providers)) provenance.providers = descriptor.metadata.providers' ), 'WP Codebox provenance artifacts must retain the canonical provider allowlist.' );
$assert( str_contains( $producer_execute, 'sanitizeRuntimeSourceValue(nativeRuntimeResult, privateRuntimeSourceRootForSanitization)' ), 'WP Codebox must sanitize private runtime paths from native task results before persistence.' );
$assert( str_contains( $producer_execute, 'forbiddenRoots: [workspace, artifactsPath]' ) && str_contains( $producer_execute, 'const privatePreparationRoot = privateRuntimeSourceRoot ? join(privateRuntimeSourceRoot, "prepared-runtime-sources") : ""' ), 'WP Codebox must keep private runtime sources outside the workspace and artifact roots.' );
$assert( str_contains( $producer_upload, 'sanitizeArtifactUploadText(text, privateUploadRoots, secretValues)' ), 'WP Codebox must sanitize private runtime and workspace paths from artifact uploads.' );
$assert( str_contains( $producer_sanitizer, 'RUNTIME_SOURCE_PLACEHOLDER = "[runtime-source]"' ), 'WP Codebox must replace private runtime paths with the published placeholder.' );
$assert( str_contains( $producer_sanitizer, 'PRIVATE_RUNTIME_SOURCE_FIELDS = new Set(["source_package_root"])' ), 'WP Codebox must remove private runtime source-root fields.' );
$assert( str_contains( $producer_execute, 'ability: "wp-codebox/run-runtime-package"' ) && str_contains( $producer_execute, 'imported_agent: materializedPackage.identity' ), 'WP Codebox must execute and preserve the imported package identity.' );
$assert( str_contains( $producer_execute, 'mode: "readwrite", sourceMode: "repo-backed", seed: { type: "directory", source: runnerWorkspaceSeedSnapshot.source' ), 'WP Codebox must seed a sandbox-local copy-on-write workspace from the external target-repository snapshot.' );
$assert( str_contains( $producer_execute, 'task_input: {' ) && str_contains( $producer_execute, 'workspaces: runnerWorkspaceSeedSnapshot ?' ), 'WP Codebox must hand runner workspaces through canonical task_input.workspaces.' );
$assert( str_contains( $producer_execute, 'createRunnerWorkspaceSeedSnapshot(workspace)' ) && str_contains( $producer_execute, 'seed: { type: "directory", source: runnerWorkspaceSeedSnapshot.source' ), 'WP Codebox must seed runner workspaces from an external snapshot.' );
$assert( str_contains( $producer_execute, 'runner_workspace_seed: runnerWorkspaceSeedSnapshot.provenance' ) && str_contains( $producer_execute, 'RUNNER_WORKSPACE_SEED_EXCLUDES' ), 'WP Codebox must retain seed provenance while applying the published exclusions.' );
$assert( str_contains( $producer_execute, 'createTrustedArtifactApplyChannel()' ) && str_contains( $producer_execute, 'WP_CODEBOX_TRUSTED_APPLY_ARTIFACT_ROOT' ), 'WP Codebox must create a private trusted apply channel before runtime execution.' );
$assert( str_contains( $producer_execute, 'trustedArtifactApplyRefs(trustedApplyArtifactRoot, refs)' ) && str_contains( $producer_execute, 'applyRunnerWorkspacePatch({ artifactRoot: trustedArtifacts.root, artifactRefs: trustedArtifacts.refs, workspaceRoot: workspace, writablePaths, seedIdentity: runnerWorkspaceSeedSnapshot?.provenance.identity })' ), 'WP Codebox must apply the pre-redaction trusted artifacts while retaining seed identity validation.' );
$assert( str_contains( $producer_execute, 'await rm(trustedApplyArtifactRoot, { recursive: true, force: true })' ) && str_contains( $producer_execute, 'runtimeResult = sanitizeRuntimeSourceValue(runtimeResult, runtimeSourceOutputRoots)' ), 'WP Codebox must remove private trusted apply inputs before durable-result sanitization.' );
$assert( str_contains( $producer_execute, '...(error?.evidence ? { evidence: error.evidence } : {})' ), 'WP Codebox must retain rejected apply evidence in the normalized failure result.' );
$assert( str_contains( $producer_upload, 'async function stageApplyFailureEvidence(result)' ) && str_contains( $producer_upload, 'apply-failure' ) && str_contains( $producer_upload, 'rejected.patch' ), 'WP Codebox must stage rejected patch and changed-file evidence for upload.' );
$assert( str_contains( $producer_execute, '.filter((ref) => ref.kind === "codebox-patch" || ref.kind === "codebox-changed-files")' ), 'WP Codebox must treat canonical patch and changed-file references as actionable workspace evidence.' );
$assert( str_contains( $producer_execute, 'verifyRunnerWorkspaceIntegrity(workspaceApply.integrity)' ) && str_contains( $producer_execute, 'publishRunnerWorkspace' ), 'WP Codebox must verify host-applied changes before publication.' );
$assert( str_contains( $producer_execute, '["name", "type", "path"]' ), 'WP Codebox command entries must accept the generic name/type/path artifact descriptor.' );
$assert( str_contains( $producer_request_builder, '...(artifact ? { artifact } : {})' ) && str_contains( $producer_request_builder, '["name", "type", "path"]' ), 'WP Codebox must preserve the generic command artifact descriptor while building the native request.' );
$assert( str_contains( $producer_execute, 'commandArtifactReference(check.artifact, artifactDeclarations, artifactsPath, kind)' ), 'WP Codebox must validate and reference a successful command artifact.' );
$assert( str_contains( $producer_execute, 'schema: "wp-codebox/typed-artifact/v1"' ) && str_contains( $producer_execute, 'source: `runner-${kind}-command`' ), 'WP Codebox must add a canonical typed reference for command-produced artifacts.' );
$drift_artifact_capture = strpos( $producer_execute, 'verification.push(await verificationRecord("drift", check, artifactsPath, request.artifacts?.declarations))' );
$publication_start = strpos( $producer_execute, 'const publisher = testPublisher' );
$assert( false !== $drift_artifact_capture && false !== $publication_start && $drift_artifact_capture < $publication_start, 'WP Codebox must capture drift-command artifacts before publication.' );
$assert( str_contains( $producer_upload, 'const declaredPaths = new Set(declaredArtifactPaths(result, declarations(request)))' ) && str_contains( $producer_upload, 'await stageTextFile(source, join(uploadPath, ".codebox", "agent-task-artifacts", path))' ), 'WP Codebox upload preparation must stage command references only through the declared-artifact allowlist.' );
$assert( str_contains( $producer_execute, 'runtime_result: redact(runtimeRecord)' ) && str_contains( $producer_execute, '...(downstreamFailure ? { failure:' ), 'WP Codebox must retain normalized runtime evidence when downstream execution fails.' );
$assert( str_contains( $producer_upload, 'function runtimeProvenance(request)' ) && str_contains( $producer_upload, 'runtime-provenance.json' ), 'WP Codebox uploads must retain runtime provenance without prepared source content.' );
$assert( str_contains( $producer_upload, 'function compactNativeInput(text)' ) && str_contains( $producer_upload_policy, 'Temporary runner workspace seed paths must never be persisted in artifact uploads.' ), 'WP Codebox uploads must preserve seed provenance without exposing secret-filtered snapshot paths.' );
$assert( str_contains( $producer_execute, 'async function canonicalReviewerTranscript(nativeRuntimeResult, artifactsPath)' ) && str_contains( $producer_execute, 'Canonical transcript requires exactly one distinct existing file.' ), 'WP Codebox must canonicalize reviewer evidence from one trusted transcript before sanitization.' );
$assert( str_contains( $producer_execute, 'reviewerEvidence = await canonicalReviewerTranscript(nativeRuntimeResult, artifactsPath)' ) && str_contains( $producer_execute, 'let runtimeResult = sanitizeRuntimeSourceValue(nativeRuntimeResult, privateRuntimeSourceRootForSanitization)' ), 'WP Codebox must capture reviewer evidence before runtime-result sanitization.' );
$assert( str_contains( $producer_execute, '...(reviewerEvidence ? { reviewer_evidence: reviewerEvidence } : {})' ), 'WP Codebox must persist the canonical reviewer-evidence descriptor in workflow results.' );
$assert( str_contains( $producer_upload, 'const descriptor = record(record(result).reviewer_evidence).transcript' ) && str_contains( $producer_upload, 'Reviewer evidence transcript descriptor is malformed.' ), 'WP Codebox uploads must consume only the persisted reviewer-evidence descriptor.' );
$assert( str_contains( $producer_upload, 'Canonical transcript digest does not match its reviewer evidence descriptor.' ) && str_contains( $producer_upload, 'Canonical transcript size does not match its reviewer evidence descriptor.' ), 'WP Codebox uploads must revalidate canonical reviewer-evidence digest and size.' );
$assert( str_contains( $producer_upload, 'schema: "wp-codebox/reviewer-agent-transcript/v1"' ) && str_contains( $producer_upload, '".codebox", "agent-task-artifacts", "transcript.json"' ), 'WP Codebox must upload the compact reviewer transcript at its controlled path.' );
$assert( str_contains( $producer_upload, 'source_sha256: actualDigest, projection_sha256: projectionDigest' ) && str_contains( $producer_upload, 'canonical_transcripts: [transcript]' ), 'WP Codebox must retain verified canonical transcript provenance.' );
$assert( str_contains( $producer_upload, 'omitted_payloads' ) && str_contains( $producer_sanitizer, '[host-path]' ) && str_contains( $producer_upload, '[redacted-source-content]' ), 'WP Codebox reviewer transcripts must omit payloads and redact private paths or source content.' );
$projection_start = strpos( $producer_upload, 'function projectWorkflowResult(value)' );
$projection_end = strpos( $producer_upload, 'function safeTargetPath(value)', $projection_start );
$assert( false !== $projection_start && false !== $projection_end, 'WP Codebox must define the reviewer-safe workflow-result projection.' );
$workflow_result_projection = substr( $producer_upload, $projection_start, $projection_end - $projection_start );
foreach ( array( 'schema: result.schema', 'run_id: result.run_id', 'status: result.status', 'success: result.success', 'request_path: result.request_path', 'reviewer_evidence:', 'verification,', 'publication: result.publication', 'transcript: result.transcript', 'artifacts: result.artifacts', 'outputs: outputs.projections', 'access: result.access', 'publication_verification: result.publication_verification', 'publication_error: result.publication_error', 'failure: result.failure', 'projection_error: result.projection_error' ) as $public_projection_field ) {
	$assert( str_contains( $workflow_result_projection, $public_projection_field ), "WP Codebox reviewer-safe workflow-result projection must retain {$public_projection_field}." );
}
foreach ( array( 'runtime_result', 'engine_data', 'model', 'provider', 'tool_calls', 'tool_results', 'source_package_root' ) as $private_projection_field ) {
	$assert( ! str_contains( $workflow_result_projection, $private_projection_field ), "WP Codebox reviewer-safe workflow-result projection must exclude {$private_projection_field}." );
}
$assert( (string) $upload_regression_run === ( $producer_upload_regression['run_id'] ?? null ), 'WP Codebox upload regression fixture must match the recorded run.' );
$assert( (string) $diagnostic_regression_run === ( $producer_diagnostic_regression['run_id'] ?? null ), 'WP Codebox diagnostic regression fixture must match the recorded run.' );
$diagnostic = $producer_diagnostic_regression['result']['diagnostics'][0] ?? null;
$assert( is_array( $diagnostic ) && str_contains( (string) ( $diagnostic['message'] ?? null ), 'OpenAiProvider' ) && str_contains( (string) ( $diagnostic['stack'] ?? null ), 'WP_Agents_Registry' ), 'WP Codebox diagnostic regression fixture must preserve runtime class names.' );
$assert( 'failed-on-runtime-source' === ( $producer_upload_regression['observed']['upload_preparation'] ?? null ), 'WP Codebox upload regression fixture must retain the runtime-source failure.' );
$assert( in_array( '.codebox/agent-task-request.json', $producer_upload_regression['observed']['uploaded'] ?? array(), true ), 'WP Codebox upload regression fixture must retain the controlled request upload.' );
$assert( ! array_intersect( array( 'MODEL_PROVIDER_SECRET_1', 'MODEL_PROVIDER_SECRET_2', 'MODEL_PROVIDER_SECRET_3', 'MODEL_PROVIDER_SECRET_4', 'MODEL_PROVIDER_SECRET_5' ), array_keys( $caller_secrets ) ), 'Docs Agent must forward only the OPENAI_API_KEY provider secret name.' );

$assert( str_contains( $workflow, 'output_projections="$(jq -cn --arg path \'metadata.runner_workspace_publication.pull_request.url\' --argjson required "$success_requires_pr" \'{docs_agent_publication:{path:$path,required:$required}}\')"' ), 'Docs Agent must define the v0.12.29 publication projection descriptor.' );
$docs_projections = array(
	'docs_agent_publication' => array(
		'path'     => 'metadata.runner_workspace_publication.pull_request.url',
		'required' => true,
	),
);
$publication_descriptor = $docs_projections['docs_agent_publication'] ?? null;
$assert( is_array( $publication_descriptor ), 'Docs Agent must define the docs_agent_publication projection descriptor.' );
$publication_path = $publication_descriptor['path'] ?? null;
$assert( 'metadata.runner_workspace_publication.pull_request.url' === $publication_path, 'Docs Agent publication projection must use the v0.12.29 runner workspace publication pull request URL path.' );

$assert( 'string' === ( $contract['inputs']['output_projections']['type'] ?? null ), 'WP Codebox must accept Docs Agent custom output projections.' );

$runtime_result_fixture = $read_json( $root . '/tests/wp-codebox-runtime-result.fixture.json' );
$publication_url            = $result_value( $runtime_result_fixture, $publication_path );
$assert( is_string( $publication_url ) && '' !== $publication_url, 'Docs Agent publication projection must resolve from the representative runtime-result fixture.' );
$assert( null === $result_value( $runtime_result_fixture, $publication_path . '.invalid' ), 'Representative runtime-result fixture must reject an invalid publication projection path.' );

$lane_matrix = array(
	'technical:bootstrap'   => true,
	'technical:maintenance' => false,
	'user:bootstrap'        => true,
	'user:maintenance'      => false,
	'skills:maintenance'    => false,
);
foreach ( $lane_matrix as $lane => $requires_pr ) {
	$case = preg_quote( $lane . ')', '/' );
	$assert( 1 === preg_match( '/case "\$AUDIENCE:\$RUN_KIND" in.*?' . $case . '(?<body>.*?);;(?=\n            )/s', $workflow, $match ), "Docs Agent must define the {$lane} lane." );
	$expected = $requires_pr ? 'true' : 'false';
	$assert( str_contains( $match['body'], "lane_requires_pr={$expected}" ), "Docs Agent {$lane} lane publication default must be {$expected}." );
}
$assert( str_contains( $workflow, 'REQUIRE_PR: ${{ inputs.require_pr }}' ), 'Docs Agent must make the caller publication policy available while resolving the recipe.' );
$assert( str_contains( $workflow, 'success_requires_pr="$lane_requires_pr"' ) && str_contains( $workflow, 'if [ "$REQUIRE_PR" = true ]; then' ) && str_contains( $workflow, 'success_requires_pr=true' ), 'Docs Agent must resolve caller PR policy after lane defaults so bootstrap remains required and opted-in maintenance fails closed.' );
$publication_policy_cases = array(
	'maintenance default' => array( false, false, false ),
	'maintenance required' => array( false, true, true ),
	'bootstrap default' => array( true, false, true ),
	'bootstrap required' => array( true, true, true ),
);
foreach ( $publication_policy_cases as $case => list( $lane_requires_pr, $require_pr, $expected ) ) {
	$resolved = $lane_requires_pr || $require_pr;
	$assert( $expected === $resolved, "Docs Agent {$case} publication policy must resolve deterministically." );
}
$assert( '${{ needs.prepare.outputs.success_requires_pr == \'true\' }}' === ( $caller_inputs['success_requires_pr'] ?? null ), 'Docs Agent must pass the selected lane publication policy to WP Codebox.' );
$assert( array( 'job' => 'prepare', 'output' => 'success_requires_pr' ) === ( $caller_outputs['success_requires_pr'] ?? null ), 'Docs Agent must expose the selected publication policy to consumers.' );
$assert( str_contains( $workflow, 'successRequiresPr:$successRequiresPr' ), 'Docs Agent recipe must retain the selected publication policy.' );
$assert( str_contains( $workflow, '--arg validationDependencies "$INPUT_VALIDATION_DEPENDENCIES"' ), 'Docs Agent recipe must retain caller-owned validation dependencies.' );
$assert( str_contains( $workflow, '{enabled:true,repo:$repo,clone_url:("https://github.com/" + $repo + ".git"),branch:$branch,branch_prefix:$branch,base:$baseRef,from:("origin/" + $baseRef)}' ), 'Docs Agent runner workspace publication must forward base_ref as base and preserve origin-qualified from.' );
$runner_workspace_json = shell_exec( "jq -cn --arg repo 'Automattic/example' --arg branch 'docs-agent/docs-upkeep' --arg baseRef 'trunk' '{enabled:true,repo:\$repo,clone_url:(\"https://github.com/\" + \$repo + \".git\"),branch:\$branch,branch_prefix:\$branch,base:\$baseRef,from:(\"origin/\" + \$baseRef)}'" );
$runner_workspace      = json_decode( (string) $runner_workspace_json, true );
$assert( array(
	'enabled'       => true,
	'repo'          => 'Automattic/example',
	'clone_url'     => 'https://github.com/Automattic/example.git',
	'branch'        => 'docs-agent/docs-upkeep',
	'branch_prefix' => 'docs-agent/docs-upkeep',
	'base'          => 'trunk',
	'from'          => 'origin/trunk',
) === $runner_workspace, 'Docs Agent must emit trunk as publication base and origin/trunk as runner workspace from.' );

preg_match( "/artifact_declarations='(?<json>\[.*?\])'\n/s", $workflow, $artifact_declarations_match );
$assert( isset( $artifact_declarations_match['json'] ), 'Docs Agent must define typed artifact declarations.' );
$artifact_declarations = json_decode( $artifact_declarations_match['json'], true );
$assert( is_array( $artifact_declarations ) && $artifact_declarations !== array(), 'Docs Agent artifact declarations must be valid JSON.' );
foreach ( $artifact_declarations as $artifact_declaration ) {
	$assert( false === ( $artifact_declaration['required'] ?? null ), 'Docs Agent artifact declarations must remain optional.' );
}
$assert( str_contains( $producer_execute, 'function requiredArtifacts(declarations)' ) && str_contains( $producer_execute, 'artifact.required === true' ), 'WP Codebox must derive required artifacts only from typed declarations.' );
$assert( ! str_contains( $producer_execute, 'requiredArtifacts(request.artifacts?.expected' ), 'WP Codebox must not treat every expected artifact as required.' );
$assert( str_contains( $producer_execute, 'if (projected === undefined) continue' ), 'WP Codebox must omit unresolved optional output projections from successful no-op results.' );

$recipe_start = strpos( $workflow, 'recipe_json="$(jq -cn' );
$recipe_end   = strpos( $workflow, 'external_package_source="$(jq -cn', $recipe_start );
$assert( false !== $recipe_start && false !== $recipe_end, 'Docs Agent must build a bounded portable recipe.' );
$recipe_builder = substr( $workflow, $recipe_start, $recipe_end - $recipe_start );
foreach ( array( 'OPENAI_API_KEY', 'ACCESS_TOKEN', 'EXTERNAL_PACKAGE_SOURCE_POLICY', 'github.token', 'secrets.' ) as $secret_fragment ) {
	$assert( ! str_contains( $recipe_builder, $secret_fragment ), "Docs Agent recipe must not serialize {$secret_fragment}." );
}

fwrite( STDOUT, "Docs Agent WP Codebox contract validation passed.\n" );

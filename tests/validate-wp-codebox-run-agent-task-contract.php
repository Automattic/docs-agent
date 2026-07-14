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
$assert( substr( $release_tag, 1 ) === ( $release['package_version'] ?? null ), 'WP Codebox release fixture package version must match its tag.' );
$run = $release['run'] ?? null;
$assert( is_string( $run ) && preg_match( '/^\d+$/', $run ) === 1, 'WP Codebox release fixture must retain the regression run reference.' );
$native_result_path = $release['native_result_path'] ?? null;
$workflow_result_path = $release['workflow_result_path'] ?? null;
$assert( '.codebox/native-agent-task-result.json' === $native_result_path, 'WP Codebox release fixture must declare the controlled native result path.' );
$assert( '.codebox/agent-task-workflow-result.json' === $workflow_result_path, 'WP Codebox release fixture must declare the workflow result path.' );
$producer_package = $read_json( rtrim( $wp_codebox_dir, '/' ) . '/package.json' );
$assert( ( $release['package_version'] ?? null ) === ( $producer_package['version'] ?? null ), 'Checked-out WP Codebox package version must match the release fixture.' );

$workflow = (string) file_get_contents( $root . '/.github/workflows/maintain-docs.yml' );
$assert( preg_match( '/^\s*uses: Automattic\/wp-codebox\/\.github\/workflows\/run-agent-task\.yml@' . preg_quote( $release_tag, '/' ) . '$/m', $workflow ) === 1, 'Docs Agent must call the released WP Codebox workflow tag.' );
$validation_workflow = (string) file_get_contents( $root . '/.github/workflows/validate.yml' );
$assert( str_contains( $validation_workflow, 'repository: Automattic/wp-codebox' ) && str_contains( $validation_workflow, 'ref: ' . $release_tag ), 'CI must validate against the same released WP Codebox producer tag.' );
$workflow_readme = (string) file_get_contents( $root . '/.github/workflows/README.md' );
$assert( str_contains( $workflow_readme, 'run `' . $run . '`' ), 'Workflow documentation must retain the regression run reference.' );

$producer_workflow = (string) file_get_contents( rtrim( $wp_codebox_dir, '/' ) . '/.github/workflows/run-agent-task.yml' );
$producer_execute = (string) file_get_contents( rtrim( $wp_codebox_dir, '/' ) . '/.github/scripts/run-agent-task/execute-native-agent-task.mjs' );
$producer_runtime_sources = (string) file_get_contents( rtrim( $wp_codebox_dir, '/' ) . '/.github/scripts/run-agent-task/materialize-external-native-package.mjs' );
$producer_upload = (string) file_get_contents( rtrim( $wp_codebox_dir, '/' ) . '/.github/scripts/run-agent-task/prepare-agent-task-upload.mjs' );
$producer_result = $read_json( rtrim( $wp_codebox_dir, '/' ) . '/contracts/agent-task-workflow-result.fixture.json' );
$assert( str_contains( $producer_workflow, $workflow_result_path ), 'WP Codebox producer workflow must upload the workflow result file.' );
$assert( str_contains( $producer_execute, '"--result-file", nativeResultPath' ), 'WP Codebox producer must pass the native result-file argument.' );
$assert( str_contains( $producer_execute, 'const nativeResultPath = join(controlledCodeboxPath, "native-agent-task-result.json")' ), 'WP Codebox producer must constrain the native result path to .codebox.' );
$assert( str_contains( $producer_execute, 'const resultPath = join(workspace, ".codebox", "agent-task-workflow-result.json")' ), 'WP Codebox producer must write the workflow result at the declared path.' );
$assert( str_contains( $producer_execute, 'sandbox_tool_policy: {' ), 'WP Codebox producer must include an explicit sandbox tool-policy in the native task request.' );
$assert( str_contains( $producer_execute, 'schema: "wp-codebox/sandbox-tool-policy/v1"' ), 'WP Codebox producer sandbox tool-policy must use the published schema.' );
$assert( str_contains( $producer_execute, 'version: 1' ), 'WP Codebox producer sandbox tool-policy must declare its contract version.' );
$assert( str_contains( $producer_execute, 'tools: runnerWorkspaceTools.map((id) => ({ id, runtime_tool_id: id, execution_location: "parent", transport_visibility: "visible", allowed: true' ), 'WP Codebox producer sandbox tool-policy must preserve the allowlisted parent tool mapping.' );
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
$assert( '${{ needs.prepare.outputs.runtime_sources }}' === ( $caller_inputs['runtime_sources'] ?? null ), 'Docs Agent must pass its prepared runtime closure to WP Codebox.' );
$assert( ! isset( $caller_inputs['provider'], $caller_inputs['model'] ), 'Docs Agent must leave provider/model selection to the published WP Codebox contract.' );
$assert( 'string' === ( $contract['inputs']['provider']['type'] ?? null ) && 'openai' === ( $contract['inputs']['provider']['default'] ?? null ), 'WP Codebox must publish the OpenAI provider input.' );
$assert( 'string' === ( $contract['inputs']['model']['type'] ?? null ) && 'gpt-5.5' === ( $contract['inputs']['model']['default'] ?? null ), 'WP Codebox must publish the default model input.' );
$is_coherent_release_pair = static function ( string $consumer_workflow ): bool {
	preg_match( '/uses: Automattic\/wp-codebox\/\.github\/workflows\/run-agent-task\.yml@(?<workflow_tag>[^\s]+)/', $consumer_workflow, $workflow_match );
	preg_match( '/^\s+wp_codebox_release_ref: (?<helper_tag>[^\s]+)$/m', $consumer_workflow, $helper_match );

	return isset( $workflow_match['workflow_tag'], $helper_match['helper_tag'] )
		&& preg_match( '/^v\d+\.\d+\.\d+$/', $workflow_match['workflow_tag'] ) === 1
		&& $workflow_match['workflow_tag'] === $helper_match['helper_tag'];
};
$assert( $is_coherent_release_pair( $workflow ), 'Docs Agent must use matching exact WP Codebox release tags.' );
$assert( ! $is_coherent_release_pair( str_replace( 'wp_codebox_release_ref: ' . $release_tag, 'wp_codebox_release_ref: v0.12.3', $workflow ) ), 'A mismatched WP Codebox workflow and helper release tag must fail.' );

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
$assert( str_contains( $workflow, 'DOCS_AGENT_PACKAGE_REVISION: 7b2df969c34de112ec7ad13189ba94226a7f76f3' ), 'Docs Agent must use the fixed native package source revision.' );
$assert( ! str_contains( $workflow, 'github.job_workflow_sha' ), 'Docs Agent must not depend on unavailable called-workflow provenance.' );

preg_match( "/runtime_sources='(?<json>[^']+)'/", $workflow, $runtime_sources_match );
$assert( isset( $runtime_sources_match['json'] ), 'Docs Agent must prepare native runtime sources.' );
$runtime_sources = json_decode( $runtime_sources_match['json'], true );
$assert( is_array( $runtime_sources ) && 3 === count( $runtime_sources ), 'Docs Agent must declare the complete three-source native runtime closure.' );
$assert( array(
	'version' => 1,
	'role' => 'component',
	'repository' => 'Automattic/agents-api',
	'revision' => '59d1e6b473f22498e40e279130bbb4f9bcde3b73',
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
$assert( str_contains( $producer_upload, 'function runtimeSourceProvenance(source)' ), 'WP Codebox artifacts must derive runtime-source provenance separately from materialized runtime paths.' );
$assert( str_contains( $producer_upload, 'if (key === "runtime_sources" && Array.isArray(entry)) return [[key, entry.map(runtimeSourceProvenance)]]' ), 'WP Codebox artifacts must emit provenance-only runtime sources.' );
$assert( str_contains( $producer_upload, 'if (descriptor.role === "provider_plugin" && Array.isArray(descriptor.metadata?.providers)) provenance.providers = descriptor.metadata.providers' ), 'WP Codebox provenance artifacts must retain the canonical provider allowlist.' );
$assert( ! array_intersect( array( 'MODEL_PROVIDER_SECRET_1', 'MODEL_PROVIDER_SECRET_2', 'MODEL_PROVIDER_SECRET_3', 'MODEL_PROVIDER_SECRET_4', 'MODEL_PROVIDER_SECRET_5' ), array_keys( $caller_secrets ) ), 'Docs Agent must forward only the OPENAI_API_KEY provider secret name.' );

preg_match( "/output_projections='(?<json>[^']+)'/", $workflow, $projection_match );
$assert( isset( $projection_match['json'] ), 'Docs Agent must define output projections for the producer.' );
$docs_projections = json_decode( $projection_match['json'], true );
$assert( is_array( $docs_projections ), 'Docs Agent output projections must be valid JSON.' );
$publication_path = $docs_projections['docs_agent_publication'] ?? null;
$assert( is_string( $publication_path ), 'Docs Agent must define the docs_agent_publication projection.' );

$producer_request_fixture = $read_json( rtrim( $wp_codebox_dir, '/' ) . '/contracts/agent-task-workflow-request.fixture.json' );
$producer_projection_paths = array_values( $producer_request_fixture['outputs']['projections'] ?? array() );
$assert( in_array( $publication_path, $producer_projection_paths, true ), 'Docs Agent publication projection must match a WP Codebox producer fixture projection path.' );

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
	$assert( str_contains( $match['body'], "success_requires_pr={$expected}" ), "Docs Agent {$lane} lane success_requires_pr must be {$expected}." );
}
$assert( '${{ needs.prepare.outputs.success_requires_pr == \'true\' }}' === ( $caller_inputs['success_requires_pr'] ?? null ), 'Docs Agent must pass the selected lane publication policy to WP Codebox.' );
$assert( array( 'job' => 'prepare', 'output' => 'success_requires_pr' ) === ( $caller_outputs['success_requires_pr'] ?? null ), 'Docs Agent must expose the selected publication policy to consumers.' );
$assert( str_contains( $workflow, 'successRequiresPr:$successRequiresPr' ), 'Docs Agent recipe must retain the selected publication policy.' );

$recipe_start = strpos( $workflow, 'recipe_json="$(jq -cn' );
$recipe_end   = strpos( $workflow, 'external_package_source="$(jq -cn', $recipe_start );
$assert( false !== $recipe_start && false !== $recipe_end, 'Docs Agent must build a bounded portable recipe.' );
$recipe_builder = substr( $workflow, $recipe_start, $recipe_end - $recipe_start );
foreach ( array( 'OPENAI_API_KEY', 'ACCESS_TOKEN', 'EXTERNAL_PACKAGE_SOURCE_POLICY', 'github.token', 'secrets.' ) as $secret_fragment ) {
	$assert( ! str_contains( $recipe_builder, $secret_fragment ), "Docs Agent recipe must not serialize {$secret_fragment}." );
}

fwrite( STDOUT, "Docs Agent WP Codebox contract validation passed.\n" );

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
$producer_package = $read_json( rtrim( $wp_codebox_dir, '/' ) . '/package.json' );
$assert( ( $release['package_version'] ?? null ) === ( $producer_package['version'] ?? null ), 'Checked-out WP Codebox package version must match the release fixture.' );

$workflow = (string) file_get_contents( $root . '/.github/workflows/maintain-docs.yml' );
$assert( preg_match( '/^\s*uses: Automattic\/wp-codebox\/\.github\/workflows\/run-agent-task\.yml@' . preg_quote( $release_tag, '/' ) . '$/m', $workflow ) === 1, 'Docs Agent must call the released WP Codebox workflow tag.' );

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

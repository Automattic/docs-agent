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

$workflow = (string) file_get_contents( $root . '/.github/workflows/maintain-docs.yml' );
$assert( preg_match( '/^\s*uses: Automattic\/wp-codebox\/\.github\/workflows\/run-agent-task\.yml@54c2f9a7bc3cd1fe20055d496c83efcfb99afb41$/m', $workflow ) === 1, 'Docs Agent must call the #1754 producer workflow revision.' );

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
	$actual_type = preg_match( '/^(true|false)$/', $value ) === 1 || '${{ inputs.run_agent }}' === $value ? 'boolean' : ( is_numeric( $value ) ? 'number' : 'string' );
	$assert( $type === $actual_type, "Docs Agent input {$name} has {$actual_type} shape; producer requires {$type}." );
}

foreach ( $contract['inputs'] ?? array() as $name => $input ) {
	if ( ! empty( $input['required'] ) ) {
		$assert( array_key_exists( $name, $caller_inputs ), "Docs Agent does not satisfy required producer input {$name}." );
	}
}

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

$assert( 'true' === ( $caller_inputs['require_access_token'] ?? null ), 'Docs Agent must require the native runner access token.' );
$assert( '${{ secrets.ACCESS_TOKEN }}' === ( $caller_secrets['ACCESS_TOKEN'] ?? null ), 'Docs Agent must explicitly forward ACCESS_TOKEN.' );
$assert( '${{ secrets.EXTERNAL_PACKAGE_SOURCE_POLICY }}' === ( $caller_secrets['EXTERNAL_PACKAGE_SOURCE_POLICY'] ?? null ), 'Docs Agent must explicitly forward the external package policy secret.' );
$assert( preg_match( '/secrets:\n      ACCESS_TOKEN:\n(?:        .*\n)*        required: true/m', $workflow ) === 1, 'Docs Agent must require ACCESS_TOKEN from reusable-workflow callers.' );
$assert( preg_match( '/secrets:\n(?:      [A-Z_]+:\n(?:        .*\n)*        required: (?:true|false)\n)*      EXTERNAL_PACKAGE_SOURCE_POLICY:\n(?:        .*\n)*        required: true/m', $workflow ) === 1, 'Docs Agent must require EXTERNAL_PACKAGE_SOURCE_POLICY from reusable-workflow callers.' );
$assert( '${{ inputs.run_agent }}' === ( $caller_inputs['run_agent'] ?? null ), 'Docs Agent must delegate run_agent so WP Codebox reports deterministic skipped status.' );
$assert( preg_match( '/\n    if: inputs\.run_agent\n/', $workflow ) !== 1, 'Docs Agent must not skip the producer job outside the producer contract.' );

$producer_outputs = array();
foreach ( $contract['outputs'] ?? array() as $name => $output ) {
	$producer_outputs[ $name ] = $output['value'] ?? null;
	$assert( is_string( $producer_outputs[ $name ] ) && preg_match( '/^\$\{\{ jobs\.run-agent-task\.outputs\.' . preg_quote( $name, '/' ) . ' \}\}$/', $producer_outputs[ $name ] ) === 1, "WP Codebox producer output {$name} must map to its run-agent-task job output." );
}

$caller_outputs = array();
preg_match_all( '/^      (?<name>[a-z_]+):\n        description: [^\n]+\n        value: \$\{\{ jobs\.run-agent\.outputs\.(?<producer>[a-z_]+) \}\}$/m', $workflow, $matches, PREG_SET_ORDER );
foreach ( $matches as $match ) {
	$caller_outputs[ $match['name'] ] = $match['producer'];
}

foreach ( array( 'job_status', 'transcript_summary', 'projected_outputs_json', 'credential_mode', 'declared_artifacts_json' ) as $name ) {
	$assert( isset( $producer_outputs[ $name ] ), "WP Codebox producer contract does not define {$name}." );
	$assert( $name === ( $caller_outputs[ $name ] ?? null ), "Docs Agent {$name} must forward the matching WP Codebox producer output." );
}

$assert( isset( $caller_inputs['external_package_source'] ), 'Docs Agent must provide the required external package descriptor.' );
$assert( ! isset( $caller_inputs['agent_bundle'] ), 'Docs Agent must not pass the removed agent_bundle input.' );

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

fwrite( STDOUT, "Docs Agent WP Codebox contract validation passed.\n" );

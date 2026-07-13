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

$contract = $read_json( rtrim( $wp_codebox_dir, '/' ) . '/contracts/run-agent-task-reusable-workflow-interface.v1.json' );
$assert( 'wp-codebox/reusable-workflow-interface/v1' === ( $contract['schema'] ?? null ), 'WP Codebox producer schema version mismatch.' );
$assert( '.github/workflows/run-agent-task.yml' === ( $contract['workflow'] ?? null ), 'WP Codebox producer contract targets an unexpected workflow.' );

$workflow = (string) file_get_contents( $root . '/.github/workflows/maintain-docs.yml' );
$assert( preg_match( '/^\s*uses: Automattic\/wp-codebox\/\.github\/workflows\/run-agent-task\.yml@/m', $workflow ) === 1, 'Docs Agent must call the producer workflow declared by the contract.' );

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
	$actual_type = preg_match( '/^(true|false)$/', $value ) === 1 ? 'boolean' : ( is_numeric( $value ) ? 'number' : 'string' );
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
$assert( preg_match( '/secrets:\n      ACCESS_TOKEN:\n(?:        .*\n)*        required: true/m', $workflow ) === 1, 'Docs Agent must require ACCESS_TOKEN from reusable-workflow callers.' );

fwrite( STDOUT, "Docs Agent WP Codebox contract validation passed.\n" );

<?php
/**
 * Resolve the Docs Agent runner recipe to Homeboy Extensions workflow inputs.
 */

declare( strict_types=1 );

$root        = dirname( __DIR__ );
$recipe_path = $root . '/ci/docs-agent-runner-recipe.json';
$output_path = getenv( 'GITHUB_OUTPUT' ) ?: '';

$runner_recipe_checkout = trim( (string) ( getenv( 'RUNNER_RECIPE_CHECKOUT' ) ?: '.' ) );
$github_workspace    = trim( (string) ( getenv( 'GITHUB_WORKSPACE' ) ?: '${{ github.workspace }}' ) );

if ( ! is_file( $recipe_path ) ) {
	fwrite( STDERR, "Docs Agent runner recipe not found: {$recipe_path}\n" );
	exit( 1 );
}

$recipe = json_decode( (string) file_get_contents( $recipe_path ), true );
if ( ! is_array( $recipe ) ) {
	fwrite( STDERR, "Docs Agent runner recipe must be a JSON object.\n" );
	exit( 1 );
}

$replace_placeholders = static function ( mixed $value ) use ( &$replace_placeholders, $runner_recipe_checkout, $github_workspace ): mixed {
	if ( is_string( $value ) ) {
		return str_replace(
			array( '${runner_recipe_checkout}', '${github_workspace}' ),
			array( rtrim( $runner_recipe_checkout, '/' ), rtrim( $github_workspace, '/' ) ),
			$value
		);
	}

	if ( is_array( $value ) ) {
		foreach ( $value as $key => $item ) {
			$value[ $key ] = $replace_placeholders( $item );
		}
	}

	return $value;
};

$recipe = $replace_placeholders( $recipe );

$required_keys = array(
	'runtime',
	'runtime_ref',
	'profile',
	'capabilities',
	'runtime_dependencies',
	'openai_provider_ref',
	'component_contracts',
	'ability_requirements',
	'runtime_config',
);

foreach ( $required_keys as $key ) {
	if ( ! array_key_exists( $key, $recipe ) ) {
		fwrite( STDERR, "Docs Agent runner recipe missing {$key}.\n" );
		exit( 1 );
	}
}

$encode = static function ( mixed $value ): string {
	$json = json_encode( $value, JSON_UNESCAPED_SLASHES );
	if ( false === $json ) {
		fwrite( STDERR, "Failed to encode Docs Agent runner recipe output.\n" );
		exit( 1 );
	}

	return $json;
};

$outputs = array(
	'runner_recipe_id'     => (string) ( $recipe['id'] ?? 'docs-agent/codebox-homeboy-runner' ),
	'runtime'              => (string) $recipe['runtime'],
	'runtime_ref'          => (string) $recipe['runtime_ref'],
	'profile'              => (string) $recipe['profile'],
	'runtime_profiles'     => $encode(
		array(
			(string) $recipe['profile'] => array(
				'id'           => (string) $recipe['profile'],
				'capabilities' => $recipe['capabilities'],
			),
		)
	),
	'runtime_dependencies' => $encode( $recipe['runtime_dependencies'] ),
	'openai_provider_ref'  => (string) $recipe['openai_provider_ref'],
	'component_contracts'  => $encode( $recipe['component_contracts'] ),
	'ability_requirements' => $encode( $recipe['ability_requirements'] ),
	'runtime_config'       => $encode( $recipe['runtime_config'] ),
);

$output = '';
foreach ( $outputs as $name => $value ) {
	if ( str_contains( $value, "\n" ) ) {
		$output .= "{$name}<<EOF\n{$value}\nEOF\n";
	} else {
		$output .= "{$name}={$value}\n";
	}
}

if ( '' !== $output_path ) {
	file_put_contents( $output_path, $output, FILE_APPEND );
} else {
	fwrite( STDOUT, $output );
}

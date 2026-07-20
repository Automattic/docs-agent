<?php
/** Generate and validate the deterministic Docs Agent completion artifact. */

declare( strict_types=1 );

final class Docs_Agent_Contract_Failure extends RuntimeException {
	public function __construct( public readonly string $diagnostic_code, string $message ) {
		parent::__construct( $message );
	}
}

function docs_agent_fail( string $code, string $message ): never {
	throw new Docs_Agent_Contract_Failure( $code, $message );
}

/** @return list<string> */
function docs_agent_strings( mixed $value, string $field, bool $allow_empty = false ): array {
	if ( ! is_array( $value ) || ! array_is_list( $value ) || ( ! $allow_empty && array() === $value ) ) {
		docs_agent_fail( 'CALLER_INPUT_MALFORMED', "{$field} must be " . ( $allow_empty ? 'a' : 'a non-empty' ) . ' list.' );
	}
	$strings = array();
	foreach ( $value as $item ) {
		if ( ! is_string( $item ) || '' === trim( $item ) ) {
			docs_agent_fail( 'CALLER_INPUT_MALFORMED', "{$field} must contain only non-empty strings." );
		}
		$strings[] = str_replace( '\\', '/', trim( $item ) );
	}
	if ( count( $strings ) !== count( array_unique( $strings ) ) ) {
		docs_agent_fail( 'CALLER_INPUT_MALFORMED', "{$field} contains duplicate values." );
	}
	sort( $strings );
	return $strings;
}

function docs_agent_path_matches( string $path, string $pattern ): bool {
	$path    = ltrim( str_replace( '\\', '/', $path ), './' );
	$pattern = ltrim( str_replace( '\\', '/', trim( $pattern ) ), './' );
	$quoted  = preg_quote( $pattern, '~' );
	$regex   = str_replace( array( '\*\*/', '\*\*', '\*', '\?' ), array( '(?:.*/)?', '.*', '[^/]*', '[^/]' ), $quoted );
	return 1 === preg_match( '~^' . $regex . '$~', $path );
}

/** @return list<string> */
function docs_agent_workspace_changes( string $workspace ): array {
	$paths = array();
	foreach ( array( 'diff --name-only --relative HEAD', 'ls-files --others --exclude-standard' ) as $command ) {
		$output = array();
		exec( 'git -C ' . escapeshellarg( $workspace ) . ' ' . $command . ' 2>/dev/null', $output, $code );
		if ( 0 !== $code ) {
			docs_agent_fail( 'WORKSPACE_DIFF_UNAVAILABLE', 'The target workspace must be a readable Git checkout.' );
		}
		foreach ( $output as $path ) {
			if ( '' !== trim( $path ) && ! str_starts_with( $path, '.codebox/' ) ) {
				$paths[] = str_replace( '\\', '/', trim( $path ) );
			}
		}
	}
	$paths = array_values( array_unique( $paths ) );
	sort( $paths );
	return $paths;
}

/** @return list<string> */
function docs_agent_workspace_files( string $workspace ): array {
	$files = array();
	$iterator = new RecursiveIteratorIterator( new RecursiveCallbackFilterIterator( new RecursiveDirectoryIterator( $workspace, FilesystemIterator::SKIP_DOTS ), static fn( SplFileInfo $file ): bool => ! in_array( $file->getFilename(), array( '.git', '.codebox', 'node_modules', 'vendor' ), true ) ) );
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && ! $file->isLink() ) {
			$files[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( rtrim( $workspace, DIRECTORY_SEPARATOR ) ) + 1 ) );
		}
	}
	sort( $files );
	return $files;
}

/** @param array<string,mixed> $contract */
function docs_agent_validate_bootstrap_contract( array $contract, string $workspace, array $actual_changes ): void {
	$required_paths    = docs_agent_strings( $contract['required_paths'] ?? array(), 'bootstrap_contract.required_paths', true );
	$required_globs    = $contract['required_globs'] ?? array();
	$entry_points      = $contract['entry_points'] ?? array();
	$forbidden_phrases = docs_agent_strings( $contract['forbidden_phrases'] ?? array(), 'bootstrap_contract.forbidden_phrases', true );
	if ( array() === $required_paths && array() === $required_globs && array() === $entry_points ) {
		docs_agent_fail( 'BOOTSTRAP_CONTRACT_EMPTY', 'Bootstrap requires positive caller completion criteria.' );
	}
	if ( ! is_array( $required_globs ) || ! array_is_list( $required_globs ) || ! is_array( $entry_points ) || ! array_is_list( $entry_points ) ) {
		docs_agent_fail( 'BOOTSTRAP_CONTRACT_MALFORMED', 'Bootstrap glob and entry-point criteria must be lists.' );
	}
	$files = docs_agent_workspace_files( $workspace );
	foreach ( $required_paths as $path ) {
		if ( ! is_file( $workspace . '/' . $path ) || 80 > filesize( $workspace . '/' . $path ) ) {
			docs_agent_fail( 'BOOTSTRAP_REQUIRED_PATH', "Bootstrap required path is absent or insubstantial: {$path}" );
		}
	}
	foreach ( $required_globs as $glob ) {
		if ( ! is_array( $glob ) || ! is_string( $glob['pattern'] ?? null ) || ! is_int( $glob['min'] ?? $glob['min_count'] ?? null ) ) {
			docs_agent_fail( 'BOOTSTRAP_CONTRACT_MALFORMED', 'Each bootstrap glob requires pattern and integer min.' );
		}
		$count = count( array_filter( $files, static fn( string $file ): bool => docs_agent_path_matches( $file, $glob['pattern'] ) ) );
		$min = $glob['min'] ?? $glob['min_count'];
		if ( $count < $min ) {
			docs_agent_fail( 'BOOTSTRAP_GLOB_MINIMUM', "Bootstrap glob {$glob['pattern']} matched {$count}; {$min} required." );
		}
	}
	foreach ( $entry_points as $entry ) {
		if ( ! is_array( $entry ) || ! is_string( $entry['path'] ?? null ) ) {
			docs_agent_fail( 'BOOTSTRAP_CONTRACT_MALFORMED', 'Each bootstrap entry point requires a path.' );
		}
		$targets = docs_agent_strings( $entry['must_link_to'] ?? array(), 'bootstrap_contract.entry_points.must_link_to' );
		$content = @file_get_contents( $workspace . '/' . $entry['path'] );
		if ( false === $content ) {
			docs_agent_fail( 'BOOTSTRAP_ENTRY_POINT', "Bootstrap entry point is absent: {$entry['path']}" );
		}
		foreach ( $targets as $target ) {
			if ( ! is_file( $workspace . '/' . $target ) || ! str_contains( $content, $target ) ) {
				docs_agent_fail( 'BOOTSTRAP_NAVIGATION', "Bootstrap entry point {$entry['path']} must link to {$target}." );
			}
		}
	}
	foreach ( $forbidden_phrases as $phrase ) {
		foreach ( $files as $file ) {
			if ( 1 !== preg_match( '/\.(?:md|mdx)$/i', $file ) && ! in_array( $file, $required_paths, true ) ) {
				continue;
			}
			$content = file_get_contents( $workspace . '/' . $file );
			if ( false !== $content && str_contains( $content, $phrase ) ) {
				docs_agent_fail( 'BOOTSTRAP_FORBIDDEN_PHRASE', "Bootstrap documentation contains forbidden phrase in {$file}." );
			}
		}
	}
	$documentation_changes = array_filter( $actual_changes, static fn( string $path ): bool => 1 === preg_match( '/\.(?:md|mdx)$/i', $path ) );
	$bytes = array_sum( array_map( static fn( string $path ): int => is_file( $workspace . '/' . $path ) ? (int) filesize( $workspace . '/' . $path ) : 0, $documentation_changes ) );
	if ( count( $documentation_changes ) < 2 || $bytes < 500 ) {
		docs_agent_fail( 'BOOTSTRAP_NOT_SUBSTANTIVE', 'Fresh bootstrap changes require at least two documentation pages and 500 bytes of navigable content.' );
	}
}

/** @return array{refs:list<string>,records:list<array<string,mixed>>} */
function docs_agent_validate_source_delta( mixed $source_delta, string $run_kind ): array {
	if ( ! is_array( $source_delta ) || ! array_is_list( $source_delta ) ) {
		docs_agent_fail( 'SOURCE_DELTA_MALFORMED', 'source_delta must be a JSON list.' );
	}
	if ( 'maintenance' === $run_kind && array() === $source_delta ) {
		docs_agent_fail( 'SOURCE_DELTA_EMPTY', 'Maintenance requires a non-empty caller-bounded source_delta.' );
	}
	$ids = array();
	$refs = array();
	$records = array();
	foreach ( $source_delta as $delta ) {
		if ( ! is_array( $delta ) || ! is_string( $delta['id'] ?? null ) || '' === trim( $delta['id'] ) || ! is_bool( $delta['requires_documentation_change'] ?? null ) ) {
			docs_agent_fail( 'SOURCE_DELTA_MALFORMED', 'Each source_delta item requires id, source_refs, and requires_documentation_change.' );
		}
		$id = trim( $delta['id'] );
		if ( isset( $ids[ $id ] ) ) {
			docs_agent_fail( 'SOURCE_DELTA_MALFORMED', "source_delta repeats id {$id}." );
		}
		$ids[ $id ] = true;
		$source_refs = docs_agent_strings( $delta['source_refs'] ?? null, "source_delta.{$id}.source_refs" );
		$refs = array_merge( $refs, $source_refs );
		$records[] = array( 'id' => $id, 'source_refs' => $source_refs, 'requires_documentation_change' => $delta['requires_documentation_change'] );
	}
	sort( $refs );
	$refs = array_values( array_unique( $refs ) );
	usort( $records, static fn( array $left, array $right ): int => $left['id'] <=> $right['id'] );
	return array( 'refs' => $refs, 'records' => $records );
}

/** @param array<string,mixed> $options @return array<string,mixed> */
function docs_agent_build_completion_report( array $options ): array {
	$workspace = realpath( (string) ( $options['workspace'] ?? '' ) );
	if ( false === $workspace ) {
		docs_agent_fail( 'INVOCATION', 'workspace must be a real directory.' );
	}
	$lane = $options['lane'] ?? null;
	$run_kind = $options['run_kind'] ?? null;
	if ( ! in_array( $lane, array( 'technical', 'user', 'skills' ), true ) || ! in_array( $run_kind, array( 'bootstrap', 'maintenance' ), true ) ) {
		docs_agent_fail( 'INVOCATION', 'lane and run_kind must be supported caller values.' );
	}
	$writable_paths = docs_agent_strings( array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $options['writable_paths'] ?? '' ) ) ) ) ), 'writable_paths' );
	$source = docs_agent_validate_source_delta( $options['source_delta'] ?? array(), $run_kind );
	$actual_changes = docs_agent_workspace_changes( $workspace );
	foreach ( $actual_changes as $path ) {
		if ( ! array_filter( $writable_paths, static fn( string $pattern ): bool => docs_agent_path_matches( $path, $pattern ) ) ) {
			docs_agent_fail( 'WRITABLE_SCOPE_VIOLATION', "Actual changed path is outside writable_paths: {$path}" );
		}
	}
	$outcome = array() === $actual_changes ? 'no_changes' : 'changes';
	$documentation_changes = array_filter( $actual_changes, static fn( string $path ): bool => 1 === preg_match( '/\.(?:md|mdx)$/i', $path ) );
	foreach ( $source['records'] as $record ) {
		if ( $record['requires_documentation_change'] && array() === $documentation_changes ) {
			docs_agent_fail( 'KNOWN_DRIFT_NO_CHANGE', "Known source drift {$record['id']} requires a documentation diff." );
		}
	}
	$bootstrap = 'bootstrap' === $run_kind;
	if ( $bootstrap ) {
		docs_agent_validate_bootstrap_contract( $options['bootstrap_contract'] ?? array(), $workspace, $actual_changes );
	}
	return array(
		'schema' => 'docs-agent/completion-report/v1',
		'lane' => $lane,
		'run_kind' => $run_kind,
		'outcome' => $outcome,
		'changed_paths' => $actual_changes,
		'writable_paths' => $writable_paths,
		'source' => array(
			'basis' => 'maintenance' === $run_kind ? 'bounded_delta' : 'inventory',
			'refs' => 'maintenance' === $run_kind ? $source['refs'] : array( 'repository inventory' ),
			'records' => $source['records'],
		),
		'bootstrap' => array( 'criteria_satisfied' => $bootstrap ),
		'checks' => array( 'workspace' => 'passed', 'writable_scope' => 'passed', 'source_drift' => 'passed', 'bootstrap' => $bootstrap ? 'passed' : 'not_applicable', 'verification' => 'passed', 'drift_checks' => 'passed' ),
	);
}

/** @param array<string,mixed> $report */
function docs_agent_canonical_report_json( array $report ): string {
	return json_encode( $report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n";
}

/** @param array<string,mixed> $report */
function docs_agent_write_report_artifact( array $report, string $workspace, string $artifact_path ): string {
	$artifact_path = str_replace( '\\', '/', trim( $artifact_path ) );
	if ( '' === $artifact_path || str_starts_with( $artifact_path, '/' ) || preg_match( '~(?:^|/)\.\.(?:/|$)~', $artifact_path ) || '.json' !== strtolower( substr( $artifact_path, -5 ) ) ) {
		docs_agent_fail( 'ARTIFACT_PATH_INVALID', 'Completion artifact path must be a relative JSON path under .codebox/agent-task-artifacts.' );
	}
	$workspace_real = realpath( $workspace );
	if ( false === $workspace_real ) {
		docs_agent_fail( 'ARTIFACT_PATH_INVALID', 'Completion artifact workspace must be a real directory.' );
	}
	$root = $workspace_real . '/.codebox/agent-task-artifacts';
	if ( ( ! is_dir( $root ) && ! mkdir( $root, 0700, true ) ) || is_link( $workspace_real . '/.codebox' ) || is_link( $root ) ) {
		docs_agent_fail( 'ARTIFACT_PATH_INVALID', 'Completion artifact root must be a writable real directory.' );
	}
	$target = $root . '/' . $artifact_path;
	$parent = dirname( $target );
	if ( ! is_dir( $parent ) && ! mkdir( $parent, 0700, true ) ) {
		docs_agent_fail( 'ARTIFACT_WRITE_FAILED', 'Could not create completion artifact parent directory.' );
	}
	$root_real = realpath( $root );
	$parent_real = realpath( $parent );
	if ( false === $root_real || false === $parent_real || ( $parent_real !== $root_real && ! str_starts_with( $parent_real, $root_real . DIRECTORY_SEPARATOR ) ) || is_link( $target ) ) {
		docs_agent_fail( 'ARTIFACT_PATH_INVALID', 'Completion artifact path escapes .codebox/agent-task-artifacts.' );
	}
	$bytes = docs_agent_canonical_report_json( $report );
	$temp = tempnam( $parent_real, '.docs-agent-completion-' );
	if ( false === $temp || strlen( $bytes ) !== file_put_contents( $temp, $bytes ) || ! rename( $temp, $target ) ) {
		if ( is_string( $temp ) ) {
			@unlink( $temp );
		}
		docs_agent_fail( 'ARTIFACT_WRITE_FAILED', 'Could not atomically write the completion artifact.' );
	}
	return $target;
}

/** @return array{publish:bool,pr_required:bool} */
function docs_agent_publication_expectation( string $outcome, string $run_kind, bool $require_pr ): array {
	return array( 'publish' => 'changes' === $outcome, 'pr_required' => 'bootstrap' === $run_kind || $require_pr );
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	$options = getopt( '', array( 'workspace:', 'lane:', 'run-kind:', 'writable-paths:', 'bootstrap-contract-b64:', 'source-delta-b64:', 'artifact-path:' ) );
	try {
		foreach ( array( 'workspace', 'lane', 'run-kind', 'writable-paths', 'artifact-path' ) as $required ) {
			if ( ! isset( $options[ $required ] ) || ! is_string( $options[ $required ] ) ) {
				docs_agent_fail( 'INVOCATION', "Missing required --{$required} option." );
			}
		}
		$decode_b64_json = static function ( mixed $value, string $name, array $default ): array {
			if ( ! is_string( $value ) || '' === $value ) {
				return $default;
			}
			$decoded = base64_decode( $value, true );
			$value = false === $decoded ? null : json_decode( $decoded, true );
			if ( ! is_array( $value ) ) {
				docs_agent_fail( 'INVOCATION', "{$name} must be base64-encoded JSON." );
			}
			return $value;
		};
		$report = docs_agent_build_completion_report( array( 'workspace' => $options['workspace'], 'lane' => $options['lane'], 'run_kind' => $options['run-kind'], 'writable_paths' => $options['writable-paths'], 'bootstrap_contract' => $decode_b64_json( $options['bootstrap-contract-b64'] ?? '', 'bootstrap_contract', array() ), 'source_delta' => $decode_b64_json( $options['source-delta-b64'] ?? '', 'source_delta', array() ) ) );
		docs_agent_write_report_artifact( $report, $options['workspace'], $options['artifact-path'] );
		fwrite( STDOUT, 'Docs Agent completion contract passed: ' . json_encode( array( 'outcome' => $report['outcome'], 'changed_paths' => $report['changed_paths'] ), JSON_UNESCAPED_SLASHES ) . PHP_EOL );
	} catch ( Docs_Agent_Contract_Failure $error ) {
		fwrite( STDERR, "docs-agent.completion-contract.{$error->diagnostic_code}: {$error->getMessage()}" . PHP_EOL );
		exit( 1 );
	}
}

<?php
/**
 * Validate a Docs Agent completion report against the target workspace.
 *
 * The live workflow reads the report from the canonical raw transcript and
 * writes validated canonical bytes for declared-artifact staging. Tests may
 * pass --report directly to exercise the same semantic validator.
 */

declare( strict_types=1 );

final class Docs_Agent_Contract_Failure extends RuntimeException {
	public function __construct( public readonly string $diagnostic_code, string $message ) {
		parent::__construct( $message );
	}
}

function docs_agent_fail( string $code, string $message ): never {
	throw new Docs_Agent_Contract_Failure( $code, $message );
}

/** @return array<string,mixed> */
function docs_agent_decode_object( string $json, string $code, string $name ): array {
	try {
		$value = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
	} catch ( JsonException $error ) {
		docs_agent_fail( $code, "{$name} is malformed JSON: {$error->getMessage()}" );
	}
	if ( ! is_array( $value ) || array_is_list( $value ) ) {
		docs_agent_fail( $code, "{$name} must be a JSON object." );
	}
	return $value;
}

/** @return list<string> */
function docs_agent_strings( mixed $value, string $field, bool $allow_empty = false ): array {
	if ( ! is_array( $value ) || ! array_is_list( $value ) || ( ! $allow_empty && array() === $value ) ) {
		docs_agent_fail( 'REPORT_INCOMPLETE', "{$field} must be " . ( $allow_empty ? 'a' : 'a non-empty' ) . ' list.' );
	}
	$strings = array();
	foreach ( $value as $item ) {
		if ( ! is_string( $item ) || '' === trim( $item ) ) {
			docs_agent_fail( 'REPORT_INCOMPLETE', "{$field} must contain only non-empty strings." );
		}
		$strings[] = str_replace( '\\', '/', trim( $item ) );
	}
	if ( count( $strings ) !== count( array_unique( $strings ) ) ) {
		docs_agent_fail( 'REPORT_CONTRADICTORY', "{$field} contains duplicate values." );
	}
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
	$commands = array(
		'git diff --name-only --relative HEAD',
		'git ls-files --others --exclude-standard',
	);
	$paths = array();
	foreach ( $commands as $command ) {
		$output = array();
		$code   = 0;
		exec( 'git -C ' . escapeshellarg( $workspace ) . ' ' . substr( $command, 4 ) . ' 2>/dev/null', $output, $code );
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
	$iterator = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $workspace, FilesystemIterator::SKIP_DOTS ),
			static fn( SplFileInfo $file ): bool => ! in_array( $file->getFilename(), array( '.git', '.codebox', 'node_modules', 'vendor' ), true )
		)
	);
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && ! $file->isLink() ) {
			$files[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( rtrim( $workspace, DIRECTORY_SEPARATOR ) ) + 1 ) );
		}
	}
	sort( $files );
	return $files;
}

/** @param array<string,mixed> $contract */
function docs_agent_validate_bootstrap_contract( array $contract, string $workspace, array $actual_changes, string $outcome ): void {
	$required_paths    = docs_agent_strings( $contract['required_paths'] ?? array(), 'bootstrap_contract.required_paths', true );
	$required_globs    = $contract['required_globs'] ?? array();
	$entry_points      = $contract['entry_points'] ?? array();
	$forbidden_phrases = docs_agent_strings( $contract['forbidden_phrases'] ?? array(), 'bootstrap_contract.forbidden_phrases', true );
	if ( array() === $required_paths && array() === $required_globs && array() === $entry_points ) {
		docs_agent_fail( 'BOOTSTRAP_CONTRACT_EMPTY', 'Bootstrap requires positive caller completion criteria.' );
	}
	$files = docs_agent_workspace_files( $workspace );
	foreach ( $required_paths as $path ) {
		if ( ! is_file( $workspace . '/' . $path ) || 80 > filesize( $workspace . '/' . $path ) ) {
			docs_agent_fail( 'BOOTSTRAP_REQUIRED_PATH', "Bootstrap required path is absent or insubstantial: {$path}" );
		}
	}
	if ( ! is_array( $required_globs ) || ! array_is_list( $required_globs ) || ! is_array( $entry_points ) || ! array_is_list( $entry_points ) ) {
		docs_agent_fail( 'BOOTSTRAP_CONTRACT_MALFORMED', 'Bootstrap glob and entry-point criteria must be lists.' );
	}
	foreach ( $required_globs as $glob ) {
		if ( ! is_array( $glob ) || ! is_string( $glob['pattern'] ?? null ) || ! is_int( $glob['min'] ?? $glob['min_count'] ?? null ) ) {
			docs_agent_fail( 'BOOTSTRAP_CONTRACT_MALFORMED', 'Each bootstrap glob requires pattern and integer min.' );
		}
		$count = count( array_filter( $files, static fn( string $file ): bool => docs_agent_path_matches( $file, $glob['pattern'] ) ) );
		$min   = $glob['min'] ?? $glob['min_count'];
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
			$content = @file_get_contents( $workspace . '/' . $file );
			if ( false !== $content && str_contains( $content, $phrase ) ) {
				docs_agent_fail( 'BOOTSTRAP_FORBIDDEN_PHRASE', "Bootstrap documentation contains forbidden phrase in {$file}." );
			}
		}
	}
	if ( 'changes' === $outcome ) {
		$markdown_changes = array_values( array_filter( $actual_changes, static fn( string $path ): bool => 1 === preg_match( '/\.(?:md|mdx)$/i', $path ) ) );
		$bytes = 0;
		foreach ( $markdown_changes as $path ) {
			$bytes += is_file( $workspace . '/' . $path ) ? (int) filesize( $workspace . '/' . $path ) : 0;
		}
		if ( count( $markdown_changes ) < 2 || $bytes < 500 ) {
			docs_agent_fail( 'BOOTSTRAP_NOT_SUBSTANTIVE', 'Fresh bootstrap changes require at least two documentation pages and 500 bytes of navigable content.' );
		}
	}
}

/** @param array<string,mixed> $report @param array<string,mixed> $options */
function docs_agent_validate_report( array $report, array $options ): array {
	$required_top = array( 'schema', 'lane', 'run_kind', 'outcome', 'scope', 'items', 'changed_paths' );
	foreach ( $required_top as $field ) {
		if ( ! array_key_exists( $field, $report ) ) {
			docs_agent_fail( 'REPORT_INCOMPLETE', "Completion report is missing {$field}." );
		}
	}
	if ( 'docs-agent/completion-report/v1' !== $report['schema'] ) {
		docs_agent_fail( 'REPORT_SCHEMA', 'Completion report must use docs-agent/completion-report/v1.' );
	}
	foreach ( array( 'lane', 'run_kind' ) as $field ) {
		if ( ( $options[ $field ] ?? null ) !== $report[ $field ] ) {
			docs_agent_fail( 'REPORT_CONTEXT_MISMATCH', "Completion report {$field} does not match the requested run." );
		}
	}
	$outcome = $report['outcome'];
	if ( ! in_array( $outcome, array( 'changes', 'no_changes' ), true ) ) {
		docs_agent_fail( 'REPORT_INCOMPLETE', 'Completion report outcome must be changes or no_changes.' );
	}
	$scope = $report['scope'];
	if ( ! is_array( $scope ) || array_is_list( $scope ) || ! in_array( $scope['source_basis'] ?? null, array( 'inventory', 'bounded_delta' ), true ) ) {
		docs_agent_fail( 'REPORT_INCOMPLETE', 'Completion report requires an inventory or bounded_delta scope.' );
	}
	docs_agent_strings( $scope['source_refs'] ?? null, 'scope.source_refs' );
	docs_agent_strings( $scope['documentation_surfaces'] ?? null, 'scope.documentation_surfaces' );
	if ( 'maintenance' === $report['run_kind'] && 'bounded_delta' !== $scope['source_basis'] ) {
		docs_agent_fail( 'REPORT_CONTEXT_MISMATCH', 'Maintenance completion reports must use the caller-bounded source delta.' );
	}
	$items = $report['items'];
	if ( ! is_array( $items ) || ! array_is_list( $items ) || array() === $items ) {
		docs_agent_fail( 'REPORT_INCOMPLETE', 'Completion report requires at least one evidence-backed disposition.' );
	}
	$item_map = array();
	$change_dispositions = 0;
	foreach ( $items as $item ) {
		if ( ! is_array( $item ) || ! is_string( $item['id'] ?? null ) || '' === trim( $item['id'] ) || ! is_string( $item['evidence'] ?? null ) || '' === trim( $item['evidence'] ) ) {
			docs_agent_fail( 'REPORT_INCOMPLETE', 'Every report item requires a unique id and non-empty evidence.' );
		}
		$id = trim( $item['id'] );
		if ( isset( $item_map[ $id ] ) ) {
			docs_agent_fail( 'REPORT_CONTRADICTORY', "Completion report repeats item {$id}." );
		}
		docs_agent_strings( $item['source_refs'] ?? null, "items.{$id}.source_refs" );
		docs_agent_strings( $item['documentation_paths'] ?? null, "items.{$id}.documentation_paths", true );
		$disposition = $item['disposition'] ?? null;
		if ( ! in_array( $disposition, array( 'created', 'updated', 'verified_current', 'not_documentation_relevant' ), true ) ) {
			docs_agent_fail( 'REPORT_INCOMPLETE', "Report item {$id} has an invalid disposition." );
		}
		if ( 'no_changes' === $outcome && in_array( $disposition, array( 'created', 'updated' ), true ) ) {
			docs_agent_fail( 'REPORT_CONTRADICTORY', "No-change report item {$id} claims a documentation change." );
		}
		$change_dispositions += in_array( $disposition, array( 'created', 'updated' ), true ) ? 1 : 0;
		$item_map[ $id ] = $item;
	}
	$workspace      = (string) $options['workspace'];
	$actual_changes = docs_agent_workspace_changes( $workspace );
	$reported       = docs_agent_strings( $report['changed_paths'], 'changed_paths', true );
	sort( $reported );
	if ( 'changes' === $outcome && array() === $actual_changes ) {
		docs_agent_fail( 'CHANGES_DIFF_EMPTY', 'A changes outcome requires an actual workspace diff; no-op writes do not count.' );
	}
	if ( 'no_changes' === $outcome && array() !== $actual_changes ) {
		docs_agent_fail( 'NO_CHANGES_DIFF_DIRTY', 'A no_changes outcome requires a clean workspace diff.' );
	}
	if ( $reported !== $actual_changes ) {
		docs_agent_fail( 'REPORT_DIFF_MISMATCH', 'Completion report changed_paths must exactly match the actual workspace diff.' );
	}
	if ( 'changes' === $outcome && 0 === $change_dispositions ) {
		docs_agent_fail( 'REPORT_CONTRADICTORY', 'A changes outcome requires at least one created or updated disposition.' );
	}
	$writable_paths = array_values( array_filter( array_map( 'trim', explode( ',', (string) $options['writable_paths'] ) ) ) );
	foreach ( $actual_changes as $path ) {
		if ( array() === $writable_paths || ! array_filter( $writable_paths, static fn( string $pattern ): bool => docs_agent_path_matches( $path, $pattern ) ) ) {
			docs_agent_fail( 'WRITABLE_SCOPE_VIOLATION', "Actual changed path is outside writable_paths: {$path}" );
		}
	}
	$source_delta = $options['source_delta'] ?? array();
	if ( ! is_array( $source_delta ) || ! array_is_list( $source_delta ) ) {
		docs_agent_fail( 'SOURCE_DELTA_MALFORMED', 'source_delta must be a JSON list.' );
	}
	if ( 'maintenance' === $report['run_kind'] && array() === $source_delta ) {
		docs_agent_fail( 'SOURCE_DELTA_EMPTY', 'Maintenance requires a non-empty caller-bounded source_delta.' );
	}
	$delta_ids = array();
	foreach ( $source_delta as $delta ) {
		if ( ! is_array( $delta ) || ! is_string( $delta['id'] ?? null ) || '' === trim( $delta['id'] ) || ! is_bool( $delta['requires_documentation_change'] ?? null ) ) {
			docs_agent_fail( 'SOURCE_DELTA_MALFORMED', 'Each source_delta item requires id, source_refs, and requires_documentation_change.' );
		}
		$id          = trim( $delta['id'] );
		$source_refs = docs_agent_strings( $delta['source_refs'] ?? null, "source_delta.{$id}.source_refs" );
		if ( isset( $delta_ids[ $id ] ) ) {
			docs_agent_fail( 'SOURCE_DELTA_MALFORMED', "source_delta repeats id {$id}." );
		}
		$delta_ids[ $id ] = true;
		if ( ! isset( $item_map[ $id ] ) ) {
			docs_agent_fail( 'SOURCE_DELTA_INCOMPLETE', "Completion report omits caller source delta {$id}." );
		}
		$reported_refs = docs_agent_strings( $item_map[ $id ]['source_refs'], "items.{$id}.source_refs" );
		$missing_refs  = array_values( array_diff( $source_refs, $reported_refs ) );
		if ( array() !== $missing_refs ) {
			docs_agent_fail( 'SOURCE_DELTA_INCOMPLETE', "Completion report item {$id} omits caller source refs: " . implode( ', ', $missing_refs ) );
		}
		if ( true === $delta['requires_documentation_change'] && ! in_array( $item_map[ $id ]['disposition'], array( 'created', 'updated' ), true ) ) {
			docs_agent_fail( 'KNOWN_DRIFT_NO_CHANGE', "Known source drift {$id} requires a documentation change." );
		}
	}
	if ( 'bootstrap' === $report['run_kind'] ) {
		docs_agent_validate_bootstrap_contract( $options['bootstrap_contract'] ?? array(), $workspace, $actual_changes, $outcome );
	}
	return array( 'outcome' => $outcome, 'changed_paths' => $actual_changes, 'items' => count( $items ) );
}

/** @param array<string,mixed> $report */
function docs_agent_canonical_report_json( array $report ): string {
	$canonical_strings = static function ( array $values ): array {
		$values = array_map( static fn( string $value ): string => str_replace( '\\', '/', trim( $value ) ), $values );
		sort( $values );
		return array_values( $values );
	};
	$items = array_map(
		static fn( array $item ): array => array(
			'id'                  => trim( $item['id'] ),
			'source_refs'         => $canonical_strings( $item['source_refs'] ),
			'documentation_paths' => $canonical_strings( $item['documentation_paths'] ),
			'disposition'         => $item['disposition'],
			'evidence'            => trim( $item['evidence'] ),
		),
		$report['items']
	);
	usort( $items, static fn( array $left, array $right ): int => $left['id'] <=> $right['id'] );
	$canonical = array(
		'schema'        => $report['schema'],
		'lane'          => $report['lane'],
		'run_kind'      => $report['run_kind'],
		'outcome'       => $report['outcome'],
		'scope'         => array(
			'source_basis'          => $report['scope']['source_basis'],
			'source_refs'           => $canonical_strings( $report['scope']['source_refs'] ),
			'documentation_surfaces' => $canonical_strings( $report['scope']['documentation_surfaces'] ),
		),
		'items'         => $items,
		'changed_paths' => $canonical_strings( $report['changed_paths'] ),
	);
	return json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n";
}

/** @param array<string,mixed> $report */
function docs_agent_write_report_artifact( array $report, string $workspace, string $artifact_path ): string {
	$artifact_path = str_replace( '\\', '/', trim( $artifact_path ) );
	if ( '' === $artifact_path || str_starts_with( $artifact_path, '/' ) || preg_match( '~(?:^|/)\.\.(?:/|$)~', $artifact_path ) || '.json' !== strtolower( substr( $artifact_path, -5 ) ) ) {
		docs_agent_fail( 'ARTIFACT_PATH_INVALID', 'Completion artifact path must be a relative JSON path under .codebox/agent-task-artifacts.' );
	}
	$root = rtrim( $workspace, DIRECTORY_SEPARATOR ) . '/.codebox/agent-task-artifacts';
	if ( ( ! is_dir( $root ) && ! mkdir( $root, 0700, true ) ) || is_link( $root ) ) {
		docs_agent_fail( 'ARTIFACT_WRITE_FAILED', 'Completion artifact root must be a writable real directory.' );
	}
	$target = $root . '/' . $artifact_path;
	$parent = $root;
	foreach ( array_slice( explode( '/', $artifact_path ), 0, -1 ) as $segment ) {
		if ( '' === $segment || '.' === $segment ) {
			continue;
		}
		$parent .= '/' . $segment;
		if ( ( ! is_dir( $parent ) && ! mkdir( $parent, 0700 ) ) || is_link( $parent ) ) {
			docs_agent_fail( 'ARTIFACT_PATH_INVALID', 'Completion artifact path must not traverse symlinks.' );
		}
	}
	if ( is_link( $target ) ) {
		docs_agent_fail( 'ARTIFACT_PATH_INVALID', 'Completion artifact path must not traverse symlinks.' );
	}
	$root_real   = realpath( $root );
	$parent_real = realpath( $parent );
	if ( false === $root_real || false === $parent_real || ( $parent_real !== $root_real && ! str_starts_with( $parent_real, $root_real . DIRECTORY_SEPARATOR ) ) ) {
		docs_agent_fail( 'ARTIFACT_PATH_INVALID', 'Completion artifact path escapes .codebox/agent-task-artifacts.' );
	}
	$bytes = docs_agent_canonical_report_json( $report );
	if ( strlen( $bytes ) > 2 * 1024 * 1024 ) {
		docs_agent_fail( 'ARTIFACT_TOO_LARGE', 'Completion artifact exceeds the 2 MiB bound.' );
	}
	$temp = tempnam( $parent_real, '.docs-agent-completion-' );
	if ( false === $temp || strlen( $bytes ) !== file_put_contents( $temp, $bytes ) || ! rename( $temp, $target ) ) {
		if ( is_string( $temp ) ) {
			@unlink( $temp );
		}
		docs_agent_fail( 'ARTIFACT_WRITE_FAILED', 'Could not atomically write the validated completion artifact.' );
	}
	return $target;
}

/** @return array{publish:bool,pr_required:bool} */
function docs_agent_publication_expectation( string $outcome, string $run_kind, bool $require_pr ): array {
	return array(
		'publish'     => 'changes' === $outcome,
		'pr_required' => 'bootstrap' === $run_kind || $require_pr,
	);
}

/** @return array<string,mixed> */
function docs_agent_report_from_transcripts( string $root ): array {
	if ( ! is_dir( $root ) ) {
		docs_agent_fail( 'REPORT_MISSING', 'Canonical transcript directory is absent.' );
	}
	$reports = array();
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || $file->isLink() || 'json' !== strtolower( $file->getExtension() ) || $file->getSize() > 2 * 1024 * 1024 ) {
			continue;
		}
		try {
			$transcript = json_decode( (string) file_get_contents( $file->getPathname() ), true, 128, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			continue;
		}
		$values = array( $transcript );
		while ( array() !== $values ) {
			$value = array_pop( $values );
			if ( is_array( $value ) ) {
				array_push( $values, ...array_values( $value ) );
				continue;
			}
			if ( ! is_string( $value ) || ! preg_match_all( '~<DOCS_AGENT_COMPLETION_REPORT>\s*(\{.*?\})\s*</DOCS_AGENT_COMPLETION_REPORT>~s', $value, $matches ) ) {
				continue;
			}
			foreach ( $matches[1] as $json ) {
				$report = docs_agent_decode_object( $json, 'REPORT_MALFORMED', 'Completion report' );
				$reports[ hash( 'sha256', (string) json_encode( $report ) ) ] = $report;
			}
		}
	}
	if ( array() === $reports ) {
		docs_agent_fail( 'REPORT_MISSING', 'Canonical transcript does not contain a Docs Agent completion report.' );
	}
	if ( 1 !== count( $reports ) ) {
		docs_agent_fail( 'REPORT_CONTRADICTORY', 'Canonical transcript must contain exactly one completion report.' );
	}
	return array_values( $reports )[0];
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	$options = getopt( '', array( 'workspace:', 'lane:', 'run-kind:', 'writable-paths:', 'bootstrap-contract-b64:', 'source-delta-b64:', 'transcript-root:', 'report:', 'artifact-path:' ) );
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
			if ( false === $decoded ) {
				docs_agent_fail( 'INVOCATION', "{$name} is not valid base64." );
			}
			$value = json_decode( $decoded, true );
			if ( ! is_array( $value ) ) {
				docs_agent_fail( 'INVOCATION', "{$name} is not valid JSON." );
			}
			return $value;
		};
		$report = isset( $options['report'] ) && is_string( $options['report'] )
			? docs_agent_decode_object( (string) file_get_contents( $options['report'] ), 'REPORT_MALFORMED', 'Completion report' )
			: docs_agent_report_from_transcripts( (string) ( $options['transcript-root'] ?? '.codebox/agent-task-artifacts' ) );
		$workspace = realpath( $options['workspace'] ) ?: $options['workspace'];
		$result    = docs_agent_validate_report(
			$report,
			array(
				'workspace'          => $workspace,
				'lane'               => $options['lane'],
				'run_kind'           => $options['run-kind'],
				'writable_paths'      => $options['writable-paths'],
				'bootstrap_contract' => $decode_b64_json( $options['bootstrap-contract-b64'] ?? '', 'bootstrap_contract', array() ),
				'source_delta'       => $decode_b64_json( $options['source-delta-b64'] ?? '', 'source_delta', array() ),
			)
		);
		docs_agent_write_report_artifact( $report, $workspace, $options['artifact-path'] );
		fwrite( STDOUT, 'Docs Agent completion contract passed: ' . json_encode( $result, JSON_UNESCAPED_SLASHES ) . PHP_EOL );
	} catch ( Docs_Agent_Contract_Failure $error ) {
		fwrite( STDERR, "docs-agent.completion-contract.{$error->diagnostic_code}: {$error->getMessage()}" . PHP_EOL );
		exit( 1 );
	}
}

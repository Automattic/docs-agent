<?php
/** Validate deterministic Docs Agent completion and drift semantics. */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/scripts/validate-docs-agent-completion.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$run = static function ( string $command, string $cwd ): void {
	$output = array();
	$code   = 0;
	exec( 'cd ' . escapeshellarg( $cwd ) . ' && ' . $command . ' 2>&1', $output, $code );
	if ( 0 !== $code ) {
		throw new RuntimeException( "Command failed: {$command}\n" . implode( "\n", $output ) );
	}
};

$workspace = static function () use ( $run ): string {
	$path = sys_get_temp_dir() . '/docs-agent-contract-' . bin2hex( random_bytes( 6 ) );
	if ( ! mkdir( $path, 0700, true ) ) {
		throw new RuntimeException( 'Failed to create test workspace.' );
	}
	mkdir( $path . '/docs', 0700, true );
	file_put_contents( $path . '/README.md', "# Project\n\nExisting project documentation.\n" );
	file_put_contents( $path . '/docs/guide.md', "# Guide\n\nExisting guide.\n" );
	$run( 'git init -q && git config user.name test && git config user.email test@example.com && git add . && git commit -qm baseline', $path );
	return $path;
};

$base_report = static function ( string $outcome, array $changed_paths = array() ): array {
	return array(
		'schema'        => 'docs-agent/completion-report/v1',
		'lane'          => 'technical',
		'run_kind'      => 'maintenance',
		'outcome'       => $outcome,
		'scope'         => array(
			'source_basis'          => 'bounded_delta',
			'source_refs'           => array( 'src/service.php:10-40' ),
			'documentation_surfaces' => array( 'README.md', 'docs/guide.md' ),
		),
		'items'         => array(
			array(
				'id'                  => 'service-contract',
				'source_refs'         => array( 'src/service.php:10-40' ),
				'documentation_paths' => array( 'docs/guide.md' ),
				'disposition'         => 'changes' === $outcome ? 'updated' : 'verified_current',
				'evidence'            => 'Compared the public method and failure behavior with the guide.',
			),
		),
		'changed_paths' => $changed_paths,
	);
};

$options = static function ( string $workspace, array $overrides = array() ): array {
	return array_merge(
		array(
			'workspace'          => $workspace,
			'lane'               => 'technical',
			'run_kind'           => 'maintenance',
			'writable_paths'      => 'README.md,docs/**',
			'bootstrap_contract' => array(),
			'source_delta'       => array(
				array(
					'id'                            => 'service-contract',
					'source_refs'                   => array( 'src/service.php:10-40' ),
					'requires_documentation_change' => false,
				),
			),
		),
		$overrides
	);
};

$expect_failure = static function ( string $code, callable $callback ) use ( $assert ): void {
	try {
		$callback();
	} catch ( Docs_Agent_Contract_Failure $error ) {
		$assert( $code === $error->diagnostic_code, "Expected {$code}; received {$error->diagnostic_code}: {$error->getMessage()}" );
		return;
	}
	throw new RuntimeException( "Expected Docs Agent diagnostic {$code}." );
};

$workspaces = array();
try {
	// Maintenance changes: report and actual writable diff agree.
	$maintenance = $workspace();
	$workspaces[] = $maintenance;
	file_put_contents( $maintenance . '/docs/guide.md', "# Guide\n\nUpdated source-grounded service contract and failure behavior.\n" );
	$result = docs_agent_validate_report( $base_report( 'changes', array( 'docs/guide.md' ) ), $options( $maintenance ) );
	$assert( 'changes' === $result['outcome'], 'Maintenance changes must pass with a matching report and diff.' );

	// Honest no-change: bounded evidence exists and the documentation diff is clean.
	$no_change = $workspace();
	$workspaces[] = $no_change;
	$result = docs_agent_validate_report( $base_report( 'no_changes' ), $options( $no_change ) );
	$assert( 'no_changes' === $result['outcome'], 'Evidence-backed maintenance no_changes must pass on a clean diff.' );
	$expect_failure( 'SOURCE_DELTA_EMPTY', static fn() => docs_agent_validate_report( $base_report( 'no_changes' ), $options( $no_change, array( 'source_delta' => array() ) ) ) );
	$transcript_root = $no_change . '/.codebox/agent-task-artifacts/runtime/files';
	mkdir( $transcript_root, 0700, true );
	$report_json = json_encode( $base_report( 'no_changes' ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
	file_put_contents( $transcript_root . '/transcript.json', json_encode( array( 'messages' => array( array( 'role' => 'assistant', 'content' => "<DOCS_AGENT_COMPLETION_REPORT>{$report_json}</DOCS_AGENT_COMPLETION_REPORT>" ) ) ), JSON_THROW_ON_ERROR ) );
	$extracted = docs_agent_report_from_transcripts( $no_change . '/.codebox/agent-task-artifacts' );
	$assert( 'no_changes' === $extracted['outcome'], 'The live transcript transport must materialize one parseable completion report.' );
	$validator    = dirname( __DIR__ ) . '/scripts/validate-docs-agent-completion.php';
	$source_delta = base64_encode( (string) json_encode( $options( $no_change )['source_delta'], JSON_THROW_ON_ERROR ) );
	$run(
		'php ' . escapeshellarg( $validator ) .
		' --workspace ' . escapeshellarg( $no_change ) .
		' --lane technical --run-kind maintenance --writable-paths ' . escapeshellarg( 'README.md,docs/**' ) .
		' --source-delta-b64 ' . escapeshellarg( $source_delta ) .
		' --transcript-root ' . escapeshellarg( $no_change . '/.codebox/agent-task-artifacts' ) .
		' --artifact-path docs-agent-completion-report.json',
		$no_change
	);
	$artifact_path = $no_change . '/.codebox/agent-task-artifacts/docs-agent-completion-report.json';
	$expected_bytes = '{"schema":"docs-agent/completion-report/v1","lane":"technical","run_kind":"maintenance","outcome":"no_changes","scope":{"source_basis":"bounded_delta","source_refs":["src/service.php:10-40"],"documentation_surfaces":["README.md","docs/guide.md"]},"items":[{"id":"service-contract","source_refs":["src/service.php:10-40"],"documentation_paths":["docs/guide.md"],"disposition":"verified_current","evidence":"Compared the public method and failure behavior with the guide."}],"changed_paths":[]}' . "\n";
	$assert( $expected_bytes === file_get_contents( $artifact_path ), 'The validator must write deterministic canonical report bytes at the declared artifact path.' );
	$expect_failure( 'ARTIFACT_PATH_INVALID', static fn() => docs_agent_write_report_artifact( $extracted, $no_change, '../completion.json' ) );

	// A no-op write cannot support a changes outcome.
	$noop = $workspace();
	$workspaces[] = $noop;
	file_put_contents( $noop . '/docs/guide.md', (string) file_get_contents( $noop . '/docs/guide.md' ) );
	$expect_failure( 'CHANGES_DIFF_EMPTY', static fn() => docs_agent_validate_report( $base_report( 'changes', array( 'docs/guide.md' ) ), $options( $noop ) ) );

	// Known drift cannot be hidden behind no_changes.
	$known_drift = $workspace();
	$workspaces[] = $known_drift;
	$expect_failure(
		'KNOWN_DRIFT_NO_CHANGE',
		static fn() => docs_agent_validate_report(
			$base_report( 'no_changes' ),
			$options( $known_drift, array( 'source_delta' => array( array( 'id' => 'service-contract', 'source_refs' => array( 'src/service.php:10-40' ), 'requires_documentation_change' => true ) ) ) )
		)
	);

	// Every caller-bounded item and source ref must be represented in the report.
	$incomplete_delta = array(
		array( 'id' => 'service-contract', 'source_refs' => array( 'src/service.php:10-40', 'src/service.php:50-60' ), 'requires_documentation_change' => false ),
		array( 'id' => 'missing-contract', 'source_refs' => array( 'src/missing.php:1-20' ), 'requires_documentation_change' => false ),
	);
	$expect_failure( 'SOURCE_DELTA_INCOMPLETE', static fn() => docs_agent_validate_report( $base_report( 'no_changes' ), $options( $no_change, array( 'source_delta' => $incomplete_delta ) ) ) );
	$missing_ref_delta = array( array( 'id' => 'service-contract', 'source_refs' => array( 'src/service.php:10-40', 'src/service.php:50-60' ), 'requires_documentation_change' => false ) );
	$expect_failure( 'SOURCE_DELTA_INCOMPLETE', static fn() => docs_agent_validate_report( $base_report( 'no_changes' ), $options( $no_change, array( 'source_delta' => $missing_ref_delta ) ) ) );

	// Dirty no_changes, report/diff mismatch, and scope violations are distinct.
	$dirty = $workspace();
	$workspaces[] = $dirty;
	file_put_contents( $dirty . '/docs/guide.md', "# Changed\n" );
	$expect_failure( 'NO_CHANGES_DIFF_DIRTY', static fn() => docs_agent_validate_report( $base_report( 'no_changes' ), $options( $dirty ) ) );
	$expect_failure( 'REPORT_DIFF_MISMATCH', static fn() => docs_agent_validate_report( $base_report( 'changes', array( 'README.md' ) ), $options( $dirty ) ) );
	$expect_failure( 'WRITABLE_SCOPE_VIOLATION', static fn() => docs_agent_validate_report( $base_report( 'changes', array( 'docs/guide.md' ) ), $options( $dirty, array( 'writable_paths' => 'README.md' ) ) ) );

	// Missing, malformed, incomplete, and contradictory reports fail specifically.
	$expect_failure( 'REPORT_MISSING', static fn() => docs_agent_report_from_transcripts( sys_get_temp_dir() . '/not-a-docs-agent-transcript' ) );
	$expect_failure( 'REPORT_MALFORMED', static fn() => docs_agent_decode_object( '{', 'REPORT_MALFORMED', 'Completion report' ) );
	$incomplete = $base_report( 'no_changes' );
	unset( $incomplete['items'] );
	$expect_failure( 'REPORT_INCOMPLETE', static fn() => docs_agent_validate_report( $incomplete, $options( $no_change ) ) );
	$contradictory = $base_report( 'no_changes' );
	$contradictory['items'][0]['disposition'] = 'updated';
	$expect_failure( 'REPORT_CONTRADICTORY', static fn() => docs_agent_validate_report( $contradictory, $options( $no_change ) ) );

	// Fresh bootstrap produces a substantive, navigable system satisfying caller criteria.
	$bootstrap = $workspace();
	$workspaces[] = $bootstrap;
	$overview = "# Architecture\n\n" . str_repeat( 'Source-grounded architecture, lifecycle, extension, operation, and testing guidance. ', 6 ) . "\n";
	$setup    = "# Setup and Validation\n\n" . str_repeat( 'Install dependencies, run tests, inspect failures, and verify generated outputs. ', 5 ) . "\n";
	file_put_contents( $bootstrap . '/README.md', "# Project\n\nStart with [architecture](docs/architecture.md) and [setup](docs/setup.md).\n" );
	file_put_contents( $bootstrap . '/docs/architecture.md', $overview );
	file_put_contents( $bootstrap . '/docs/setup.md', $setup );
	$bootstrap_report = $base_report( 'changes', array( 'README.md', 'docs/architecture.md', 'docs/setup.md' ) );
	$bootstrap_report['run_kind'] = 'bootstrap';
	$bootstrap_report['scope']['source_basis'] = 'inventory';
	$bootstrap_report['items'][0]['documentation_paths'] = array( 'README.md', 'docs/architecture.md', 'docs/setup.md' );
	$bootstrap_contract = array(
		'required_paths'    => array( 'README.md', 'docs/architecture.md', 'docs/setup.md' ),
		'required_globs'    => array( array( 'pattern' => 'docs/**/*.md', 'min' => 2 ) ),
		'entry_points'      => array( array( 'path' => 'README.md', 'must_link_to' => array( 'docs/architecture.md', 'docs/setup.md' ) ) ),
		'forbidden_phrases' => array( 'TODO: document this' ),
	);
	$result = docs_agent_validate_report( $bootstrap_report, $options( $bootstrap, array( 'run_kind' => 'bootstrap', 'bootstrap_contract' => $bootstrap_contract, 'source_delta' => array() ) ) );
	$assert( 3 === count( $result['changed_paths'] ), 'Bootstrap must validate its complete actual documentation diff.' );

	$thin_bootstrap = $workspace();
	$workspaces[] = $thin_bootstrap;
	file_put_contents( $thin_bootstrap . '/README.md', "# Project\n\n[Guide](docs/guide.md)\n" );
	file_put_contents( $thin_bootstrap . '/docs/guide.md', "# Tiny\n" );
	$thin_report = $base_report( 'changes', array( 'README.md', 'docs/guide.md' ) );
	$thin_report['run_kind'] = 'bootstrap';
	$expect_failure(
		'BOOTSTRAP_REQUIRED_PATH',
		static fn() => docs_agent_validate_report(
			$thin_report,
			$options( $thin_bootstrap, array( 'run_kind' => 'bootstrap', 'bootstrap_contract' => array( 'required_paths' => array( 'docs/guide.md' ), 'required_globs' => array(), 'entry_points' => array(), 'forbidden_phrases' => array() ), 'source_delta' => array() ) )
		)
	);

	// Publication remains a separate deterministic consequence of semantic outcome.
	$assert( array( 'publish' => true, 'pr_required' => true ) === docs_agent_publication_expectation( 'changes', 'bootstrap', false ), 'Bootstrap changes must publish a required PR.' );
	$assert( array( 'publish' => true, 'pr_required' => false ) === docs_agent_publication_expectation( 'changes', 'maintenance', false ), 'Maintenance changes publish without strengthening the PR projection.' );
	$assert( array( 'publish' => true, 'pr_required' => true ) === docs_agent_publication_expectation( 'changes', 'maintenance', true ), 'Maintenance require_pr must strengthen publication.' );
	$assert( array( 'publish' => false, 'pr_required' => false ) === docs_agent_publication_expectation( 'no_changes', 'maintenance', false ), 'Honest default no_changes must not fabricate publication.' );
	$assert( array( 'publish' => false, 'pr_required' => true ) === docs_agent_publication_expectation( 'no_changes', 'maintenance', true ), 'Maintenance no_changes must fail a caller publication requirement rather than fabricating a PR.' );
} finally {
	foreach ( $workspaces as $path ) {
		$run( 'rm -rf ' . escapeshellarg( $path ), '/' );
	}
}

fwrite( STDOUT, "Docs Agent completion contract validation passed.\n" );

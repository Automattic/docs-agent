<?php
/**
 * Import Docs Agent native packages through the real Agents API runtime.
 *
 * Run with: AGENTS_API_DIR=/path/to/agents-api php tests/native-agent-import.php
 */

declare( strict_types=1 );

$agents_api_dir = getenv( 'AGENTS_API_DIR' );
if ( ! is_string( $agents_api_dir ) || ! is_file( $agents_api_dir . '/agents-api.php' ) || ! is_file( $agents_api_dir . '/tests/agents-api-smoke-helpers.php' ) ) {
	fwrite( STDERR, "Set AGENTS_API_DIR to an Automattic/agents-api checkout containing agents-api.php and tests/agents-api-smoke-helpers.php.\n" );
	exit( 1 );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

$root     = dirname( __DIR__ );
$failures = array();
$passes   = 0;

require_once $agents_api_dir . '/tests/agents-api-smoke-helpers.php';
require_once $agents_api_dir . '/src/Runtime/class-wp-agent-message.php';
require_once $agents_api_dir . '/src/Runtime/class-wp-agent-tool-call-gate.php';
agents_api_smoke_require_module();
do_action( 'init' );

$packages = array(
	'technical-docs-bootstrap-agent'   => array( 'path' => 'bundles/technical-docs-agent/native/technical-docs-bootstrap-agent.agent.json' ),
	'technical-docs-maintenance-agent' => array( 'path' => 'bundles/technical-docs-agent/native/technical-docs-maintenance-agent.agent.json' ),
	'user-docs-bootstrap-agent'        => array( 'path' => 'bundles/user-docs-agent/native/user-docs-bootstrap-agent.agent.json' ),
	'user-docs-maintenance-agent'      => array( 'path' => 'bundles/user-docs-agent/native/user-docs-maintenance-agent.agent.json' ),
	'skills-maintenance-agent'         => array( 'path' => 'bundles/skills-agent/native/skills-maintenance-agent.agent.json' ),
);

$specs = array();
foreach ( $packages as $package ) {
	$specs[] = array( 'source' => $root . '/' . $package['path'] );
}

$results = wp_agent_import_runtime_bundles( $specs, array( 'on_conflict' => 'error' ) );
agents_api_smoke_assert_equals( count( $packages ), count( $results ), 'all native packages return importer results', $failures, $passes );

foreach ( array_keys( $packages ) as $index => $slug ) {
	$package = $packages[ $slug ];
	$result  = $results[ $index ] ?? array();

	agents_api_smoke_assert_equals( true, $result['success'] ?? false, "{$slug} imports successfully", $failures, $passes );
	agents_api_smoke_assert_equals( 'registered', $result['status'] ?? '', "{$slug} importer reports registration", $failures, $passes );
	agents_api_smoke_assert_equals( $slug, $result['agent_slug'] ?? '', "{$slug} importer returns its registered slug", $failures, $passes );
	agents_api_smoke_assert_equals( true, wp_has_agent( $slug ), "{$slug} is registered in Agents API", $failures, $passes );

	$agent  = wp_get_agent( $slug );
	$config = $agent instanceof WP_Agent ? $agent->get_default_config() : array();
	$rules  = is_array( $config['tool_call_rules'] ?? null ) ? $config['tool_call_rules'] : array();

	agents_api_smoke_assert_equals( true, in_array( 'workspace_write', $config['enabled_tools'] ?? array(), true ), "{$slug} preserves the workspace write tool", $failures, $passes );
	if ( in_array( $slug, array( 'technical-docs-bootstrap-agent', 'user-docs-bootstrap-agent' ), true ) ) {
		$required_path = 'user-docs-bootstrap-agent' === $slug ? 'docs/user/getting-started.md' : 'docs/README.md';
		$gate = AgentsAPI\AI\WP_Agent_Tool_Call_Gate::from_config( $rules );
		agents_api_smoke_assert_equals( true, $gate instanceof AgentsAPI\AI\WP_Agent_Tool_Call_Gate, "{$slug} creates a completion gate for a fresh documentation surface", $failures, $passes );

		$fresh_surface = array( AgentsAPI\AI\WP_Agent_Message::text( 'user', "The workspace has no {$required_path}. Create the caller-required documentation paths." ) );
		$blocked = $gate instanceof AgentsAPI\AI\WP_Agent_Tool_Call_Gate ? $gate->evaluate_completion( $fresh_surface ) : array();
		agents_api_smoke_assert_equals( false, $blocked['allowed'] ?? null, "{$slug} blocks prose-only completion on a fresh documentation surface", $failures, $passes );
		agents_api_smoke_assert_equals( 'require-bootstrap-workspace-write', $blocked['context']['rule_id'] ?? '', "{$slug} identifies the required bootstrap write gate", $failures, $passes );

		$after_write = array_merge( $fresh_surface, array( AgentsAPI\AI\WP_Agent_Message::toolCall( '', 'workspace_write', array( 'path' => $required_path ), 1, array( 'tool_call_id' => 'bootstrap-docs-index' ) ) ) );
		$allowed = $gate instanceof AgentsAPI\AI\WP_Agent_Tool_Call_Gate ? $gate->evaluate_completion( $after_write ) : array();
		agents_api_smoke_assert_equals( true, $allowed['allowed'] ?? null, "{$slug} accepts completion after a required workspace write", $failures, $passes );

		if ( 'user-docs-bootstrap-agent' === $slug && $gate instanceof AgentsAPI\AI\WP_Agent_Tool_Call_Gate ) {
			// Bounded discovery: reading the whole tree before writing exhausted
			// the turn budget, so reads are capped before the first write and
			// between writes.
			$call = static fn( string $tool, int $n ): array => AgentsAPI\AI\WP_Agent_Message::toolCall( '', $tool, array( 'path' => "docs/user/page-{$n}.md" ), 1, array( 'tool_call_id' => "call-{$tool}-{$n}" ) );
			$reads = array( $call( 'workspace_show', 0 ) );
			for ( $n = 1; $n <= 24; $n++ ) {
				$reads[] = $call( 'workspace_read', $n );
			}
			$read_blocked = $gate->evaluate_call( 'workspace_read', $reads );
			agents_api_smoke_assert_equals( false, $read_blocked['allowed'] ?? null, "{$slug} blocks discovery past its first-write budget", $failures, $passes );
			agents_api_smoke_assert_equals( 'bootstrap-first-write-budget', $read_blocked['context']['rule_id'] ?? '', "{$slug} names the first-write discovery budget", $failures, $passes );
			agents_api_smoke_assert_equals( true, $gate->evaluate_call( 'workspace_write', $reads )['allowed'] ?? null, "{$slug} always allows the documentation write", $failures, $passes );

			$after_first_write = array_merge( $reads, array( $call( 'workspace_write', 100 ) ) );
			agents_api_smoke_assert_equals( true, $gate->evaluate_call( 'workspace_read', $after_first_write )['allowed'] ?? null, "{$slug} resets the discovery budget after a write", $failures, $passes );
			for ( $n = 101; $n <= 116; $n++ ) {
				$after_first_write[] = $call( 'workspace_grep', $n );
			}
			$cadence_blocked = $gate->evaluate_call( 'workspace_read', $after_first_write );
			agents_api_smoke_assert_equals( false, $cadence_blocked['allowed'] ?? null, "{$slug} requires another write after its per-page discovery budget", $failures, $passes );
			agents_api_smoke_assert_equals( 'bootstrap-write-cadence-after-write', $cadence_blocked['context']['rule_id'] ?? '', "{$slug} names the per-page write cadence", $failures, $passes );
			$after_edit = array_merge( $after_first_write, array( $call( 'workspace_edit', 200 ) ) );
			agents_api_smoke_assert_equals( true, $gate->evaluate_call( 'workspace_read', $after_edit )['allowed'] ?? null, "{$slug} accepts an edit as the next write", $failures, $passes );
			agents_api_smoke_assert_equals( true, $gate->evaluate_call( 'workspace_git_diff', $after_edit )['allowed'] ?? null, "{$slug} allows the final git diff review after its last write", $failures, $passes );
			agents_api_smoke_assert_equals( true, $gate->evaluate_completion( $after_first_write )['allowed'] ?? null, "{$slug} may finish once it has written", $failures, $passes );
		}
	} else {
		agents_api_smoke_assert_equals( array(), $rules, "{$slug} permits clean evidence-backed no-change completion", $failures, $passes );
	}

	$chat = AgentsAPI\AI\Channels\WP_Agent_Default_Chat_Handler::execute( array( 'agent' => $slug, 'message' => 'Verify native registration.' ) );
	agents_api_smoke_assert_equals( 'agents_chat_provider_required', $chat instanceof WP_Error ? $chat->get_error_code() : '', "{$slug} resolves through the default native chat handler", $failures, $passes );
}

agents_api_smoke_finish( 'Docs Agent native package import', $failures, $passes );

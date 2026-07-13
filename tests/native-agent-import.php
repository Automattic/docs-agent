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
agents_api_smoke_require_module();
do_action( 'init' );

$packages = array(
	'technical-docs-bootstrap-agent'   => array( 'path' => 'bundles/technical-docs-agent/native/technical-docs-bootstrap-agent.agent.json', 'write_gate' => 'require-doc-write' ),
	'technical-docs-maintenance-agent' => array( 'path' => 'bundles/technical-docs-agent/native/technical-docs-maintenance-agent.agent.json', 'write_gate' => 'require-doc-write' ),
	'user-docs-bootstrap-agent'        => array( 'path' => 'bundles/user-docs-agent/native/user-docs-bootstrap-agent.agent.json', 'write_gate' => 'require-doc-write' ),
	'user-docs-maintenance-agent'      => array( 'path' => 'bundles/user-docs-agent/native/user-docs-maintenance-agent.agent.json', 'write_gate' => 'require-doc-write' ),
	'skills-maintenance-agent'         => array( 'path' => 'bundles/skills-agent/native/skills-maintenance-agent.agent.json', 'write_gate' => 'require-skill-write' ),
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
	$gate   = array_values( array_filter( $rules, static fn( $rule ): bool => is_array( $rule ) && $package['write_gate'] === ( $rule['id'] ?? '' ) ) );

	agents_api_smoke_assert_equals( true, in_array( 'workspace_write', $config['enabled_tools'] ?? array(), true ), "{$slug} preserves the workspace write tool", $failures, $passes );
	agents_api_smoke_assert_equals( true, isset( $gate[0] ) && true === ( $gate[0]['require_tool_use'] ?? false ), "{$slug} preserves the required write gate", $failures, $passes );

	$chat = AgentsAPI\AI\Channels\WP_Agent_Default_Chat_Handler::execute( array( 'agent' => $slug, 'message' => 'Verify native registration.' ) );
	agents_api_smoke_assert_equals( 'agents_chat_provider_required', $chat instanceof WP_Error ? $chat->get_error_code() : '', "{$slug} resolves through the default native chat handler", $failures, $passes );
}

agents_api_smoke_finish( 'Docs Agent native package import', $failures, $passes );

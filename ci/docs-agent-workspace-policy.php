<?php
/**
 * Scope central Docs Agent workspace writes to workflow-approved paths.
 *
 * This file is mounted as a must-use plugin by the central dispatcher workflow.
 */

declare( strict_types=1 );

add_filter(
	'datamachine_workspace_git_policies',
	static function ( array $policies ): array {
		$target_repo = defined( 'DOCS_AGENT_TARGET_REPO' ) ? (string) DOCS_AGENT_TARGET_REPO : '';
		$paths_value = defined( 'DOCS_AGENT_WRITABLE_PATHS' ) ? (string) DOCS_AGENT_WRITABLE_PATHS : '';

		if ( '' === $target_repo || '' === $paths_value ) {
			return $policies;
		}

		$repo_name = preg_replace( '/\.git$/', '', basename( str_replace( '\\', '/', $target_repo ) ) );
		$paths     = array_values(
			array_filter(
				array_map(
					static fn ( string $path ): string => trim( $path ),
					explode( ',', $paths_value )
				),
				static fn ( string $path ): bool => '' !== $path
			)
		);

		if ( '' === $repo_name || empty( $paths ) ) {
			return $policies;
		}

		if ( ! isset( $policies['repos'] ) || ! is_array( $policies['repos'] ) ) {
			$policies['repos'] = array();
		}

		$policies['repos'][ $repo_name ] = array_merge(
			$policies['repos'][ $repo_name ] ?? array(),
			array(
				'write_enabled'  => true,
				'push_enabled'   => true,
				'writable_roots' => $paths,
				'allowed_paths'  => $paths,
			)
		);

		return $policies;
	}
);

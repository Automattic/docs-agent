# Docs Agent Workflows

`maintain-docs.yml` is the consumer-facing reusable workflow for scheduled upkeep. Consumer repositories pass product-level inputs such as `audience`, `base_ref`, `docs_branch`, `writable_paths`, `verification_commands`, `drift_checks`, `prompt`, `run_agent`, and `dry_run`.

The consumer workflow supports separate lanes for technical docs, user docs, and live skills maintenance. Use `audience: skills` with skills/package writable paths instead of broad docs paths.

Schedule skills upkeep separately from docs upkeep. The skills lane should use a dedicated branch such as `docs-agent/skills-upkeep`, skill/package writable paths, verification commands, and drift checks through the portable recipe.

When verification commands or drift checks are needed, pass them through the reusable workflow inputs above. The reusable workflow includes those executable inputs in the portable recipe and keeps the target repository as the writable Docs Agent workspace.

The reusable workflow declares the expected typed review artifacts for Docs Agent runs: transcript, change summary, verification report, drift report, and workspace publication links. `maintain-docs.yml` writes those declarations into the portable recipe and exposes the declaration objects through `declared_artifacts_json`.

The target repository grants `contents: write`, `pull-requests: write`, and `issues: write`. Docs Agent forwards the caller-scoped `${{ github.token }}` to WP Codebox for same-repository publication, so consumers do not configure `ACCESS_TOKEN`. `OPENAI_API_KEY` is an optional workflow secret and is required only for a live OpenAI run; skipped and dry-run calls do not require it. `EXTERNAL_PACKAGE_SOURCE_POLICY` remains a separate required v1 JSON secret that authorizes only the selected public standalone Docs Agent package. Both secrets are forwarded to WP Codebox without serialization into the task descriptor.

Docs Agent consumes the released [WP Codebox v0.12.6](https://github.com/Automattic/wp-codebox/releases/tag/v0.12.6) workflow and passes the matching `wp_codebox_release_ref: v0.12.6` input. WP Codebox validates that the paired tags match, resolves the release tag, verifies its package version, and transports the native task result through controlled `.codebox` result files before publishing the workflow result. See [WP Codebox #1759](https://github.com/Automattic/wp-codebox/issues/1759). Regression reference: [run `29296787203`](https://github.com/Automattic/wp-codebox/actions/runs/29296787203).

## Docs Agent Runner Recipe

Docs Agent workflow call sites prepare a portable recipe instead of calling a concrete runner. Docs Agent owns the native package, lane, artifact, prompt, and workspace mapping. Consumers depend on Docs Agent inputs and review artifacts, not runner internals.

The recipe boundary covers standalone native package selection, workspace publication expectations, artifact declarations, verification, drift checks, and output mapping suggestions. Package provenance is the fixed `DOCS_AGENT_PACKAGE_REVISION`, independent of the reusable-workflow revision, and each descriptor includes a byte digest. Package changes advance that revision and every declared digest atomically. Runtime substrate checkout resolution is intentionally outside the Docs Agent-facing recipe, and validation blocks concrete runner workflow calls, runtime ability names, component paths, mount directives, provider defaults, and define directives from consumer examples.

Native package selection is expressed through recipe `docsAgent.externalPackageSource`. Workspace boundaries are expressed through recipe `runner` fields so agents remain workspace editors while caller-owned execution handles sandboxing and publication handoff.

Run `php tests/validate-docs-agent-bundle.php` after workflow changes so workflow routing and runner config stay aligned.
